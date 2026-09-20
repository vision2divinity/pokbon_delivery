import { Module } from '@nestjs/common';
import { AuthModule } from '../auth/auth.module';
import { RidersService } from './riders.service';

@Module({
  imports: [AuthModule],
  providers: [RidersService],
  exports: [RidersService],
})
export class RidersModule {}
