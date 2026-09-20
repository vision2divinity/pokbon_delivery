import { BadRequestException, Injectable } from '@nestjs/common';
import { ConfigService } from '@nestjs/config';
import { randomUUID } from 'node:crypto';
import { mkdir, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

/** Decoded bytes. Base64 inflates by a third, so the JSON parser allows 12 MB. */
const MAX_BYTES = 6 * 1024 * 1024;

const EXT: Record<string, string> = {
  'image/jpeg': 'jpg',
  'image/png': 'png',
  'image/webp': 'webp',
};

/**
 * Photos at pickup, delivery and failure. PRD § 7.
 *
 * Local disk under .uploads/ in development, served by main.ts. A deployment
 * swaps this for object storage; the interface (bytes in, URL out) stays.
 */
@Injectable()
export class UploadsService {
  private readonly root = join(process.cwd(), '.uploads');
  private readonly publicBase: string;

  constructor(config: ConfigService) {
    this.publicBase = config.getOrThrow<string>('PUBLIC_BASE_URL').replace(/\/$/, '');
  }

  async storeBase64(
    folder: string,
    contentType: string,
    base64: string,
  ): Promise<{ url: string; bytes: number; contentType: string }> {
    const ext = EXT[contentType];
    if (!ext) throw new BadRequestException(`Unsupported photo type ${contentType}`);

    const buffer = Buffer.from(base64.replace(/^data:[^;]+;base64,/, ''), 'base64');
    if (buffer.length === 0) throw new BadRequestException('Photo is empty');
    if (buffer.length > MAX_BYTES) {
      throw new BadRequestException(
        `That photo is ${(buffer.length / 1024 / 1024).toFixed(1)} MB, over the 6 MB limit. Reduce the capture quality.`,
      );
    }

    const dir = join(this.root, safe(folder));
    await mkdir(dir, { recursive: true });
    const name = `${randomUUID()}.${ext}`;
    await writeFile(join(dir, name), buffer);

    return { url: `${this.publicBase}/uploads/${safe(folder)}/${name}`, bytes: buffer.length, contentType };
  }
}

function safe(segment: string): string {
  return segment.replace(/[^a-zA-Z0-9_-]/g, '');
}
