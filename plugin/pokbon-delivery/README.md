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

## Known gaps, stated rather than hidden

1. **The in-app inbox copy is not wired.** The marketplace's push system is an audience broadcast tool with a
   draft workflow, and a one-to-one delivery code does not belong in it. SMS always carries the code, so no
   buyer is left without one. To wire it, hook `pokbon_delivery_inbox_message` and return true from
   `pokbon_delivery_inbox_delivered`.
2. **Vendor pickup coordinates.** Vendor store locations are vendor-plugin specific, so the plugin asks through
   the `pokbon_delivery_vendor_pickup` filter and falls back to the default pickup zone in Settings. That is
   correct for a single-warehouse start and needs wiring for real vendor stores.
3. **"Cash on delivery" still says cash.** The rename to *Pay on delivery* is a marketplace-app change and must
   land with the rollout, not before — today there really is cash.

## Verified

- The PHP and Node HMAC of the same body and secret are byte-identical, so the two services authenticate.
- The PHP and Node distance calculations agree to the metre, so a pin resolves to the same zone at checkout as
  it does at job creation. A price quoted to a buyer cannot differ from the price the job is created with.
