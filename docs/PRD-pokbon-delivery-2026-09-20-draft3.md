# POKBON Delivery — Product Requirements, draft 3

**2026-09-20 · draft 3 · every owner decision closed · supersedes draft 2 of the same day**
Draft 2 asked eight questions in § 17. Francis answered all eight on 2026-09-20 and said "this should be the
complete fix and flow, let's get started." This draft records those answers as settled, rewrites the sections
they change, and is the document the build is made from. Nothing in § 17 is open any more.

---

## 0 · Decisions, all of them

### 0a · Settled 2026-09-20, first round

| # | Decision |
|---|---|
| 1 | **Vendors may onboard their own riders.** A delivery may be made by a POKBON rider or a vendor's rider, and the money flows differently in each case (§ 2, § 9) |
| 2 | **Rider licence is mandatory** for anyone on a motorbike, tricycle or car |
| 3 | **One job per vendor**, but a rider may hold more than one at a time if they choose, with a warning about late delivery (§ 6) |
| 4 | **Standalone jobs are prepaid online**, before the rider collects (§ 9e) |
| 5 | **Riders pay no commission at launch**; the requester or buyer absorbs the platform margin. Riders own the bike, the fuel and the maintenance |
| 6 | **Failed delivery: goods return to the vendor** |
| 7 | **Background location** accepted as solvable; a policy page will be produced (§ 14) |
| 8 | **Data protection** to be worked through separately, but it gates NIA access (§ 4a) |

### 0b · Settled 2026-09-20, second round — the answers to draft 2 § 17

| # | Question in draft 2 | Decision |
|---|---|---|
| 1 | Employees or contractors | **Independent contractors.** A rider can opt out of the service at any time. No shifts, no minimum hours, no POKBON-owned equipment (§ 10) |
| 2 | Visibility or custody of the doorstep payment | **The cashless flow in § 2, exactly as described.** POKBON sees every step and receives the whole payment digitally. Treat it as fixing roughly 60% of the cash-on-delivery problem now, then improve from what testing shows. **No physical cash changes hands in any flow, marketplace or standalone** |
| 3 | Rider commission | **Zero for about six months from go-live, then phased in gradually.** POKBON bears the cost meanwhile as a business decision. The schedule, the percentages and the effective dates are **settings in the admin plugin, never constants in code** (§ 9d) |
| 4 | Rider pay on a failed delivery | **Marketplace order:** rider returns the goods to the vendor and receives a **higher fee on their next delivery**; the uplift is a plugin setting. **Standalone job:** goods return to the sender and any return fee is **between the rider and the sender**; POKBON stays out of it (§ 11) |
| 5 | Who pays the delivery fee | **The buyer or requester pays. The delivery fee is the rider's.** POKBON's margin is the difference between what the buyer sees and what the rider is offered (§ 9) |
| 6 | First coverage area | **The areas already advertised on pokbongroup.com/local-delivery:** Madina, Circle, Ashaiman, Lapaz, Kumasi and Santasi, each "and environs". Operating hours there are 6:00 to 22:00, seven days (§ 12b) |
| 7 | Website services | **Buy-for-me and pay-for-me are distinct services and stay distinct.** They do not fold into Delivery |
| 8 | Delivery cost and pricing | **A zone-to-zone price matrix that Francis edits in the admin plugin.** Locations can be added, edited and removed; each pair carries a rider fee and a buyer price. Example: Madina to Circle, rider fee GH₵30, buyer price GH₵40, POKBON keeps GH₵10. **The rider never sees the buyer's price, anywhere** (§ 9) |

### 0c · The correction from draft 1, kept for the record

Draft 1 recommended adding a `DELIVERY` job type to POKBON AutoRescue. That was wrong. AutoRescue's providers
are mechanics and tow operators; delivery providers are couriers. The job objects look alike, but the supply
pool, onboarding, economics and dispatch rules all differ. **Harvest, do not merge.** From AutoRescue take the
phone OTP auth, the duty and location-ping plumbing, the job lifecycle shape and event log, the offer cascade
and scheduler pattern, the MapLibre setup and the NestJS + Prisma layout. Share no database, no deployment and
no rider identity.

---

## 1 · What POKBON Delivery is

One Android app with two roles behind one login, plus an admin plugin inside the existing WordPress.

- **Rider mode.** Couriers go on duty, receive, accept and complete delivery jobs. They see only their own fee.
- **Requester mode.** Anyone books a courier to move something between two points and pays up front.

Two streams of demand feed the same job object and the same rider experience:

1. **POKBON marketplace orders.** A buyer in Lapaz orders from a vendor in Adenta. The vendor confirms and moves
   the order to processing. Riders near Adenta are offered the job, collect from the vendor, deliver to Lapaz.
   The buyer follows it inside the POKBON Marketplace app they already have.
2. **Standalone courier requests.** Someone books a rider to move an item between two addresses. Not visible in
   the marketplace app; managed from the new admin plugin.

### 1a · The three parts, and who owns the money

| Part | Stack | Owns |
|---|---|---|
| **Delivery API** | NestJS + Prisma + Postgres, harvested from AutoRescue | Riders, duty, jobs, offers, the delivery code, live position, the event log |
| **Delivery app** | Expo / React Native, harvested from AutoRescue mobile | Rider mode and requester mode |
| **Admin plugin** (`pokbon-delivery`, WordPress) | PHP, same conventions as the marketplace plugin | **Every setting** (zones, fee matrix, commission schedule, hours), **every payment** (Paystack), **every message** (Zenoph SMS, push, inbox), rider approval, the live board |

The Delivery API never touches money and never sends a message directly. It asks the plugin to charge, to SMS,
to push. This is already the contract on the marketplace side
(`pokbon_mobile_app/docs/DELIVERY_INTEGRATION_2026-09-20.md`, revised the same day for this design) and it is
right for one more reason: Francis has one Paystack account, one SMS account and one place he edits settings.
Putting a second copy of any of those in the API doubles the things that can disagree.

Settings are pushed from the plugin to the API on save (signed), and pulled by the API on boot. The plugin is
the source of truth; the API holds a cache it can run on if WordPress is briefly unreachable.

## 2 · Why this is worth building — the cash-on-delivery argument, and the exact flow

Cash on delivery breaks commission collection. Today a COD order is money that never touches POKBON: the vendor
hands over goods, takes cash, and owes commission on a sale POKBON cannot see settle. The 1.20 commission engine
made netting mandatory because, in the code's own words, a vendor sits on COD cash. Netting recovers the debt
later, from future payouts, if there are future payouts. It documents the problem. It does not remove it.

**The fix is that there is no cash.** The buyer still chooses "cash on delivery" at checkout, and the rider
still comes to the door, but the payment happens on the buyer's own phone while the rider stands there, and
POKBON receives the whole amount. The owner's flow, step by step, and this is the flow to build:

1. The rider arrives at the buyer's location and taps **Arrived**.
2. The rider taps **Send code**. The API generates a six-digit code and asks the plugin to deliver it to the
   buyer: **by SMS always, and also to the in-app inbox when it is a POKBON order.** The rider's screen shows a
   code entry field and nothing else. **The rider never sees the code.**
3. The buyer reads the code to the rider. The rider types it. The API compares server-side and answers only
   *matched* or *not matched*.
4. **The moment the code matches**, the API asks the plugin to prompt the buyer for payment. The plugin, which
   owns the order and the Paystack integration, creates one payment intent for the order total (items plus
   delivery) and pushes a mobile-money approval prompt to the buyer's phone.
5. The buyer approves on their phone. Paystack's webhook lands in the plugin; the plugin tells the API `paid`.
6. The rider's screen turns to **PAID — hand over the item**, unmistakable at arm's length in sunlight. Goods
   change hands only now.
7. If the buyer missed the prompt, the rider taps **Send prompt again**. Same intent, same amount, no second
   charge, ever.
8. After `delivered`, POKBON settles from the one inbound payment: the vendor's share if the goods are not
   POKBON's, the rider's delivery fee, and POKBON keeps its commission and its delivery margin.

For a **prepaid** order or a **prepaid standalone job**, steps 4 to 6 do not exist: code matched is delivered.
The code step is identical in every flow; the payment step exists only where money is still owed.

**When a vendor's own rider delivers**, POKBON is not in the money flow for that delivery. What POKBON gets is
the same code-verified proof that the sale completed, which turns a disputed debt into an evidenced one. Store
which case a job is on the job as `riderSource: pokbon | vendor` so settlement and reporting never guess.

### What this removes, and what it introduces

Removed: cash floats, cash caps, remittance, daily reconciliation, riders robbed for cash, and the entire
vendor-debt problem for POKBON-delivered orders.

Introduced: **payment failure at the doorstep.** The buyer has no funds, the wallet is down, the network is
down. § 11 is the design for that. It is a narrower and better-behaved problem than cash.

Also introduced: **gateway fees on orders that used to carry none.** A pay-on-delivery order is a MoMo charge
like any other. The marketplace side already handles this: plugin 1.20.2 reclassifies a `cod` order as
digitally settled the moment `_pokbon_paid_on_delivery` is written, so the gateway fee is counted and no vendor
debt row is created.

### Tell buyers before the rider arrives

The buyer chose "cash on delivery" and will be asked for a MoMo PIN. Three cheap touches close that gap:

- At checkout, under the method: *pay by mobile money or card when the rider arrives. No cash.*
- When the rider is dispatched: *"Your rider is on the way. Have GH₵X ready on MoMo."*
- At arrival, in the same SMS as the code.

**Rename the method everywhere it appears** to *Pay on delivery*, keeping it selectable exactly as now. Land
the wording with the rollout, not before: today there really is cash and the app would be lying. The label
lives in the marketplace app's `src/constants/config.ts` and the checkout screen.

## 3 · The people

| Who | Needs | Surface |
|---|---|---|
| **Buyer (POKBON order)** | Know where the parcel is, read the code, approve the payment | POKBON Marketplace app; SMS if no app |
| **Requester (standalone)** | Book a courier, see the price, pay, track | Delivery app, requester mode |
| **Rider** | Be offered nearby work, see their fee, navigate, prove delivery, get paid. **Never handles cash, never sees the buyer's price** | Delivery app, rider mode |
| **Vendor** | Know a rider is coming and the parcel left | Existing vendor screens in the marketplace app |
| **Francis and dispatchers** | Approve riders, set zones and prices, watch live jobs, intervene, chase failed payments | The admin plugin |

Riders remain the constraint. POKBON owns no vehicles, so every rider is a small business deciding whether this
app earns more than the alternative. Fee level, payout speed and job density are product features.

## 4 · Onboarding

### 4a · Riders — self-service, then human review

Flow: rider registers in the app → uploads documents → accepts the contractor agreement → submits → appears in
the admin queue → admin reviews and contacts them → approved → can go on duty.

| Field | Status | Note |
|---|---|---|
| Full name | Mandatory | Must match the ID |
| Phone number | Mandatory | Also the login identity, via OTP |
| Ghana Card | Mandatory | Primary identity |
| Voter ID | Alternative | Only where no Ghana Card |
| Rider or driver licence | **Mandatory** | Motorbike, tricycle or car |
| Vehicle type and registration | Mandatory | Drives which jobs they can be offered |
| Base area (a zone from § 9) | Mandatory | Drives dispatch |
| Selfie / profile photo | Mandatory | Shown to buyers; the anti-substitution check |
| MoMo number for payouts | Mandatory | Where the delivery fee lands |
| Next of kin or guarantor | Recommended | |
| Contractor agreement | Mandatory | Version and timestamp stored; states the commission schedule (§ 9d) |

#### Verifying the Ghana Card

Collecting an ID is not verifying one. Three levels, stored explicitly on the rider as `photo`, `nfc` or `nia`
so nobody later mistakes a scan for a check:

| Level | Proves | Availability |
|---|---|---|
| Photograph / OCR | Text was extracted from something card-shaped | Immediate, and **not verification** |
| NFC chip read | The card is genuine, by cryptographic signature | Buildable now on most Android phones |
| NIA Identity Verification System | The person exists in the national register | Contract required |

The NIA route is real but gated: an institution emails `idverification@nia.gov.gh` with business registration,
**a data protection certificate**, an SSNIT certificate and a business profile, meets NIA, sets up
infrastructure and signs a contract. Start data protection registration now because it blocks this. Build NFC
plus selfie-to-card face match as the interim; it is far stronger than a photograph and needs nobody's permission.

**Account sharing** is the failure nobody plans for: one person registers, a cousin rides. Cheap mitigations: a
selfie check when going on duty after a period offline, the rider's photo shown to the buyer, and the delivery
photo tied to the rider's account.

### 4b · Buyers and requesters — reuse the POKBON account

A person with a POKBON Marketplace account signs in with the same credentials, by a **delivery-scoped token
minted by the marketplace plugin**. The Delivery app never sees a marketplace password. Someone registering in
the Delivery app with a phone that already has a POKBON account is linked after proving control of the phone by
OTP. No silent merge, no forced password reset.

## 5 · Vehicle classes

Motorbike, tricycle (pragya), small car, pickup, canter. Class affects which riders may accept a job and, later,
price. **Start with motorbike only.** One class end to end beats five half-built.

## 6 · Dispatch

- Jobs are offered to on-duty, approved riders whose base zone or current position is near the **pickup**.
- Offer to one rider at a time with a short timeout, then cascade to the next. Broadcast-to-all creates a race
  that punishes riders on slower connections. The timeout and cascade depth are plugin settings.
- **The offer shows the rider: pickup zone, drop-off zone, distance, parcel size, and their fee.** It does not
  show the buyer's price, the order value, or the item value. This is enforced in the API's rider-facing
  responses, not only in the app: the rider endpoints never serialise `buyerPrice`.
- Dispatcher can assign manually and override anything. On day one, manual assignment is the whole system.

**One job per vendor** on multi-vendor orders. **A rider may hold more than one job** by choice; warn at the
moment of the second acceptance that both deliveries are now late if either goes wrong, and cap concurrency by
tenure and rating rather than leaving it unbounded. No auto-batching in phase 1.

## 7 · The delivery lifecycle

| # | Status | Trigger | Buyer sees | SMS |
|---|---|---|---|---|
| 1 | `created` | Vendor sets order to processing, or requester pays | "Preparing your delivery" | — |
| 2 | `offered` | Sent to a rider | nothing | — |
| 3 | `assigned` | Rider accepts | Rider name, photo, rating, vehicle | ✅ "have GH₵X ready on MoMo" when pay on delivery |
| 4 | `at_pickup` | Rider reaches the vendor or sender | "Collecting your item" | — |
| 5 | `picked_up` | Rider confirms collection, photo | Map goes live | ✅ |
| 6 | `en_route` | Rider moving to the drop-off | Live position | — |
| 7 | `arrived` | Rider taps Arrived | "Your rider is here" | — |
| 8 | `code_sent` | Rider taps Send code | **Code by SMS, and inbox for POKBON orders** | ✅ **with code** |
| 9 | `code_verified` | Rider typed the code the buyer read out; matched | "Confirming it's you" | — |
| 10 | `payment_pending` | Plugin pushed the MoMo prompt | "Approve the payment on your phone" | — |
| 11 | `paid` | Paystack webhook, via the plugin | "Paid, collect your item" | — |
| 12 | `delivered` | Rider hands over after `paid` (or after `code_verified` when prepaid), photo | "Delivered", receipt | ✅ |
| 13 | `payment_failed` | No funds, wallet down, prompt expired, window closed | What to do next | ✅ |
| 14 | `failed` | Nobody home, refused, unreachable, damaged | Reason and next step | ✅ |
| 15 | `returned` | Goods back with the vendor or sender | "Returned to sender" | — |
| 16 | `cancelled` | Buyer, vendor, ops or timeout | Reason and refund state | ✅ |

Rules:

- **Forward only.** Corrections are new events, never rewrites.
- **`delivered` is reachable only through `code_verified`, and only through `paid` when payment is due.** A
  rider cannot self-declare success and cannot hand over unpaid goods. These two rules are the product.
- **The rider never sees the code.** Generated server-side, sent to the buyer, compared server-side. The
  rider's app submits an attempt and learns only whether it matched. Not shown to riders "for support", not in
  any rider-facing payload, not in logs.
- **Six digits.** Single use, two-hour expiry, five attempts then locked and the dispatcher is alerted.
- **Code delivery channel:** SMS always. For POKBON orders also the in-app inbox, which requires opening the
  app, rather than a lock-screen push preview. A code that can be read off a locked screen by whoever is holding
  the phone is weaker than one that cannot.
- **A dispatcher bypass will be needed on day one** for no-signal, neighbour handover and language problems. It
  is a separate audited action with a mandatory reason, shown to the buyer as "confirmed by POKBON, not by
  code", and reported weekly. One rider accumulating bypasses is the signal.
- **`failed` carries a reason from a fixed list**, a photo where sensible, and a next step: retry, return, or
  hold at a pickup point.
- **Photo at pickup and at delivery**, stored against the job.

## 8 · What the buyer sees in the POKBON Marketplace app

- Delivery status on the existing order timeline. No new place to look.
- A live map once `picked_up`, rendered **in a WebView**. The marketplace app has no native map module and
  adding one pulls native code into an app that broke twice in one week this month. One web page serves the app,
  an SMS tracking link, and the ops board.
- Honest freshness: "updated 40 seconds ago", and "no signal from the rider" after three minutes.
- Poll every 10 to 15 seconds while `en_route` and foregrounded. No sockets in phase 1.
- Feature-detect: if the delivery endpoint 404s, hide delivery entirely.

## 8a · What "delivery" means at checkout — clarified 2026-09-21

The owner drew this distinction from the live checkout, and getting it wrong would have built the
wrong thing. **Two different things both say "delivery" on a POKBON order.**

| Option at checkout | What it is | Who prices it |
|---|---|---|
| **Ship from Abroad — Air Freight** | The item is not in Ghana. Weight-based, 1–14 days | The marketplace. Untouched by this project |
| **Ship from Abroad — Sea Freight** | Same, slower and cheaper, 1–45 days | The marketplace. Untouched |
| **POKBON Delivery Services** | **The rider leg. This is what we are building** | The price ladder, § 9 |
| **Store Pickup** | The buyer collects from POKBON or the vendor | Free |

**The rider leg is sometimes the whole journey and sometimes the last mile.** For an item held locally
it is the entire delivery. For an item shipped from abroad the buyer pays freight at checkout, and the
rider leg happens **when the goods land in Ghana** — which means that job is created on arrival, not at
checkout. Automatic job creation on `processing` is therefore correct for local stock and wrong for
freight; a freight order becomes dispatchable only when someone marks the shipment arrived.

**The website and the app show this differently and both are right.** The website charges by region
(Greater Accra GH₵30, Ashanti GH₵60) because a buyer there types a city and the rider works it out. The
app lists the four options above explicitly. The price ladder sits behind "POKBON Delivery Services" in
both.

**None of this reaches the standalone courier service.** Someone booking a rider to move something
between two addresses has no marketplace, no vendor, no freight and no order status. Pickup, drop-off,
the ladder, done. Keeping that clean is why the standalone flow must not grow marketplace concepts.

---

## 9 · Pricing — the three-rung ladder

**Francis sets the prices.** Not a formula and not a routing engine. Revised 2026-09-21: a single matrix
does not survive growth, so the matrix became the top rung of a ladder.

**A route takes the first rung that answers:**

| Rung | What it is | When it earns its place |
|---|---|---|
| 1 | **An exact route** — Adenta → Kasoa | A route that is genuinely special: a bad road, a bridge, an area worth more |
| 2 | **The bands those zones belong to** — Inner Accra → Outer Accra | Structural pricing. Four bands cover any number of zones with sixteen prices |
| 3 | **How far it actually is** — 0–5km, 5–10km, … | The catch-all, so a zone added this morning prices this morning |

Six zones is 36 cells; twelve is 144; twenty is 400. Pricing every new area against every existing one is
work nobody keeps up with, and a stale price is worse than no price. With the ladder, **adding a zone
costs one decision — which band — and it is priced the same day.** Nothing is ever unpriced, and a route
no rung answers is reported as unserved rather than guessed at.

Each job records **which rung set its price and what matched**, because a fee nobody can explain six
weeks later is a fee a rider does not trust.

The rules exist twice: in the plugin, so checkout can quote without a round trip to the delivery service,
and in the service itself. `scripts/check-price-parity.mjs` prices the same routes through both and fails
on any disagreement.

### 9-0 · Multi-vendor orders — settled 2026-09-21

**Charged per vendor.** Two vendors is two collections and two rider fees however close they are. The
buyer sees **one total**, not a split, the same way Hubtel does it — a basket broken into delivery lines
reads as several orders going wrong. The rider sees the jobs and their own earnings.

If any leg is unserved the whole quote is withheld, because taking money for a delivery POKBON cannot
complete is worse than declining it.

### 9a · Zones

A zone is a named area with a centre point and a radius, for example *Madina & environs*. Zones are created,
edited and removed in the admin plugin. The launch list is the six areas on pokbongroup.com/local-delivery:
Madina, Circle, Ashaiman, Lapaz in Greater Accra; Kumasi and Santasi in Ashanti. A zone can be switched off
without being deleted, which keeps its history.

A drop-off pin is resolved to a zone by nearest centre within radius. If no zone claims the pin, the buyer is
asked to pick one, or told delivery is not yet available there. Pickup zones come from the vendor's store
address or pickup point the same way.

### 9b · The matrix

For every ordered pair of zones that POKBON serves, two numbers:

| From | To | Rider fee | Buyer price | POKBON margin |
|---|---|---|---|---|
| Madina | Circle | GH₵30 | GH₵40 | GH₵10 |
| Madina | Madina | GH₵15 | GH₵20 | GH₵5 |

The margin is not stored; it is the difference. A **default markup** (percentage or flat, a setting) fills in the
buyer price when Francis enters only a rider fee, and he can override any cell. An unpriced pair means POKBON
does not serve that route yet, and checkout says so.

Prices are versioned. A job freezes the rider fee and buyer price at the moment it is created, so a later
edit never changes a live job or a settled one.

### 9c · Who sees what

| | Rider fee | Buyer price | Order value |
|---|---|---|---|
| Rider | ✅ | **never** | never |
| Buyer / requester | never | ✅ | ✅ |
| Admin | ✅ | ✅ | ✅ |

Enforced in the API: the rider-facing serialisers omit `buyerPrice` and `orderTotal` structurally, not by a
flag the app could ignore.

### 9d · Rider commission — zero now, phased later, configured not coded

Riders pay nothing for about six months from go-live. Then a commission phases in gradually. This is a business
decision Francis has made and is bearing the cost of. What engineering owes him is that **nothing about it is
hardcoded**:

- A **commission schedule** in the plugin: rows of *effective from date → percentage of rider fee (or flat
  amount)*. Empty schedule means zero.
- The schedule is **shown to the rider at enrolment** in the contractor agreement and in the app, so raising it
  later is a scheduled change they agreed to, not a surprise.
- Each job records the commission rate in force when it was created.

### 9e · Standalone jobs — prepaid

1. Requester picks pickup and drop-off; the matrix returns the buyer price before they commit.
2. They pay online first, through the plugin's Paystack flow.
3. Nearby riders are offered the job with the rider fee. Accept or decline.
4. On `delivered` the rider fee is released to the rider's balance.

POKBON holds the money from the start. No cash, no chasing. **This is the cleanest flow in the product and
ships before pay on delivery.**

### 9f · Marketplace orders

The delivery fee (the buyer price) is added to the order at checkout when both zones are in the matrix. It is
charged to the buyer and the rider fee passes to the rider.

- **Paid online:** POKBON holds everything. Commission netted at settlement, rider paid.
- **Pay on delivery:** the § 2 flow. One inbound payment, then vendor share, rider fee, POKBON commission and
  delivery margin.

Rider earnings are released on `delivered`, so the code is also the trigger for being paid. Keep that alignment.

### 9g · Relationship to the existing pokbon-checkout regions

The `pokbon-checkout` plugin holds **region** rates for shipping across Ghana (`pokbon_checkout_region_rates`,
code → name and rate). That stays as it is for inter-regional shipping. The Delivery zone matrix is for
**intra-city rider delivery** and is a separate table in the new plugin. At checkout: if both pickup and
drop-off resolve to served zones, offer rider delivery at the matrix price; otherwise fall back to the region
rate exactly as today. Two tables, two purposes, one checkout decision.

### 9h · Record on every job

Rider fee, buyer price, commission rate in force, gateway fee, payment reference, zones, and `riderSource`.
Without all of these a profitable route cannot be told from a subsidised one.

### 9i · Tips

Optional, on the buyer's payment prompt, default nothing, never a condition of the code or the handover, and
the app never nags.

## 10 · Riders are independent contractors

- A rider **can stop at any time**: go off duty, or leave the platform. No shifts, no minimum hours, no penalty
  for declining offers beyond the natural effect on how often they are offered work.
- Riders own and run their own vehicle, fuel, maintenance and phone.
- The **contractor agreement** is accepted at enrolment, versioned, and re-accepted when it changes. It states:
  the fee is per job and shown before acceptance; the commission schedule (§ 9d); the failed-delivery rule
  (§ 11); that no cash is ever collected; and the data collected and why.
- Payouts by MoMo on a settlement cycle (a setting), reusing the marketplace's affiliate payout patterns:
  request, approve, reference, reject with rollback. Delivery fees accrue to a rider balance on `delivered`.

## 11 · Payment at the door, and what happens when it fails

There is no cash handling. What replaces it is a bounded, well-behaved problem.

### Failure paths, in order

1. **Send the prompt again.** Same intent, same amount. Most failures are a missed or expired prompt.
2. **Let someone else pay.** An SMS payment link for the same intent so a spouse or colleague can settle it from
   their own phone. A first-class button, not a workaround.
3. **Wait, briefly.** A bounded window (a setting, default 10 minutes) for the buyer to top up. Bounded because
   a waiting rider is losing money.
4. **Abandon.** The job goes to `payment_failed` then `failed`, and the goods go back.

### What happens to the goods and the rider

**Marketplace order.** The rider returns the goods to the vendor; the job ends `returned`. The rider is not
paid for the failed trip. Instead **their next completed delivery pays a higher fee**: rider fee plus a
failed-trip uplift. The uplift is a plugin setting (percentage or flat), it attaches to the rider as a credit
that the next `delivered` consumes, and it appears on the offer so the rider sees why this one pays more. Francis
will refine this from experience; the mechanism must allow the number to change without a release.

**Standalone job.** The goods return to the sender. **Any return fee is between the rider and the sender.**
POKBON records the outcome, shows both parties each other's phone number as it already does, and takes no
position on the money. The requester's prepaid buyer price is refunded per the refund setting less any
non-refundable component Francis configures.

**Damaged goods, refused at the door.** Reason `refused_damaged`, photo mandatory, same return path. Francis
handles the vendor conversation off-platform for now; the job carries the evidence.

## 12 · The admin plugin (WordPress)

A new plugin, `pokbon-delivery`, in the existing wp-admin, matching the marketplace plugin's conventions:
numbered migrations, audit-log rows, nonce plus capability plus step-up confirmation on destructive actions, no
schema change outside a migration, and `ok_private()` on anything personal.

### 12a · Screens

Rider applications queue · rider roster and suspension · live job board · job detail with event log and photos
· doorstep payments and failed-payment follow-up · **zones** · **price matrix** · **commission schedule** ·
settings · payouts · reports.

### 12b · Settings — nothing in this list may be a constant in code

| Setting | Launch value | Notes |
|---|---|---|
| Zones | The six from the website | Name, centre, radius, active |
| Price matrix | Francis enters | Rider fee and buyer price per pair |
| Default markup | Francis enters | Fills buyer price from rider fee |
| Rider commission schedule | Empty (zero) | Effective date → rate; ~6 months after go-live the first row |
| Failed-trip uplift | Francis enters | Percentage or flat, credited to the next delivery |
| Operating hours | 06:00–22:00, 7 days | Per zone if needed |
| Offer timeout, cascade depth | 45 s, 5 | |
| Code length, expiry, attempts | 6, 120 min, 5 | |
| Payment wait window | 10 min | § 11 step 3 |
| Prompt re-send limit | 3 per job | Then pay-by-link or abandon |
| Concurrency cap by tenure | 1 new, 2 after 30 jobs, 3 after 100 | |
| Payout cycle | Weekly | |
| Standalone refund rule on failure | Francis enters | |
| SMS templates | Defaults provided | Every buyer-facing text editable |
| Service-to-service secret | Generated | Rotates with a two-value window |

### 12c · Zone and matrix editing

A grid: zones down the side, zones across the top, each cell editable in place with rider fee and buyer price,
the margin computed and shown. Add a zone and a new row and column appear, unpriced. Remove a zone and it is
deactivated, not deleted, so history stays intact. Every edit writes an audit row and a price version.

## 13 · Addresses

Drop-off coordinates, an optional GhanaPost GPS address and a landmark note are **already captured** at
checkout in plugin 1.20.1 and the app. Pickup coordinates come from the vendor's store or a pickup point, which
already carry latitude and longitude. What Delivery adds is zone resolution (§ 9a) and, on the rider's side,
one-tap navigation by handing the pin to the phone's maps app. No routing engine is needed for pricing any more;
if ETAs are wanted later, self-hosted OSRM on a Ghana extract is the option, and it is optional.

Always show the buyer's phone to the assigned rider and log every call. Calling is the fallback that works.

## 14 · Things that will bite

1. **Background location.** Play requires a declaration, a reachable privacy-policy URL and usually a short
   video. Submit it with the first build that requests the permission.
2. **Offline behaviour.** Status changes and photos queue locally and sync. A code attempt cannot be queued: it
   needs the server. The app must say so plainly when there is no signal at the door, and the SMS code still
   reaches the buyer because the plugin sends it.
3. **Battery and data cost.** Riders pay for both. Ping sparingly, batch when offline.
4. **Trust and safety.** SOS control for riders, share-trip for requesters.
5. **Insurance.** Commercial goods carriage on a motorbike by a contractor. Confirm what the contractor
   agreement requires of the rider and what POKBON holds.
6. **Data protection.** Ghana Cards, selfies, phone numbers and live location make POKBON a data controller
   under Act 843. Registration with the Data Protection Commission is on the critical path because NIA needs the
   certificate. Decide rider-track retention early. Delivery stores presence, not history, by default.
7. **Disintermediation.** A rider delivering to the same person repeatedly is a signal to review, not an
   automatic reroute. The real defence is job density and fast payout.
8. **Paystack mobile-money charge.** The marketplace plugin today uses Paystack's *initialize transaction* flow
   (a checkout URL). The doorstep prompt needs Paystack's *charge* API with a mobile-money payload, which pushes
   the approval to the buyer's handset. That is new code in the plugin, with the same webhook. Test against
   MTN, Telecel and AirtelTigo separately; their prompt behaviour differs.
9. **Play Store policy.** Two roles in one binary is fine; keep requester mode the default face of the app.
10. **Do not show marketplace products in the Delivery app in phase 1.**

## 15 · Phasing

**Phase 0 — one real delivery, cashless (3 to 4 weeks).** Admin plugin with zones, matrix, settings and manual
assignment. Delivery API with riders, jobs, the code, and the plugin contract. Rider app with duty, job list,
Arrived, Send code, code entry, Send prompt again, PAID screen, photos. Plugin sends the code by SMS and pushes
the MoMo prompt. **Goal: one pay-on-delivery order reaches one buyer, the code verifies, the buyer approves on
their phone, the rider hands over on PAID, and the commission engine sees `_pokbon_paid_on_delivery`.** Manual
dispatch only, motorbike only, one zone pair.

**Phase 1 — marketplace deliveries at the six zones.** Automatic job creation on processing. Offer cascade. Buyer
tracking in the marketplace app. Rider self-onboarding and the admin queue. Payouts. Failed-trip uplift.

**Phase 2 — the standalone service.** Requester mode, matrix quote, prepay, ratings, tips.

**Phase 3 — only if the numbers say so.** More vehicle classes, multi-drop, scheduled windows, third-party
merchants, ETAs from a routing engine.

**Not doing:** passenger rides, rider bidding, surge pricing, a separate buyer-facing delivery app, folding
buy-for-me or pay-for-me into Delivery.

## 16 · How to tell if it worked

| Measure | Why |
|---|---|
| Deliveries confirmed by code, as a share of all | The integrity number. Rising bypass share means quiet failure |
| **Doorstep payments approved on the first prompt** | Whether the § 2 flow works for real buyers |
| Doorstep payments that fail outright, by reason | Where § 11 needs work |
| POKBON-delivered COD orders with zero vendor debt created | The reason the product exists |
| Margin per zone pair, actual | Whether Francis's prices are right, per route |
| Rider jobs per active hour; rider retention at 30 days | Whether the economics work for riders |
| "Where is my order" contacts per 100 orders | Should fall |

Deliberately absent: total deliveries.

## 17 · Open decisions

**None for the owner.** Every question from drafts 1 and 2 is answered in § 0.

Still to confirm, engineering and compliance, none of which blocks the build starting:

1. Paystack Ghana mobile-money charge API behaviour per network, in test mode.
2. Data Protection Commission registration, then the NIA application.
3. Insurance wording for the contractor agreement.
4. The exact first zone pair and first rider for phase 0.

---

## Appendix · Verified facts this design leans on

Read from code and pages on 2026-09-13 and 2026-09-20, not from memory.

| Fact | Where |
|---|---|
| Commission engine treats COD cash held by vendors as the core problem; netting mandatory | `class-vendor-commissions.php` |
| `settled_digitally()` flips a `cod` order to digitally settled when `_pokbon_paid_on_delivery` is set | plugin 1.20.2, per the integration contract § 3 |
| Drop-off lat/lng, GhanaPost GPS and landmark note captured at checkout | plugin 1.20.1 and app checkout |
| Multi-vendor orders already modelled | `class-vendor-commissions.php` |
| MoMo payout request / approve / reject / reference flow exists | affiliate payouts, plugin and app |
| Paystack integration uses *initialize transaction*, channels include `mobile_money`; webhook handles `charge.success` | `class-payments-endpoint.php` |
| SMS gateway is Zenoph, credentials in the secret vault | `class-sms.php`, `Pokbon_App_Secret_Vault` |
| Push and in-app inbox live | marketplace app |
| Pickup points store latitude and longitude | `admin/pages/pickup-points.php` |
| Region shipping rates are `pokbon_checkout_region_rates`, code → name, rate | `class-delivery-regions-endpoint.php` |
| Marketplace app has no native map; has WebView and expo-location | app `package.json` |
| Local delivery page advertises Madina, Circle, Ashaiman, Lapaz, Kumasi, Santasi; 06:00–22:00 daily; quote by WhatsApp today | pokbongroup.com/local-delivery |
| AutoRescue API: NestJS 10, Prisma 5, Postgres/PostGIS, zod validation, global auth guard, OTP with scrypt and per-phone/per-IP limits, offer cascade with a 5 s sweep | `autorescue/apps/api` |
| AutoRescue mobile: Expo 57, expo-router, MapLibre, expo-location, secure store | `autorescue/apps/mobile/package.json` |
| Marketplace-side contract already revised for the cashless flow, § 4b | `pokbon_mobile_app/docs/DELIVERY_INTEGRATION_2026-09-20.md` |
