import {
  applyDecorators,
  CallHandler,
  CanActivate,
  ExecutionContext,
  Injectable,
  Logger,
  NestInterceptor,
  UnauthorizedException,
  UseGuards,
  UseInterceptors,
} from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import type { Request } from 'express';
import { Observable, from, of } from 'rxjs';
import { mergeMap, tap } from 'rxjs/operators';
import { Public } from '../auth/auth.guard';
import { PrismaService } from '../prisma/prisma.service';
import { verifySignedRequest } from './signature';

type SignedRequest = Request & { rawBody?: Buffer; pluginEventId?: string };

/**
 * Inbound calls from the WordPress plugin: signature, timestamp window, and
 * an event id. The guard checks the first two; the interceptor makes the call
 * idempotent on the third by storing and replaying the response.
 */
@Injectable()
export class PluginAuthGuard implements CanActivate {
  private readonly logger = new Logger(PluginAuthGuard.name);
  private readonly secret: string;

  constructor(config: ConfigService) {
    this.secret = config.getOrThrow<string>('PLUGIN_SHARED_SECRET');
  }

  canActivate(context: ExecutionContext): boolean {
    const req = context.switchToHttp().getRequest<SignedRequest>();
    const result = verifySignedRequest(
      this.secret,
      req.rawBody,
      req.headers as Record<string, string | string[] | undefined>,
    );
    if (!result.ok) {
      this.logger.warn(`Rejected plugin call ${req.method} ${req.path}: ${result.reason}`);
      throw new UnauthorizedException('Invalid service signature');
    }
    req.pluginEventId = result.eventId;
    return true;
  }
}

@Injectable()
export class PluginIdempotencyInterceptor implements NestInterceptor {
  constructor(private readonly prisma: PrismaService) {}

  intercept(context: ExecutionContext, next: CallHandler): Observable<unknown> {
    const req = context.switchToHttp().getRequest<SignedRequest>();
    const eventId = req.pluginEventId;
    // GETs are read-only; replaying them is harmless and storing them is noise.
    if (!eventId || req.method === 'GET') return next.handle();

    const route = `${req.method} ${req.route?.path ?? req.path}`;

    return from(this.prisma.inboundEvent.findUnique({ where: { eventId } })).pipe(
      mergeMap((seen) => {
        if (seen && seen.responseBody !== null) {
          return of(seen.responseBody);
        }
        return next.handle().pipe(
          tap((body) => {
            void this.prisma.inboundEvent
              .upsert({
                where: { eventId },
                create: { eventId, route, responseStatus: 200, responseBody: body as object },
                update: { responseStatus: 200, responseBody: body as object },
              })
              .catch(() => undefined);
          }),
        );
      }),
    );
  }
}

/** Routes the plugin calls. Skips rider JWT, requires the service signature, replays by event id. */
export function PluginAuth() {
  return applyDecorators(
    Public(),
    UseGuards(PluginAuthGuard),
    UseInterceptors(PluginIdempotencyInterceptor),
  );
}
