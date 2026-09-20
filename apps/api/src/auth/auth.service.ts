import { Injectable, Logger, UnauthorizedException } from '@nestjs/common';
import { JwtService } from '@nestjs/jwt';
import { createHash } from 'node:crypto';
import { RiderStatus } from '@pokbon-delivery/shared';
import { PrismaService } from '../prisma/prisma.service';
import { newOpaqueToken, OtpService } from './otp.service';

const ROTATION_IN_PROGRESS = 'rotating';
const ACCESS_TOKEN_TTL_SECONDS = 15 * 60;
const REFRESH_TOKEN_TTL_DAYS = 60;

export interface JwtPayload {
  /** Rider id. */
  sub: string;
  phone: string;
  /** Rider status at issue time. Services re-check the database for anything that matters. */
  status: string;
}

export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
  expiresInSeconds: number;
}

@Injectable()
export class AuthService {
  private readonly logger = new Logger(AuthService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly otp: OtpService,
    private readonly jwt: JwtService,
  ) {}

  requestOtp(phone: string, ip: string | null, cameThroughProxy: boolean) {
    return this.otp.request(phone, ip, cameThroughProxy);
  }

  /** Verify and issue tokens. Creates the rider as DRAFT on first login. */
  async verifyOtpAndIssueTokens(phone: string, code: string): Promise<AuthTokens> {
    const ok = await this.otp.verify(phone, code);
    if (!ok) throw new UnauthorizedException('Incorrect verification code');

    const rider = await this.prisma.rider.upsert({
      where: { phone },
      update: { lastSeenAt: new Date() },
      create: { phone, lastSeenAt: new Date(), status: RiderStatus.DRAFT },
    });

    if (rider.status === RiderStatus.SUSPENDED) throw new UnauthorizedException('This account is suspended');
    if (rider.status === RiderStatus.LEFT) throw new UnauthorizedException('This account has left the platform');

    return this.issueTokens(rider.id, phone, rider.status);
  }

  /**
   * Rotate a refresh token. The claim is atomic: exactly one caller can move
   * the row out of the unrotated state; everyone else is treated as reuse and
   * every session for the rider is revoked.
   */
  async refresh(refreshToken: string): Promise<AuthTokens> {
    const tokenHash = hashToken(refreshToken);
    const stored = await this.prisma.refreshToken.findUnique({ where: { tokenHash }, include: { rider: true } });
    if (!stored) throw new UnauthorizedException('Invalid refresh token');
    if (stored.expiresAt < new Date()) throw new UnauthorizedException('Refresh token has expired');
    if (stored.rider.status === RiderStatus.SUSPENDED || stored.rider.status === RiderStatus.LEFT) {
      throw new UnauthorizedException('This account is not active');
    }

    const claim = await this.prisma.refreshToken.updateMany({
      where: { tokenHash, revokedAt: null, replacedByHash: null },
      data: { revokedAt: new Date(), replacedByHash: ROTATION_IN_PROGRESS },
    });
    if (claim.count === 0) {
      this.logger.error(`Refresh token reuse detected for rider ${stored.riderId}; revoking all sessions.`);
      await this.revokeAllForRider(stored.riderId);
      throw new UnauthorizedException('Refresh token has already been used');
    }

    const next = await this.issueTokens(stored.riderId, stored.rider.phone, stored.rider.status);
    await this.prisma.refreshToken.update({ where: { tokenHash }, data: { replacedByHash: hashToken(next.refreshToken) } });
    return next;
  }

  async logout(refreshToken: string): Promise<void> {
    await this.prisma.refreshToken.updateMany({
      where: { tokenHash: hashToken(refreshToken), revokedAt: null },
      data: { revokedAt: new Date() },
    });
  }

  async revokeAllForRider(riderId: string): Promise<void> {
    await this.prisma.refreshToken.updateMany({ where: { riderId, revokedAt: null }, data: { revokedAt: new Date() } });
  }

  private async issueTokens(riderId: string, phone: string, status: string): Promise<AuthTokens> {
    const payload: JwtPayload = { sub: riderId, phone, status };
    const accessToken = await this.jwt.signAsync(payload, { expiresIn: ACCESS_TOKEN_TTL_SECONDS });
    const refreshToken = newOpaqueToken();
    await this.prisma.refreshToken.create({
      data: { riderId, tokenHash: hashToken(refreshToken), expiresAt: new Date(Date.now() + REFRESH_TOKEN_TTL_DAYS * 86_400_000) },
    });
    return { accessToken, refreshToken, expiresInSeconds: ACCESS_TOKEN_TTL_SECONDS };
  }
}

/** SHA-256 is fine: 256 bits of CSPRNG output cannot be brute-forced. */
function hashToken(token: string): string {
  return createHash('sha256').update(token).digest('hex');
}
