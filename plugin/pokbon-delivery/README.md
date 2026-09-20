# POKBON Delivery — WordPress plugin

Installs alongside the POKBON Mobile App plugin on pokbongroup.com. It owns **every setting, every payment and
every message**; the Delivery API owns riders, jobs and the delivery code. Neither writes the other's database.

## Install

Zip the `pokbon-delivery` folder and upload it under Plugins → Add New. Activation is not required for the
schema — migrations also run on load, because a zip upload never fires activation.

Then, in **POKBON Delivery → Settings**:

1. Set the API address and paste the same shared secret as the API's `PLUGIN_SHARED_SECRET`.
2. Press **Test the connection**.
3. Press **Push zones, prices and settings**.

Until that is done, zones and prices are saved here and nothing dispatches. Every screen says so rather than
showing an empty table.

## The five screens

| Screen | What it is for |
|---|---|
| **Job board** | Live jobs, manual assignment, the offer cascade, the audited code bypass, the event log |
| **Riders** | The application queue and the roster. Approve, suspend, reinstate, reject |
| **Zones** | The named areas. Centre, radius, on or off. Switched off, never deleted |
| **Price matrix** | Rider fee and buyer price for every pair of zones. The margin is the difference |
| **Settings** | The connection, the rider commission schedule, and every operating number |

## What it does automatically

- **Creates a delivery job** when an order reaches `processing`, one per vendor. Off by default: turn it on in
  Settings once the zones and matrix are set. Phase 0 is manual dispatch.
- **Pushes settings to the API** on every save, on shutdown so an admin save is never held up by an HTTP call.
- **Pushes the mobile-money prompt** when the API says the delivery code matched.
- **Writes `_pokbon_paid_on_delivery`** when the payment lands, which is what makes plugin 1.20.2 treat a `cod`
  order as digitally settled: the gateway fee is counted and no vendor debt is created.

## The routes the API calls

All under `/wp-json/pokbon/v1/`, all requiring the service signature:

```
POST delivery/messages/sms              relayed to Zenoph
POST delivery/messages/inbox            see "Known gaps"
POST delivery/payment/prompt            Paystack mobile-money charge
GET  delivery/payment/{intentId}        verified against Paystack, not just local state
POST delivery/payment/{intentId}/link   pay-by-link by SMS
POST delivery/callback                  every job status change
GET  delivery/settings                  zones, prices and settings, for the API's boot pull
```

Signature: `X-Pokbon-Delivery-Signature` (HMAC-SHA256 of the raw body, hex), `X-Pokbon-Delivery-Timestamp`
(unix seconds, ±5 minutes), `X-Pokbon-Delivery-Event-Id` (a replay returns the stored answer). Verified against
the current secret and, during a rotation window, the previous one.

## How a double charge is prevented

Paystack refuses a repeated reference and a mobile-money prompt expires, so a retry genuinely needs a new
transaction. The protection is the order of operations, not a reused reference: **before every retry the
previous reference is verified with Paystack, and if it already succeeded no new charge is created.** One
logical intent (`pkbd_<order>`) spans however many attempts it takes.

The reference is written to `_pokbon_paystack_reference`, the same meta the marketplace plugin's own webhook
looks orders up by, so `charge.success` lands on the existing proven handler. This plugin then hooks
`woocommerce_payment_complete`, which means any route that pays the order reaches delivery.

## The three gaps, now closed

All three were open when the plugin was first written. Each is implemented; what each one does and what it
still depends on is below.

### 1. The in-app copy — wired, and deliberately code-free

There is no server-side inbox table. The marketplace app builds its notification list from admin announcements
and from **push notifications that arrived on the device**, so the way to reach one buyer's inbox is to push to
that buyer. `Pokbon_App_Push_Endpoint::send_to_user()` already does exactly that, and `Pokbon_Delivery_Messages::inbox()`
now calls it.

**The delivery code is never in that message**, and that is the important part. Because the inbox is fed by push,
anything in the body is readable on a locked screen by whoever is holding the phone — which is the one thing the
code exists to prevent. Two defences:

- The API sends a code-free body: *"Your rider is at your door. Your delivery code has been sent to you by SMS."*
- This plugin overrides the body itself for any message whose purpose is about the code, so a change on either
  side cannot quietly reintroduce one.

The smoke test asserts it (`scripts/smoke-phase0.mjs`): the in-app body must not contain the code, nor any
six-digit run. An earlier draft guarded by pattern-matching digits instead; it was removed because it would
have mangled `GH₵1600.00` and `order #537313` while still being fooled by wording it did not anticipate.

### 2. Vendor pickup — four sources, best first

`Pokbon_Delivery_Orders::pickup_for()` now tries, in order:

1. the `pokbon_delivery_vendor_pickup` filter, for anything bespoke;
2. **the vendor's own WCFM store location** — `wcfmmp_profile_settings`, then the legacy `_wcfmmp_*` keys, with
   `_wcfmmp_lat`/`_wcfmmp_lng` falling back to `_wcfm_lat`/`_wcfm_lng`. These are the same keys the marketplace's
   own pickup-points endpoint reads, so a vendor who appears as a pickup point in the app is dispatchable here;
3. a configured pickup point belonging to that vendor, which is curated by the owner and so more trustworthy
   than a vendor-entered pin;
4. the default pickup in Settings, correct for a single-warehouse start.

Every candidate must have coordinates inside a served zone **and** a callable Ghana number, or it is discarded.
A job with the wrong pickup is worse than no job, because a rider is dispatched on it.

### 3. "Cash on delivery" — written, tested, and behind a switch

`Pokbon_Delivery_Labels` renames the method to **Pay on delivery** across the website checkout, every
WooCommerce email, the order-received page and the admin, including orders already placed. The gateway id stays
`cod`: changing it would strand every historic order and every report that groups by payment method.

It is **off by default**, because until riders exist there really is cash and the checkout would be lying. Turn
it on in Settings the day the first rider goes out.

The mobile app carries its own copy of the string and cannot be relabelled from the server. The settings screen
names both files so the app release ships in the same week:
`src/constants/config.ts` (around line 113) and `src/screens/checkout/CheckoutScreen.tsx` (around line 123).

## Verified

- The PHP and Node HMAC of the same body and secret are byte-identical, so the two services authenticate.
- The PHP and Node distance calculations agree to the metre, so a pin resolves to the same zone at checkout as
  it does at job creation. A price quoted to a buyer cannot differ from the price the job is created with.
- Every PHP file lints clean.

**Not verified:** the plugin has never been installed on a WordPress site. Nothing here has run against real
WooCommerce orders, a real vendor record or a real Paystack account.
