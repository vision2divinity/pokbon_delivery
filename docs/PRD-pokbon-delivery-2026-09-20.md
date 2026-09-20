# POKBON Delivery — Product Requirements, draft 2

**2026-09-20 · draft 2, revised the same day after the owner's answers · supersedes the 2026-09-13 draft**
Rewritten from Francis's brief of 2026-09-20, then revised again once he corrected the money model.
Section 0a records what is now settled; every section it touches was rewritten, not appended to.

---

## 0 · What changed from draft 1, and a correction

Draft 1 recommended adding a `DELIVERY` job type to POKBON AutoRescue. **That was wrong, and the owner was
right to push back.**

AutoRescue bridges motorists and mechanics. Its providers are mechanics and tow operators, recruited for
competence with vehicles that have stopped working. Delivery providers are couriers, recruited for coverage and
speed. The job objects look alike on a whiteboard, but the supply pool, the onboarding, the economics and the
dispatch rules are all different, and a shared platform would have forced two unrelated businesses through one
set of compromises.

**The right relationship is harvest, not merge.** From AutoRescue take the things that are genuinely generic
and already solved:

| Take from AutoRescue | Why it is safe to reuse |
|---|---|
| MapLibre setup, mobile and web | Rendering a map is not business logic |
| Phone OTP auth, rate limited per phone and per IP | Identical requirement, already hardened |
| Duty on/off and location-ping plumbing | A courier going online is the same event as a mechanic doing so |
| Job lifecycle *shape* and event log | The pattern, not the states |
| Arrival-code screen (four digits) | Widen to six — see § 7 |
| NestJS + Prisma project layout, ops board patterns | Saves a week of scaffolding |

Do not share a database, a deployment, or a rider identity. If the dispatch core is worth sharing later,
extract it as a package both services depend on — never as one service serving both.

Everything else below is new or substantially rewritten.

## 0a · Settled by the owner, 2026-09-20

| # | Decision |
|---|---|
| 1 | **Vendors may onboard their own riders.** A delivery may be made by a POKBON rider or a vendor's rider, and the money flows differently in each case (§ 2, § 9) |
| 2 | **Rider licence is mandatory.** No longer an open question |
| 3 | **One job per vendor**, but a rider may hold more than one at a time if they choose, with a warning about late delivery (§ 6) |
| 4 | **Standalone jobs are prepaid online**, before the rider collects (§ 9a) |
| 5 | **Riders pay little or no commission** at launch; the requester absorbs the platform fee. Riders own the bike, the fuel and the maintenance (§ 9) |
| 6 | **Failed COD: goods return to the vendor.** Rider compensation deferred, to be settled before enrolment (§ 11) |
| 7 | **Background location** accepted as solvable; a policy page will be produced (§ 14) |
| 8 | **Data protection** to be worked through separately — but it gates NIA access, see § 4a |

Still open: § 17.

---

## 1 · What POKBON Delivery is

One Android app with two roles behind one login, plus an admin console inside WordPress.

- **Rider mode.** Couriers receive, accept and complete delivery jobs.
- **Requester mode.** Anyone books a courier to move something between two points.

It serves two streams of demand:

1. **POKBON marketplace orders.** A buyer in Lapaz orders from a vendor in Adenta. The vendor confirms
   availability and moves the order to processing. Riders near Adenta see the job, collect from the vendor, and
   deliver to Lapaz. The buyer follows it inside the POKBON Marketplace app they already have.
2. **Standalone courier requests.** Someone books a rider to move an item between two addresses that has
   nothing to do with a POKBON order. Not visible in the marketplace app; managed entirely from a new
   WordPress admin plugin.

Both produce the same job object and the same rider experience. Only the origin and who can see it differ.

## 2 · Why this is worth building — the cash-on-delivery argument

There are several reasons, but one is much stronger than the rest and should drive the roadmap.

**Cash on delivery breaks commission collection, and a POKBON rider fixes it structurally.**

Today a COD order is money that never touches POKBON. The vendor hands goods to a buyer, takes cash, and owes
commission on a sale POKBON cannot see settle. The 1.20 commission engine already treats this as the central
problem — netting was made mandatory specifically because, in the code's own words, a vendor sits on COD cash —
but netting is a mitigation. It recovers the debt later, from future payouts, if there are future payouts.

Delivery fixes this, but there are **two different strengths of fix**, and the model as described currently
chooses the weaker one. Both are legitimate. The difference should be a decision rather than an accident.

**Visibility.** The rider confirms delivery, or the buyer confirms receipt, so POKBON knows the sale completed,
which vendor owes, and how much. The claim becomes evidenced instead of disputed. The vendor still ends up
holding the cash, so POKBON still chases — but now with proof.

**Custody.** The rider remits the item cash to POKBON, which nets commission and pays the vendor the rest. The
debt never forms. A vendor who never sells again cannot walk away owing, because the money was never theirs.

Custody is much stronger and is the only version that removes the problem rather than documenting it. It is
also the one vendors will resist, and it is impossible when a **vendor's own rider** delivers, because POKBON
never touches that money.

**Recommendation: support both, per job.**

| Who delivers | Cash custody | What POKBON gets |
|---|---|---|
| POKBON rider | POKBON holds item cash, nets commission, remits to vendor | The debt never forms |
| Vendor's own rider | Vendor holds the cash | Proof of delivery, an evidenced claim |

Store it on the job as `cashCustody: pokbon` or `vendor`. Settlement, reconciliation and what the vendor sees
all follow from that one field. Without it the two cases blur and reconciliation becomes guesswork.

Either way delivery makes the marketplace's weakest economics measurable, which is only possible if POKBON
controls delivery.

The secondary reasons still stand: delivery cost becomes a measured number instead of an estimate, which the
commission engine needs for true margin; "where is my order" calls stop; and the standalone service creates
revenue independent of marketplace volume.

**This also creates a new risk in the same move — riders holding cash.** See § 11, which is the price of § 2
and must be designed at the same time, not later.

## 3 · The people

| Who | Needs | Surface |
|---|---|---|
| **Buyer (POKBON order)** | Know where the parcel is, get the code, confirm receipt | POKBON Marketplace app; SMS if no app |
| **Requester (standalone)** | Book a courier, price it, track it | POKBON Delivery app, requester mode |
| **Rider** | Get offered nearby work, navigate, prove delivery, get paid, hand over cash | POKBON Delivery app, rider mode |
| **Vendor** | Know a rider is coming and that the parcel left | Existing vendor screens in the marketplace app |
| **Dispatcher / admin** | Approve riders, watch live jobs, intervene, reconcile cash | New WordPress admin plugin |

Riders remain the constraint. **POKBON owns no vehicles**, so every rider is a small business deciding whether
this app earns them more than the alternative. Pricing, payout speed and job density are product features, not
finance details.

## 4 · Onboarding

### 4a · Riders — self-service, then human review

Flow: rider registers in the app → uploads documents → submits → appears in the admin queue → admin reviews,
contacts them with questions → approved → can go on duty.

**Collected:**

| Field | Status | Note |
|---|---|---|
| Full name | Mandatory | Must match the ID |
| Phone number | Mandatory | Also the login identity, via OTP |
| Ghana Card | Mandatory | Primary identity |
| Voter ID | Alternative | Only where no Ghana Card |
| Rider/driver licence | **Mandatory for motorised vehicles** | See below |
| Vehicle type and registration | Mandatory | Drives which jobs they can be offered |
| Base area | Mandatory | Drives dispatch (§ 6) |
| Selfie / profile photo | Mandatory | Shown to buyers; also the anti-substitution check |
| Next of kin or guarantor | Recommended | Matters when cash goes missing |

**The licence is mandatory** (owner, 2026-09-20), for anyone on a motorbike, tricycle or car. An unlicensed
rider carrying POKBON-branded goods makes POKBON the deep pocket in any accident, and "we did not ask" is a
worse position than "they gave us one that turned out to be false".

#### Verifying the Ghana Card — what is actually available

Researched 2026-09-20. **Collecting an ID is not verifying one**, and there are three distinct levels:

| Level | What it proves | Availability to POKBON |
|---|---|---|
| Photograph / OCR | Text was extracted from something card-shaped | Immediate, and **not verification** |
| NFC chip read | The card is genuine and unaltered, by cryptographic signature | Buildable now; Ghana Cards carry an NFC chip and most Android phones can read it |
| NIA Identity Verification System | The person exists in the national register | Contract required, see below |

**The NIA route is real but gated.** NIA runs an Identity Verification System already used at scale by banks
and telcos. It is not self-serve: an institution emails `idverification@nia.gov.gh`, supplies business
registration, **a data protection certificate**, an SSNIT certificate and a business profile, then meets NIA,
sets up technical infrastructure and executes a contract before access is granted. No public pricing.

Two consequences worth acting on now:

1. **Data protection registration is a prerequisite, not a nicety.** § 14 lists it as a legal obligation; it is
   also the gate to NIA. Start it early, because it blocks the verification POKBON actually wants.
2. **Apply early, build the interim.** The contract process will take longer than the build. In the meantime,
   NFC chip validation plus a selfie-to-card face match gets most of the value: the card is provably genuine and
   the holder provably resembles it. That is far stronger than a photograph and available without anyone's
   permission.

Do not let an OCR extraction be recorded in the admin as "verified". Store the level explicitly
(`photo`, `nfc`, `nia`) so nobody later mistakes a scan for a check.

**Account sharing is the failure nobody plans for.** One person registers, their cousin rides. Cheap
mitigations: a selfie check when going on duty after a period offline, the rider's photo shown to the buyer,
and the delivery photo (§ 7) tied to the rider's account.

### 4b · Buyers and requesters — reuse the POKBON account

A person with a POKBON Marketplace account signs in with the same credentials. Once signed in they see their
POKBON orders and delivery statuses inside the Delivery app.

**Do this by issuing a token from the marketplace, never by asking the Delivery app to handle a POKBON
password.** The plugin already mints JWTs for the mobile app; the Delivery app should use the same mechanism.
If the Delivery app ever sees a raw marketplace password, a compromise of the smaller app becomes a compromise
of the store.

If someone registers in the Delivery app with a phone or email that already has a POKBON account, link the
accounts after proving control of that phone or email by OTP. Do not silently merge, and do not force a
password reset unless there is a reason — an unexplained "change your password" prompt reads as a breach notice
and frightens people.

## 5 · Vehicle classes

| Class | Typical load |
|---|---|
| Motorbike | Documents, small parcels |
| Tricycle (pragya) | Bulky but light, short range |
| Small car | Multiple parcels, fragile items |
| Pickup | Appliances, furniture |
| Canter / truck | Haulage, moving |

Class affects price, dispatch eligibility and which riders may accept. Requesters pick a class, or the system
proposes one from declared size. **Start with motorbike only.** One class end to end beats five half-built, and
haulage has different insurance, different loading time and different failure modes.

## 6 · Dispatch

- Jobs are offered to riders whose base area or current position is near the **pickup**, not the drop-off.
- Offer to one rider at a time with a short timeout, then cascade to the next. Broadcast-to-all creates a race
  that punishes riders on slower connections and makes acceptance feel arbitrary.
- A rider must be on duty, approved, within their vehicle class, and under their cash cap (§ 11) to be offered
  work.
- Dispatcher can assign manually and override anything. On day one, manual assignment is the whole system.

**Multi-vendor orders: one job per vendor** (owner, 2026-09-20). The marketplace already supports orders
spanning vendors and the commission engine reasons about `required_vendor_ids` per order. One job per vendor
keeps partial delivery representable; the buyer sees one order with two deliveries, which is honest.

**A rider may hold more than one job at a time**, by choice. Show the available jobs and let them decide. Two
rules make this safe rather than chaotic:

- **Warn at the moment of the second acceptance**, naming the risk plainly: both deliveries are now late if
  either goes wrong, and lateness affects their rating and their job offers.
- **Cap concurrency** by tenure and rating rather than leaving it unbounded. A new rider holding four jobs is
  four unhappy buyers.

Do not auto-batch or auto-route multi-job riders in phase 1. Let them choose and observe what they actually do
before optimising a behaviour nobody has measured yet.

## 7 · The delivery lifecycle

| # | Status | Trigger | Buyer sees (marketplace app) | SMS |
|---|---|---|---|---|
| 1 | `created` | Vendor sets order to processing | "Preparing your delivery" | — |
| 2 | `offered` | Sent to a rider | nothing | — |
| 3 | `assigned` | Rider accepts | Rider name, photo, rating, vehicle | ✅ |
| 4 | `at_pickup` | Rider reaches the vendor | "Collecting your item" | — |
| 5 | `picked_up` | Rider confirms collection | Map goes live | ✅ |
| 6 | `en_route` | Rider moving to buyer | Live position + ETA | — |
| 7 | `arrived` | Rider reaches the buyer | **Delivery code shown** | ✅ **with code** |
| 8 | `delivered` | Code verified, cash collected if COD | "Delivered", receipt | ✅ |
| 9 | `failed` | Nobody home, refused, unreachable | Reason and next step | ✅ |
| 10 | `cancelled` | Buyer, vendor, ops, or timeout | Reason and refund state | ✅ |

Rules:

- **Forward only.** Corrections are new events, never rewrites. Matches the marketplace's existing order guards.
- **`delivered` is reachable only through code verification** (and cash collection on COD). A rider cannot
  self-declare success. This is the integrity rule the whole product rests on.
- **Six digits, not four.** AutoRescue uses four. Four digits with a few attempts is guessable by someone
  holding the parcel and wanting to mark it delivered. Single use, two-hour expiry, five attempts then locked.
- **The code goes to the buyer by SMS only.** Never push — push can be read on a locked screen or mirrored to a
  laptop.
- **A dispatcher bypass will be needed on day one** for no-signal, neighbour handover and language problems. It
  must be a separate audited action with a mandatory reason, shown to the buyer as "confirmed by POKBON, not by
  code", and reported weekly. If one rider accumulates bypasses, that is the signal.
- **`failed` is a first-class outcome**, with a reason from a fixed list, a photo where sensible, and a stated
  next step: retry, return to vendor, or hold at a pickup point.
- **Photo at pickup and at delivery**, stored against the job. This settles disputes that the code cannot.

## 8 · What the buyer sees in the POKBON Marketplace app

- Delivery status on the existing order timeline. No new place to look.
- A live map once `picked_up`, rendered **in a WebView, not a native map module**. The marketplace app has
  `react-native-webview` and `expo-location` and no native map; adding one pulls native code into an app that
  broke twice in one week this month. The same web page then serves the app, an SMS tracking link for people
  without the app, and the ops board.
- Position shown with honest freshness: "updated 40 seconds ago", and an explicit "no signal from the rider"
  after three minutes. A stale dot presented as live is worse than an honest one.
- Poll every 10 to 15 seconds while `en_route` and foregrounded. No sockets in phase 1 — Ghanaian mobile data
  drops constantly and a poll degrades where a socket needs reconnection logic.
- In-app notification on each status change, through the existing push and inbox.

## 9 · Pricing, and who pays whom

POKBON owns no bikes, buys no fuel and pays no maintenance. The owner's position is that riders carry little or
no commission at launch and the **requester or buyer absorbs the platform fee**. That is the right instinct for
a network that must attract supply before demand, and it is built into the model below.

### 9a · Standalone courier jobs — prepaid

1. Requester enters pickup and drop-off and sees **distance and price before committing**.
2. They confirm and **pay online first**, through the payment methods the marketplace already supports.
3. Nearby riders see the request, the fee, the destination and the distance, and accept or decline.
4. On confirmed delivery the rider's earning is released.

Because the job is prepaid, POKBON holds the money from the start. No cash risk, no chasing. **This is the
cleanest money flow in the product and should ship before COD.**

Price = base fare by vehicle class + per-kilometre on the **routed** distance + waiting time beyond a free
allowance, with a **minimum fare**. The minimum matters most: two kilometres through Accra traffic can take
forty minutes, and pure distance pricing makes short urban jobs the worst-paid work on the platform, which is
exactly the work there is most of.

POKBON's revenue is a **service fee added on top of the rider's fee and shown to the requester**, not a cut
taken out of the rider's earning. Identical arithmetic, completely different message: the rider sees the full
fee they earned and the requester sees what the platform costs. Riders leave platforms that appear to shave
their earnings, and they compare notes with each other.

### 9b · Marketplace orders

The delivery fee is charged to the buyer and **passes to the rider**.

- **Paid online.** POKBON already holds everything. Commission is netted at settlement and the rider is paid.
- **Cash on delivery.** The rider collects **item price + delivery fee** as one amount, which then splits:
  - the **delivery fee is the rider's**, kept immediately;
  - the **item money** goes to POKBON (custody) or the vendor (visibility), per § 2.

**Rider earnings are released on confirmed delivery**, so the delivery code is not only proof for the buyer, it
is the trigger for the rider being paid. Keep that alignment: it makes the rider want the code entered properly.

**When a vendor's own rider delivers, POKBON pays that rider nothing** — the vendor does. POKBON's interest is
the commission on the sale and the proof that the sale completed.

### 9c · Record on every job

Quoted fee, routed distance, rider earning, platform service fee, gateway fee, cash collected, and
`cashCustody`. Without all of these a profitable route cannot be told from a subsidised one, and COD cannot be
reconciled at all.

### 9d · The risk in taking nothing from riders

Charging riders nothing wins supply, and it makes **the requester-side fee the entire business**. Set it too low
to win demand and the platform loses money on volume it cannot easily reduce. Decide a target margin per job
early and watch it per route, not in aggregate.

Raising rider commission later is far harder than starting modest and holding. If riders will ever be charged,
say so at enrolment rather than introducing it in month six.

### 9e · Tips

Cash is simplest, or added to the COD amount. Anything else is a second payment flow. **A tip must never be a
condition of the code being accepted**, and the app must not nag — tipping pressure is the fastest way to make
a delivery feel unpleasant.

## 10 · Payouts

Riders are paid by MoMo on a settlement cycle. The marketplace already runs MoMo payouts for affiliates,
including request, approval, reference capture and rejection-rollback; reuse those patterns.

**Decide employee versus contractor before launch.** It changes tax, insurance and how much control can be
exerted over working hours, and it is painful to reverse once hundreds of riders are onboarded.

## 11 · Cash handling

Cash risk exists **only on COD marketplace orders where a POKBON rider takes custody** (§ 2). Standalone jobs
are prepaid and carry none, which is another reason to ship those first.

- **Per-rider outstanding cash cap.** Above it no new COD jobs are offered; prepaid jobs keep flowing.
- **The cap rises with tenure.** Week one carries far less than month six.
- **Daily remittance**, with the outstanding balance always visible to the rider in the app.
- **Declared-value cap per job**, so no single delivery loses more than the business can absorb.
- **Reconciliation in the admin plugin**: collected, remitted, outstanding — per rider, per day.
- **A written policy for loss** — deposit, guarantor or absorbed — decided before the first cedi is collected.

**Note the asymmetry the split creates.** The rider keeps the delivery fee immediately but owes POKBON the item
money, so a rider who absconds keeps both. The cash cap is the only thing bounding that loss, and it should be
set against what the business can lose in a week rather than what feels generous to a good rider.

### Failed COD delivery

Settled in principle: **the goods return to the vendor.** What the rider is paid for a trip they completed
correctly is deferred, to be communicated at enrolment rather than discovered later.

Worth flagging once more because it decides rider trust: a rider who rides to Lapaz, finds nobody home and
rides back to Adenta for nothing will not take the next COD job. Whatever the figure is, it should be non-zero,
known in advance, and paid automatically rather than on appeal.

## 12 · The admin plugin (WordPress)

A new plugin in the existing wp-admin, matching the conventions already in use: numbered migrations, audit-log
entries, nonce plus capability plus step-up confirmation on destructive actions, and no schema change outside a
migration.

Screens: rider applications queue, rider roster and suspension, live job board, job detail with event log and
photos, cash reconciliation, pricing settings, coverage areas, payouts, and reports.

**Every delivery action writes to the existing audit log**, so delivery sits in the same forensic trail as
everything else rather than in a parallel one.

## 13 · Addresses — still the hardest problem

Unchanged from draft 1 and still the thing most likely to cause failures. "Behind the blue kiosk at Madina,
call when you reach" is a real address and no routing engine can use it.

1. **Capture a pin, not a string** — at checkout and at booking, let people drop a pin or share current
   location.
2. **Support GhanaPostGPS digital addresses** as a first-class field.
3. **Save and reuse** a confirmed pin per customer.
4. **Keep the free-text note.** It is genuinely useful to riders.
5. **Always show the buyer's phone to the assigned rider**, and log every call. Calling is the fallback that
   works in Ghana today.

**This is the one thing worth starting before the delivery build**, because every order placed without
coordinates is permanently unroutable, and the backlog grows daily.

## 14 · Things the brief did not mention that will bite

1. **Background location.** The owner is content this is solvable and will produce the policy page, which is
   the right call — but keep it on the release checklist, not the backlog. Google Play requires a specific
   declaration, a reachable privacy-policy URL and usually a short demo video showing why the app needs
   location while backgrounded. The engineering is routine; the review step is what has delayed other people's
   releases, so submit it with the first build that requests the permission rather than discovering it at
   launch.
2. **Offline behaviour.** Status updates, photos and cash entries must queue locally and sync. A rider in a
   basement stockroom must still be able to mark a pickup.
3. **Battery and data cost.** Riders pay for both. Ping sparingly, batch when offline, and be able to state
   what the app costs a rider per day. This affects adoption more than any feature.
4. **Trust and safety.** An SOS control for riders, and the ability to share a trip with a contact.
5. **Insurance.** Commercial goods carriage on a motorbike. Confirm what exists and what POKBON must hold.
6. **Data protection — and it blocks NIA.** Ghana Cards, selfies, phone numbers and live location make POKBON
   a data controller under Act 843: registration with the Data Protection Commission, a retention schedule,
   encryption of ID images, and a policy on who in admin may view an ID. **This is now on the critical path**,
   because NIA requires a data protection certificate before granting verification access (§ 4a). **Location
   history is the sensitive one** — decide how long rider tracks are kept, and why.
7. **The disintermediation guardrail needs care.** The proposed rule — watch a rider who delivers to the same
   person three or more times, then route new requests elsewhere — catches the right behaviour but also catches
   the most valuable normal behaviour there is: an office that orders lunch daily, a shop with a regular
   supplier, a customer who simply lives on a rider's route. In a thin market the same pair will recur
   constantly with nothing wrong.

   Use it as a **signal to review, not an automatic reroute.** Auto-rerouting punishes a rider for being
   convenient and degrades service for the customer, and neither of them did anything wrong. Flag the pair,
   look at it, and act only if something else corroborates — jobs cancelled after acceptance, or a vendor whose
   marketplace volume falls while their rider's activity does not.

   The honest position is that the real defence is job density and fast reliable payout. A rider with steady
   work and same-day money has little reason to go around the platform; a rider with sparse work and slow
   payout will, whatever the rules say.

8. **Existing website services overlap.** `buyforme.php`, `payforme.php` and `localservice.php` already promise
   delivery-shaped things with no fulfilment. Decide whether they become Delivery jobs or are retired; leaving
   both is how customers end up with two ways to ask for the same thing and two answers.
9. **Play Store policy.** Two roles in one binary is fine, but a rider-facing app collecting IDs will draw
   scrutiny. Keep requester mode the default face of the app.
10. **Do not show marketplace products in the Delivery app in phase 1.** The brief already leaned this way and
   the instinct is right. It doubles the app's surface and halves its clarity for the one audience that must
   love it.

## 15 · Phasing

**Phase 0 — prove one delivery (2 to 3 weeks).** Manual assignment from the admin plugin, one rider, prepaid
orders only, SMS only, no map. Goal: one real order reaches one real buyer and the code verifies.

**Phase 1 — marketplace deliveries.** Rider app with duty, offers, navigation, code and photos. Buyer tracking
in the marketplace app. Automatic job creation on processing. COD collection with cash caps. Rider onboarding
and admin review.

**Phase 2 — the standalone service.** Requester mode, quotes, payouts, ratings and tips, coverage areas.

**Phase 3 — scale, only if phase 2 pays.** More vehicle classes, multi-drop and batching, scheduled windows,
third-party merchants.

**Not doing:** passenger rides, rider bidding, surge pricing, a separate buyer-facing delivery app.

## 16 · How to tell if it worked

| Measure | Why |
|---|---|
| Deliveries confirmed by code, as a share of all | The integrity number. A rising bypass share means the product is failing quietly |
| **COD cash remitted on time, and outstanding by rider** | The § 2 benefit and the § 11 risk in one number |
| Failed deliveries by reason | Where money leaks |
| Rider jobs per active hour, and rider retention at 30 days | Whether the economics work for the people doing the work |
| Quoted versus actual cost | Whether pricing reflects reality |
| "Where is my order" contacts per 100 orders | Should fall |

Deliberately absent: total deliveries. It rises when you spend more and tells you nothing.

## 17 · Open decisions

1. **Riders: employees or contractors.** Changes tax, insurance and how much control can be exerted.
2. **Visibility or custody of COD item cash** (§ 2). The single biggest lever on whether the commission problem
   is removed or merely documented.
3. **The platform service fee on standalone jobs.** With riders paying nothing, this is the entire business
   (§ 9d).
4. **What a rider is paid for a failed COD delivery** (§ 11). Deferred, but must be settled before enrolment.
5. **Per-rider cash cap, and who absorbs a loss** — deposit, guarantor, or the business.
6. **First coverage area.**
7. **Whether the existing website delivery services** (`buyforme`, `payforme`, `localservice`) fold into this
   or retire.
8. **What deliveries currently cost POKBON**, if that number exists anywhere. It should anchor § 9.

Settled since draft 2 was first written: § 0a.

---

## Appendix · Verified marketplace facts this design leans on

Read from the code on 2026-09-13 and 2026-09-20, not from memory.

| Fact | Where |
|---|---|
| Commission engine reasons per vendor and treats COD cash held by vendors as the core problem; netting is mandatory | `class-vendor-commissions.php` |
| Multi-vendor orders already modelled (`order_commission_complete`) | same |
| MoMo payout request/approve/reject/reference flow exists | affiliate payouts, plugin + app |
| Phone/SMS gateway is Zenoph, credentials in the secret vault | `class-sms.php` |
| Push and in-app inbox live | marketplace app |
| Pickup points already store latitude and longitude | `pickup-points.php` |
| Order status transitions are forward-only and guarded | plugin 1.16 |
| Audit log used by every admin action | `class-audit-log.php` |
| Marketplace app has **no** native map; it has WebView and expo-location | app `package.json` |
| JWT minting for app clients already exists | `class-auth.php` |
| Customer delivery-address coordinates | **absent — § 13** |
| Routing engine | **absent — self-host OSRM or Valhalla on a Ghana extract** |
