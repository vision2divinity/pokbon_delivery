# POKBON Delivery — Product Requirements, draft 2

**2026-09-20 · supersedes `PRD-pokbon-delivery-2026-09-13.md` · for review, still not for build**
Rewritten from Francis's brief of 2026-09-20, which corrected draft 1 on a central point.

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

Put a POKBON rider in the middle and the money flows the right way round. **The rider collects the cash, so
POKBON holds it, nets its commission, and remits the remainder to the vendor.** The debt never forms. A vendor
who never sells again still cannot walk away owing, because the money was never theirs to hold.

That single change converts the weakest part of the marketplace's economics into its most reliable part, and it
is only possible if POKBON controls delivery.

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

**The licence should be mandatory, not optional.** The brief was unsure. Make it required for anyone on a
motorbike, tricycle or car. An unlicensed rider carrying POKBON-branded goods makes POKBON the deep pocket in
any accident, and "we did not ask" is a worse position than "they gave us a licence that turned out to be
false". Make it optional only for a bicycle or on-foot tier, if one ever exists.

**Collecting an ID is not verifying one.** Decide explicitly whether the Ghana Card is checked against the NIA
database or merely photographed. If merely photographed, say so internally and price the risk, rather than
letting an unverified image feel like verification.

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

**Multi-vendor orders need an explicit answer.** The marketplace already supports orders spanning vendors; the
commission engine reasons about `required_vendor_ids` per order. A single order with items from two vendors is
either two pickups on one job, or two jobs against one order. Recommendation: **one job per vendor**, because a
single rider routing between two shops doubles the failure surface and makes partial delivery unrepresentable.
The buyer sees one order with two deliveries, which is honest.

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

## 9 · Pricing and commission

POKBON owns no vehicles, so pricing must leave the rider clearly better off or supply evaporates.

**Structure:** base fare by vehicle class + per-kilometre rate on the **routed** distance + waiting time beyond
a free allowance, with a **minimum fare**. The minimum matters most: a two-kilometre delivery through Accra
traffic can take forty minutes, and a purely distance-based fee makes short urban jobs the worst-paid work on
the platform, which is exactly the work there is most of.

**Commission:** take a percentage of the delivery fee, not of the goods value. Start low, 10 to 15 percent, and
say plainly what it buys: order flow, payment handling and dispute cover. POKBON is not supplying the vehicle,
the fuel or the labour, and a commission that ignores that gets riders leaving for direct arrangements with the
same vendors they meet on the platform. **Disintermediation is the main commercial risk in this model**, and
the defence is job density and reliable fast payout, not contract terms.

**Who pays the delivery fee** on a marketplace order — buyer, vendor, or split — is a business decision that is
not yet made and should be, because it changes the checkout.

**Record four numbers on every job**: quoted price, routed distance, rider payout, and any gateway fee. Without
all four, a profitable route cannot be told from a subsidised one.

**Tips:** simplest is cash, or added to the COD amount. Anything else means a second payment flow. Never let a
tip be a condition of the code being accepted.

## 10 · Payouts

Riders are paid by MoMo on a settlement cycle. The marketplace already runs MoMo payouts for affiliates,
including request, approval, reference capture and rejection-rollback; reuse those patterns.

**Decide employee versus contractor before launch.** It changes tax, insurance and how much control can be
exerted over working hours, and it is painful to reverse once hundreds of riders are onboarded.

## 11 · Cash handling — the risk that § 2 creates

Putting riders in the COD path solves the vendor commission problem by moving the cash risk onto riders. That
trade is worth making, but only with controls designed in from the start.

- **Per-rider outstanding cash cap.** Above it, no new COD jobs are offered. Prepaid jobs still flow.
- **Daily remittance**, with the outstanding balance visible to the rider at all times.
- **Cap by tenure.** A rider in week one carries far less than one in month six.
- **Declared-value cap per job**, so no single delivery can lose more than the business can absorb.
- **Reconciliation in the admin plugin**: what was collected, what was remitted, what is outstanding, per rider
  and per day.
- **A written policy for loss** — deposit, guarantor, or absorbed — decided before the first cedi is collected,
  not after the first loss.

**Failed COD delivery needs an explicit rule.** If the buyer refuses the goods, the rider has made the trip and
the vendor has lost a sale. Who pays the rider? Recommendation: POKBON pays a reduced failed-delivery fee and
recovers it from whichever party caused the failure, because leaving the rider unpaid for a trip they completed
correctly is the fastest way to lose riders.

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

1. **Background location is the hardest technical problem in the app.** Android kills background location
   aggressively, and Google Play requires a specific declaration, a privacy-policy URL and usually a demo video
   for any app requesting it. This has delayed other people's releases by weeks. Plan it as a schedule item,
   not a checkbox.
2. **Offline behaviour.** Status updates, photos and cash entries must queue locally and sync. A rider in a
   basement stockroom must still be able to mark a pickup.
3. **Battery and data cost.** Riders pay for both. Ping sparingly, batch when offline, and be able to state
   what the app costs a rider per day. This affects adoption more than any feature.
4. **Trust and safety.** An SOS control for riders, and the ability to share a trip with a contact.
5. **Insurance.** Commercial goods carriage on a motorbike. Confirm what exists and what POKBON must hold.
6. **Data protection.** Ghana Cards, selfies, phone numbers and live location make POKBON a data controller
   under Act 843. Registration with the Data Protection Commission, a retention schedule, encryption of ID
   images, and a policy on who in admin can view an ID. **Location history is the sensitive one** — decide how
   long rider tracks are kept and why.
7. **Existing website services overlap.** `buyforme.php`, `payforme.php` and `localservice.php` already promise
   delivery-shaped things with no fulfilment. Decide whether they become Delivery jobs or are retired; leaving
   both is how customers end up with two ways to ask for the same thing and two answers.
8. **Play Store policy.** Two roles in one binary is fine, but a rider-facing app collecting IDs will draw
   scrutiny. Keep requester mode the default face of the app.
9. **Do not show marketplace products in the Delivery app in phase 1.** The brief already leaned this way and
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

1. Riders: employees or contractors.
2. Who pays the delivery fee on a marketplace order — buyer, vendor or split.
3. Is the Ghana Card verified against NIA, or only photographed.
4. First coverage area.
5. Cash cap, and who absorbs a loss.
6. Whether the existing website delivery services fold into this or retire.
7. What deliveries currently cost POKBON, if that number exists anywhere. It should anchor § 9.

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
