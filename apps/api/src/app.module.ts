import { Module } from '@nestjs/common';
import { ConfigModule } from '@nestjs/config';
import { APP_GUARD } from '@nestjs/core';
import { AuthGuard } from './auth/auth.guard';
import { AuthModule } from './auth/auth.module';
import { validateEnv } from './config/env';
import { DispatchModule } from './dispatch/dispatch.module';
import { HealthController } from './health.controller';
import { HttpModule } from './http/http.module';
import { JobsModule } from './jobs/jobs.module';
import { OutboxModule } from './outbox/outbox.module';
import { PluginModule } from './plugin/plugin.module';
import { PricingModule } from './pricing/pricing.module';
import { PrismaModule } from './prisma/prisma.module';
import { RidersModule } from './riders/riders.module';
import { SettingsModule } from './settings/settings.module';
import { UploadsModule } from './uploads/uploads.module';

@Module({
  imports: [
    ConfigModule.forRoot({
      isGlobal: true,
      validate: validateEnv,
      envFilePath: ['../../.env', '.env'],
    }),
    PrismaModule,
    PluginModule,
    SettingsModule,
    PricingModule,
    UploadsModule,
    OutboxModule,
    AuthModule,
    RidersModule,
    JobsModule,
    DispatchModule,
    HttpModule,
  ],
  controllers: [HealthController],
  providers: [
    {
      // Protected by default; routes opt out with @Public() or @PluginAuth().
      provide: APP_GUARD,
      useClass: AuthGuard,
    },
  ],
})
export class AppModule {}
