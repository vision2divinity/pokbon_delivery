import { Logger } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { NestFactory } from '@nestjs/core';
import type { NestExpressApplication } from '@nestjs/platform-express';
import { join } from 'node:path';
import { AppModule } from './app.module';

// BigInt ids (job_events) must serialise; as strings, so nothing is lost.
(BigInt.prototype as unknown as { toJSON: () => string }).toJSON = function () {
  return this.toString();
};

async function bootstrap(): Promise<void> {
  const app = await NestFactory.create<NestExpressApplication>(AppModule, {
    /**
     * The plugin signs the RAW request bytes (HMAC-SHA256). Once Express has
     * parsed and we re-stringify, key order and whitespace change and the
     * signature never matches. rawBody keeps the original buffer.
     */
    rawBody: true,
  });

  // Validation is explicit zod in each handler, shared with the app through
  // @pokbon-delivery/shared. No class-validator pipe.

  // Photos arrive as base64 JSON. 12 MB gives headroom over the 6 MB decoded
  // limit in UploadsService so the service produces the actionable error, not
  // Express's generic 413. Via useBodyParser so rawBody capture survives.
  app.useBodyParser('json', { limit: '12mb' });
  app.useBodyParser('urlencoded', { limit: '12mb', extended: true });

  app.enableShutdownHooks();

  const config = app.get(ConfigService);
  const port = config.getOrThrow<number>('PORT');

  const trustProxyHops = config.getOrThrow<number>('TRUST_PROXY_HOPS');
  if (trustProxyHops > 0) {
    app.set('trust proxy', trustProxyHops);
  }

  // Local photo store in development. Deployments put photos in object storage.
  app.useStaticAssets(join(process.cwd(), '.uploads'), { prefix: '/uploads/' });

  // 0.0.0.0 so a phone on the same Wi-Fi can reach a dev build.
  await app.listen(port, '0.0.0.0');

  const logger = new Logger('Bootstrap');
  logger.log(`POKBON Delivery API listening on http://localhost:${port}`);
  logger.log(`Environment: ${config.getOrThrow<string>('NODE_ENV')}`);
  logger.log(
    `Plugin mode: ${config.getOrThrow<string>('PLUGIN_MODE')}` +
      (config.getOrThrow<string>('PLUGIN_MODE') === 'console'
        ? ' — nothing is sent; see GET /dev/outbox'
        : ` → ${config.getOrThrow<string>('PLUGIN_BASE_URL')}`),
  );
}

void bootstrap();
