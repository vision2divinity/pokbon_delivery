import { Module } from '@nestjs/common';
import { DispatchModule } from '../dispatch/dispatch.module';
import { JobsModule } from '../jobs/jobs.module';
import { RidersModule } from '../riders/riders.module';
import { PluginJobsController } from './plugin-jobs.controller';
import { PluginRidersController } from './plugin-riders.controller';
import { RiderJobsController } from './rider-jobs.controller';
import { RiderMeController } from './rider-me.controller';

/** All HTTP surface except auth, health, settings sync and the dev outbox. */
@Module({
  imports: [JobsModule, DispatchModule, RidersModule],
  controllers: [RiderMeController, RiderJobsController, PluginJobsController, PluginRidersController],
})
export class HttpModule {}
