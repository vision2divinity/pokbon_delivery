# POKBON Delivery — state of play

Last updated: 2026-09-22

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

6. **The delivery fee charged at checkout is not the fee the job is priced at.**
   On #87619 the buyer paid **GH¢30** (the site's own regional shipping rate),
   the job recorded **GH¢50** of delivery revenue (the zone matrix), and
   **GH¢52** was paid out. POKBON lost GH¢22 on a delivery the board showed as
   earning GH¢10. Nothing reconciles the three numbers. This is the only open
   item that loses money silently, and it changes what the reconciliation
   screen must show.
7. **No arrival guard.** On #87619 the rider went from assigned to "at your
   door" in **30 seconds**, which would have sent a real customer an SMS and a
   payment prompt while the rider was still at the shop. The arrival event now
   carries the rider's GPS, so a distance check is straightforward; a time
   floor alone would punish the honest short delivery. Allow an override with a
   recorded reason for bad GPS.
8. **The tunnel is a dev-only stopgap.** It dropped twice in one session with
   `cloudflared` still running as a process. `ops/tunnel-watchdog.sh` is a
   plaster. The API wants a VPS.

9. **The rider app stops reporting position when it is not in the foreground.**
   A JavaScript timer is suspended when Android backgrounds the app, so a
   rider with the phone in a pocket goes stale after five minutes
   (`location_stale_seconds`) and is silently skipped for every offer. Nothing
   on their screen says so, and the dispatcher sees only "nobody eligible".
   Observed 2026-09-22: 52 minutes stale with the rider on duty a kilometre
   from the pickup. Needs background location (expo-location's task manager)
   rather than a longer staleness window, since the window is what stops jobs
   going to riders who are no longer where they say.

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
  not move real money.

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
