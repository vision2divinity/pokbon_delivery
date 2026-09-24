<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Handler {
	private static $instance = null;

	const ACTION = 'pokbon_checkout_place_order';
	const RATE_LIMIT_PER_HOUR = 10;
	const MAX_FIELD_LEN = 250;
	const MAX_NOTES_LEN = 1000;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_' . self::ACTION,        array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	public function handle() {
		nocache_headers();

		// 1. CSRF: verify nonce. Nonce is the canonical CSRF defence — referer headers are stripped
		//    by many security stacks (Wordfence, browser strict-origin policies, Cloudflare proxies)
		//    so checking referer caused legitimate submissions to fail.
		$nonce = isset( $_POST[ Pokbon_Form::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( $_POST[ Pokbon_Form::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) ) {
			$this->fail( __( 'Your session has expired. Please refresh the page and try again.', 'pokbon-checkout' ), 'nonce_failed' );
		}

		// 2. Honeypot — bot fills hidden field, human doesn't.
		if ( ! empty( $_POST[ Pokbon_Form::HONEYPOT ] ) ) {
			Pokbon_Security::audit( '', 0, 'honeypot_triggered' );
			$this->fail( __( 'Submission blocked.', 'pokbon-checkout' ), 'honeypot' );
		}

		// 4. Rate limit by IP.
		if ( Pokbon_Security::is_rate_limited() ) {
			$this->fail( __( 'Too many checkout attempts. Please wait an hour and try again.', 'pokbon-checkout' ), 'rate_limited' );
		}
		Pokbon_Security::increment_rate();

		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->fail( __( 'WooCommerce is not active.', 'pokbon-checkout' ), 'no_wc' );
		}

		// admin-post.php runs in is_admin() context. WC's WooCommerce::init_cart() is
		// hooked only when ! is_admin() || DOING_AJAX, so WC()->cart and WC()->session are
		// null here. wc_load_cart() is the official WC helper that bootstraps both for
		// non-frontend contexts (REST, CLI, admin-post). Without this the cart appears empty.
		if ( function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		if ( ! WC()->cart ) {
			$this->fail( __( 'Cart could not be loaded. Please refresh and try again.', 'pokbon-checkout' ), 'no_cart' );
		}

		if ( WC()->cart->is_empty() ) {
			$this->fail( __( 'Your cart is empty. Please add items before checking out.', 'pokbon-checkout' ), 'empty_cart' );
		}

		// 5. Sanitize input.
		$input = $this->collect_input();

		// 6. Validate.
		$errors = $this->validate( $input );
		if ( ! empty( $errors ) ) {
			$this->fail_back( $errors, $input );
		}

		// 7. Calculate shipping with server-trusted values.
		WC()->cart->calculate_totals();
		$subtotal = (float) WC()->cart->get_subtotal();
		$shipping = Pokbon_Shipping::calculate(
			$input['delivery_type'],
			$input['shipping_state'],
			$subtotal,
			$input['delivery_zone'] ?? ''
		);
		if ( empty( $shipping['valid'] ) ) {
			$this->fail_back( array( $shipping['error'] ?? __( 'Invalid delivery selection.', 'pokbon-checkout' ) ), $input );
		}

		// 8. Build the order.
		try {
			$order_id = $this->create_order( $input, $shipping, $subtotal );
		} catch ( Throwable $e ) {
			Pokbon_Security::audit( $input['billing_email'], 0, 'order_create_exception', $e->getMessage() );
			$this->fail_back( array( __( 'We could not create your order. Please try again.', 'pokbon-checkout' ) ), $input );
			return;
		}

		if ( ! $order_id ) {
			$this->fail_back( array( __( 'We could not create your order. Please try again.', 'pokbon-checkout' ) ), $input );
		}

		Pokbon_Security::audit( $input['billing_email'], $order_id, 'order_created' );

		// 9. Route payment.
		$result = Pokbon_Payment::process( $order_id, $input['payment_method'] );

		if ( ! is_array( $result ) || empty( $result['result'] ) ) {
			Pokbon_Security::audit( $input['billing_email'], $order_id, 'payment_route_failed' );
			$this->fail_back( array( __( 'Payment could not be initialised. Please try a different method.', 'pokbon-checkout' ) ), $input );
		}

		if ( 'success' === $result['result'] && ! empty( $result['redirect'] ) ) {
			Pokbon_Security::audit( $input['billing_email'], $order_id, 'redirect_to_payment', $result['redirect'] );
			// Paystack may return an off-site hosted-checkout URL (checkout.paystack.com).
			// wp_safe_redirect blocks unknown hosts and silently lands on /wp-admin/. Allow
			// Paystack domains explicitly for this single redirect.
			add_filter( 'allowed_redirect_hosts', function ( $hosts ) {
				return array_merge( (array) $hosts, array(
					'checkout.paystack.com',
					'standard.paystack.co',
					'paystack.com',
					'api.paystack.co',
				) );
			} );
			wp_safe_redirect( esc_url_raw( $result['redirect'] ) );
			exit;
		}

		Pokbon_Security::audit( $input['billing_email'], $order_id, 'payment_returned_failure', wp_json_encode( $result ) );
		$this->fail_back( array( __( 'Payment failed. Please try again.', 'pokbon-checkout' ) ), $input );
	}

	private function collect_input() {
		$f = function ( $key, $default = '', $maxlen = self::MAX_FIELD_LEN ) {
			$value = isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default;
			$value = is_string( $value ) ? sanitize_text_field( $value ) : '';
			if ( strlen( $value ) > $maxlen ) {
				$value = substr( $value, 0, $maxlen );
			}
			return $value;
		};

		$email = isset( $_POST['billing_email'] ) ? sanitize_email( wp_unslash( $_POST['billing_email'] ) ) : '';

		$notes = isset( $_POST['order_notes'] ) ? wp_unslash( $_POST['order_notes'] ) : '';
		$notes = sanitize_textarea_field( $notes );
		if ( strlen( $notes ) > self::MAX_NOTES_LEN ) {
			$notes = substr( $notes, 0, self::MAX_NOTES_LEN );
		}

		return array(
			'billing_email'        => $email,
			'shipping_first_name'  => $f( 'shipping_first_name' ),
			'shipping_last_name'   => $f( 'shipping_last_name' ),
			'shipping_phone'       => $f( 'shipping_phone' ),
			'shipping_address_1'   => $f( 'shipping_address_1' ),
			'shipping_address_2'   => $f( 'shipping_address_2' ),
			'pokbon_landmark'      => $f( 'pokbon_landmark' ),
			'shipping_city'        => $f( 'shipping_city' ),
			'shipping_state'       => strtoupper( $f( 'shipping_state', '', 4 ) ),
			// The area the buyer picked, when POKBON Delivery offered a list.
			// Empty is normal and means "price it by region as before".
			'delivery_zone'        => strtoupper( $f( 'delivery_zone', '', 40 ) ),
			'delivery_type'        => Pokbon_Shipping::normalize_type( $f( 'delivery_type', '', 16 ) ),
			'payment_method'       => $f( 'payment_method', '', 24 ),
			'order_notes'          => $notes,
		);
	}

	private function validate( $in ) {
		$errors = array();

		if ( empty( $in['billing_email'] ) || ! is_email( $in['billing_email'] ) ) {
			$errors[] = __( 'A valid email address is required.', 'pokbon-checkout' );
		}
		if ( empty( $in['shipping_first_name'] ) ) {
			$errors[] = __( 'First name is required.', 'pokbon-checkout' );
		}
		if ( empty( $in['shipping_last_name'] ) ) {
			$errors[] = __( 'Last name is required.', 'pokbon-checkout' );
		}
		if ( empty( $in['shipping_phone'] ) || ! $this->is_valid_phone( $in['shipping_phone'] ) ) {
			$errors[] = __( 'A valid phone number is required.', 'pokbon-checkout' );
		}
		if ( empty( $in['shipping_address_1'] ) ) {
			$errors[] = __( 'Street address is required.', 'pokbon-checkout' );
		}
		if ( empty( $in['shipping_city'] ) ) {
			$errors[] = __( 'City is required.', 'pokbon-checkout' );
		}

		$valid_regions = Pokbon_Shipping::regions_for_select();
		if ( empty( $in['shipping_state'] ) || ! isset( $valid_regions[ $in['shipping_state'] ] ) ) {
			$errors[] = __( 'Please select a valid region.', 'pokbon-checkout' );
		}

		if ( empty( $in['delivery_type'] ) ) {
			$errors[] = __( 'Please choose a delivery type.', 'pokbon-checkout' );
		}

		/*
		 * Refuse an address POKBON cannot reach, when the shop has asked us to.
		 *
		 * The browser already hides the area picker and disables the button for
		 * an unserved region, but that is a courtesy, not a rule: JavaScript
		 * can be off, a form can be replayed, and a select can be edited. The
		 * decision that costs a sale has to be made here as well as there.
		 *
		 * Home delivery only. Store pickup needs no rider and no coverage, and
		 * refusing a collection because nobody rides to that region would be
		 * absurd.
		 */
		if ( ( $in['delivery_type'] ?? '' ) === 'home' && ! empty( $in['shipping_state'] ) ) {
			$region = (string) $in['shipping_state'];
			$areas  = apply_filters( 'pokbon_delivery_zone_areas_by_region', array(), array( $region ), null );
			$here   = is_array( $areas[ $region ] ?? null ) ? $areas[ $region ] : array();

			if ( empty( $here ) ) {
				// Nothing priced in this region. Refuse only if the shop has
				// asked us to; otherwise the flat regional rate stands, as it
				// always has.
				if ( (bool) apply_filters( 'pokbon_delivery_coverage_required', false ) ) {
					$errors[] = __( 'We do not deliver to this area yet. Please choose another location.', 'pokbon-checkout' );
				}
			} else {
				/*
				 * The region HAS priced areas, so one must be chosen.
				 *
				 * This form carries `novalidate` — deliberately, so the plugin
				 * renders its own errors — which means the `required` the script
				 * puts on the select does nothing at all. And the placeholder
				 * option is what is selected when the page loads. So the DEFAULT
				 * state of the form was "no area", and the default price was the
				 * flat regional rate: against the shipped distance bands a
				 * twenty-kilometre run charges GH¢30 and pays the rider GH¢40.
				 * Every buyer who did not happen to open the dropdown cost money,
				 * and nothing anywhere recorded that they had not.
				 *
				 * The browser is asked politely and the server insists.
				 */
				$chosen = strtoupper( trim( (string) ( $in['delivery_zone'] ?? '' ) ) );
				if ( $chosen === '' ) {
					$errors[] = __( 'Please choose the delivery area closest to you.', 'pokbon-checkout' );
				} else {
					/*
					 * And it must be an area of the region they picked.
					 *
					 * The page hands the browser every region's areas with their
					 * prices, so without this a buyer could post the cheapest code
					 * in the country and be charged for a Circle run to Kumasi.
					 * The shipping label reads the region, not the zone, so the
					 * order would have looked entirely ordinary.
					 */
					$valid = false;
					foreach ( $here as $area ) {
						if ( strtoupper( (string) ( $area['code'] ?? '' ) ) === $chosen ) {
							$valid = true;
							break;
						}
					}
					if ( ! $valid ) {
						$errors[] = __( 'That delivery area is not in the region you selected. Please choose again.', 'pokbon-checkout' );
					}
				}
			}
		}

		$enabled = (array) get_option( 'pokbon_checkout_payment_methods', array( 'paystack' => 1, 'cod' => 1 ) );
		$ok_methods = array();
		if ( ! empty( $enabled['paystack'] ) && Pokbon_Payment::is_gateway_available( 'paystack' ) ) {
			$ok_methods[] = 'paystack';
		}
		if ( ! empty( $enabled['cod'] ) ) {
			$ok_methods[] = 'cod';
		}
		if ( ! empty( $enabled['ussd'] ) && Pokbon_Payment::is_ussd_available() ) {
			$ok_methods[] = 'ussd';
		}
		if ( empty( $in['payment_method'] ) || ! in_array( $in['payment_method'], $ok_methods, true ) ) {
			$errors[] = __( 'Please choose a valid payment method.', 'pokbon-checkout' );
		}

		return $errors;
	}

	private function is_valid_phone( $value ) {
		// Accepts +233 followed by 9 digits, or 10 digits starting with 0, allowing spaces, dashes, parens.
		$digits = preg_replace( '/\D+/', '', $value );
		$len = strlen( $digits );
		return $len >= 9 && $len <= 15;
	}

	/**
	 * @return int Order ID, or 0 on failure.
	 */
	private function create_order( $in, $shipping, $subtotal ) {
		$order = wc_create_order( array(
			'status'      => 'pending',
			'customer_id' => get_current_user_id(),
			'created_via' => 'pokbon_checkout',
		) );
		if ( is_wp_error( $order ) ) {
			throw new Exception( $order->get_error_message() );
		}

		// Cart items.
		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product = $cart_item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$item_id = $order->add_product(
				$product,
				(int) $cart_item['quantity'],
				array(
					'variation' => isset( $cart_item['variation'] ) ? $cart_item['variation'] : array(),
					'totals'    => array(
						'subtotal'     => isset( $cart_item['line_subtotal'] ) ? $cart_item['line_subtotal'] : 0,
						'subtotal_tax' => isset( $cart_item['line_subtotal_tax'] ) ? $cart_item['line_subtotal_tax'] : 0,
						'total'        => isset( $cart_item['line_total'] ) ? $cart_item['line_total'] : 0,
						'tax'          => isset( $cart_item['line_tax'] ) ? $cart_item['line_tax'] : 0,
					),
				)
			);
			if ( $item_id && ! empty( $cart_item['line_tax_data'] ) && is_array( $cart_item['line_tax_data'] ) ) {
				$line_item = $order->get_item( $item_id );
				if ( $line_item ) {
					$line_item->set_taxes( $cart_item['line_tax_data'] );
					$line_item->save();
				}
			}
		}

		// Cart-level fees (vendors / WCFM may add them).
		foreach ( WC()->cart->get_fees() as $fee ) {
			$item = new WC_Order_Item_Fee();
			$item->set_props( array(
				'name'      => $fee->name,
				'tax_class' => $fee->taxable ? $fee->tax_class : 0,
				'amount'    => $fee->amount,
				'total'     => $fee->amount,
				'total_tax' => $fee->tax,
				'taxes'     => array( 'total' => isset( $fee->tax_data ) ? $fee->tax_data : array() ),
			) );
			$order->add_item( $item );
		}

		// Coupons applied to the cart.
		foreach ( WC()->cart->get_coupons() as $code => $coupon ) {
			$order->apply_coupon( $coupon );
		}

		// Addresses.
		$billing  = $this->build_billing_address( $in );
		$shipping_addr = $this->build_shipping_address( $in );
		$order->set_address( $billing, 'billing' );
		$order->set_address( $shipping_addr, 'shipping' );

		// Shipping line.
		$shipping_item = new WC_Order_Item_Shipping();
		$shipping_item->set_method_title( $shipping['label'] );
		$shipping_item->set_method_id( 'pokbon_' . $in['delivery_type'] );
		$shipping_item->set_total( (float) $shipping['amount'] );
		$shipping_item->set_taxes( array( 'total' => array() ) );
		$order->add_item( $shipping_item );

		// Payment method label/id (final processing happens in Pokbon_Payment::process).
		if ( 'paystack' === $in['payment_method'] ) {
			$payment_label = __( 'Pay Online (Paystack)', 'pokbon-checkout' );
		} elseif ( 'ussd' === $in['payment_method'] ) {
			$payment_label = __( 'Pay by USSD', 'pokbon-checkout' );
		} else {
			$payment_label = __( 'Cash on Delivery', 'pokbon-checkout' );
		}
		$order->set_payment_method( $in['payment_method'] );
		$order->set_payment_method_title( $payment_label );

		// Customer note.
		if ( ! empty( $in['order_notes'] ) ) {
			$order->set_customer_note( $in['order_notes'] );
		}

		// Custom delivery_type meta — visible in admin and on email.
		$order->update_meta_data( '_pokbon_delivery_type', $in['delivery_type'] );
		// Read by POKBON Delivery and sent to the rider, whose app searches for
		// it before the address when the order has no map pin.
		$landmark = trim( (string) ( $in['pokbon_landmark'] ?? '' ) );
		if ( $landmark !== '' ) {
			$order->update_meta_data( '_pokbon_delivery_landmark', sanitize_text_field( $landmark ) );
		}

		$order->update_meta_data( '_pokbon_customer_ip', Pokbon_Security::get_ip() );
		$order->update_meta_data( '_pokbon_customer_ua', substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 240 ) );

		$order->calculate_totals( true );
		$order->save();

		// Fire WCFM-compatible hooks so vendor splits, emails, stock reductions still run.
		$posted_data = array(
			'billing_email'      => $in['billing_email'],
			'billing_first_name' => $billing['first_name'],
			'billing_last_name'  => $billing['last_name'],
			'shipping_state'     => $in['shipping_state'],
			'payment_method'     => $in['payment_method'],
		);
		/*
		 * Tell POKBON Delivery which area the buyer chose.
		 *
		 * A website order has never carried a map pin, so a dispatcher had to
		 * read the address and pick a zone. When the buyer has already said,
		 * that guess is unnecessary — and the delivery is priced and
		 * dispatched from the same answer.
		 */
		if ( ! empty( $in['delivery_zone'] ) ) {
			do_action( 'pokbon_checkout_order_created', $order->get_id(), $in['delivery_zone'] );
		}

		do_action( 'woocommerce_checkout_order_created', $order );
		do_action( 'woocommerce_checkout_order_processed', $order->get_id(), $posted_data, $order );

		return $order->get_id();
	}

	private function build_billing_address( $in ) {
		$regions = Pokbon_Shipping::regions_for_select();
		return array(
			'first_name' => $in['shipping_first_name'],
			'last_name'  => $in['shipping_last_name'],
			'company'    => '',
			'email'      => $in['billing_email'],
			'phone'      => $in['shipping_phone'],
			'address_1'  => $in['shipping_address_1'],
			'address_2'  => $in['shipping_address_2'],
			'city'       => $in['shipping_city'],
			'state'      => $in['shipping_state'],
			'postcode'   => '',
			'country'    => 'GH',
		);
	}

	private function build_shipping_address( $in ) {
		return array(
			'first_name' => $in['shipping_first_name'],
			'last_name'  => $in['shipping_last_name'],
			'company'    => '',
			'phone'      => $in['shipping_phone'],
			'address_1'  => $in['shipping_address_1'],
			'address_2'  => $in['shipping_address_2'],
			'city'       => $in['shipping_city'],
			'state'      => $in['shipping_state'],
			'postcode'   => '',
			'country'    => 'GH',
		);
	}

	private function fail( $message, $reason ) {
		Pokbon_Security::audit( '', 0, $reason, $message );
		// Always redirect back to checkout with the error flashed in session — never leave the
		// customer staring at /wp-admin/admin-post.php.
		if ( WC()->session ) {
			WC()->session->set( 'pokbon_checkout_flash', array(
				'errors' => array( $message ),
				'old'    => array(),
			) );
		}
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = wc_get_checkout_url();
		}
		wp_safe_redirect( $back );
		exit;
	}

	private function fail_back( $errors, $input = array() ) {
		if ( WC()->session ) {
			WC()->session->set( 'pokbon_checkout_flash', array(
				'errors' => array_values( array_unique( array_filter( (array) $errors ) ) ),
				'old'    => $input,
			) );
		}
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = wc_get_checkout_url();
		}
		wp_safe_redirect( $back );
		exit;
	}
}
