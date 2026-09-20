import { Controller, Get, NotFoundException } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { Public } from '../auth/auth.guard';
import { PluginClient } from './plugin.client';

/**
 * What the API would have sent to the plugin, in console mode only.
 *
 * This is how a developer reads the delivery code during local testing — it is
 * in the SMS body that would have gone to the buyer. It does not exist when
 * PLUGIN_MODE=live or NODE_ENV=production: the guard in env.ts refuses console
 * mode in production, and this controller 404s outside console mode.
 */
@Controller('dev')
export class DevOutboxController {
  private readonly enabled: boolean;

  constructor(
    private readonly plugin: PluginClient,
    config: ConfigService,
  ) {
    this.enabled =
      config.getOrThrow<string>('PLUGIN_MODE') === 'console' &&
      config.getOrThrow<string>('NODE_ENV') !== 'production';
  }

  @Public()
  @Get('outbox')
  outbox() {
    if (!this.enabled) throw new NotFoundException();
    return { mode: 'console', entries: this.plugin.recentOutbox() };
  }
}
