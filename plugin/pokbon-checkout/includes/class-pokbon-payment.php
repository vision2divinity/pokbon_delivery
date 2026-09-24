<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Payment {
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Make sure the WC COD gateway is available even if not enabled in WC settings,
		// when the admin has enabled it in our plugin settings.
		add_filter( 'woocommerce_available_payment_gateways', array( $this, 'ensure_cod_gateway' ) );
	}

	public function ensure_cod_gateway( $gateways ) {
		$enabled = (array) get_option( 'pokbon_checkout_payment_methods', array() );
		if ( empty( $enabled['cod'] ) ) {
			return $gateways;
		}
		// We don't force-add the WC_Gateway_COD class — we just use 'cod' as our payment method id
		// and process the order ourselves. This filter is left for future extension.
		return $gateways;
	}

	public static function is_gateway_available( $gateway_id ) {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( ! isset( $gateways[ $gateway_id ] ) ) {
			return false;
		}
		$gateway = $gateways[ $gateway_id ];
		return method_exists( $gateway, 'is_available' ) ? $gateway->is_available() : ( 'yes' === $gateway->enabled );
	}

	/**
	 * @return array{ result:string, redirect:string }
	 */
	public static function process( $order_id, $method ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return array( 'result' => 'failure', 'redirect' => '' );
		}

		if ( 'cod' === $method ) {
			return self::process_cod( $order );
		}
		if ( 'ussd' === $method ) {
			return self::process_ussd( $order );
		}
		if ( 'paystack' === $method ) {
			return self::process_paystack( $order );
		}
		return array( 'result' => 'failure', 'redirect' => '' );
	}

	/**
	 * USSD config lives on the MOBILE plugin's option so web + mobile share ONE
	 * source of truth. We deliberately do NOT mirror it into a web-side option.
	 *
	 * @return array{code:string, merchantName:string}
	 */
	private static function get_ussd_config() {
		$ussd = get_option( 'pokbon_app_ussd_payment', array() );
		return array(
			'code'         => is_array( $ussd ) ? (string) ( $ussd['code'] ?? '' ) : '',
			'merchantName' => is_array( $ussd ) ? (string) ( $ussd['merchantName'] ?? '' ) : '',
		);
	}

	/**
	 * USSD is only offered when the mobile app's merchant config has a dial code set.
	 */
	public static function is_ussd_available() {
		$ussd = self::get_ussd_config();
		return '' !== $ussd['code'];
	}

	/**
	 * Merchant-editable USSD instructions shown in the checkout popup (mirrors the
	 * mobile app's UssdPaymentScreen instructions). {code}/{merchant} placeholders
	 * are swapped for the live values from the shared pokbon_app_ussd_payment option.
	 * Falls back to a sensible default when the admin hasn't customized it.
	 *
	 * Output is HTML — the option is sanitized with wp_kses_post on save, and the
	 * default string is escaped before the allowed <strong> wrapper is added, so
	 * callers can echo the return value directly.
	 *
	 * @return string
	 */
	public static function get_ussd_instructions_html() {
		$ussd     = self::get_ussd_config();
		$code     = $ussd['code'];
		$merchant = '' !== $ussd['merchantName'] ? $ussd['merchantName'] : __( 'POKBON', 'pokbon-checkout' );
		$custom   = (string) get_option( 'pokbon_checkout_ussd_instructions', '' );

		if ( '' !== trim( $custom ) ) {
			$replaced = str_replace(
				array( '{code}', '{merchant}' ),
				array( $code, $merchant ),
				$custom
			);
			return wp_kses_post( $replaced );
		}

		return wp_kses_post( sprintf(
			/* translators: 1: USSD dial code, 2: merchant name */
			__( 'Dial %1$s and pay to %2$s, then place your order. We\'ll confirm your payment and process your order.', 'pokbon-checkout' ),
			'<strong>' . esc_html( $code ) . '</strong>',
			'<strong>' . esc_html( $merchant ) . '</strong>'
		) );
	}

	private static function process_cod( WC_Order $order ) {
		$order->update_status( apply_filters( 'pokbon_checkout_cod_initial_status', 'processing' ), __( 'Cash on Delivery selected. Awaiting payment on delivery.', 'pokbon-checkout' ) );
		// wc_reduce_stock_levels handles the "stock already reduced" flag so it's safe to call once.
		wc_reduce_stock_levels( $order->get_id() );

		// Empty the cart only after we've successfully claimed the order.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	/**
	 * USSD is like COD: no gateway object, just an order status + notes. Unlike COD it
	 * NEVER auto-advances past on-hold — a human has to confirm the dial-in payment
	 * actually landed (mirrors the mobile app's apply_ussd_method()).
	 */
	private static function process_ussd( WC_Order $order ) {
		$ussd = self::get_ussd_config();
		$code = $ussd['code'];
		$merchant = $ussd['merchantName'];

		if ( '' === $code ) {
			// Config disappeared between page render and submit (e.g. admin cleared it
			// in the mobile settings mid-checkout). Fail loudly rather than silently
			// accept an order we can't tell the customer how to pay for.
			$order->update_status( 'failed', __( 'USSD payment is not configured.', 'pokbon-checkout' ) );
			return array( 'result' => 'failure', 'redirect' => '' );
		}

		$order->set_payment_method( 'pokbon_ussd' );
		$order->set_payment_method_title( sprintf( __( 'USSD payment (%s)', 'pokbon-checkout' ), $code ) );
		$order->update_meta_data( '_pokbon_ussd_code', $code );
		$order->update_meta_data( '_pokbon_ussd_merchant', $merchant );
		$order->update_meta_data( '_pokbon_payment_channel', 'ussd' );

		// Customer-visible note (mirrors mobile's apply_ussd_method()).
		$order->add_order_note(
			sprintf(
				__( 'Customer chose to pay via USSD %1$s to %2$s. Awaiting admin verification of payment.', 'pokbon-checkout' ),
				$code,
				$merchant ? $merchant : __( 'POKBON', 'pokbon-checkout' )
			),
			1 /* note_type: customer */
		);
		// Admin-facing note.
		$order->add_order_note(
			sprintf(
				__( 'USSD payment selected at checkout. Verify the transaction and flip to "Processing" once confirmed. Dial code: %1$s · Merchant: %2$s', 'pokbon-checkout' ),
				$code,
				$merchant ? $merchant : '(unset)'
			)
		);

		// Never auto-paid — drop to on-hold so admin manually confirms receipt.
		$order->update_status( apply_filters( 'pokbon_checkout_ussd_initial_status', 'on-hold' ), __( 'Awaiting USSD payment verification.', 'pokbon-checkout' ) );

		// wc_reduce_stock_levels handles the "stock already reduced" flag so it's safe to call once.
		wc_reduce_stock_levels( $order->get_id() );

		// Empty the cart only after we've successfully claimed the order.
		WC()->cart->empty_cart();

		return array(
			'result'   => 'success',
			'redirect' => $order->get_checkout_order_received_url(),
		);
	}

	private static function process_paystack( WC_Order $order ) {
		$gateways = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
		if ( ! isset( $gateways['paystack'] ) ) {
			$order->update_status( 'failed', __( 'Paystack gateway not available.', 'pokbon-checkout' ) );
			return array( 'result' => 'failure', 'redirect' => '' );
		}
		$gateway = $gateways['paystack'];
		if ( ! method_exists( $gateway, 'process_payment' ) ) {
			$order->update_status( 'failed', __( 'Paystack gateway is incompatible.', 'pokbon-checkout' ) );
			return array( 'result' => 'failure', 'redirect' => '' );
		}

		try {
			$result = $gateway->process_payment( $order->get_id() );
		} catch ( Throwable $e ) {
			$order->add_order_note( 'Paystack process_payment exception: ' . $e->getMessage() );
			return array( 'result' => 'failure', 'redirect' => '' );
		}

		if ( is_array( $result ) && 'success' === ( $result['result'] ?? '' ) ) {
			// Do NOT empty the cart yet — the Paystack plugin empties it on successful callback
			// after the customer pays. Emptying here would make the order-pay endpoint think the
			// cart is empty and bounce the user back to /checkout/, wiping the form.
			return array(
				'result'   => 'success',
				'redirect' => isset( $result['redirect'] ) ? (string) $result['redirect'] : $order->get_checkout_payment_url( true ),
			);
		}

		return array( 'result' => 'failure', 'redirect' => '' );
	}
}
