# POKBON Delivery — Product Requirements

**Draft 1 · 2026-09-13 · for review, not for build**
Author: Claude (Opus 5), from Francis Bonsu's brief of 2026-09-13.

---

## 0 · The one thing to decide before anything else

**You have already built most of this, and it is called AutoRescue.**

Before writing a line of new code, look at `C:\Users\franc\Downloads\POKBON AutoRescue\autorescue`. It is a
NestJS + Prisma dispatch platform with a MapLibre mobile app, and its handoff note of 2026-08-17 reports 43 of
54 designed screens built and running on real devices. What it already does:

| AutoRescue has | Delivery needs | Same thing? |
|---|---|---|
| Provider goes on duty / off duty | Rider goes online / offline | Identical |
| `POST dispatch/location` pings | Live rider position | Identical |
| Offer → accept / decline, with cascade | Assign a delivery to a rider | Identical |
| `en-route` → `arrive` → `complete` → `close` | Picked up → on the way → delivered | Identical, different labels |
| Arrival code, "four digits that prove identity" | Delivery code the customer reads back | **Identical** |
| Phone OTP auth, rate limited per phone and IP | Rider sign-in | Identical |
| `JobPhoto`, `JobRating`, `JobEvent` | Proof of delivery, rider rating, audit trail | Identical |
| Quote against a real route, not a straight line | Delivery pricing by distance | Identical |
| Ops board, CSV exports, stats | Dispatch desk | Identical |

The difference between a breakdown job and a delivery job is **the payload and the pricing**, not the machinery.
A tow job moves a car from A to B for a fee, tracked live, proven by a code at the destination. A delivery job
moves a parcel from A to B for a fee, tracked live, proven by a code at the destination.

**Recommendation: add a job type to AutoRescue rather than start a second dispatch platform.** Two dispatch
systems means two rider apps, two duty states, two payout ledgers, two sets of live-location plumbing, and a
rider who has to decide which app to open. The cost of that compounds forever.

This is the single highest-leverage decision in this document, and everything below assumes it. If you decide
against it, most of the requirements still stand — only § 7 changes.

---

## 1 · What we are building, in one paragraph

A delivery network POKBON controls, serving two kinds of demand: **orders placed on the marketplace that need
to reach a customer**, and **standalone jobs where someone asks POKBON to move something for them**. Riders
work from a dedicated app. Customers never have to install that app: they follow the delivery on a map inside
the POKBON Marketplace app they already have, and by SMS if they have no app at all. A delivery is proven
complete by a code the customer reads to the rider, not by the rider tapping "done".

## 2 · Why this is worth building

Three reasons, in order of strength.

1. **It closes the last untracked gap in your order flow.** Everything up to dispatch is instrumented — payment,
   vendor commission, order status, push. Then the parcel vanishes into somebody's motorbike and the customer
   phones you to ask where it is. Every one of those calls is a cost you pay and a confidence you lose.
2. **Delivery cost is currently a guess.** You already noticed this: *"the delivery Jumia makes to my end differs
   based on the item size."* A dispatch system that quotes against a real route and records what was actually
   paid turns delivery from an estimate into a measured line item, which is what the 1.20 commission engine
   needs to compute true margin.
3. **The standalone service already exists and has no fulfilment.** `buyforme.php`, `payforme.php` and
   `localservice.php` sit in your SERVICES folder, and the app has a Services tab with a request flow and
   priced tiers. Requests come in. Nothing dispatches them. This gives that demand a delivery arm.

**What this is not:** a Bolt or Uber competitor. No passenger rides, no open marketplace of riders bidding, no
surge pricing. A courier network POKBON dispatches, with a rider app good enough that riders prefer it.

## 3 · The people

| Who | What they need | Where they are |
|---|---|---|
| **Customer** | Know where their parcel is and when it arrives, without installing anything new | POKBON Marketplace app, or SMS only |
| **Rider** | Get offered work, navigate, prove delivery, get paid correctly | Rider app (Android first) |
| **Dispatcher** | See every live job, intervene when one stalls, reassign | Ops web console |
| **Vendor** | Know a parcel left their shop and reached the buyer | Existing vendor screens in the app |
| **Francis** | Margin per delivery, rider reliability, where money leaks | Admin console, already built in app 1.4.0 |

Riders are the constraint. Customers will use whatever you ship. **If riders do not like the app they will not
use it, and the network does not exist.** Design decisions should break in the rider's favour.

## 4 · The two demand sources

### 4a · Marketplace order delivery

A paid order that needs to move. Sources a delivery job automatically when an order reaches a dispatchable
state.

- Pickup is a vendor's shop, a pickup point, or POKBON's own store. **Pickup points already carry latitude and
  longitude** in the plugin, so their coordinates exist today.
- Drop-off is the order's shipping address, which needs coordinates it does not currently have. See § 9.
- The customer did not "order a delivery"; they ordered a soap holder. The delivery is invisible to them until
  it starts moving, and then it should feel like a gift, not a form.

### 4b · Standalone "move this for me"

Someone wants a parcel taken from one place to another, or something bought and brought to them. This is the
existing Services demand with fulfilment attached.

- Requested from the Services tab, priced by distance and size, confirmed before a rider is offered it.
- Payment before dispatch, with one exception: pay-for-me, where POKBON fronts the item cost. That has real
  fraud exposure and should be **out of phase 1** with a deposit or a spend cap when it lands.

Both sources produce the **same job object**. Everything downstream is identical. Resist any design where they
diverge.

## 5 · The delivery lifecycle

This is the spine of the product. Every status is a row in the job's event log, with who caused it and when.

| # | Status | Trigger | Customer sees | SMS |
|---|---|---|---|---|
| 1 | `created` | Order paid, or request confirmed | "Preparing your delivery" | — |
| 2 | `offered` | Sent to a rider | nothing | — |
| 3 | `assigned` | Rider accepts | Rider name, photo, rating | ✅ |
| 4 | `at_pickup` | Rider reaches pickup | "Collecting your item" | — |
| 5 | `picked_up` | Rider confirms collection | Map goes live | ✅ |
| 6 | `en_route` | Rider starts moving | Live position + ETA | — |
| 7 | `arrived` | Rider reaches drop-off | **Delivery code shown** | ✅ **with code** |
| 8 | `delivered` | Code verified by rider | "Delivered", receipt | ✅ |
| 9 | `failed` | Nobody home, refused, unreachable | Reason + what happens next | ✅ |
| 10 | `cancelled` | By customer, ops, or timeout | Reason + refund state | ✅ |

Rules that matter more than the list:

- **Forward only.** A job never moves backwards. Corrections are new events, not rewrites. The marketplace
  plugin already enforces forward-only order transitions; match it.
- **`delivered` is only reachable through code verification.** A rider cannot self-declare success. This is the
  single most important integrity rule in the product.
- **`failed` is a first-class outcome, not an error.** Most delivery pain is badly handled failure. Every
  failure needs a reason from a fixed list, a photo where sensible, and a stated next step: retry today, return
  to pickup, or hold at a pickup point.
- Every status change writes to the **existing audit log**, so delivery sits in the same forensic trail as
  everything else.

## 6 · The delivery code — the part to get right

**Why it exists.** Without it, "delivered" means "the rider pressed a button". With it, "delivered" means the
person holding the parcel proved who they were. That is the difference between a dispute you can settle and one
you cannot.

**How it works.**

1. When the rider marks `arrived`, the server generates a code and SMSs it to the **customer**, never to the
   rider.
2. The customer reads it aloud. The rider types it into their app.
3. The server verifies. Only the server ever compares codes; the rider app never receives the correct value.
4. On success, the job is `delivered` and the code is burned.

**Rules.** Six digits, not four — four digits over SMS with three attempts is brute-forceable when a rider has
the parcel and an incentive. Single use. Expires after two hours. Maximum five attempts, then it locks and only
a dispatcher can release it. Regenerating sends a fresh code to the customer and invalidates the old one, and
each regeneration is logged with who asked for it.

**The bypass, which you will need on day one.** Some customers have no phone signal, some hand the parcel to a
neighbour, some are illiterate in English. A dispatcher can mark a job delivered without a code, but it is a
**separate, audited action with a mandatory reason**, it shows in the customer's history as "confirmed by
POKBON, not by code", and it appears on a weekly report. If one rider accumulates these, that is a signal worth
seeing. Do not let the bypass quietly become the normal path.

**AutoRescue already has this**, as screen 18, the arrival code. Reuse it; widen it from four digits to six.

## 7 · Architecture

### 7a · Backend

Extend AutoRescue's NestJS + Prisma dispatch service with a `DELIVERY` job type. Concretely:

- `Job` gains a discriminator and delivery-specific fields: pickup and dropoff coordinates and contact,
  parcel size class, declared value, marketplace order id where applicable.
- Reuse untouched: `JobOffer`, `JobEvent`, `JobPhoto`, `JobRating`, `ProviderProfile`, duty state, location
  pings, OTP auth, payouts ledger.
- New: a thin integration surface to the WooCommerce plugin (§ 7d).

### 7b · Maps and routing — all open source, no per-request billing

| Need | Choice | Why |
|---|---|---|
| Map rendering | **MapLibre** | Already chosen and running in AutoRescue, mobile and web |
| Tiles | Self-hosted or a free OSM-based style | Avoid a metered SDK whose price grows with success |
| Routing and ETA | **OSRM or Valhalla**, self-hosted, Ghana extract | A route, distance and duration are the only questions asked |
| Geocoding | **Nominatim**, self-hosted, plus saved addresses | Ghana address quality is poor; see § 9 |

Self-hosting a Ghana OSM extract is a small VPS, and it removes the risk of a map bill that scales with orders.
Budget a day for OSRM and a day for Nominatim, and accept that Nominatim on Ghanaian free-text addresses will
be mediocre — which is exactly why § 9 matters.

### 7c · Customer tracking inside the marketplace app — deliberately not a native module

**Render the tracking map in a WebView, not with a native map library.**

The marketplace app is managed Expo SDK 54 and ships no native map today; it has `react-native-webview` and
`expo-location` already. Adding `@maplibre/maplibre-react-native` pulls native code into an app that currently
has 9 installs and just survived two bad releases in two days. A WebView loading a MapLibre page costs one
screen, no native dependency, no new build risk, and it works identically on a phone with the app and in a
browser for a customer without it.

The same page serves three surfaces: the marketplace app's tracking screen, a public tracking link in SMS, and
the ops board. Build it once.

**Refresh cadence:** poll every 10 to 15 seconds while a job is `en_route` and the screen is foregrounded; stop
when backgrounded. Do not open a socket for phase 1. Riders on Ghanaian mobile data drop connections constantly,
and a poll degrades gracefully where a socket needs reconnection logic you would have to write and debug.

**Position honesty.** Show the rider's last known position with a timestamp: "updated 40 seconds ago". A stale
dot presented as live is worse than an honest one. If no ping has arrived in three minutes, say so.

### 7d · Marketplace integration

A new plugin module, or a section of the existing one, that:

- Creates a delivery job when an order becomes dispatchable, and only then.
- Receives status callbacks and maps them onto the order timeline the customer already sees.
- Exposes tracking through the existing app API, so the app needs no new authentication.
- Records the **actual delivery cost** against the order, which feeds the 1.20 commission engine's margin maths.

Authenticate callbacks with a shared secret and a signed payload, and make them idempotent. Deliveries will
retry; a duplicate callback must not double-charge or double-notify. The plugin already has the patterns for
this in its webhook handling.

### 7e · Notifications

You have both channels already, so use each for what it is good at.

- **SMS via Zenoph**, already wired with credentials in the secret vault. Use it for the four moments that
  matter: rider assigned, picked up, **arrived with the code**, delivered. Nothing else. Every additional SMS
  costs money and trains people to ignore the ones that matter.
- **Push and the in-app inbox**, already built, for everything softer: preparing, en route, running late.
- **The code only ever goes by SMS.** Push can fail silently, be read on a locked screen by someone else, or be
  mirrored to a laptop. SMS is the weaker channel technically and the stronger one for this purpose.

## 8 · Pricing

Phase 1 should be **boringly predictable**, not clever.

- A base fee by zone, plus a per-kilometre rate on the **routed** distance, plus a size-class multiplier.
- Quote before dispatch, hold it for a stated window, and never bill more than the quote without an explicit
  customer confirmation. Your own instinct here was right: *"I don't want to overprice, price should be
  marginal."*
- Record quoted price, actual distance, rider payout and gateway fee separately on every job. Without those
  four numbers you cannot tell a profitable route from a subsidised one.
- Rider pay: a per-job rate plus distance, paid on a settlement cycle through the existing ledger. Decide early
  whether riders are employees or contractors, because it changes the tax position and it is painful to change
  later.

No surge pricing, no dynamic pricing, no per-rider negotiation in phase 1.

## 9 · The hardest problem: Ghanaian addresses

**This will cause more failed deliveries than every technical decision in this document combined.** "Behind the
blue kiosk at Madina, call when you reach" is a real address and a routing engine cannot use it.

Mitigations, in order of value:

1. **Capture a pin, not a string.** At checkout, let the customer drop a pin on a map or share their current
   location. One tap beats any address parser.
2. **Support GhanaPostGPS digital addresses** as a first-class field. The format is national, it resolves to
   coordinates, and adoption is growing because government services increasingly require it.
3. **Save and reuse.** A delivered address with a confirmed pin is worth keeping. The second delivery to the
   same customer should need no input.
4. **Keep the free-text note.** Never remove it. "Call when you reach the junction" is genuinely useful to the
   rider and no coordinate replaces it.
5. **Always show the customer's phone to the assigned rider**, and log every call. Calling is the fallback that
   actually works in Ghana today.

Treat a fully machine-usable address as a goal you approach over months, not a precondition.

## 10 · Phasing

**Phase 0 — prove it moves (2 to 3 weeks).** One job type, one rider, manual assignment from the ops board, no
customer map. SMS only. Goal: a real order reaches a real customer through the system and the code verifies.
Everything else is premature until this works once.

**Phase 1 — the product.** Rider app with duty, offers, navigation and the code. Customer tracking map in the
marketplace app. Automatic job creation from paid orders. Quote and record cost. Failure handling with reasons.

**Phase 2 — the business.** Standalone requests from the Services tab. Rider payouts through the ledger.
Ratings. Dispatcher assignment rules. Margin reporting per route.

**Phase 3 — scale, only if phase 2 pays.** Multi-drop routes, batching, scheduled windows, pay-for-me with
fraud controls, opening the network to third-party merchants.

**Explicitly not doing:** passenger rides, rider bidding, surge pricing, a customer-facing delivery app.

## 11 · How to tell if it worked

| Measure | Why this one |
|---|---|
| Deliveries completed by code, as a share of all | The integrity number. If the bypass share climbs, the product is failing quietly |
| Failed-delivery rate, by reason | Where the money actually leaks |
| Quoted versus actual cost per delivery | Whether pricing reflects reality |
| Rider jobs per active hour | Whether riders can earn enough to stay |
| "Where is my order" contacts per 100 orders | Should fall. This is the reason you are building it |

Deliberately absent: total deliveries. It goes up when you spend more and tells you nothing.

## 12 · Risks, honestly

| Risk | Severity | What to do |
|---|---|---|
| Not enough riders to cover demand | **Highest** | Start in one area with 2 to 3 riders. Coverage beats reach |
| Addresses cannot be routed | High | § 9, and accept phone calls as normal, not as failure |
| Rider battery and data cost | High | Ping sparingly, batch when offline, and say plainly what the app costs a rider per day |
| Building a second dispatch platform | High | § 0 |
| Cash handling on delivery | Medium | Cash-on-delivery reconciliation is its own project. Prefer prepaid in phase 1 |
| Rider safety and parcel theft | Medium | Declared value caps, photos at pickup and drop-off, known riders only |
| Regulatory | Medium | Commercial motorbike delivery, rider insurance, and data protection under Ghana's Act 843 — check before scaling, not after |

## 13 · Questions I cannot answer for you

1. **Riders: employees or contractors?** Changes tax, insurance, and how much control you can exert.
2. **Own riders, or partner with an existing courier?** Partnering gets coverage immediately and gives up
   margin and control.
3. **Which area first?** Pick one you know well and can drive to when something goes wrong.
4. **Cash on delivery in phase 1?** It doubles the reconciliation work. I would defer it.
5. **Does AutoRescue's rider base overlap with delivery riders?** If tow operators will not do parcels, the
   shared app still saves engineering but not recruitment.
6. **What did the last 100 deliveries actually cost?** If that number exists anywhere, it should anchor § 8.

---

## Appendix A · What already exists, verified 2026-09-13

| Capability | Where | State |
|---|---|---|
| Dispatch engine, job lifecycle, offers, duty, location pings | AutoRescue `apps/api` | Built |
| Arrival code | AutoRescue screen 18 | Partial |
| MapLibre, mobile and web | AutoRescue `apps/mobile`, `apps/web` | Built |
| Phone OTP auth, rate limited | AutoRescue `apps/api/src/auth` | Built |
| SMS gateway (Zenoph) | Marketplace plugin, secret vault | Live |
| Push and in-app inbox | Marketplace app | Live |
| Pickup points with coordinates | Marketplace plugin | Live |
| Services request flow with priced tiers | Marketplace app + plugin | Live |
| Shipping zones and delivery regions | Marketplace plugin | Live |
| Order status guards, forward-only | Marketplace plugin 1.16 | Live |
| Audit log | Marketplace plugin | Live |
| Admin console in-app | Marketplace app 1.4.0 | Live |
| Vendor commission and margin engine | Marketplace plugin 1.20.0 | Live |
| Customer address coordinates | — | **Missing, see § 9** |
| Routing engine | — | **Missing, see § 7b** |

## Appendix B · Sources for the claims above

Every "already exists" line was verified by reading the code on 2026-09-13, not from memory. AutoRescue's
maturity is from its own handoff note dated 2026-08-17, which says 43 of 54 screens are built. The Zenoph
gateway is named in the marketplace plugin's secret vault. Pickup point coordinates are in the plugin's
pickup-points admin page. The marketplace app's absence of a native map library is from its dependency list.
