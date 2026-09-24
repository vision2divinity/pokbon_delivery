<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pokbon_Checkout {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		Pokbon_Settings::instance();
		Pokbon_Security::instance();
		Pokbon_Shipping::instance();
		Pokbon_Form::instance();
		Pokbon_Handler::instance();
		Pokbon_Payment::instance();

		add_filter( 'plugin_action_links_' . POKBON_CHECKOUT_BASENAME, array( $this, 'plugin_action_links' ) );
	}

	public function plugin_action_links( $links ) {
		$settings = '<a href="' . esc_url( admin_url( 'admin.php?page=pokbon-checkout' ) ) . '">' . esc_html__( 'Settings', 'pokbon-checkout' ) . '</a>';
		array_unshift( $links, $settings );
		return $links;
	}
}
