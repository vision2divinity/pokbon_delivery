import { BadRequestException, Body, Controller, HttpCode, HttpStatus, Post, Req } from '@nestjs/common';
import type { Request } from 'express';
import { refreshSchema, requestOtpSchema, verifyOtpSchema } from '@pokbon-delivery/shared';
import { AuthService, AuthTokens } from './auth.service';
import { Public } from './auth.guard';

@Controller('auth')
export class AuthController {
  constructor(private readonly auth: AuthService) {}

  @Public()
  @Post('otp/request')
  @HttpCode(HttpStatus.OK)
  async requestOtp(@Body() body: unknown, @Req() req: Request) {
    const parsed = requestOtpSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    const result = await this.auth.requestOtp(
      parsed.data.phone,
      clientIp(req),
      Boolean(req.headers['x-forwarded-for']),
    );
    return { sent: true, ...result };
  }

  @Public()
  @Post('otp/verify')
  @HttpCode(HttpStatus.OK)
  async verifyOtp(@Body() body: unknown): Promise<AuthTokens> {
    const parsed = verifyOtpSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.auth.verifyOtpAndIssueTokens(parsed.data.phone, parsed.data.code);
  }

  @Public()
  @Post('refresh')
  @HttpCode(HttpStatus.OK)
  async refresh(@Body() body: unknown): Promise<AuthTokens> {
    const parsed = refreshSchema.safeParse(body);
    if (!parsed.success) throw new BadRequestException(parsed.error.issues.map((i) => i.message));
    return this.auth.refresh(parsed.data.refreshToken);
  }

  @Public()
  @Post('logout')
  @HttpCode(HttpStatus.NO_CONTENT)
  async logout(@Body() body: unknown): Promise<void> {
    const parsed = refreshSchema.safeParse(body);
    if (!parsed.success) return;
    await this.auth.logout(parsed.data.refreshToken);
  }
}

/** `req.ip` only: Express resolves it through the trust-proxy setting. */
function clientIp(req: Request): string | null {
  return req.ip ?? req.socket.remoteAddress ?? null;
}
