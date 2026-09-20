import {
  CanActivate,
  createParamDecorator,
  ExecutionContext,
  Injectable,
  SetMetadata,
  UnauthorizedException,
} from '@nestjs/common';
import { Reflector } from '@nestjs/core';
import { JwtService } from '@nestjs/jwt';
import type { Request } from 'express';
import type { JwtPayload } from './auth.service';

export const IS_PUBLIC = 'isPublic';

/** Opt a route out of rider authentication. Everything else requires a bearer token. */
export const Public = () => SetMetadata(IS_PUBLIC, true);

export type AuthedRequest = Request & { rider?: JwtPayload };

/** Inject the authenticated rider, or a single field of it. */
export const CurrentRider = createParamDecorator((field: keyof JwtPayload | undefined, ctx: ExecutionContext) => {
  const request = ctx.switchToHttp().getRequest<AuthedRequest>();
  if (!request.rider) return undefined;
  return field ? request.rider[field] : request.rider;
});

/**
 * Registered globally, so routes are protected by DEFAULT and opt out with
 * @Public(). A forgotten decorator then fails closed, which is the right
 * failure mode for a system holding location data and delivery codes.
 */
@Injectable()
export class AuthGuard implements CanActivate {
  constructor(
    private readonly jwt: JwtService,
    private readonly reflector: Reflector,
  ) {}

  async canActivate(context: ExecutionContext): Promise<boolean> {
    const isPublic = this.reflector.getAllAndOverride<boolean>(IS_PUBLIC, [context.getHandler(), context.getClass()]);
    if (isPublic) return true;

    const request = context.switchToHttp().getRequest<AuthedRequest>();
    const token = extractBearer(request);
    if (!token) throw new UnauthorizedException('Missing bearer token');

    try {
      request.rider = await this.jwt.verifyAsync<JwtPayload>(token);
    } catch {
      throw new UnauthorizedException('Invalid or expired token');
    }
    return true;
  }
}

function extractBearer(request: Request): string | null {
  const header = request.headers.authorization;
  if (!header) return null;
  const [scheme, value] = header.split(' ');
  if (scheme?.toLowerCase() !== 'bearer' || !value) return null;
  return value.trim();
}
