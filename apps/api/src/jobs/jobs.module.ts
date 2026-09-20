import { Module } from '@nestjs/common';
import { CodesService } from './codes.service';
import { JobsService } from './jobs.service';

@Module({
  providers: [JobsService, CodesService],
  exports: [JobsService, CodesService],
})
export class JobsModule {}
