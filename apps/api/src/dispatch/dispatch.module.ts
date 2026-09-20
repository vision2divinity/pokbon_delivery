import { Module } from '@nestjs/common';
import { JobsModule } from '../jobs/jobs.module';
import { DispatchScheduler } from './dispatch.scheduler';
import { DispatchService } from './dispatch.service';

@Module({
  imports: [JobsModule],
  providers: [DispatchService, DispatchScheduler],
  exports: [DispatchService],
})
export class DispatchModule {}
