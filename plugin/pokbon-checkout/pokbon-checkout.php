<?php
/**
 * Plugin Name:       POKBON Checkout
 * Plugin URI:        https://pokbongroup.com
 * Description:       Custom server-rendered checkout that bypasses the WooCommerce Store API. Provides Paystack + Cash on Delivery, region-based shipping for Ghana, store pickup, and an editable admin settings page.
 * Version:           1.3.2
 * Author:            POKBON IT Solutions
 * License:           GPL-2.0-or-later
 * Text Domain:       pokbon-checkout
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * WC requires at least: 7.0
 * WC tested up to:   10.7
 *
 * == Changelog ==
 * 1.1.0 (2026-09-22) — delivery is priced by area, not only by region.
 *   A flat rate per region charged the same for a run across the street and a
 *   run across the city, while POKBON Delivery priced the rider's route zone
 *   to zone. The two never met: an order could take GH¢30 from a buyer and
 *   cost GH¢40 to deliver, and nothing said so.
 *   The buyer now picks their area and pays what that route is priced at.
 *   Three hooks do it, so this plugin needs no knowledge of zones or matrices:
 *     pokbon_checkout_delivery_zones  — the areas available in a region
 *     pokbon_checkout_shipping_rate   — the price for a chosen area
 *     pokbon_checkout_order_created   — the area, handed to the delivery side
 *   None of it is required. With no filters attached the regional rate is used
 *   exactly as before, and a region with no priced areas shows no selector, so
 *   this cannot make an address unsellable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'POKBON_CHECKOUT_VERSION', '1.3.2' );
define( 'POKBON_CHECKOUT_FILE', __FILE__ );
define( 'POKBON_CHECKOUT_DIR', plugin_dir_path( __FILE__ ) );
define( 'POKBON_CHECKOUT_URL', plugin_dir_url( __FILE__ ) );
define( 'POKBON_CHECKOUT_BASENAME', plugin_basename( __FILE__ ) );

require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-installer.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-security.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-settings.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-shipping.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-form.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-handler.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-payment.php';
require_once POKBON_CHECKOUT_DIR . 'includes/class-pokbon-checkout.php';

register_activation_hook( __FILE__, array( 'Pokbon_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Pokbon_Installer', 'deactivate' ) );

add_action( 'plugins_loaded', static function () {
	// WooCommerce must be active. Otherwise display an admin notice and bail.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', static function () {
			echo '<div class="notice notice-error"><p><strong>POKBON Checkout</strong> requires WooCommerce to be active.</p></div>';
		} );
		return;
	}

	load_plugin_textdomain( 'pokbon-checkout', false, dirname( POKBON_CHECKOUT_BASENAME ) . '/languages' );

	Pokbon_Checkout::instance()->boot();
}, 20 );

// HPOS compatibility declaration (WooCommerce 8+).
add_action( 'before_woocommerce_init', static function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );
