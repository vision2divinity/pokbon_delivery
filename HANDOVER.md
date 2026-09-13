# POKBON Delivery — start here

Written 2026-09-13, at the end of a very long session that was mostly about the marketplace, not this.
**Read this first in any new chat, then `docs/PRD-pokbon-delivery-2026-09-13.md`.**

## What this project is

A delivery dispatch network for POKBON: marketplace orders that need to reach a customer, and standalone
"move this for me" jobs. Riders use a dedicated app. Customers track on a map inside the POKBON Marketplace
app they already have, and by SMS if they have no app.

Status: **PRD draft 1 only. No code. Nothing decided.** Francis wants to review and improve the PRD before any
build.

## The one finding that should shape everything

**POKBON AutoRescue is already a dispatch platform** and the delivery product is the same machinery with a
different job payload. Verified by reading the code, not assumed:

- `C:\Users\franc\Downloads\POKBON AutoRescue\autorescue` — NestJS + Prisma monorepo, `apps/{api,mobile,web,ops}`
- Its dispatch controller already has duty toggle, location pings, offer/accept/decline with cascade, and a job
  lifecycle of en-route → arrive → estimate → complete → close, plus ops board, stats and CSV exports
- Phone OTP auth with per-phone and per-IP rate limiting
- MapLibre on mobile (`@maplibre/maplibre-react-native`) and web (`maplibre-gl`)
- Its own handoff, dated 2026-08-17, says 43 of 54 designed screens are built and running on real devices
- Screen 18 is an **arrival code, "the four digits that prove identity"** — exactly the delivery code concept

The PRD recommends extending AutoRescue with a `DELIVERY` job type rather than starting a second platform.
That recommendation is the first thing to accept or reject, because § 7 of the PRD depends on it.

## Where things live

| What | Path |
|---|---|
| This project | `C:\Users\POKBON Marketplace\pokbon-delivery` |
| AutoRescue (reuse candidate) | `C:\Users\franc\Downloads\POKBON AutoRescue\autorescue` |
| Marketplace app + WP plugin (one repo) | `C:\Users\franc\Downloads\pokbon_mobile_app` |
| Marketplace handover, ~1,700 lines of history | that repo's `HANDOFF.md` |
| Website services (buy-for-me, pay-for-me, local service) | `C:\Users\franc\OneDrive\Desktop\POKBON Marketplace\SERVICES` |

## Integration points that already exist

Do not rebuild these. Each was verified on 2026-09-13.

- **SMS**: Zenoph, credentials in the plugin's secret vault, `includes/class-sms.php`
- **Push + in-app inbox**: live in the marketplace app
- **Pickup points already carry latitude and longitude**: plugin admin page `pickup-points.php`
- **Services request flow with priced tiers**: app Services tab + `service-pricing.php` / `service-requests.php`
- **Shipping zones / delivery regions**: `class-shipping-gate.php`, `class-delivery-regions-endpoint.php`
- **Audit log**: every delivery status change should land here, same as everything else
- **Vendor commission and margin engine**: plugin 1.20.0 — it needs real delivery cost per order, which this
  project is what finally supplies

**Missing and needed**: customer address coordinates, and a routing engine. See PRD § 7b and § 9.

## Constraints carried over from the marketplace work

These are expensive lessons, not preferences.

1. **The marketplace app has no native map module.** It has `react-native-webview` and `expo-location`. The PRD
   deliberately renders the customer tracking map in a WebView so no native dependency enters an app that just
   shipped two bad releases in two days.
2. **Never run bare `npm audit fix` in the marketplace repo.** It nested the wrong Expo version under SDK 54.
3. **Check module format before any npm `overrides` entry.** An ESM-only pin of `decode-uri-component` broke
   every deep link carrying a query string for three releases. This is in memory as
   `feedback-security-overrides-module-format`.
4. **Plugin schema changes go in `class-migrations.php` as a numbered migration.** Zip uploads never fire
   activation. Changing a PHP default does nothing on an install that already stored that option.
5. **EasyWP caches admin pages.** A write that appears to have done nothing may have worked; re-read with a
   cache-busting request before concluding anything.
6. **PowerShell mangles UTF-8 in these repos.** Use Python with explicit utf-8 when scripting file edits, or
   `GH₵` ships as mojibake.

## Open decisions Francis has to make

From PRD § 13, repeated because they block design rather than build:

1. Riders as employees or contractors
2. Own rider network or partner with an existing courier
3. Which area to start in
4. Cash on delivery in phase 1, or prepaid only
5. Whether AutoRescue's providers would also take parcel jobs
6. What the last 100 deliveries actually cost, if that number exists anywhere

## Where the marketplace stood when this was written

Relevant only so a new chat does not trip over it:

- App **1.4.1 live** on Google Play; **1.4.2 in review**, carrying the deep-link fix
- Plugin **1.20.0 live**; affiliate bridge **off**; all 199 affiliates on one universal code
- One Dependabot alert open **on purpose**, documented in the marketplace `HANDOFF.md` § 1ae
- Product-page Share now emits a clean link; referral links only come from the affiliate dashboard
