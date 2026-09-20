<?php
/**
 * The doorstep payment. PRD § 2 and § 11, contract § 4b.
 *
 * The rider never touches money. When the delivery code matches, the Delivery
 * API asks this class to push a mobile-money approval to the buyer's own
 * phone. The buyer approves, Paystack confirms, and only then does the rider's
 * screen say PAID and the goods change hands.
 *
 * WHY THE CHARGE API AND NOT INITIALIZE
 * `initialize` returns a checkout URL — it needs a browser, and there isn't
 * one at a doorstep. `charge` with a mobile_money payload asks the telco to
 * push the approval prompt straight to the handset, which is the interaction
 * the owner described.
 *
 * HOW A SECOND CHARGE IS PREVENTED
 * The contract says one intent per job and never a double charge. Paystack
 * will not accept a repeated reference, and a mobile-money prompt expires, so
 * a retry genuinely needs a new Paystack transaction. The protection is
 * therefore not a reused reference but an ORDER OF OPERATIONS: before every
 * retry the previous reference is verified with Paystack, and if it already
 * succeeded no new charge is created and the job is reported paid. One
 * logical intent (`pkbd_<order>`) spans however many Paystack attempts it
 * takes, and the buyer can only ever be captured once.
 *
 * HOW THE MONEY GETS RECONCILED
 * The reference is written to `_pokbon_paystack_reference`, the same meta the
 * marketplace plugin's own webhook looks orders up by. So `charge.success`
 * lands on the existing, proven handler, which marks the order paid. This
 * class then hooks `woocommerce_payment_complete` and tells the Delivery API.
 * No change to the marketplace webhook, and any path that pays the order —
 * webhook, app verify, or an admin marking it paid — reaches delivery.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Payments {

	const META_INTENT     = '_pokbon_delivery_payment_intent';
	const META_STATUS     = '_pokbon_delivery_payment_status';
	const META_JOB_ID     = '_pokbon_delivery_job_id';
	const META_PROMPTS    = '_pokbon_delivery_prompt_count';
	const META_PAID_AT    = '_pokbon_paid_on_delivery';
	const META_PAYMENT_REF = '_pokbon_delivery_payment_ref';

	const API_BASE = 'https://api.paystack.co';

	public static function bootstrap(): void {
		// Whatever marks the order paid, delivery hears about it.
		add_action( 'woocommerce_payment_complete', [ self::class, 'on_payment_complete' ], 20, 1 );
	}

	// ─── credentials ────────────────────────────────────────────────────────

	private static function secret_key(): string {
		if ( ! class_exists( 'Pokbon_App_Payments_Endpoint' ) ) {
			return '';
		}
		$settings = Pokbon_App_Payments_Endpoint::get_settings();
		return (string) ( $settings['mode'] === 'live'
			? $settings['live_secret_key']
			: $settings['test_secret_key'] );
	}

	private static function currency(): string {
		if ( ! class_exists( 'Pokbon_App_Payments_Endpoint' ) ) {
			return 'GHS';
		}
		$settings = Pokbon_App_Payments_Endpoint::get_settings();
		return (string) ( $settings['currency'] ?? 'GHS' );
	}

	// ─── the prompt ─────────────────────────────────────────────────────────

	/**
	 * Push (or re-push) the approval prompt for an order.
	 *
	 * $reason is 'arrival' for the first prompt and 'retry' when the rider
	 * taps Send prompt again. Returns the intent shape the API expects, or a
	 * WP_Error the API turns into "prompt failed, try again".
	 */
	public static function prompt( int $order_id, string $job_id, string $reason = 'arrival' ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'wc_missing', 'WooCommerce is not active.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', 'Order not found.' );
		}

		$secret = self::secret_key();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'Paystack is not configured in POKBON App → Payments.' );
		}

		$intent_id = self::intent_id( $order_id );

		// Already paid? Say so and charge nothing. This is the guard that
		// makes a retry safe, and it runs before anything else.
		$already = self::confirm_existing_payment( $order, $secret );
		if ( is_array( $already ) ) {
			return $already;
		}

		$amount_minor = (int) round( ( (float) $order->get_total() ) * 100 );
		if ( $amount_minor <= 0 ) {
			return new WP_Error( 'bad_amount', 'This order has no amount to collect.' );
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( (string) $order->get_billing_phone() );
		if ( $phone === '' ) {
			return new WP_Error( 'no_phone', 'The order has no usable Ghana mobile number for the prompt.' );
		}
		$provider = Pokbon_Delivery_Messages::momo_provider( $phone );
		if ( $provider === null ) {
			// Guessing the network sends the prompt nowhere and the rider waits
			// at the door for something that will never arrive.
			return new WP_Error( 'unknown_network', 'That number does not map to a known mobile-money network. Use pay by link instead.' );
		}

		$prompts = (int) $order->get_meta( self::META_PROMPTS );
		$max     = (int) Pokbon_Delivery_Settings::get( 'payment_max_prompts' );
		if ( $max > 0 && $prompts >= $max ) {
			return new WP_Error( 'too_many_prompts', sprintf( 'The prompt has already been sent %d times. Use pay by link, or mark the delivery failed.', $prompts ) );
		}

		// A fresh Paystack reference per attempt, rotated exactly the way the
		// marketplace's own re-init does — so the webhook can still reconcile
		// a charge completed against the previous one.
		$reference = 'pkb_' . $order_id . '_' . wp_generate_password( 8, false );
		$previous  = (string) $order->get_meta( '_pokbon_paystack_reference' );

		$response = self::paystack( 'POST', '/charge', [
			'email'        => self::buyer_email( $order ),
			'amount'       => $amount_minor,
			'currency'     => self::currency(),
			'reference'    => $reference,
			'mobile_money' => [
				'phone'    => $phone,
				'provider' => $provider,
			],
			'metadata'     => [
				'pokbon_delivery_job_id' => $job_id,
				'pokbon_order_id'        => $order_id,
				'channel'                => 'pay_on_delivery',
			],
		], $secret );

		if ( is_wp_error( $response ) ) {
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
				'order_id' => $order_id,
				'job_id'   => $job_id,
				'stage'    => 'charge',
				'error'    => $response->get_error_message(),
			] );
			return $response;
		}

		if ( $previous !== '' && $previous !== $reference ) {
			$order->update_meta_data( '_pokbon_paystack_reference_prev', $previous );
		}
		$order->update_meta_data( '_pokbon_paystack_reference', $reference );
		$order->update_meta_data( '_pokbon_paystack_channel', 'mobile_money' );
		$order->update_meta_data( self::META_INTENT, $intent_id );
		$order->update_meta_data( self::META_JOB_ID, $job_id );
		$order->update_meta_data( self::META_STATUS, 'pending' );
		$order->update_meta_data( self::META_PROMPTS, $prompts + 1 );
		$order->save();

		Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PROMPTED, [
			'order_id'  => $order_id,
			'job_id'    => $job_id,
			'reason'    => $reason,
			'attempt'   => $prompts + 1,
			'provider'  => $provider,
			'reference' => $reference,
		] );

		$wait = (int) Pokbon_Delivery_Settings::get( 'payment_wait_minutes' );

		return [
			'intentId'  => $intent_id,
			'status'    => 'pending',
			'amount'    => round( $amount_minor / 100, 2 ),
			'currency'  => self::currency(),
			'expiresAt' => gmdate( 'c', time() + max( 1, $wait ) * MINUTE_IN_SECONDS ),
			'reference' => $reference,
		];
	}

	/**
	 * Current state of a job's payment, for the API's poll.
	 *
	 * Asks Paystack rather than trusting local state: the webhook may simply
	 * be late, and a rider should not be told "failed" for a payment that in
	 * fact went through.
	 */
	public static function status( string $intent_id ) {
		$order = self::order_for_intent( $intent_id );
		if ( ! $order ) {
			return new WP_Error( 'no_intent', 'No order carries that payment intent.' );
		}

		$paid_at = (string) $order->get_meta( self::META_PAID_AT );
		if ( $paid_at !== '' ) {
			return [
				'intentId'  => $intent_id,
				'status'    => 'paid',
				'reference' => (string) $order->get_meta( self::META_PAYMENT_REF ),
				'paidAt'    => $paid_at,
				'amount'    => round( ( (float) $order->get_total() ), 2 ),
				'currency'  => self::currency(),
			];
		}

		$secret = self::secret_key();
		if ( $secret !== '' ) {
			$confirmed = self::confirm_existing_payment( $order, $secret );
			if ( is_array( $confirmed ) ) {
				return $confirmed;
			}
		}

		return [
			'intentId'  => $intent_id,
			'status'    => (string) ( $order->get_meta( self::META_STATUS ) ?: 'pending' ),
			'amount'    => round( ( (float) $order->get_total() ), 2 ),
			'currency'  => self::currency(),
			'expiresAt' => null,
		];
	}

	/**
	 * Pay by link (§ 11 step 2): someone else settles it from their own phone.
	 *
	 * A hosted checkout is right here — the payer is not the buyer, may be on
	 * any network, and may want to use a card.
	 */
	public static function pay_by_link( string $intent_id, string $phone ) {
		$order = self::order_for_intent( $intent_id );
		if ( ! $order ) {
			return new WP_Error( 'no_intent', 'No order carries that payment intent.' );
		}
		$secret = self::secret_key();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'Paystack is not configured.' );
		}

		$already = self::confirm_existing_payment( $order, $secret );
		if ( is_array( $already ) ) {
			return $already;
		}

		$to = Pokbon_Delivery_Messages::normalise_ghana_phone( $phone );
		if ( $to === '' ) {
			return new WP_Error( 'bad_phone', 'That is not a valid Ghana mobile number.' );
		}

		$order_id  = $order->get_id();
		$reference = 'pkb_' . $order_id . '_' . wp_generate_password( 8, false );
		$previous  = (string) $order->get_meta( '_pokbon_paystack_reference' );

		$response = self::paystack( 'POST', '/transaction/initialize', [
			'email'     => self::buyer_email( $order ),
			'amount'    => (int) round( ( (float) $order->get_total() ) * 100 ),
			'currency'  => self::currency(),
			'reference' => $reference,
			'metadata'  => [
				'pokbon_delivery_job_id' => (string) $order->get_meta( self::META_JOB_ID ),
				'pokbon_order_id'        => $order_id,
				'channel'                => 'pay_on_delivery_link',
			],
		], $secret );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$url = (string) ( $response['data']['authorization_url'] ?? '' );
		if ( $url === '' ) {
			return new WP_Error( 'no_link', 'Paystack did not return a payment link.' );
		}

		if ( $previous !== '' && $previous !== $reference ) {
			$order->update_meta_data( '_pokbon_paystack_reference_prev', $previous );
		}
		$order->update_meta_data( '_pokbon_paystack_reference', $reference );
		$order->save();

		$sent = Pokbon_Delivery_Messages::sms(
			$to,
			sprintf(
				'POKBON: pay GH₵%s for order #%d here: %s',
				number_format( (float) $order->get_total(), 2 ),
				$order_id,
				$url
			),
			[ 'purpose' => 'pay_by_link', 'order_id' => $order_id ]
		);

		if ( ! $sent ) {
			return new WP_Error( 'sms_failed', 'The payment link could not be sent by SMS.' );
		}

		Pokbon_Delivery_Audit::log( 'delivery.pay_by_link_sent', [
			'order_id'  => $order_id,
			'reference' => $reference,
		] );

		return [ 'sent' => true, 'intentId' => $intent_id, 'reference' => $reference ];
	}

	// ─── reconciliation ─────────────────────────────────────────────────────

	/**
	 * The order was paid, by whatever route. Tell the Delivery API, and write
	 * the meta that reclassifies a `cod` order as digitally settled.
	 *
	 * `_pokbon_paid_on_delivery` is what plugin 1.20.2's `settled_digitally()`
	 * reads. Writing it is the whole reason the commission engine stops
	 * creating a vendor debt and starts counting the gateway fee.
	 */
	public static function on_payment_complete( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$job_id = (string) $order->get_meta( self::META_JOB_ID );
		if ( $job_id === '' ) {
			return; // Not a delivery-collected payment. Nothing to do.
		}
		if ( (string) $order->get_meta( self::META_PAID_AT ) !== '' ) {
			return; // Already recorded. Webhook and verify both land here.
		}

		$reference = (string) $order->get_meta( '_pokbon_paystack_reference' );
		$paid_at   = gmdate( 'c' );

		$order->update_meta_data( self::META_PAID_AT, $paid_at );
		$order->update_meta_data( self::META_PAYMENT_REF, $reference );
		$order->update_meta_data( self::META_STATUS, 'paid' );
		$order->save();

		Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PAID, [
			'order_id'  => $order->get_id(),
			'job_id'    => $job_id,
			'reference' => $reference,
		] );

		$result = Pokbon_Delivery_API_Client::report_payment( $job_id, [
			'intentId'  => self::intent_id( (int) $order->get_id() ),
			'status'    => 'paid',
			'reference' => $reference,
			'paidAt'    => $paid_at,
		] );

		if ( is_wp_error( $result ) ) {
			// The money is in and the meta is written; only the rider's screen
			// is behind. The API polls its own payment status, so this
			// recovers by itself — but it must be visible if it does not.
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
				'order_id' => $order->get_id(),
				'job_id'   => $job_id,
				'stage'    => 'notify_api',
				'error'    => $result->get_error_message(),
			] );
		}
	}

	/**
	 * Ask Paystack whether the reference already on the order succeeded.
	 *
	 * Returns the paid intent array when it did, null when it did not. This is
	 * the single guard that makes every retry path safe.
	 */
	private static function confirm_existing_payment( $order, string $secret ) {
		foreach ( [ '_pokbon_paystack_reference', '_pokbon_paystack_reference_prev' ] as $meta_key ) {
			$reference = (string) $order->get_meta( $meta_key );
			if ( $reference === '' ) {
				continue;
			}

			$verify = self::paystack( 'GET', '/transaction/verify/' . rawurlencode( $reference ), null, $secret );
			if ( is_wp_error( $verify ) ) {
				continue; // Cannot confirm; fall through and do not assume paid.
			}

			$status = (string) ( $verify['data']['status'] ?? '' );
			if ( $status !== 'success' ) {
				continue;
			}

			// Amount must match, exactly as the marketplace webhook insists.
			$paid     = (int) ( $verify['data']['amount'] ?? 0 );
			$expected = (int) round( ( (float) $order->get_total() ) * 100 );
			if ( abs( $paid - $expected ) > 1 ) {
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
					'order_id' => $order->get_id(),
					'stage'    => 'amount_mismatch',
					'paid'     => $paid,
					'expected' => $expected,
				] );
				continue;
			}

			// Make sure WooCommerce agrees, which fires on_payment_complete().
			if ( ! $order->is_paid() ) {
				$order->payment_complete( $reference );
			}

			return [
				'intentId'  => self::intent_id( (int) $order->get_id() ),
				'status'    => 'paid',
				'reference' => $reference,
				'paidAt'    => (string) ( $verify['data']['paid_at'] ?? gmdate( 'c' ) ),
				'amount'    => round( $paid / 100, 2 ),
				'currency'  => self::currency(),
			];
		}

		return null;
	}

	// ─── plumbing ───────────────────────────────────────────────────────────

	/** One stable logical intent per order, however many attempts it takes. */
	public static function intent_id( int $order_id ): string {
		return 'pkbd_' . $order_id;
	}

	private static function order_for_intent( string $intent_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		if ( strpos( $intent_id, 'pkbd_' ) === 0 ) {
			$order_id = (int) substr( $intent_id, 5 );
			if ( $order_id > 0 ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					return $order;
				}
			}
		}
		$orders = wc_get_orders( [ 'limit' => 1, 'meta_key' => self::META_INTENT, 'meta_value' => $intent_id ] );
		return $orders[0] ?? null;
	}

	private static function buyer_email( $order ): string {
		$email = (string) $order->get_billing_email();
		if ( $email !== '' ) {
			return $email;
		}
		// Paystack requires one. A per-order alias keeps their dashboard
		// meaningful without inventing a person's address.
		return 'order-' . $order->get_id() . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** One place that talks to Paystack, with a bounded timeout. */
	private static function paystack( string $method, string $path, ?array $body, string $secret ) {
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache',
			],
		];
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'paystack_unreachable', 'Paystack unreachable: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['message'] ?? 'Unknown error' ) : 'Unparseable response';
			return new WP_Error( 'paystack_error', sprintf( 'Paystack %s %s → HTTP %d: %s', $method, $path, $code, $message ) );
		}

		return $data;
	}
}
