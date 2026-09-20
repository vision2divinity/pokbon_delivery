import { z } from 'zod';

/**
 * Environment validation. Fails at boot rather than at the first request.
 */
const envSchema = z.object({
  NODE_ENV: z.enum(['development', 'test', 'production']).default('development'),
  PORT: z.coerce.number().int().positive().default(3001),

  DATABASE_URL: z.string().url(),

  JWT_SECRET: z.string().min(32, 'JWT_SECRET must be at least 32 characters'),

  PUBLIC_BASE_URL: z.string().url().default('http://localhost:3001'),

  TRUST_PROXY_HOPS: z.coerce.number().int().min(0).max(5).default(0),

  /**
   * The WordPress plugin is the only thing this API talks to for money, SMS,
   * push and settings (PRD § 1a). 'console' logs instead of calling, so the
   * whole lifecycle can be exercised with no WordPress running.
   */
  PLUGIN_MODE: z.enum(['console', 'live']).default('console'),
  PLUGIN_BASE_URL: z.string().url().default('http://localhost:8080/wp-json/pokbon/v1'),
  PLUGIN_SHARED_SECRET: z.string().min(32, 'PLUGIN_SHARED_SECRET must be at least 32 characters'),
  PLUGIN_SYNC_ON_BOOT: z
    .string()
    .default('false')
    .transform((v) => v === 'true' || v === '1'),

  OTP_MAX_PER_PHONE_PER_HOUR: z.coerce.number().int().positive().default(5),
  OTP_MAX_PER_IP_PER_HOUR: z.coerce.number().int().positive().default(20),
  OTP_MIN_SECONDS_BETWEEN: z.coerce.number().int().min(0).default(30),
});

export type Env = z.infer<typeof envSchema>;

export function validateEnv(raw: Record<string, unknown>): Env {
  const parsed = envSchema.safeParse(raw);
  if (!parsed.success) {
    const issues = parsed.error.issues
      .map((i) => `  - ${i.path.join('.')}: ${i.message}`)
      .join('\n');
    throw new Error(`Invalid environment configuration:\n${issues}`);
  }

  const env = parsed.data;

  if (env.NODE_ENV === 'production') {
    // Console mode in production means no SMS, no payment prompts, and every
    // delivery silently stuck at CODE_SENT while the process looks healthy.
    if (env.PLUGIN_MODE !== 'live') {
      throw new Error('PLUGIN_MODE must be "live" in production — console mode sends nothing.');
    }
    for (const [key, value] of [
      ['PUBLIC_BASE_URL', env.PUBLIC_BASE_URL],
      ['PLUGIN_BASE_URL', env.PLUGIN_BASE_URL],
    ] as const) {
      const host = new URL(value).hostname;
      const isPrivate =
        host === 'localhost' ||
        host === '127.0.0.1' ||
        /^10\./.test(host) ||
        /^192\.168\./.test(host) ||
        /^172\.(1[6-9]|2\d|3[01])\./.test(host);
      if (isPrivate) {
        throw new Error(`${key} is ${value} — a private address. Set a public https hostname.`);
      }
      if (!value.startsWith('https://')) {
        throw new Error(`${key} must be https in production.`);
      }
    }
    if (env.PLUGIN_SHARED_SECRET.startsWith('change-me')) {
      throw new Error('PLUGIN_SHARED_SECRET is still the placeholder.');
    }
    if (env.JWT_SECRET.startsWith('change-me')) {
      throw new Error('JWT_SECRET is still the placeholder.');
    }
  }

  return env;
}
