<?php
/**
 * Plugin Name:       POKBON Delivery
 * Plugin URI:        https://pokbongroup.com
 * Description:       Rider dispatch for POKBON — zones and the delivery price matrix, rider approval, the live job board, and the cashless pay-on-delivery flow. Owns every setting, every payment and every message; the Delivery API owns riders, jobs and the delivery code.
 * Version:           0.1.0
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
