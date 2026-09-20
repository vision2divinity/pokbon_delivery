import { ConflictException, Injectable, Logger } from '@nestjs/common';
import { Prisma } from '@prisma/client';
import { randomInt, scrypt as scryptCb } from 'node:crypto';
import { promisify } from 'node:util';
import { safeEquals } from '../auth/otp.service';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';

const scrypt = promisify(scryptCb) as (password: string, salt: string, keylen: number) => Promise<Buffer>;

export type VerifyOutcome = 'MATCHED' | 'WRONG' | 'EXPIRED' | 'LOCKED' | 'NONE';

/**
 * The delivery code. PRD § 7.
 *
 * Generated here, handed to the plugin to send to the buyer, compared here.
 * The plaintext leaves this service exactly once, to the caller that passes
 * it to the SMS, and is never stored or logged. The rider's app learns only
 * MATCHED or not.
 */
@Injectable()
export class CodesService {
  private readonly logger = new Logger(CodesService.name);

  constructor(
    private readonly prisma: PrismaService,
    private readonly settings: SettingsService,
  ) {}

  /**
   * Issue a fresh code for a job, invalidating any outstanding one. Returns
   * the plaintext for the caller to send. Enforces the per-job send limit.
   */
  async issue(jobId: string, sendsSoFar: number, tx: Prisma.TransactionClient | PrismaService = this.prisma): Promise<string> {
    const maxSends = this.settings.get('code_max_sends');
    if (sendsSoFar >= maxSends) {
      throw new ConflictException(`The code has already been sent ${maxSends} times. Ask the dispatcher to help.`);
    }

    const length = this.settings.get('code_length');
    const ttlMinutes = this.settings.get('code_expiry_minutes');
    const code = String(randomInt(0, 10 ** length)).padStart(length, '0');

    await tx.deliveryCode.updateMany({
      where: { jobId, verifiedAt: null, lockedAt: null },
      data: { lockedAt: new Date() },
    });
    await tx.deliveryCode.create({
      data: {
        jobId,
        codeHash: await this.hash(code, jobId),
        expiresAt: new Date(Date.now() + ttlMinutes * 60_000),
      },
    });
    return code;
  }

  /** Compare an attempt. Consumes the code on a match; locks it after too many misses. */
  async verify(jobId: string, attempt: string): Promise<VerifyOutcome> {
    const current = await this.prisma.deliveryCode.findFirst({
      where: { jobId, verifiedAt: null, lockedAt: null },
      orderBy: { createdAt: 'desc' },
    });
    if (!current) return 'NONE';
    if (current.expiresAt < new Date()) return 'EXPIRED';

    const maxAttempts = this.settings.get('code_max_attempts');
    if (current.attempts >= maxAttempts) {
      await this.prisma.deliveryCode.update({ where: { id: current.id }, data: { lockedAt: new Date() } });
      return 'LOCKED';
    }

    const candidate = await this.hash(attempt, jobId);
    if (!safeEquals(candidate, current.codeHash)) {
      const updated = await this.prisma.deliveryCode.update({
        where: { id: current.id },
        data: { attempts: { increment: 1 } },
      });
      this.logger.warn(`Wrong delivery code for job ${jobId}: attempt ${updated.attempts}/${maxAttempts}`);
      if (updated.attempts >= maxAttempts) {
        await this.prisma.deliveryCode.update({ where: { id: current.id }, data: { lockedAt: new Date() } });
        return 'LOCKED';
      }
      return 'WRONG';
    }

    const consumed = await this.prisma.deliveryCode.updateMany({
      where: { id: current.id, verifiedAt: null },
      data: { verifiedAt: new Date() },
    });
    return consumed.count === 1 ? 'MATCHED' : 'NONE';
  }

  private async hash(code: string, jobId: string): Promise<string> {
    return (await scrypt(code, `pokbon-delivery-code:${jobId}`, 32)).toString('hex');
  }
}
