<?php
/**
 * Plugin Name:       POKBON Delivery
 * Plugin URI:        https://pokbongroup.com
 * Description:       Rider dispatch for POKBON — zones and the delivery price matrix, rider approval, the live job board, and the cashless pay-on-delivery flow. Owns every setting, every payment and every message; the Delivery API owns riders, jobs and the delivery code.
 * Version:           0.2.1
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
require_once POKBON_DELIVERY_DIR . 'includes/class-migrations.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-api-client.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-audit.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-messages.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-payments.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-orders.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-rest.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-labels.php';
require_once POKBON_DELIVERY_DIR . 'includes/class-app-config.php';

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
