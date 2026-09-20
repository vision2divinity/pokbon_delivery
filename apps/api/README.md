# POKBON Delivery API

NestJS 10 + Prisma 5 + Postgres. Owns riders, duty, jobs, offers, the delivery code, live position and the
event log. **Never touches money and never sends a message**: it asks the WordPress plugin (PRD § 1a).

## Run it locally

From the repo root:

```bash
cp .env.example .env
npm install
npm run db:up
npm run db:push
npm run dev
```

Then, in another terminal, push the launch zones and a starter matrix the way the plugin will, and run the
phase 0 smoke test:

```bash
npx dotenv -e .env -- node scripts/seed-dev.mjs
npx dotenv -e .env -- node scripts/smoke-phase0.mjs
```

`PLUGIN_MODE=console` (the default in `.env.example`) logs every call to the plugin instead of making it and
keeps the last 200 at `GET /dev/outbox`. That is where the smoke test reads the buyer's code from, because the
SMS body is the only place it ever appears. The rider endpoints never return it, and the smoke test asserts
that they do not.

## Surface

| Who | Routes | Auth |
|---|---|---|
| Rider | `POST /auth/otp/request`, `/auth/otp/verify`, `/auth/refresh`, `/auth/logout` | none |
| Rider | `GET/PATCH /rider/me`, `POST /rider/me/agreement`, `/rider/me/apply`, `PUT /rider/me/duty`, `POST /rider/me/location`, `GET /rider/me/earnings`, `POST /rider/me/leave` | bearer |
| Rider | `GET /rider/jobs/offers`, `POST /rider/jobs/offers/:id/accept|decline`, `GET /rider/jobs/active|history|:id` | bearer |
| Rider | `POST /rider/jobs/:id/at-pickup|picked-up|en-route|arrived|send-code|verify-code|prompt-again|pay-by-link|delivered|failed|returned` | bearer |
| Plugin | `POST /jobs`, `GET /quote`, `POST /jobs/:id/cancel|payment|assign|offer|bypass-code`, `GET /jobs`, `GET /jobs/:id`, `GET /jobs/:id/tracking`, `GET /orders/:orderId/tracking` | service signature |
| Plugin | `GET /plugin/riders`, `GET /plugin/riders/:id`, `POST /plugin/riders/:id/decision` | service signature |
| Plugin | `POST /plugin/settings/sync`, `GET /plugin/settings` | service signature |
| Anyone | `GET /health`; `GET /dev/outbox` (console mode only) | none |

Service signature: `X-Pokbon-Delivery-Signature` (HMAC-SHA256 of the raw body, hex),
`X-Pokbon-Delivery-Timestamp` (unix seconds, ±5 min), `X-Pokbon-Delivery-Event-Id` (UUID; a replay returns the
stored answer). `scripts/lib/plugin-sign.mjs` shows the client side.

## What the API asks the plugin for

All under `PLUGIN_BASE_URL` (`…/wp-json/pokbon/v1`), signed the same way:

| Call | When |
|---|---|
| `POST /delivery/messages/sms` | rider OTP, "rider on the way", the delivery code |
| `POST /delivery/messages/inbox` | the code, for POKBON orders, into the buyer's in-app inbox |
| `POST /delivery/payment/prompt` | the moment the code matches on a pay-on-delivery job; and on "Send prompt again" |
| `GET /delivery/payment/{intentId}` | when the payment window closes, before giving up |
| `POST /delivery/payment/{intentId}/link` | pay-by-link to another phone |
| `POST /delivery/callback` | every status change (contract § 5), from the outbox with retries |
| `GET /delivery/settings` | on boot when `PLUGIN_SYNC_ON_BOOT=true` |

The plugin, in turn, calls `POST /jobs/:id/payment` here when Paystack's webhook lands.

## Invariants the code enforces

- `DELIVERED` is reachable only from `PAID`, or from `CODE_VERIFIED` when the job is prepaid.
  `packages/shared/src/lifecycle.ts` is the table; `JobsService.transition` is the only writer.
- The delivery code is generated server-side, hashed with scrypt, sent to the buyer through the plugin, and
  compared server-side. No rider-facing payload or log line carries it.
- Rider-facing serialisers have no field for `buyerPriceMinor` or `amountDueMinor` (`jobs/serialisers.ts`).
- One payment intent per job. "Send prompt again" re-uses it. The plugin keys on `jobId`.
- Every status change writes a `job_events` row and an `outbound_events` row in the same transaction.
- Nothing tunable is a constant: `packages/shared/src/settings.ts` lists every key and its launch default; the
  plugin's sync overrides them.

## Layout

```
src/
  auth/        rider OTP login, JWT, refresh rotation (harvested from AutoRescue)
  plugin/      HMAC signing, outbound client (console|live), inbound guard + idempotency, dev outbox
  settings/    the settings cache and sync endpoint
  pricing/     zone resolution and the matrix quote
  riders/      profile, application, duty, location, earnings, admin decisions
  jobs/        the state machine, the delivery code, serialisers
  dispatch/    offer cascade and the sweeps
  outbox/      at-least-once delivery of callbacks to the plugin
  uploads/     photos (local disk in dev)
  http/        controllers
prisma/schema.prisma
```
