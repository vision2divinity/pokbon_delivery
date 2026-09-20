# POKBON Delivery — start here

Written 2026-09-13, rewritten 2026-09-20 when every owner decision closed and the build began.
**Read this first in any new chat, then `docs/PRD-pokbon-delivery-2026-09-20-draft3.md` (draft 3 — current, final
requirements).** Drafts 1 and 2 are kept for history only. Draft 1's central recommendation was wrong, see below.

## What this project is

A rider dispatch network for POKBON: marketplace orders that need to reach a buyer, and standalone "move this
for me" jobs. Riders use a dedicated app. Buyers track on a map inside the POKBON Marketplace app they already
have, and by SMS if they have no app. **No cash changes hands anywhere.**

Status: **PRD draft 3 is final. Build started 2026-09-20.** See "Where the build stands" at the bottom.

## The decisions, in one screen

All eight of draft 2's open questions were answered by Francis on 2026-09-20 (PRD § 0b):

1. **Riders are independent contractors** who can opt out at any time.
2. **Cashless pay-on-delivery, exactly this flow:** rider arrives → taps Send code → code goes to the buyer by SMS
   (and inbox for POKBON orders) → buyer reads it out → rider types it → **server verifies; rider never sees the
   code** → the moment it matches, the plugin pushes a MoMo payment prompt to the buyer → Paystack webhook → rider's
   screen says PAID → goods handed over → POKBON settles vendor share, rider fee, keeps commission and margin.
   Rider can re-send the prompt; same intent, never a second charge. Treat as fixing ~60% of COD now.
3. **Rider commission is zero for ~6 months from go-live, then phased in.** Schedule lives in plugin settings.
   Nothing about money, locations or timing is hardcoded.
4. **Failed delivery:** marketplace order → goods back to vendor, rider gets a configurable uplift on their next
   delivery. Standalone → goods back to sender, return fee is between rider and sender, POKBON stays out.
5. **Buyer or requester pays the delivery fee; the fee is the rider's.** POKBON's margin is the markup.
6. **Coverage:** Madina, Circle, Ashaiman, Lapaz, Kumasi, Santasi (pokbongroup.com/local-delivery). 06:00–22:00.
7. **Buy-for-me and pay-for-me stay distinct services.**
8. **Zone-to-zone price matrix Francis edits in the plugin.** Each pair: rider fee and buyer price. Example
   Madina→Circle rider 30, buyer 40, POKBON 10. **Rider never sees the buyer price, enforced in the API.**

## Architecture (PRD § 1a)

| Part | Where in this repo | Stack | Owns |
|---|---|---|---|
| Delivery API | `apps/api` | NestJS 10 + Prisma 5 + Postgres | riders, duty, jobs, offers, the code, live position, event log |
| Delivery app | `apps/mobile` | Expo / React Native | rider mode, requester mode |
| Admin plugin | `plugin/pokbon-delivery` | WordPress PHP | **all settings, all payments (Paystack), all messages (Zenoph, push, inbox)**, rider approval, live board |

The API never touches money and never sends a message itself. It asks the plugin. The plugin is the source of
truth for settings; it pushes them to the API on save and the API pulls on boot. The marketplace-side contract is
`C:\Users\franc\Downloads\pokbon_mobile_app\docs\DELIVERY_INTEGRATION_2026-09-20.md` (already revised for the
cashless flow, § 4b). Build against it rather than inventing shapes.

## The correction that matters most

Draft 1 recommended adding a `DELIVERY` job type to POKBON AutoRescue. **That was wrong.** AutoRescue's providers
are mechanics and tow operators; delivery providers are couriers. **Harvest, do not merge.** From
`C:\Users\franc\Downloads\POKBON AutoRescue\autorescue` take: phone OTP auth (`apps/api/src/auth`), the offer
cascade and 5-second sweep (`apps/api/src/dispatch`), the job/event/offer/location Prisma shapes, the SMS
provider interface, the global auth guard, the MapLibre setup and the Expo layout. Share no database, deployment
or rider identity.

## Where things live

| What | Path |
|---|---|
| This project | `C:\Users\POKBON Marketplace\pokbon-delivery` |
| AutoRescue (harvest source) | `C:\Users\franc\Downloads\POKBON AutoRescue\autorescue` |
| Marketplace app + WP plugin (one repo) | `C:\Users\franc\Downloads\pokbon_mobile_app` |
| Marketplace handover, ~1,700 lines of history | that repo's `HANDOFF.md` |
| Existing region shipping rates plugin | `C:\Users\franc\OneDrive\Desktop\POKBON Marketplace\pokbon-checkout` (option `pokbon_checkout_region_rates`) |
| Website services (buy-for-me, pay-for-me, local service) | `C:\Users\franc\OneDrive\Desktop\POKBON Marketplace\SERVICES` |

## Integration points that already exist — do not rebuild

- **SMS**: Zenoph, credentials in the plugin's secret vault, `includes/class-sms.php`
- **Push + in-app inbox**: live in the marketplace app
- **Paystack**: `class-payments-endpoint.php` uses *initialize transaction* + `charge.success` webhook. The
  doorstep prompt needs the *charge* API with a mobile-money payload — new code, same webhook.
- **Drop-off coordinates, GhanaPost GPS, landmark note**: captured at checkout since plugin 1.20.1
- **`_pokbon_paid_on_delivery`**: plugin 1.20.2 reclassifies a COD order as digitally settled when set
- **Pickup points carry latitude and longitude**: `admin/pages/pickup-points.php`
- **Audit log, numbered migrations, `ok_private()`, step-up confirm**: marketplace plugin conventions to copy

## Constraints carried over from the marketplace work

Expensive lessons, not preferences.

1. **The marketplace app has no native map module.** Render the buyer tracking map in a WebView.
2. **Never run bare `npm audit fix` in the marketplace repo.**
3. **Check module format before any npm `overrides` entry.** Memory: `feedback-security-overrides-module-format`.
4. **Plugin schema changes go in `class-migrations.php` as a numbered migration.** Zip uploads never activate.
5. **EasyWP caches admin pages.** Re-read with a cache-busting request before concluding a write did nothing.
6. **PowerShell mangles UTF-8 in these repos.** Use Python or the Write tool for file edits, or `GH₵` ships as mojibake.
7. **This machine's port 5432 and 5433 are taken by native Postgres.** AutoRescue uses 5434 in Docker; this
   project uses **5435** so both can run.

## Where the build stands

Updated as work lands. Newest first.

- **2026-09-20** — PRD draft 3 final. Monorepo scaffold and Delivery API begun in `apps/api`: Prisma schema, job
  state machine, OTP auth harvested from AutoRescue, rider endpoints, plugin contract endpoints. See
  `apps/api/README.md` for what runs and how.
