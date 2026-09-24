# POKBON Delivery — state of play

Last updated: **2026-09-24, end of a long live-testing day.**

Read this first if you are picking the project up cold, or resuming after a
break. It is the state of the work, not a design document — the design lives in
`docs/PRD-pokbon-delivery-2026-09-20-draft3.md`.

---

## If you are starting here, read this bit

**Shipped today:** Delivery `0.5.32`, Checkout `1.3.2`, Mobile App `1.21.5`.
All three are installed on the live site. Everything is committed and pushed.

**What was happening.** Francis was running live multi-vendor tests against
real orders (87715, 87716, 87717) and each test turned up something. Those are
all fixed and written up under **Closed**, newest first. He had just asked to
start a fresh session because this one had grown long.

**The five things most worth knowing before you touch anything:**

1. **`is_paid()` is a trap and it has caught four separate pieces of code.**
   WooCommerce counts `processing` as paid, and a cash-on-delivery order is
   `processing` from the moment it is placed — which is the event that creates
   the delivery jobs. **Use `get_date_paid()`.** It is set only when money was
   actually captured.
2. **The API never touches money and never sends a message.** When something
   needs a decision about either, it belongs in the plugin. This is why
   `cod_split()` and the doorstep charge live in PHP.
3. **A fallback must announce itself.** Every serious bug in this project has
   been a sensible default producing a plausible wrong answer: a zone centre
   used as a doorstep, a default collection point standing in for a vendor, a
   list price recorded as revenue. Whenever you add a fallback, make the system
   say it took one — `pinned`, `alreadyCredited`, the pickup `source`.
4. **One bad row can freeze everything.** The settings push is all-or-nothing,
   so a single malformed zone stops every zone, price and setting reaching the
   riders. `Settings::sync_blockers()` now catches that class before it asks.
5. **The rider is never shown the delivery code or the amount due.** Both are
   structural — `toRiderJobView()` simply does not read those fields.

**Where the code lives** is the next section but one, and it matters: two
WordPress plugins now live in this repository.

---

## Live state, sampled 2026-09-24 23:24 UTC

Re-check before trusting any of it.

| | |
|---|---|
| API | up on `:3001`, reachable through the tunnel (both `/health` 200) |
| Metro | up on `:8081` |
| Postgres | up, `pokbon-delivery-postgres`, host port 5435 |
| Jobs | 34 — 12 DELIVERED, 9 RETURNED, **10 UNFULFILLED**, 2 CANCELLED, 1 ASSIGNED |
| Outbox | **0 pending**, clean. 5 rows retired as refused (the guest-inbox poison) |
| Riders | 2 approved. Test Rider on duty, position 2 min old, based OLDASHOGMANESTATESTATION |

**Two things in that table need reading properly.**

The **10 UNFULFILLED** jobs are real and mostly from today's tests: orders
87712, 87714, 87715, 87716 and 87717 each have legs from MADINA and BOLGA that
nobody was in range for. That is not a bug — it is the dispatch radius doing
its job — but it is the state the new alarm exists to shout about, and those
orders are genuinely part-delivered. **Clear them before the next test run** or
every sweep will keep retrying them and the board will be unreadable.

The seeded **Kofi Test Rider** has a position four days old. Harmless while off
duty, but it makes the roster read as two riders when there is at most one.

**Before any dispatch test:** open the rider app and let it report a position.
`location_stale_seconds` is **300**, and a stale rider is silently ineligible —
dispatch says "nobody eligible" while the board shows somebody on duty.

---

## What this is

A rider dispatch network for POKBON Marketplace in Ghana. Three parts:

| Part | Lives in | What it owns |
|---|---|---|
| Delivery API | `apps/api` (NestJS, Prisma, Postgres) | Riders, jobs, offers, the delivery code, the job lifecycle |
| WordPress plugin | `plugin/pokbon-delivery` | **Settings, money and messages.** The source of truth |
| Rider app | `apps/mobile` (Expo, expo-router) | What a rider sees and does |
| Shared | `packages/shared` | Zod schemas, the lifecycle table, the pricing ladder, geo |

The architecture rule that explains most decisions: **the API never touches
money and never sends a message.** It reports what happened; the plugin decides
whether anyone is told, who, and in what words. This is so business changes do
not require an app release.

The rider app asks the plugin what to be on launch (`/delivery/app-config`):
theme, copy, feature switches and rules all come from the backend.

---

## Proven working, end to end, on real hardware

A complete delivery ran on 2026-09-21 with a real order, a real phone and real
money (order #87619):

```
CREATED → OFFERED → ASSIGNED → AT_PICKUP → PICKED_UP → EN_ROUTE
       → ARRIVED → CODE_SENT → CODE_VERIFIED → PAYMENT_PENDING → PAID → DELIVERED
```

with earnings recorded: fee GH¢40, failed-trip uplift GH¢16 released from two
earlier returned jobs, commission −GH¢4, rider balance GH¢52.

Guarantees confirmed on the device, not just in code:

- The rider never sees the buyer price or the amount due. This is structural —
  `toRiderJobView` has no such fields — not a hidden UI element.
- The hand-over control is **absent**, not disabled, until the money is in.
- The delivery code goes only to the number on the order and is never
  redirected. The payment link may be redirected, and that is recorded.

---

## Where the code lives

Three repositories, and one of them holds two plugins.

| What | Path | Remote |
|---|---|---|
| Delivery API, rider app, shared package | `pokbon-delivery/` | `vision2divinity/pokbon_delivery` |
| POKBON Delivery (WordPress) | `pokbon-delivery/plugin/pokbon-delivery/` | same repo |
| **POKBON Checkout (WordPress)** | `pokbon-delivery/plugin/pokbon-checkout/` | same repo |
| POKBON Mobile App + its WP plugin | `~/Downloads/pokbon_mobile_app/` | `vision2divinity/POKBON_Mobile_App` |

**POKBON Checkout moved in on 2026-09-24.** It had lived in its own repo with
no remote, so three commits had never left one laptop. It belongs beside
POKBON Delivery because the two are ends of one mechanism — checkout asks
`pokbon_delivery_coverage_required` and `pokbon_delivery_zone_areas`, delivery
answers them, `pokbon_checkout_order_created` carries the area back — and the
coverage gate needed an edit in both on the same afternoon.

**The old `pokbon-checkout` directory beside this repo is now a stale copy.**
It is byte-identical as of the move and must not be edited again. Delete it
when you are confident, but do not work in it.

---

## Running it

Everything is local. Four things must be up.

```bash
# 1. Postgres (Docker), host port 5435
docker start pokbon-delivery-postgres

# 2. The API on 3001
cd apps/api && node dist/main.js        # after: npx nest build

# 3. The tunnel, so pokbongroup.com can reach the API
cloudflared --config ops/cloudflared.yml tunnel run
bash ops/tunnel-watchdog.sh             # restarts it when it silently stops serving

# 4. Metro, for the rider app
cd apps/mobile && npx expo start --dev-client --port 8081
adb devices                              # note the id, e.g. 3f5d119a
adb -s <id> reverse tcp:8081 tcp:8081    # -s IS needed; see "Things that will mislead you"
```

Already running? They are background processes and survive a closed terminal:

```bash
curl -s -o /dev/null -w "API %{http_code}
"    http://127.0.0.1:3001/health
curl -s -o /dev/null -w "tunnel %{http_code}
" https://delivery-dev.pokbongroup.com/health
curl -s -o /dev/null -w "metro %{http_code}
"  http://127.0.0.1:8081/status
```

The rider app is a **development build** installed on the device
(`com.pokbongroup.delivery`), not Expo Go — Expo Go on that phone is SDK 57 and
the project is SDK 54.

Shipping a plugin change: bump `Version:` in the plugin's main file **and the
matching `define( ..._VERSION )` right below it** — they have drifted apart
twice, and in POKBON Checkout that constant is the cache-buster on
`checkout.js`, so forgetting it means returning buyers keep the old script.
Then run the checks, zip the plugin directory, and Francis uploads it.

The API needs a rebuild and restart whenever `packages/shared` or
`apps/api` changes, and `npm run db:push` as well when `schema.prisma` does.
**Stop the API first** — a running Node process holds the Prisma DLL and
`generate` fails with EPERM.

### The checks, all of which must pass

```bash
node scripts/check-plugin-forms.mjs      # no nested forms; every button reaches a handler
node scripts/check-plugin-php-traps.mjs  # PHP idioms that have each cost a live bug
node scripts/check-price-ladder.mjs      # the three-rung ladder
node scripts/check-price-parity.mjs      # PHP and TypeScript price identically
node scripts/check-momo-prefixes.mjs     # both copies of the network table agree
node scripts/check-sms-text.mjs          # both copies of the GSM folder agree
node scripts/check-message-templates.mjs # both copies fill a template the same
node scripts/check-checkout-zones.mjs    # the website and the app quote the same price
node scripts/check-fee-split.mjs         # a delivery fee split between legs still adds up
node scripts/check-uplift.mjs            # the failed-trip uplift cannot be printed in a loop
node scripts/check-cod-split.mjs         # the doors add up to the order, and never to more

php wp-plugin/tests/delivery-coverage.test.php   # in the pokbon_mobile_app repo
```

Each of these exists because something it now catches reached production.

---

## Known open items

**One list, reconciled 2026-09-23.** Two lists had been running in parallel —
the "shelved / three larger ones" list and an ad-hoc one from the 22nd — and
they overlapped. Everything below is merged, deduplicated and ordered. Finished
work is at the bottom under *Closed*, with what proves it, so nobody re-opens
it. If you add something, add it here rather than starting a third list.

Priority order is what Francis agreed on the 23rd: what makes the system lie to
somebody comes before what makes it inconvenient.

### What to pick up first, 2026-09-25

Nothing below is blocked. In the order I would take them:

1. **Clear the 10 UNFULFILLED jobs** from yesterday's tests (orders 87712,
   87714, 87715, 87716, 87717). They are real, they retry hourly for 24 hours,
   and they make the job board unreadable. Decide each: assign by hand, or
   cancel the leg and refund. Three of those orders are PREPAID.
2. **Item 1, position reporting.** Still the most damaging open item, and it
   bit the testing twice yesterday — a rider on duty with a stale fix is
   silently ineligible and dispatch reports "nobody eligible".
3. **Finish the multi-vendor test run** Francis was part-way through. The last
   thing changed was one-prompt-per-rider (0.5.32), which has not yet been
   exercised against a real order where one rider holds several legs.
4. Then the ordered list below, starting at item 7 (the arrival guard).

**Not yet started, agreed for later:** item 15 (rider profile management, the
11-item list) and item 16 (the Postman collection, explicitly end-of-project).

---

### 1. Position reporting stops, and nothing says so

The single most damaging open item: a rider who is working, available, and
silently receiving nothing.

Two faults, probably one root cause:

- **Never re-armed after a restart.** `startDutyLocation()` is called from
  exactly one place — the duty toggle in `apps/mobile/app/rider.tsx`. On launch,
  `dutyLocationRunning()` only *displays* a flag. So a rider whose app is killed
  (crash, reboot, or the OS reclaiming memory) comes back with the server saying
  `onDuty: true`, the card showing **On duty**, and nothing reporting.
- **Stops when the screen goes off.** Observed 2026-09-22: one fix at 11:05:18
  and nothing for the following five minutes, foreground service running and the
  notification showing. Later the same evening it reported once on a duty toggle
  and stopped again **with the app in the foreground**.

The device is an **OPPO CPH2711, ColorOS, Android 16**, and the app is **not on
the Doze whitelist** — the prime suspect, and it would explain both faults at
once: ColorOS kills the app, the foreground service dies with it, and nothing
ever restarts it.

Still to rule out: battery optimisation for `com.pokbongroup.delivery`;
`Accuracy.Balanced` resolving to a network provider Doze suspends, where `High`
would not; and Android *batching* fixes for delivery on unlock, which would show
as a burst of pings the instant the screen comes on.

Whatever the cause, the duty card must stop claiming "On duty" when nothing is
being reported. A confident lie is worse than the outage.

### 2. Tracking follows the duty switch, not the parcel

Going off duty calls `stopDutyLocation()` even when the rider is carrying a job,
so the customer's tracking goes dark mid-journey. Reporting should continue
while any job is active, regardless of duty.

Agreed design (2026-09-23): off duty means **"send me no new work"**, never
"abandon the parcel you are holding". A rider mid-delivery may go off duty, but
the app should say so plainly — *"You still have a delivery in hand"* — the
dispatcher should see "off duty, carrying job X", and position must keep
flowing until the parcel is handed over.

### 3. The customer's order sits on "Processing" until the very end

`apply_status()` in `class-orders.php` writes an order **note** for every job
status and only changes the WooCommerce **status** at completion. The mobile
app's timeline is driven by order status, so it shows *Processing* through
pickup and transit and then jumps to *Completed*. Observed on #87692:
unchanged through several manual refreshes, the first movement arriving only at
the arrival/OTP step, which was an `inbox.send` notification and not a status
change at all.

The statuses already exist in the marketplace plugin — `ready-to-ship`,
`in-transit`, `delivered` (`class-vendor-order-statuses.php`). Nothing is
missing but the mapping.

### 4. "Could not deliver" has no reason and no evidence

The rider app offers **Could not deliver** with nowhere to say why and no way to
attach a photo — while the copy tells riders to document damage. A rider who
genuinely cannot complete a delivery is either stuck in the flow or forced to
lie about it.

Wants: a reason picker, an optional photo, and both sent to the admin portal for
review. `job_photos` exists in the schema and the upload path exists; the
failure reason is carried on the status callback already (`failureReason`).

### 5. The admin portal cannot close, complete or abort a live delivery

When a rider reports they cannot finish, there is no way for a dispatcher to
resolve it. Needed alongside #4, and needed for #6.

### 6. Customer cancellation after a rider has collected

Design agreed 2026-09-23, not yet built. The principle: **cancelling an order
never cancels a delivery that is already carrying goods — it converts it into a
return.** Where a parcel physically is cannot be undone by a status change.

| When | What happens | Rider paid |
|---|---|---|
| Before a job exists / before assignment | cancel outright | nothing owed |
| Assigned, not yet collected | job → `CANCELLED`, rider told at once | call-out fee |
| Collected, in transit | job → **return leg**, parcel goes back to the vendor | return leg |

After `processing` a customer cancellation is a **request**, not an action — the
app already says "Request cancellation" — landing in the admin portal for a
human, because only a human knows whether the rider is at the door or two
streets from the vendor. `RETURNED` and the failed-trip uplift already exist;
what is missing is the instruction reaching the rider's screen.

**Note a side effect of the COD fix (Mobile App 1.21.2):** app COD orders used
to sit at `pending` for ever and were therefore always self-cancellable in one
tap. They now go to `processing` immediately, so that button becomes a request.
Believed correct — an order with a rider en route should not vanish on a tap —
but it is a customer-facing change that arrived as a side effect and should be
agreed rather than discovered.

### 7. No arrival guard

A rider can go from assigned to "at your door" in seconds, sending a real
customer an SMS, a delivery code and a payment prompt while the rider is still
at the shop. Measured on #87684: **94 seconds** assigned → arrived, and **28
seconds** picked-up → arrived, with the rider telling us at that moment they
were still heading to the customer.

The arrival event already carries the rider's GPS, so a distance check is
straightforward. A time floor alone would punish the honest short delivery.
Allow an override with a recorded reason, for bad GPS.

### 8. ~~A stale rider is offered work anyway~~ — DECIDED, working as intended

**Closed 2026-09-23 by Francis's decision, not by code.** The home-station
bypass stays and the radius stays at 8 km, dropping to about 5 km once there
are riders in more places. His reasoning: he does not want riders making long
journeys just to collect a parcel and come back.

So a rider's `base_zone_code` is deliberately a radius exemption for their own
station, and the radius governs everywhere else. Proven on order #87712: the
rider was **14.78 km** from the Ashongman pickup — well outside 8 km — and was
offered those two jobs only because their base zone matched. The third job,
collecting from Madina, was never offered to anyone, which is the intended
consequence.

Worth knowing rather than worth changing: an offer that records
`distanceMetres` equal to `offer_radius_metres` exactly (8000) means the
position was too stale to measure, not that the rider was 8 km away.

### 8b. The original text, kept because the mechanism still matters

In `dispatch.service.ts` `pickCandidate()`, a rider whose last position is older
than `location_stale_seconds` gets `distance = null` but stays eligible when
`inZone` is true — their `base_zone_code` equals the job's pickup zone. The
offer then records `distanceMetres` as the full `offer_radius_metres`.

So `base_zone_code` is not a preference, it is a **staleness override**: it lets
dispatch offer a job to a rider it cannot locate at all. That is why #87684 was
accepted by a rider whose position was nine minutes old against a five-minute
threshold, and why an offer showing exactly **8000 m means "position unknown"**,
not "8 km away".

Decide deliberately whether that override is wanted. Either way, an offer made
on the fallback should say so on both screens rather than reporting a distance
it invented.

### 9. Rider home location, in the admin

Francis asked (2026-09-22) for a rider to be assignable to a location they work
from, easily changed when they move: *"sometimes riders move and might want to
work from where they are."*

**Half built already** — `riders.base_zone_code` is in the schema, set on the
live test rider, and referenced in `admin/pages/riders.php`. Finish and expose
it rather than designing it fresh. Settle #8 first, since that field is what
currently overrides staleness.

### 10. Pricing rows: delete / disable / enable

Half built. `Pokbon_Delivery_Settings::clear_price()` and `clear_band_price()`
exist and work; **nothing in `admin/pages/matrix.php` calls them** — zero
delete, disable or enable controls on the page. A wrong row can be overwritten
but never removed, and a route cannot be made to fall through to the next rung.
The work left is UI, not storage.

### 11. Notification channels

Status updates should be in-app, with real SMS reserved for arrival and the
payment approval. `inbox.send` exists but fires at one moment only and can
address only a marketplace buyer. Related to #3 but distinct: #3 is the order's
own status, this is who gets told and over which channel.

### 12. The tunnel is a dev-only stopgap

It dropped twice in one session with `cloudflared` still running, and it dies
whenever this machine's session ends. `ops/tunnel-watchdog.sh` is a plaster.
The API wants a VPS.

### 13. The rider app build, and the marketplace app build

The rider app is a dev build driven by Metro; JS changes need only a reload, and
a rebuild only when something native moves. The marketplace app's zone-pricing
release is handled in a separate session — see
`docs/DELIVERY_ZONE_PRICING_2026-09-22.md` in `POKBON_Mobile_App`. Do not
duplicate that document here.

### 14. ~~A job nobody could take is never retried~~ — done 2026-09-23

`offerNext()` was only ever called on an event: a job created, an offer
expiring, a dispatcher pressing a button. Nothing asked again on its own, so a
job created while every rider was off duty stayed at CREATED for ever, and one
whose cascade found nobody stayed at UNFULFILLED for ever.

A `retryStranded()` sweep now runs every 60s over CREATED and UNFULFILLED jobs
with no live offer, bounded to 24 hours. Safe on a timer because `offerNext()`
already refuses to act twice — it no-ops on a live offer, never re-offers to a
rider who declined, and respects the cascade depth. So it asks again; it does
not ask harder.

Bounded by age on purpose: a job nobody has taken in a day is waiting for a
human, and retrying it for ever would hide that rather than surface it.

### 15. Rider profile management — agreed 2026-09-24, not scheduled

To be built after the loophole backlog. All of it managed from the plugin.

**Done so far, 2026-09-24 (Delivery 0.5.26):** item 7 in part — a Details form
on the rider's screen edits name, base zone, vehicle, registration, licence,
MoMo number and next of kin without touching their status, and their onboarding
documents were already listed there. Items 1-6 and 8-10 remain.

1. Average delivery speed.
2. Job acceptance speed — how quickly they answer an offer.
3. Orders and requests they have had.
4. Requests they declined.
5. Requests they accepted.
6. Total earnings.
7. Full profile, settings and account management.
8. Graphs, wherever they help a rider see how they are doing.
9. Share and invite other riders.
10. **Extra vehicles and sub-riders.** A rider may own more than one bike, car
    or truck with colleagues riding them, and wants to onboard those people:
    they submit the vehicle and the driver's details, POKBON is alerted, the
    owner approves from the dashboard, and the new person signs in with their
    own number.
11. Whatever else comes up.

**Most of 1–6 is already recorded and simply unreachable.** Jobs, offers (with
`offeredAt` and `respondedAt`, so acceptance speed is a subtraction) and
`RiderEarning` rows all exist. `rider.earnings()` and `jobs.history()` are in
the app's API client with **zero call sites**, and `GET /rider/jobs/history` is
already served. Do 6 and 3–5 first: nearly free, and what a rider actually asks
about.

Item 10 is the only one needing new modelling — a vehicle belongs to a rider, a
sub-rider belongs to a vehicle, approval belongs to the owner.

This is retention work. An unexplained balance and an unexplained refusal are
the two commonest reasons a contractor stops turning up, and they tell other
riders why.

### 16. A Postman collection for every endpoint — agreed 2026-09-24, not scheduled

Francis wants one JSON Postman collection covering every API and REST endpoint
in the system, to keep for reference and to integrate other systems against.
To be built at the end of the current development, not during it.

**Three surfaces, and they are not one API:**

| Surface | Base | Auth |
|---|---|---|
| Delivery API — rider app | `:3001` (tunnel `delivery-dev.pokbongroup.com`) | rider JWT from phone OTP |
| Delivery API — admin/plugin | same | HMAC-SHA256 shared secret |
| Plugin REST — API calls back | `pokbongroup.com/wp-json/pokbon/v1` | the same HMAC, other direction |
| App plugin REST | `pokbongroup.com/wp-json/pokbon/v1` | WP nonce / logged-in cookie |

**Why it is not a five-minute export.** The signed routes are the point of the
whole contract and the hard part to get right: every plugin call carries a
timestamp, an event id and an HMAC over the body, and a collection that cannot
reproduce that signature documents only the half of the system anybody could
have guessed. The collection needs a pre-request script that signs, with the
secret in a Postman environment variable and NEVER in the collection file.

**How to apply:** generate from the route definitions rather than by hand —
`apps/api/src/**/*.controller.ts` and `class-rest.php` are the two registries,
and a collection typed out by hand goes stale the first time a route moves.
**What already exists, checked 2026-09-24:** `pokbon_mobile_app/postman/`
holds `POKBON.postman_collection.json` — **114 requests** across Auth, 2FA,
Affiliate, WC Proxy, Content, Vendors, Vendor (Self), Courses, Services,
Promotions, Reviews & Q&A and Push, with an environment file beside it. So the
marketplace half is largely done and the work is the DELIVERY half plus the
signed plugin routes — extend that collection rather than starting a second
one, or the two will disagree within a month.

---

### Unresolved, needs one fact

- **An order that reached the job board but never appeared in the rider app.**
  Reported 2026-09-22. If it was #87683 it is explained — that job was created
  before the auto-offer fix and never offered. If it was a later one, it is
  something not yet seen. **Ask Francis which order number.**
- **Plugin duplicates that return after deletion.** Three copies each of POKBON
  Delivery and POKBON Mobile App appear in the plugin list, and deleting one
  brings it back on refresh. WordPress does not behave that way on its own:
  suspect an object cache serving a stale list, or the host restoring files.
  Parked at Francis's request. **Never delete a POKBON Mobile App copy** — its
  `uninstall.php` drops tables, and a duplicate carries the same code and the
  same table names. POKBON Delivery has no `uninstall.php` and is safe to delete.

### Smaller, noted in passing

- Order notes in `class-orders.php` are still hardcoded. Admin-facing, low value.
- Two WPCode snippets (#24794, #40567) still send email and SMS inline during
  checkout, adding seconds per order. They live in the site database, so they
  cannot be changed from source.
- `pokbon-affiliate` spends ~14s on `woocommerce_new_order` and writes its
  "no affiliate" order note twice, being hooked to both `woocommerce_new_order`
  and `woocommerce_thankyou` without setting its processed guard.
- `apps/mobile/android/` is committed prebuild output. Harmless, could be
  gitignored.
- **`pokbon-checkout` lives at `C:\Users\POKBON Marketplace\pokbon-checkout`** —
  a sibling of this repo, v1.1.0, carrying all three filter call sites. It is
  **not under version control**, and neither are several plugins beside it. No
  history, no way to see what changed or undo a bad edit.

---

### Closed

Kept with their evidence, so none of this gets re-investigated.

- **Config cached in SecureStore** — `apps/mobile/lib/config.ts` picks a cache
  in a ladder: a file via `expo-file-system` when linked, the keystore under
  `KEYSTORE_SAFE_BYTES`, otherwise none. Required lazily, because importing an
  unlinked module throws at load.
- **Message text hardcoded** — a Messages page (`admin/pages/messages.php`),
  `renderMessage()` in the API, `Settings::message()` in the plugin, held
  together by `scripts/check-message-templates.mjs`.
- **Reconciliation screen** — `admin/pages/reconciliation.php`.
- **Order status never returned to the marketplace on completion** —
  `close_marketplace_order()` + `auto_completion_status()`, target status
  configurable. (Completion only; the *journey* is item #3.)
- **The fee charged is not the fee the job is priced at** — closed on both
  platforms. The job records what was actually charged; checkout prices from the
  matrix. Web proven on #87675 (Charged ₵50 / Matrix ₵50 / Rider ₵40 / Kept ₵10).
  App shipped in Mobile App 1.21.0 + Delivery 0.5.6.
- **Auto-created jobs were never offered** (was "C") — fixed in Delivery 0.5.7.
  `offer_new_jobs()` offers as jobs are created, on both the automatic path and
  the "Send to riders now" button, which had been promising riders and
  delivering silence. Proven on #87690: created → offered in **749 ms**,
  accepted 45 s later, and the offer carried a real **457 m**, not the fallback.
- **App COD orders never dispatched** (was "A") — fixed in Mobile App 1.21.2.
  App orders are clamped to `pending` by design, and COD has no later payment to
  move them on, so they were invisible to everything waiting on `processing`.
  Now mirrors `WC_Gateway_COD`, last in `create_order()` so the fee is re-priced
  and the area stamped first. Proven end to end on **#87692**, placed from the
  app, cash on delivery, run to Completed with nothing pushed by hand.
- **One rider at one door was three mobile-money prompts** — Delivery 0.5.32.
  A rider who collected from three vendors for the same buyer arrives once, and
  per-leg charging put three prompts in a row in front of a customer who ordered
  once. The legs in ONE rider's hands are summed into a single charge and
  settled together against one reference; a leg another rider holds is a
  separate journey and is never included. The API says which legs are in the
  same hands (`alsoJobIds`) and stops there — it never handles money. Each leg
  still passes through `chargeable_minor()`, so the cap at "what the order still
  owes" holds however the legs are grouped. When one rider has every leg the
  combined charge equals the order total, so `_pokbon_paystack_reference` is
  written and the marketplace webhook reconciles it as it always did.
- **`is_paid()` counts `processing` as paid — a FOURTH time** — Delivery 0.5.31.
  The first unfulfilled alarm ever sent told the owner in capital letters that a
  cash-on-delivery customer HAD ALREADY PAID. A COD order is moved to
  `processing` the moment it is placed, which is the event that creates the
  jobs, so `is_paid()` answers true for exactly the orders where nothing has
  been collected. `is_pay_on_delivery()` warns about this in a comment a
  thousand lines below; `class-payments.php` warns twice more, one of them
  saying the lesson was "learned, written down, and then repeated three hundred
  lines away". **Use `get_date_paid()`. Never `is_paid()`.** The rider's job
  screen also now opens with the COLLECTION POINT rather than the customer,
  because that is where the rider goes first.
- **The landmark had no question of its own** — Delivery 0.5.30, Checkout 1.3.2,
  Mobile App 1.21.5. "Apartment, suite, landmark (optional)" asked three things
  in one box, and whatever was typed went into address line 2 and was
  concatenated into the address string. It is its own field now on both
  checkouts, carried as `dropoff.landmark` the whole way, and `navigationUrl()`
  searches it SECOND — after GhanaPostGPS, before the address — whenever an
  order has no pin. That ordering is the point: house numbers are sparse here
  and "opposite Melcom, Sowutuom" is a place a maps app knows. Orders placed
  before it fall back to address line 2; the rider's screen suppresses the
  Landmark line when those words are already in the address.
- **A leg with no rider told nobody** — Delivery 0.5.29. Order 87715 had three
  vendors, one leg reached a rider, and the buyer had PREPAID for all three.
  `apply_status()` returns early for any status not in `ORDER_STATUS_SETTINGS`,
  and `unfulfilled` is not in it — so the only trace was a row on the job board.
  An unfulfilled leg now raises SMS + email (the pair a payout request uses),
  writes an order note, and prints a red block on the order screen. It names the
  VENDOR, not the job id. The callback carries `vendorId` and `pickupZoneCode`
  for that reason.
- **`"0"` is a truthy string, and five messages retried for three days** —
  Delivery 0.5.28. A guest checkout is customer id 0; the plugin sent the string
  `"0"`; `if (job.buyerUserId)` passed. Every guest order that reached a doorstep
  queued an inbox message for user zero, which the plugin refused with 400, and
  the outbox retried it for ever at the ten-minute backoff cap. **The outbox now
  treats a 4xx as a refusal and lets it go** (408 and 429 excepted; 5xx still
  retries) — retrying is for what gets better on its own, and a 400 is the
  plugin saying the message is wrong.
- **One zone with a lost decimal froze the entire configuration** — Delivery
  0.5.27. `save_zone` did `(float) $_POST['lng']` with no range check, so
  `-0.1651` typed as `-1651` saved silently. One bad zone rejects the WHOLE
  settings push — every zone, price, band and setting — so riders went on being
  dispatched from the previous copy while new zones and changed radii did
  nothing. The form bounds both coordinates, the handler refuses an impossible
  centre in words, and `push()` checks first and names the ZONE rather than
  letting the API answer with `zones.7.lng`.
- **The app took orders the website refused** — Mobile App 1.21.4. The coverage
  gate was consulted only by `pokbon-checkout`, and the area requirement was
  enforced only on the app's client. An older app build sends no
  `delivery_zone` at all, and `validate_delivery_zone()` turns anything
  unrecognised into `''`, which every caller reads as "price it the old way" —
  so those orders took the flat regional rate while the rider was paid from the
  matrix. Both are now enforced in `create_order()` and reported by
  `validate_cart`. **`region_key_for()` matters:** the app sends the region's
  NAME and the delivery plugin is asked by CODE, so matching raw would have
  refused every honest order with the gate on. `wp-plugin/tests/delivery-coverage.test.php`
  covers it, and is mostly about the ways it must NOT refuse.
  **Rollout note: raise `minVersion` alongside this** or old installs stick at
  Confirm Order.

- **A vendor can be given a zone, and it overrides their dashboard** — Delivery
  0.5.26. Collection points demanded a pin, then a phone, then a zone the pin
  happened to fall inside, and refused the save if any were missing — so the
  screen built to stop vendors falling back to the default collection point
  would not let you rescue the vendors who most needed it. Every field is an
  override now: fill in what you know, leave the rest blank, and what is blank
  falls through to the vendor's own WCFM profile field by field. Setting only a
  zone keeps their pin for navigation; setting only a pin keeps their phone.
  **A zone on its own is enough to price and dispatch** — the zone centre stands
  in for coordinates and the job carries `pickup.pinned = false`, so the rider's
  app searches for the shop by name instead of riding into the middle of a
  suburb. A missing phone no longer throws the collection point away; it falls
  through to POKBON's own number, because a rider who has to ring the office is
  a far better outcome than a rider sent to the wrong business over a blank
  field. `pickupPinned` is new on the job, mirroring `dropoffPinned`.
- **The rider had no way to navigate to the collection point** — Delivery
  0.5.26 / app. Every job begins by getting to a shop the rider has usually
  never seen, and the screen offered one line of text to retype into another app
  while sitting on a bike. Call the shop and Navigate now sit under "Collect
  from", and `navigationUrl()` takes either end — searching the shop's name and
  address when the pickup is not pinned, exactly as the drop-off already did.
- **A rider's details could be typed once and never again** — Delivery 0.5.26.
  Base zone, vehicle, registration, licence, MoMo number and next of kin were
  settable only on the add form, so a rider who moved across town went on being
  offered work in the zone they left. There is a Details form on the rider's own
  screen now. It deliberately does not touch their status: `add_rider` defaults
  status to APPROVED and leaves DRAFT when a box is unticked, so reusing that
  path to fix a registration plate would have approved a rider still waiting on
  their documents, or demoted an approved one, as a side effect of a typo. The
  upsert schema takes `status: UNCHANGED` for exactly this.
  Their onboarding documents (selfie, ID, licence) were already on that screen.
- **Three riders, three prompts, one order** — closed in Delivery 0.5.25.
  `codAmount` was `$order->get_total()` written to every job, and the doorstep
  charge in `class-payments.php` raised a Paystack prompt for the whole order
  whichever leg the rider was on. On a one-vendor order that is exactly right,
  and every hand-tested order had one vendor. On a three-vendor order the first
  rider to arrive collected the entire amount before the buyer had seen the
  other two parcels, and two riders arriving together could both pass the
  "already paid?" guard and both charge in full.
  The doorstep payment is now per leg, which is what the buyer experiences: one
  prompt per parcel, for that vendor's goods plus that leg's delivery.
  `Orders::cod_split()` divides the order total by goods-plus-delivery and the
  legs come to exactly the total (`scripts/check-cod-split.mjs`). Each leg
  carries its own intent (`pkbd_<order>_<n>`), its own Paystack attempts with
  the amount stored beside each reference, its own prompt allowance and its own
  settled record; `chargeable_minor()` caps every charge at what the order still
  owes, so no sequence of retries or re-dispatches can take more than the total.
  The order is marked paid when the legs add up. The API needed no change — it
  already stored `paymentIntentId` per job; only this plugin assumed one payment
  per order. A vendor whose pickup could not be resolved gets no leg and is
  never collected for, so the buyer is not asked at the door to pay for a parcel
  nobody fetched. The order panel now shows what has been collected at which
  door, because between the first rider and the last an order is genuinely
  part-paid and nothing could previously say so.
- **The rider was sent to the wrong shop** — closed in Delivery 0.5.23.
  A vendor's address exists in three places (billing details, what they typed at
  registration, a profile somebody filled in for them) and *none of them holds
  coordinates*, so a vendor who never dropped a pin in the WCFM store manager
  fell through to POKBON's own default collection point. The rider was then sent,
  confidently, to the wrong business — with a real address and a real phone
  number belonging to somebody else. **Admin → POKBON Delivery → Collection
  points** now lists every vendor with a published product, says in words which
  rung is answering for them today (your pin / their own WCFM pin / a configured
  pickup point / **your default — wrong shop**), and takes a Google Maps pin,
  address and phone per vendor. Stored in `OPT_VENDOR_PICKUPS`, served through
  the pre-existing `pokbon_delivery_vendor_pickup` filter by
  `Orders::configured_pickup()`, which sits **above** the vendor's own pin on
  purpose: a person here decided, and a field a vendor never touched did not.
  The pin parser takes `5.6689, -0.1651` or a whole Maps URL, and rejects `0,0`.
  This replaces logging into a vendor's account to set it for them.
- **Refusing a delivery and quoting a price for it at the same time** — closed in
  Checkout 1.3.1. With the coverage gate on, an unserved region showed "We do not
  deliver to this area yet" above a shipping line reading GH¢100.00 and a button
  reading "· GH¢101.00". A buyer believes the number. `compute()` now blanks the
  line ("Not available") and the totals when `refusing` is set. The same edit
  moved the gate out of `if (noCoverageEl)`: a template missing that one `<p>`
  would have taken the order at the flat rate with nothing to show for it.
- **Buttons wired to nothing** — `scripts/check-plugin-forms.mjs` now also
  asserts that every `form_open()` / `button()` action has a matching `case` in
  `class-admin.php`. This had happened three times; most recently the Payouts
  screen shipped with **"I have paid this" wired to a case that did not exist**,
  on the one screen where doing nothing means a rider is not paid. 25 controls,
  25 handlers, checked on every run.

- **Keyboard covering the rider's inputs; content under the navigation bar** —
  `KeyboardAvoidingView` and `SafeAreaView edges={['top','bottom']}` in
  `apps/mobile/components/ui.tsx`. Not separately confirmed on the device, but
  several flows have been driven through it since.

---

## Things that will mislead you

- **`is_paid()` says a cash-on-delivery order is paid.** WooCommerce counts
  `processing` as a paid status, and a COD order is moved to `processing` the
  moment it is placed — the same event that creates the delivery jobs. So it
  answers true for exactly the orders where no money has been taken. It has
  caught four separate pieces of this codebase. **`get_date_paid()`, always.**
- **"Unable to load script" on the rider app is almost never Metro.** It is
  `adb reverse` having been dropped — that mapping lives in the adb daemon, not
  in Metro, and it disappears when the cable is unplugged, the phone locks and
  re-handshakes USB, or adb restarts. The fix is one command, and **it needs
  `-s <device>`**: plain `adb reverse tcp:8081 tcp:8081` intermittently answers
  "no devices/emulators found" on this machine even with the phone listed.
  ```
  adb devices                                   # get the id
  adb -s <id> reverse tcp:8081 tcp:8081
  adb -s <id> shell 'curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/status'
  ```
- **The services keep running when the terminal is gone.** They are background
  processes, not terminal jobs. Check with `netstat -ano | grep -E ":3001|:8081"`
  and `tasklist | grep cloudflared` before concluding anything is down.
- **The zone radius and the offer radius are different settings.** A zone's
  radius decides which zone a pin belongs to. `offer_radius_metres` (default
  8000) decides which riders are offered a job. Widening a zone does not widen
  dispatch, and this has already caused one "why did that leg not reach the
  rider" conversation. The home-station bypass is why a rider 14 km away still
  got their own zone's job.
- **`php -l` will not save you from everything.** A stray word left in a
  comment position parsed cleanly as a **goto label** and lint passed. Read the
  diff.

- **`HTTP 200` from the SMS gateway does not mean delivered.** Zenoph answers
  200 for rejections. The Mobile App plugin now reads the handshake; if you see
  "sent" without a handshake quoted, you are on an old version.
- **Every plugin REST route answers 409 on error**, deliberately — 5xx is
  swallowed by the host's error page. So the status code carries no
  information; read the message.
- **Paystack's charge reply decides what happens next.** `pay_offline` means
  the customer approves on their handset; `send_otp` means Paystack texted them
  a code and expects it back through the API — no prompt appears in the MoMo
  menu. Both are recorded on the job now. `send_otp` completes fine *if* the
  customer has the checkout link to type the code into, which is why the link
  is sent alongside.
- **Paystack's Callback URL and Webhook URL are different settings.** The
  dashboard had both set to the webhook address, which is POST-only, so paying
  customers were redirected to a JSON 404. Our links now carry their own
  `callback_url`.
- **Keys are currently Paystack TEST keys** (switched 2026-09-22) so tests do
  not move real money. Note that a direct mobile-money charge to a real Ghana
  number was refused outright in test mode with `HTTP 400: Charge attempted`,
  having worked on live keys the day before. Test mode appears to want
  Paystack's own test numbers. The plugin now falls back to a checkout link
  when a charge cannot be created, so a delivery is still payable either way.

---

## Test accounts and numbers

Francis's own, used deliberately so every message lands on a phone he holds.

| Thing | Value |
|---|---|
| Vendor | ctech4gh@gmail.com — C-Tech Security Consult |
| Rider | 0556780200, record named "Test Rider", APPROVED |
| Customer | 0530463017 |
| Third number | 0208998956 |
| Order email | testfrancis6@gmail.com |
| Test product | `/product/cute-travel-bag/` — "Test Item", GH¢1, explicitly not fulfilled |
| Default pickup zone | `OLDASHOGMANESTATESTATION` ("POKBON Shop"), his actual shop |

---

## Settings worth knowing

Held in the plugin, synced to the API.

| Key | Value | Note |
|---|---|---|
| `offer_timeout_seconds` | 45 | Recommended 120. Forty-five seconds is tight for someone riding |
| `offer_cascade_depth` | 5 | Counts riders, not attempts |
| `offer_radius_metres` | 8000 | |
| `location_stale_seconds` | 300 | The app reports every 60s while on duty |
| `rider_commission_schedule` | 10% from 2026-09-21 | Francis's original decision was **zero for ~6 months**; check this is intended |
