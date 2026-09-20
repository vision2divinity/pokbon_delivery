import { Controller, Get } from '@nestjs/common';
import { Public } from './auth/auth.guard';
import { PrismaService } from './prisma/prisma.service';

@Controller('health')
export class HealthController {
  constructor(private readonly prisma: PrismaService) {}

  @Public()
  @Get()
  async health() {
    await this.prisma.$queryRaw`SELECT 1`;
    return { ok: true, service: 'pokbon-delivery-api', at: new Date().toISOString() };
  }
}
