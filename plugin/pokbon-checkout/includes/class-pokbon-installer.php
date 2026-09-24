<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Installer {

	const AUDIT_TABLE = 'pokbon_checkout_log';

	public static function activate() {
		self::create_audit_table();
		self::seed_defaults();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	private static function create_audit_table() {
		global $wpdb;
		$table   = $wpdb->prefix . self::AUDIT_TABLE;
		$charset = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			ip VARCHAR(45) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			email VARCHAR(190) NOT NULL DEFAULT '',
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			outcome VARCHAR(40) NOT NULL DEFAULT '',
			detail TEXT NULL,
			PRIMARY KEY (id),
			KEY ip (ip),
			KEY email (email),
			KEY created_at (created_at),
			KEY outcome (outcome)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	private static function seed_defaults() {
		// Region rates: Ghana, GHS. Editable from admin.
		if ( false === get_option( 'pokbon_checkout_region_rates' ) ) {
			update_option( 'pokbon_checkout_region_rates', self::default_region_rates() );
		}
		if ( false === get_option( 'pokbon_checkout_pickup_address' ) ) {
			update_option( 'pokbon_checkout_pickup_address', "Planet Close 44, Sowutuom, Accra.\nMon–Sat 9am – 6pm.\nBring your order ID and a valid photo ID." );
		}
		if ( false === get_option( 'pokbon_checkout_pickup_label' ) ) {
			update_option( 'pokbon_checkout_pickup_label', 'Store Pickup (Free)' );
		}
		if ( false === get_option( 'pokbon_checkout_home_delivery_label' ) ) {
			update_option( 'pokbon_checkout_home_delivery_label', 'Home Delivery' );
		}
		if ( false === get_option( 'pokbon_checkout_payment_methods' ) ) {
			update_option( 'pokbon_checkout_payment_methods', array(
				'paystack' => 1,
				'cod'      => 1,
			) );
		}
		if ( false === get_option( 'pokbon_checkout_free_threshold' ) ) {
			// 0 = disabled. Otherwise free home delivery once subtotal >= this.
			update_option( 'pokbon_checkout_free_threshold', 0 );
		}
		if ( false === get_option( 'pokbon_checkout_intro_text' ) ) {
			update_option( 'pokbon_checkout_intro_text', '' );
		}
		if ( false === get_option( 'pokbon_checkout_terms_text' ) ) {
			update_option( 'pokbon_checkout_terms_text', 'By proceeding with your purchase, you agree to our Terms and Conditions and Privacy Policy.' );
		}
	}

	public static function default_region_rates() {
		// Codes must match WooCommerce's Ghana state codes (i18n/states/GH.php).
		// Rates rise with distance from Accra.
		return array(
			'AA' => array( 'name' => 'Greater Accra',  'rate' => 30 ),
			'AH' => array( 'name' => 'Ashanti',        'rate' => 60 ),
			'CP' => array( 'name' => 'Central',        'rate' => 60 ),
			'EP' => array( 'name' => 'Eastern',        'rate' => 60 ),
			'WP' => array( 'name' => 'Western',        'rate' => 80 ),
			'WN' => array( 'name' => 'Western North',  'rate' => 80 ),
			'TV' => array( 'name' => 'Volta',          'rate' => 80 ),
			'BO' => array( 'name' => 'Bono',           'rate' => 80 ),
			'BE' => array( 'name' => 'Bono East',      'rate' => 80 ),
			'AF' => array( 'name' => 'Ahafo',          'rate' => 80 ),
			'BA' => array( 'name' => 'Brong-Ahafo',    'rate' => 80 ),
			'NP' => array( 'name' => 'Northern',       'rate' => 100 ),
			'SV' => array( 'name' => 'Savannah',       'rate' => 100 ),
			'OT' => array( 'name' => 'Oti',            'rate' => 100 ),
			'NE' => array( 'name' => 'North East',     'rate' => 100 ),
			'UE' => array( 'name' => 'Upper East',     'rate' => 100 ),
			'UW' => array( 'name' => 'Upper West',     'rate' => 100 ),
		);
	}
}
