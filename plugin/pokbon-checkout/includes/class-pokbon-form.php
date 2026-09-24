<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Form {
	private static $instance = null;

	const SHORTCODE  = 'pokbon_checkout';
	const NONCE_NAME = 'pokbon_checkout_nonce';
	const HONEYPOT   = 'pokbon_url_field';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		if ( ! is_singular() ) {
			return;
		}
		global $post;
		if ( ! $post || ! has_shortcode( $post->post_content, self::SHORTCODE ) ) {
			return;
		}

		wp_enqueue_style(
			'pokbon-checkout',
			POKBON_CHECKOUT_URL . 'assets/css/checkout.css',
			array(),
			POKBON_CHECKOUT_VERSION
		);

		wp_register_script(
			'pokbon-checkout',
			POKBON_CHECKOUT_URL . 'assets/js/checkout.js',
			array(),
			POKBON_CHECKOUT_VERSION,
			true
		);

		$rates = Pokbon_Shipping::get_region_rates();
		$slim  = array();
		foreach ( $rates as $code => $row ) {
			$slim[ $code ] = (float) $row['rate'];
		}

		wp_localize_script( 'pokbon-checkout', 'PokbonCheckout', array(
			'rates'         => $slim,
			'freeThreshold' => Pokbon_Shipping::get_free_threshold(),
			'currency'      => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '₵',
		) );

		wp_enqueue_script( 'pokbon-checkout' );
	}

	public function render_shortcode( $atts = array(), $content = '' ) {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return '<div class="pokbon-checkout-error">WooCommerce is not active.</div>';
		}

		// When WC redirects back to /checkout/order-received/{id}/ or /checkout/order-pay/{id}/,
		// defer to WC's default templates so the user sees the thank-you / payment-retry pages.
		if ( function_exists( 'is_wc_endpoint_url' ) ) {
			if ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) {
				return do_shortcode( '[woocommerce_checkout]' );
			}
		}

		// Make sure WC session/cart are available on a non-checkout page.
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			return $this->render_empty_cart();
		}

		WC()->cart->calculate_totals();

		$flash = $this->consume_flash();

		ob_start();
		include POKBON_CHECKOUT_DIR . 'templates/checkout-form.php';
		return ob_get_clean();
	}

	private function render_empty_cart() {
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		ob_start();
		?>
		<div class="pokbon-checkout pokbon-checkout--empty">
			<h2><?php esc_html_e( 'Your cart is empty', 'pokbon-checkout' ); ?></h2>
			<p><?php esc_html_e( 'Add some items to your cart before checking out.', 'pokbon-checkout' ); ?></p>
			<p><a class="pokbon-button" href="<?php echo esc_url( $shop_url ); ?>"><?php esc_html_e( 'Continue Shopping', 'pokbon-checkout' ); ?></a></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Read and clear flash messages set by the handler before redirecting back to the form.
	 */
	private function consume_flash() {
		$out = array( 'errors' => array(), 'old' => array() );
		if ( ! WC()->session ) {
			return $out;
		}
		$flash = WC()->session->get( 'pokbon_checkout_flash' );
		if ( is_array( $flash ) ) {
			$out['errors'] = isset( $flash['errors'] ) && is_array( $flash['errors'] ) ? $flash['errors'] : array();
			$out['old']    = isset( $flash['old'] ) && is_array( $flash['old'] ) ? $flash['old'] : array();
			WC()->session->set( 'pokbon_checkout_flash', null );
		}
		return $out;
	}

	/**
	 * Helper used by the template: previously submitted value, or default.
	 */
	public static function old( $field, $default = '', $old = array() ) {
		if ( isset( $old[ $field ] ) ) {
			return (string) $old[ $field ];
		}

		// For logged-in customers, fall back to their saved address.
		if ( is_user_logged_in() ) {
			$customer = WC()->customer;
			if ( $customer ) {
				$map = array(
					'billing_first_name'  => 'billing_first_name',
					'billing_last_name'   => 'billing_last_name',
					'billing_company'     => 'billing_company',
					'billing_address_1'   => 'billing_address_1',
					'billing_address_2'   => 'billing_address_2',
					'billing_city'        => 'billing_city',
					'billing_state'       => 'billing_state',
					'billing_postcode'    => 'billing_postcode',
					'billing_phone'       => 'billing_phone',
					'billing_email'       => 'billing_email',
				);
				if ( isset( $map[ $field ] ) ) {
					$getter = 'get_' . $map[ $field ];
					if ( method_exists( $customer, $getter ) ) {
						$value = (string) $customer->$getter();
						if ( $value !== '' ) {
							return $value;
						}
					}
				}
			}
		}

		return (string) $default;
	}
}
