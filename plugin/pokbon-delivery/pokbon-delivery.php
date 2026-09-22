<?php
/**
 * Plugin Name:       POKBON Delivery
 * Plugin URI:        https://pokbongroup.com
 * Description:       Rider dispatch for POKBON — zones and the delivery price matrix, rider approval, the live job board, and the cashless pay-on-delivery flow. Owns every setting, every payment and every message; the Delivery API owns riders, jobs and the delivery code.
 * Version:           0.5.5
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            POKBON
 * License:           GPL-2.0-or-later
 * Text Domain:       pokbon-delivery
 *
 * Companion to the POKBON Mobile App plugin, not a replacement. It reuses that
 * plugin's SMS gateway, secret vault and audit log when present, and degrades
 * to a clear admin notice when it is not.
 *
 * Requirements, § references throughout this plugin:
 *   pokbon-delivery/docs/PRD-pokbon-delivery-2026-09-20-draft3.md
 * Contract with the marketplace:
 *   pokbon_mobile_app/docs/DELIVERY_INTEGRATION_2026-09-20.md
 *
 * == Changelog ==
 * 0.5.5 — a refused charge no longer strands the rider.
 *   When Paystack would not create the mobile money charge at all, this
 *   returned the error and stopped. The rider was left at a door with a
 *   willing customer and nothing to offer: the only control on screen was
 *   "send the prompt again", which failed again for the same reason.
 *   Seen on 2026-09-22 — HTTP 400 "Charge attempted", from a number that had
 *   charged fine the previous day on live keys.
 *   A checkout link is a different call to a different endpoint and routinely
 *   works where a direct debit will not: the customer can pay by card, by
 *   another wallet, or by USSD on Paystack's own page. So the link is tried
 *   before giving up, and only a failure of both is reported as a failure.
 * 0.5.4 — the area selector never appeared, because the two region fields
 *   spoke different languages.
 *   A zone's region was free text, so it held "Greater Accra", while the
 *   checkout passes WooCommerce's state code, "AA". Comparing them matched
 *   nothing, every zone was filtered out, and checkout fell back to the flat
 *   regional rate — reintroducing the exact bug 0.5.3 was built to remove,
 *   one layer up and just as silently.
 *   A zone now matches on either the code or the region's name, and the
 *   Zones screen offers a list instead of a text box so this cannot drift
 *   again. Anything already typed still works and is flagged.
 *   The Zones table also shows what checkout would charge for each area, or
 *   why it cannot. "The selector is not showing" was a mystery that needed
 *   test orders to investigate; now the answer is on the screen where the
 *   zone is configured.
 * 0.5.3 — checkout can now price by area, from the matrix.
 *   The other half of the fee fix. POKBON Checkout asks three questions
 *   through filters and this plugin answers them: which areas a buyer in a
 *   region can choose, what that area costs for this cart, and — once the
 *   order exists — which area they picked.
 *   The price is the matrix price for pickup zone to chosen zone, summed per
 *   distinct collection point, so two vendors in different places is two rider
 *   fees while the buyer still sees one delivery line.
 *   Anything unpriced falls back to the regional rate rather than guessing:
 *   an area with no price is not offered, and one unpriced leg abandons the
 *   whole quote, because charging for a delivery POKBON cannot complete is
 *   worse than charging the old flat rate.
 *   The chosen area is stored as the dispatch zone, so a website order no
 *   longer needs a dispatcher to read the address and guess.
 * 0.5.2 — a job now records what the buyer actually paid.
 *   The buyer price on a job was taken from the zone matrix and labelled
 *   "quoted at checkout". It was not: checkout charges a flat rate per region
 *   while the matrix prices zone to zone. On #87619 the buyer paid GH¢30, the
 *   job recorded GH¢50, and the board showed a GH¢10 margin on a delivery
 *   that lost GH¢22. Recording revenue that never arrived is worse than
 *   recording a loss, because a loss can be acted on.
 *   The rider fee still comes from the matrix — that is what the route costs
 *   to ride, whoever ordered it. The buyer price is now read off the order.
 *   Freight and store pickup record zero: the shipping line on those orders
 *   is air or sea freight, and counting it as delivery revenue would flatter
 *   every one of them. The rider leg there is a cost POKBON absorbs.
 *   What the matrix would have charged is kept beside it, so Reconciliation
 *   shows the gap as a number rather than a suspicion.
 * 0.5.1 — a reconciliation screen, and the number it exists to show.
 *   Every other screen answers whether the software works. This one answers
 *   whether the business does. It puts what the customer was actually charged
 *   for delivery — which lives on the WooCommerce order and nowhere else —
 *   beside what the job was priced at and what the rider was paid.
 *   On 2026-09-21 a real delivery took GH¢30 at checkout, recorded GH¢50 of
 *   delivery revenue on the job, and paid GH¢52 out. The job board showed a
 *   GH¢10 margin; the truth was a GH¢22 loss. Rows where the two prices
 *   disagree are flagged in red and counted at the top, because until they
 *   agree every margin is wrong in the same direction.
 *   Only settled jobs count toward the money. Failed and returned trips carry
 *   a payout, because the rider still rode there.
 *   It also says plainly what it cannot yet tell you: whether a rider has
 *   actually been paid, what is owed to a sender on an outside job, and what
 *   refunds did.
 * 0.5.0 — a finished delivery closes the order, and the wording is yours.
 *   - A delivered job recorded meta and an order note and then left the order
 *     in processing, so a customer who had just signed for their parcel went
 *     on seeing it as ongoing in the app. The order now moves, to a status you
 *     choose under Settings — "choose for me" uses your Delivered status if
 *     you have one. Only POKBON orders; a courier job has no order behind it.
 *     Failed and returned deliveries are left alone by default, because a
 *     failed delivery is not a cancelled order and the money is a decision.
 *   - New Messages screen. Every message this service sends a person now
 *     lives there with its variables and an on/off switch, including the
 *     rider's sign-in code, which was hardcoded inside the delivery service
 *     where nobody running the business could reach it. Changing a word no
 *     longer needs a release of anything.
 *     Guards: a message that must carry {code} or {link} keeps its previous
 *     wording if you remove them, and an emptied box falls back to the
 *     shipped text rather than sending nothing.
 * 0.4.9 — a customer who pays should not be shown a JSON error.
 *   Paystack's dashboard had the Callback URL set to the webhook address, a
 *   POST-only route, so the browser redirect after a successful payment
 *   answered {"code":"rest_no_route"}. Somebody who has just handed over
 *   money and been shown an error has no way to know it worked.
 *   Payment links now carry their own callback_url — the order's own
 *   received page — so this holds whatever the dashboard says. The dashboard
 *   Callback URL should still be corrected; the Webhook URL was right.
 * 0.4.8 — read what Paystack said, and tell the customer how to pay.
 *   The charge reply carries data.status, which is the whole point of the
 *   call: pay_offline means a request reaches the handset and we wait, while
 *   send_otp means Paystack has texted a code that must be submitted back
 *   through the API. This code read none of it and recorded "pending" either
 *   way — so when Paystack asked for the code, nobody was listening, the
 *   customer held an SMS with nowhere to type it, and the charge sat pending
 *   until it expired.
 *   Now the status and Paystack's own display_text are recorded on the order
 *   and returned to the delivery service, and where the handset request
 *   cannot finish by itself a checkout link is created and sent in the same
 *   message. The link carries the order reference, so a payment made that way
 *   reconciles itself — which a USSD menu asking only for an amount cannot.
 *   create_payment_link() is split out of pay_by_link() so the prompt can
 *   include a link without texting the customer twice about the same money.
 *   Every message the plugin sends is now folded to what a GSM 7-bit SMS can
 *   carry, matching the delivery service character for character, because
 *   some of that text is Paystack's rather than ours.
 * 0.4.7 — 053 is MTN, and an SMS says GHS.
 *   A pay-on-delivery prompt to an 053 number was refused as an unknown
 *   mobile-money network, so the rider stood at the door waiting for a prompt
 *   that was never going to arrive. MTN runs 024, 025, 053, 054, 055 and 059;
 *   the table had every one except 053. Getting one of these wrong does not
 *   fail loudly, it fails at somebody's gate.
 *   The pay-by-link SMS now says GHS rather than GH₵: the cedi sign is not in
 *   the GSM 7-bit alphabet, so a customer was asked to approve "GH?150.00".
 * 0.4.6 — the dispatch notice tells the truth about what happened.
 *   The delivery service is idempotent per order and vendor, so a second
 *   dispatch can hand back the job that already exists. The screen reported
 *   that as "1 delivery job(s) created", which is how a re-dispatch that
 *   created nothing looked exactly like one that worked. It now says which
 *   happened.
 *   Paired with a fix on the delivery service: a cancelled job no longer
 *   satisfies that idempotency check, so cancel-and-re-dispatch — the
 *   workflow this screen offers — actually produces a new job.
 * 0.4.5 — a cancelled job no longer blocks re-dispatch.
 *   The dispatch screen refused any order that had ever been dispatched, and
 *   told you to cancel the existing job first — which did not help, because
 *   it was reading the order's list of job ids and not what became of them.
 *   Cancelling left the order permanently undispatchable.
 *   It now asks the delivery service for each job's status, ignores the
 *   cancelled ones, and names the live ones with their status instead of
 *   giving a count. If the service cannot be reached the block stays: not
 *   knowing whether a rider is already carrying the parcel is worse than
 *   waiting a minute.
 * 0.4.4 — the geography class was never loaded.
 *   includes/class-geo.php sat there unreferenced: the bootstrap never
 *   required it, so Pokbon_Delivery_Geo did not exist at runtime and every
 *   path that measured a distance or resolved a map pin died with
 *   "Class Pokbon_Delivery_Geo not found". That is the dispatch screen's
 *   white page from 0.4.2, the distance rung, and every order placed from
 *   the app, which is the only kind that carries a pin.
 *   It went unseen because the one screen that used it fataled blankly.
 *   0.4.3 made that screen say what broke; it named this in one reload.
 *   check-plugin-php-traps.mjs now fails if any file in includes/ is not
 *   required by the bootstrap.
 * 0.4.3 — found by dispatching a real order.
 *   - Cash on delivery was being sent to the riders as PREPAID with nothing
 *     to collect. is_paid() asks whether an order has reached a paid STATUS,
 *     and WooCommerce puts a cash order into processing the moment it is
 *     placed — so it answered true for exactly the orders where the cash is
 *     still owed. A rider would have handed the goods over for free. The
 *     question is now get_date_paid(), which is only set when money was
 *     actually taken.
 *   - A website buyer's delivery instructions never reached the rider. The
 *     app writes its note to its own meta key; what somebody types into
 *     "Order notes" at checkout lives in WooCommerce's customer note, and
 *     that is where the landmark and the gate colour actually are.
 *   - The rider is now given the shipping name and phone, falling back to
 *     billing. Whoever is at the door, not whoever paid.
 *   - The dispatch screen no longer dies with a white "critical error" page.
 *     Whatever breaks while pricing a route is now said on the screen, and
 *     logged, because a dispatcher who cannot dispatch needs to know why.
 * 0.4.2 — every order was claiming to be dispatched.
 *   `(array) $order->get_meta(...)` looks like it turns a missing value into
 *   an empty array. It does not: an absent meta value is '', and (array) ''
 *   is [''] — one element. `empty()` then said false and `count()` said 1,
 *   so an order that had never been near a rider rendered as "Dispatched.
 *   1 job(s), currently created", and the dispatch controls were hidden
 *   behind that claim. Found on the first real order.
 *   Job ids are now read in one place, Pokbon_Delivery_Orders::job_ids_for(),
 *   which drops the empties, and check-plugin-php-traps.mjs fails the build
 *   if that cast comes back.
 * 0.4.1 — a blank field leaves what is already there alone.
 *   0.4.0 wrote every field on every save, so re-adding a rider to correct
 *   one detail erased the licence, ID, mobile money number and next of kin
 *   that had been typed in before. Found the hard way, on a real record,
 *   within minutes of shipping. Blank now means "unchanged"; removing a
 *   detail is done on the rider's own screen, deliberately.
 *   Unticking the agreement box for somebody who has already accepted no
 *   longer rewrites their note to claim they have not.
 * 0.4.0 — onboard a rider yourself, from the Riders screen.
 *   Self-signup stays the normal path, but the first riders are recruited in
 *   person with their licence on the table, and telling them to go home and
 *   find an app loses them. An admin can now create the record directly; the
 *   rider still signs in with their own number and their own code and simply
 *   finds an account already waiting.
 *   - Keyed on the phone number, which is also the login identity, so adding
 *     somebody twice updates rather than creating a second account that could
 *     never sign in.
 *   - A suspended, rejected or departed rider keeps that status however the
 *     form is filled in. That decision belongs on their own screen, and the
 *     notice says so rather than silently ignoring the choice.
 *   - The contractor agreement can be recorded as signed on paper, against
 *     your name and the date. Left unticked, the rider accepts it in the app
 *     before their first shift — the safer default, not a delay.
 *   - An ID typed here is recorded as photographed only, never as verified.
 * 0.3.2 — website orders can be dispatched, and one nested form avoided.
 *   - WEBSITE ORDERS CARRY NO MAP PIN. Only the app's checkout captures one,
 *     so every web order would have been refused with "no coordinates". The
 *     dispatch screen now asks which zone to deliver into, pre-selected from
 *     the address text as a suggestion. That is not a degraded path: the
 *     ladder prices by zone, so a chosen zone prices exactly as a pin in it
 *     would, and the rider gets the typed address and the phone number, which
 *     is how POKBON has always worked.
 *   - The chosen zone is stored separately from the buyer's own pin, never
 *     over it. The marketplace keeps those read-only on purpose.
 *   - The order panel now LINKS to a dispatch screen instead of embedding a
 *     form. WooCommerce renders meta boxes inside the order edit form, so the
 *     button would have submitted that form instead — the same nested-form
 *     mistake that made "Save connection" run the connection test.
 *     check-plugin-forms.mjs now scans every plugin file and fails on a
 *     button() in anything that renders into another screen's form.
 *   - The dispatch screen refuses an order that already has jobs, rather than
 *     quietly creating duplicates.
 * 0.3.1 — dispatch on arrival, for the orders that cannot dispatch themselves.
 *   A POKBON Delivery panel now sits on the order screen with a "Send to
 *   riders now" button. It works whatever the order status, which is what a
 *   shipped-from-abroad order needs: it is paid for at checkout and lands
 *   weeks later, so the rider leg belongs to the day it arrives.
 *   - AUTOMATIC DISPATCH NOW SKIPS FREIGHT AND PICKUP. Creating the job on
 *     `processing` would have sent a rider to collect a parcel still on a
 *     ship. The order records why it was skipped instead of failing silently.
 *   - The panel shows what it WILL do before it does it: the method the buyer
 *     chose, the route, the price, which rung set it, and how many separate
 *     collections a multi-vendor order means. A button that only says
 *     "Dispatch" invites a click that sends a rider to the wrong place.
 *   - Registered for both the classic order screen and High-Performance Order
 *     Storage, so it does not vanish when the store switches.
 * 0.3.0 — the price ladder. Three rungs, first match wins:
 *     1. an exact route you priced      — Adenta to Kasoa
 *     2. the bands those zones belong to — Inner Accra to Outer Accra
 *     3. how far it actually is          — 0-5km, 5-10km, and so on
 *   A matrix alone does not survive growth: six zones is 36 cells, twenty is
 *   400, and every new area means pricing it against every existing one. With
 *   the ladder, adding a zone costs one decision (its band) and it prices the
 *   same day. Nothing is ever unpriced, and the exact matrix stays for routes
 *   that genuinely are special.
 *   - Pricing screen rebuilt around the three rungs, with a "try a route" box
 *     that says WHICH rung decided a price. A price nobody can explain is a
 *     price nobody trusts.
 *   - Zones gain a band. Bands and distance bands are seeded so a fresh
 *     install can price anything; the exact matrix is deliberately not seeded,
 *     because a seeded price is a guess presented as a decision.
 *   - Multi-vendor orders charge per vendor and show the buyer one total.
 *   - The rules exist in PHP and in the delivery service, so checkout can
 *     quote without a round trip. scripts/check-price-parity.mjs prices the
 *     same routes through both and fails on any disagreement — it caught the
 *     two labelling the same band "up to 2,000km" and "up to 2000km".
 *   This prices the RIDER LEG only. Ship-from-abroad freight stays
 *   weight-based and untouched; store pickup stays free.
 * 0.2.1 — three things the first live calls found:
 *   - FATAL FIXED. The in-app copy called Pokbon_App_Push_Endpoint::send_to_user().
 *     That method is on Pokbon_App_Push; the two classes live in the same file
 *     and only one has it, so every in-app message was a fatal error.
 *   - NO ROUTE RETURNS 5xx ANY MORE. EasyWP and Cloudflare replace a 5xx from
 *     the origin with their own HTML page, so a carefully worded JSON error
 *     reached the API as "HTTP 502: <!DOCTYPE html>" and the real reason was
 *     lost. Everything this plugin knows about is now a 409 carrying JSON.
 *   - SMS failures say WHY: switched off, no API key, or the gateway refused.
 *     "false" tells nobody anything when a rider is standing at a door.
 *   - NEW: what the rider is carrying, built from the order's own lines, plus
 *     a collection note. Both show on the job board and in the rider app.
 * 0.2.0 — the backend-first layer, and one real bug found on the first install:
 *   - NESTED FORMS FIXED. The Test and Push buttons sat inside the Save form.
 *     HTML has no nested forms, so the browser merged them and PHP took the
 *     last `pokbon_action` — "Save connection" quietly ran the connection test
 *     and saved nothing, with no error anywhere. `scripts/check-plugin-forms.mjs`
 *     now fails the build on this whole class of mistake.
 *   - NEW: Brand & app. Colours, every word a rider reads, feature toggles and
 *     the home screen are edited in wp-admin and served to the rider app at
 *     /wp-json/pokbon/v1/delivery/app-config. Changing a label is an admin
 *     edit, not an app release.
 *   - The theme ships as the marketplace app's own audited tokens, so both
 *     apps are one brand rather than two palettes that drift.
 *   - PHP 7.4 safe: array_is_list() (8.1) replaced with an explicit helper.
 * 0.1.0 — first cut. Zones, the price matrix, the settings that must never be
 *   hardcoded, the signed two-way contract with the Delivery API, the doorstep
 *   Paystack mobile-money charge, job creation when an order reaches
 *   processing, and the admin screens for riders and the live board.
 */

defined( 'ABSPATH' ) || exit;

define( 'POKBON_DELIVERY_VERSION', '0.1.0' );
define( 'POKBON_DELIVERY_FILE', __FILE__ );
define( 'POKBON_DELIVERY_DIR', plugin_dir_path( __FILE__ ) );
define( 'POKBON_DELIVERY_URL', plugin_dir_url( __FILE__ ) );

/**
 * The same namespace the marketplace plugin uses, on purpose.
 *
 * The contract fixes the API's callback targets at
 * /wp-json/pokbon/v1/delivery/... and the Delivery app's buyer routes sit
 * beside the marketplace's own. WordPress is happy with two plugins
 * registering different routes in one namespace.
 */
define( 'POKBON_DELIVERY_REST_NAMESPACE', 'pokbon/v1' );

/** Capability for every admin screen and destructive action here. */
define( 'POKBON_DELIVERY_CAP', 'manage_woocommerce' );

require_once POKBON_DELIVERY_DIR . 'includes/class-signature.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-settings.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-geo.php';

require_once POKBON_DELIVERY_DIR . 'includes/class-migrations.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-api-client.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-audit.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-messages.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-payments.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-orders.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-rest.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-labels.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-app-config.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-pricing.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-order-panel.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-checkout.php';

if ( is_admin() ) {
	require_once POKBON_DELIVERY_DIR . 'admin/class-admin.php';
}

/**
 * Migrations run on activation AND on load when the stored version trails.
 * A zip upload never fires activation — the lesson this repo keeps relearning.
 */
register_activation_hook( __FILE__, [ 'Pokbon_Delivery_Migrations', 'run' ] );
Pokbon_Delivery_Migrations::bootstrap();

add_action( 'rest_api_init', [ 'Pokbon_Delivery_REST', 'register_routes' ] );
add_action( 'rest_api_init', [ 'Pokbon_Delivery_App_Config', 'register_routes' ] );
add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Orders', 'bootstrap' ], 20 );
add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Payments', 'bootstrap' ], 20 );
add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Labels', 'bootstrap' ], 20 );
add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Checkout', 'bootstrap' ], 20 );

if ( is_admin() ) {
	add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Order_Panel', 'bootstrap' ], 20 );
}

if ( is_admin() ) {
	add_action( 'plugins_loaded', [ 'Pokbon_Delivery_Admin', 'bootstrap' ], 20 );
}

/**
 * Say so loudly if the marketplace plugin is missing.
 *
 * Without it there is no SMS gateway, no secret vault and no audit log, so the
 * delivery code cannot reach a buyer. Better a notice than a rider standing at
 * a door while a code goes nowhere.
 */
add_action( 'admin_notices', static function () {
	if ( class_exists( 'Pokbon_App_SMS' ) ) {
		return;
	}
	if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
		return;
	}
	echo '<div class="notice notice-error"><p><strong>POKBON Delivery:</strong> the POKBON Mobile App plugin is not active. '
		. 'Delivery codes are sent through its SMS gateway, so no buyer can receive one until it is. '
		. 'Activate it, then reload this page.</p></div>';
} );
