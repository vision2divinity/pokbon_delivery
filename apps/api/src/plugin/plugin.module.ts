import { Global, Module } from '@nestjs/common';
import { DevOutboxController } from './dev-outbox.controller';
import { PluginAuthGuard, PluginIdempotencyInterceptor } from './plugin-auth.guard';
import { PluginClient } from './plugin.client';

@Global()
@Module({
  providers: [PluginClient, PluginAuthGuard, PluginIdempotencyInterceptor],
  controllers: [DevOutboxController],
  exports: [PluginClient, PluginAuthGuard, PluginIdempotencyInterceptor],
})
export class PluginModule {}
