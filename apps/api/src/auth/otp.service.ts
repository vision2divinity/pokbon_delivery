import { BadRequestException, HttpException, HttpStatus, Injectable, Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomBytes, randomInt, scrypt as scryptCb, timingSafeEqual } from 'node:crypto';
import { promisify } from 'node:util';
import { renderMessage } from '@pokbon-delivery/shared';
import { PluginClient } from '../plugin/plugin.client';
import { PrismaService } from '../prisma/prisma.service';
import { SettingsService } from '../settings/settings.service';

const scrypt = promisify(scryptCb) as (password: string, salt: string, keylen: number) => Promise<Buffer>;

const CODE_LENGTH = 6;
const CODE_TTL_SECONDS = 5 * 60;
const MAX_VERIFY_ATTEMPTS = 5;

/**
 * Rider login codes. Harvested from AutoRescue with one change: the SMS goes
 * out through the plugin (PRD § 1a), never from here.
 *
 * OTP endpoints are the classic SMS-pumping vector and every message costs
 * money, so the limits below are enforced per phone and per IP.
 */
@Injectable()
export class OtpService {
  private readonly logger = new Logger(OtpService.name);
  private readonly maxPerPhonePerHour: number;
  private readonly maxPerIpPerHour: number;
  private readonly minSecondsBetween: number;

  constructor(
    private readonly prisma: PrismaService,
    private readonly plugin: PluginClient,
    private readonly settings: SettingsService,
    config: ConfigService,
  ) {
    this.maxPerPhonePerHour = config.getOrThrow<number>('OTP_MAX_PER_PHONE_PER_HOUR');
    this.maxPerIpPerHour = config.getOrThrow<number>('OTP_MAX_PER_IP_PER_HOUR');
    this.minSecondsBetween = config.getOrThrow<number>('OTP_MIN_SECONDS_BETWEEN');
  }

  /**
   * Issue a code. The code is echoed back ONLY in console mode to a caller on
   * this machine or LAN that did not come through a proxy — never otherwise.
   */
  async request(phone: string, ip: string | null, cameThroughProxy = false): Promise<{ devCode?: string }> {
    await this.enforceRateLimits(phone, ip);

    const code = String(randomInt(0, 10 ** CODE_LENGTH)).padStart(CODE_LENGTH, '0');
    const codeHash = await this.hash(code, phone);

    await this.prisma.otpChallenge.updateMany({
      where: { phone, consumedAt: null, expiresAt: { gt: new Date() } },
      data: { consumedAt: new Date() },
    });

    await this.prisma.otpChallenge.create({
      data: { phone, codeHash, expiresAt: new Date(Date.now() + CODE_TTL_SECONDS * 1000), requestIp: ip },
    });

    try {
      /*
       * The wording comes from the plugin, not from here.
       *
       * This was the worst offender of the hardcoded messages: the one thing a
       * rider reads before they can use the app at all, written into the
       * delivery service where nobody running the business could reach it.
       * The literal below is the fallback for a service that has not synced
       * yet, not the source of truth.
       */
      const text = renderMessage(
        this.settings.get('messages'),
        'rider_otp',
        'Your POKBON Delivery code is {code}. It expires in {minutes} minutes. Never share it.',
        { code, minutes: Math.round(CODE_TTL_SECONDS / 60) },
      );
      if (text === '') {
        // Switched off in the plugin. Nothing else can sign a rider in, so
        // this is refused loudly rather than leaving them at a dead screen.
        throw new HttpException(
          'Rider sign-in messages are switched off in POKBON Delivery → Messages.',
          HttpStatus.SERVICE_UNAVAILABLE,
        );
      }
      await this.plugin.sendSms(phone, text, { purpose: 'rider_otp' });
    } catch (error) {
      this.logger.error(`OTP SMS to ${phone} failed: ${String(error)}`);
      throw new HttpException('Could not send the verification code. Please try again.', HttpStatus.SERVICE_UNAVAILABLE);
    }

    const local = (ip === null || isPrivateAddress(ip)) && !cameThroughProxy;
    return this.plugin.isConsole && local ? { devCode: code } : {};
  }

  /** Check a code and consume it. Single use; conditional consume wins races. */
  async verify(phone: string, code: string): Promise<boolean> {
    const challenge = await this.prisma.otpChallenge.findFirst({
      where: { phone, consumedAt: null },
      orderBy: { createdAt: 'desc' },
    });
    if (!challenge) throw new BadRequestException('No verification code was requested for this number');
    if (challenge.expiresAt < new Date()) throw new BadRequestException('That code has expired. Request a new one.');

    if (challenge.attempts >= MAX_VERIFY_ATTEMPTS) {
      await this.prisma.otpChallenge.update({ where: { id: challenge.id }, data: { consumedAt: new Date() } });
      throw new BadRequestException('Too many incorrect attempts. Request a new code.');
    }

    const candidate = await this.hash(code, phone);
    if (!safeEquals(candidate, challenge.codeHash)) {
      await this.prisma.otpChallenge.update({ where: { id: challenge.id }, data: { attempts: { increment: 1 } } });
      this.logger.warn(`Failed OTP attempt ${challenge.attempts + 1}/${MAX_VERIFY_ATTEMPTS} for ${phone}`);
      return false;
    }

    const consumed = await this.prisma.otpChallenge.updateMany({
      where: { id: challenge.id, consumedAt: null },
      data: { consumedAt: new Date() },
    });
    return consumed.count === 1;
  }

  private async enforceRateLimits(phone: string, ip: string | null): Promise<void> {
    const hourAgo = new Date(Date.now() - 3600_000);
    const recent = await this.prisma.otpChallenge.findMany({
      where: { phone, createdAt: { gte: hourAgo } },
      orderBy: { createdAt: 'desc' },
      take: this.maxPerPhonePerHour,
    });
    if (recent.length >= this.maxPerPhonePerHour) {
      throw new HttpException('Too many codes requested for this number. Try again later.', HttpStatus.TOO_MANY_REQUESTS);
    }
    const newest = recent[0];
    if (newest && Date.now() - newest.createdAt.getTime() < this.minSecondsBetween * 1000) {
      throw new HttpException(`Please wait ${this.minSecondsBetween} seconds before requesting another code.`, HttpStatus.TOO_MANY_REQUESTS);
    }
    if (ip) {
      const forIp = await this.prisma.otpChallenge.count({ where: { requestIp: ip, createdAt: { gte: hourAgo } } });
      if (forIp >= this.maxPerIpPerHour) {
        this.logger.warn(`IP ${ip} hit the hourly OTP limit`);
        throw new HttpException('Too many requests from this device. Try again later.', HttpStatus.TOO_MANY_REQUESTS);
      }
    }
  }

  /** scrypt, salted with the phone. A six-digit code needs a slow hash. */
  private async hash(code: string, phone: string): Promise<string> {
    return (await scrypt(code, `pokbon-delivery-otp:${phone}`, 32)).toString('hex');
  }
}

export function safeEquals(a: string, b: string): boolean {
  const bufA = Buffer.from(a, 'utf8');
  const bufB = Buffer.from(b, 'utf8');
  return bufA.length === bufB.length && timingSafeEqual(bufA, bufB);
}

export function newOpaqueToken(): string {
  return randomBytes(32).toString('base64url');
}

export function isPrivateAddress(ip: string): boolean {
  const v4 = ip.replace(/^::ffff:/, '');
  return (
    v4 === '127.0.0.1' ||
    v4 === '::1' ||
    v4.startsWith('10.') ||
    v4.startsWith('192.168.') ||
    /^172[.](1[6-9]|2[0-9]|3[01])[.]/.test(v4)
  );
}
