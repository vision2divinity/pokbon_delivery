# POKBON Delivery — state of play

Last updated: 2026-09-22 (mobile app zone pricing)

Read this first if you are picking the project up cold, or resuming after a
break. It is the state of the work, not a design document — the design lives in
`docs/PRD-pokbon-delivery-2026-09-20-draft3.md`.

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
adb reverse tcp:8081 tcp:8081
```

The rider app is a **development build** installed on the device
(`com.pokbongroup.delivery`), not Expo Go — Expo Go on that phone is SDK 57 and
the project is SDK 54.

Shipping a plugin change: bump `Version:` in `plugin/pokbon-delivery/pokbon-delivery.php`,
add a changelog entry in the same header, run the checks below, zip the
`pokbon-delivery` directory, and Francis uploads it.

### The checks, all of which must pass

```bash
node scripts/check-plugin-forms.mjs      # no nested or unclosed admin forms
node scripts/check-plugin-php-traps.mjs  # PHP idioms that have each cost a live bug
node scripts/check-price-ladder.mjs      # the three-rung ladder
node scripts/check-price-parity.mjs      # PHP and TypeScript price identically
node scripts/check-momo-prefixes.mjs     # both copies of the network table agree
node scripts/check-sms-text.mjs          # both copies of the GSM folder agree
node scripts/check-message-templates.mjs # both copies fill a template the same
node scripts/check-checkout-zones.mjs    # the website and the app quote the same price
```

Each of these exists because something it now catches reached production.

---

## Known open items

Ordered as agreed with Francis on 2026-09-22: the shelved work first, then the
three larger items.

### Shelved, to clear first

1. **The app caches its config in SecureStore.** Logs
   `Value being stored in SecureStore is larger than 2048 bytes`. A future Expo
   SDK makes that throw, and then the app fails to start rather than falling
   back. Nothing in the config is secret; it belongs in ordinary storage.
2. **Message text is hardcoded.** The rider's sign-in code text is in the API
   (`apps/api/src/auth/otp.service.ts`), the pay-by-link text is in
   `class-payments.php`, buyer notes are in `class-orders.php`. Against the
   backend-first rule. Wants a **Messages** screen in the plugin listing every
   message, its variables and an on/off switch, pushed to the API by the
   existing settings sync.
3. **Reconciliation screen.** See `docs/` and the note below on the fee
   mismatch, which changes what this screen has to show.
4. **Pricing rows: delete / disable / enable** for zone-pair routes and
   distance bands. Disabling should fall through to the next rung.
5. **Order status is never returned to the marketplace on completion.** A
   POKBON order still reads "ongoing" in the mobile app after the rider has
   handed over. Needs the completion callback to update the order for web and
   app orders — POKBON orders only.

### The three larger ones

6. ~~**The delivery fee charged at checkout is not the fee the job is priced
   at.**~~ **Closed 2026-09-22, both platforms.** On #87619 the buyer paid
   GH¢30 (the site's own regional rate), the job recorded GH¢50 (the zone
   matrix) and GH¢52 was paid out — POKBON lost GH¢22 on a delivery the board
   showed as earning GH¢10.

   Fixed in two halves. The job records **what was actually charged**
   (`class-orders.php` reads `get_shipping_total()`), and checkout **prices
   from the matrix**: the buyer picks the area they are in and pays that route.
   Proven on #87675 — Charged GH¢50 / Matrix GH¢50 / Rider GH¢40 / Kept GH¢10.

   The app followed the same day (Mobile App 1.21.0, Delivery 0.5.6). It had
   the worse version of the same bug: one flat Door Delivery fee for every
   address in Ghana, and it never called `/delivery-regions` at all. Now
   `/delivery-regions?product_ids=` returns priced areas per region,
   `/cart/validate` quotes the chosen one, and the order proxy re-prices it
   before saving — the client picks *where*, the server decides *how much*.
   The chosen area rides on through the same `pokbon_checkout_order_created`
   action the web checkout fires, so a phone order reaches dispatch with the
   buyer's own area on it and nobody guesses a zone from an address.

   **The app half is committed but NOT BUILT.** A phone still shows the old flat
   fee, because the change is JavaScript in a bundle the installed APK does not
   have. EAS Update cannot carry it either — `checkAutomatically` is
   `ON_ERROR_RECOVERY` and nothing calls `checkForUpdateAsync()`, so a published
   update would sit there unfetched. It needs a binary.

   Everything the release needs is written up in the app's own repo:
   **`docs/DELIVERY_ZONE_PRICING_2026-09-22.md`** in `POKBON_Mobile_App` (app
   commit `b78f1445` on `main`), pointed at from the top of that repo's
   `HANDOFF.md`. Deployment is handled in a separate session; do not duplicate
   that doc here, update it there.

   Nothing is half-broken meanwhile. An old app never sends `delivery_zone`, so
   the live plugin prices it exactly as it did before — the two halves can stay
   out of step indefinitely.
7. **No arrival guard.** On #87619 the rider went from assigned to "at your
   door" in **30 seconds**, which would have sent a real customer an SMS and a
   payment prompt while the rider was still at the shop. The arrival event now
   carries the rider's GPS, so a distance check is straightforward; a time
   floor alone would punish the honest short delivery. Allow an override with a
   recorded reason for bad GPS.
8. **The tunnel is a dev-only stopgap.** It dropped twice in one session with
   `cloudflared` still running as a process. `ops/tunnel-watchdog.sh` is a
   plaster. The API wants a VPS.

9. **The rider app stops reporting position when the screen is off.** STILL
   OPEN, and the most important one left. Built since the first report:
   `apps/mobile/lib/duty-location.ts` — a TaskManager task with a foreground
   service and a persistent notification, `timeInterval: 60_000`,
   `distanceInterval: 0` (Android treats the two as **both** conditions, so
   100m meant a stationary rider reported nothing), and stop-then-start on
   every duty toggle so a task registered days ago cannot keep running old
   options.

   The notification shows and the service runs. **Fixes still stop the moment
   the phone is locked** — sampled 2026-09-22 11:05-11:10: one fix at 11:05:18
   and nothing for the following five minutes. Next things to rule out, in
   order: battery optimisation for `com.pokbongroup.delivery` (Tecno, Infinix
   and Samsung all throttle hard regardless of a foreground service); whether
   `Accuracy.Balanced` is resolving to a network provider that Doze suspends,
   where `Accuracy.High` would not; and whether Android is *batching* fixes and
   delivering them on unlock, which would show as a burst of pings the instant
   the screen comes on.

   The original report:
   A JavaScript timer is suspended when Android backgrounds the app, so a
   rider with the phone in a pocket goes stale after five minutes
   (`location_stale_seconds`) and is silently skipped for every offer. Nothing
   on their screen says so, and the dispatcher sees only "nobody eligible".
   Observed 2026-09-22: 52 minutes stale with the rider on duty a kilometre
   from the pickup. Needs background location (expo-location's task manager)
   rather than a longer staleness window, since the window is what stops jobs
   going to riders who are no longer where they say.

10. ~~**The rider app's keyboard covers the input it is there to fill.**~~
   Done 2026-09-22: `Screen` in `apps/mobile/components/ui.tsx` is wrapped in a
   KeyboardAvoidingView with `keyboardShouldPersistTaps="handled"` and bottom
   padding for the navigation bar. Not yet confirmed on the device. Reported
   2026-09-22 while entering the delivery code. The code and payment-link
   fields sit low on the screen and Android's keyboard hides them, so a rider
   types blind. Needs KeyboardAvoidingView or a keyboard-aware scroll around
   those screens.
11. **The app does not fit screens with on-screen navigation buttons.** Content
   runs under the gesture/navigation bar on devices that show one. Needs the
   safe-area insets honoured at the bottom, not just the top.

### Smaller, noted in passing

- Notification channels: status updates should be in-app, with real SMS
  reserved for arrival and the payment approval. `inbox.send` already exists
  but fires at one moment only and can address only a marketplace buyer.
- Two WPCode snippets (#24794, #40567) still send email and SMS inline during
  checkout, adding seconds per order. They live in the site database, so they
  cannot be changed from source.
- `pokbon-affiliate` spends ~14s on `woocommerce_new_order` and writes its
  "no affiliate" order note twice, being hooked to both `woocommerce_new_order`
  and `woocommerce_thankyou` without setting its processed guard.
- `apps/mobile/android/` is committed prebuild output. Harmless, could be
  gitignored.
- **`pokbon-checkout` lives at `C:\Users\POKBON Marketplace\pokbon-checkout`**
  — a sibling of this repo, not inside it. v1.1.0, and it carries all three
  filter call sites (`class-pokbon-shipping.php`, `templates/checkout-form.php`,
  `class-pokbon-handler.php`). It is the plugin that *asks*; this one answers.

  An earlier note here claimed no local copy existed and told you to pull the
  live one down. That was wrong — the search behind it looked through OneDrive
  and Downloads and never looked one directory up from this repo. The stale
  v1.0.0 copy under `~/OneDrive/Desktop/POKBON Marketplace/` is a decoy.

  **It is not under version control.** No `.git`, no history, no way to see what
  changed or undo a bad edit. Several plugins beside it are in the same
  position. Worth putting in a repo before the next change to any of them.

  Useful confirmation from finding it: `class-pokbon-shipping.php:84` calls
  `apply_filters( 'pokbon_checkout_delivery_zones', array(), $code )` with
  **two** arguments, against a callback now registered for three. That is the
  compatibility `check-checkout-zones.mjs` exists to hold, now verified against
  the code that actually makes the call.

---

## Things that will mislead you

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
