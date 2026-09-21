<?php
/**
 * What the Delivery API calls. Contract § 4b and § 5.
 *
 * Every route here is signed, timestamped and idempotent on the event id.
 * None of them authenticate a person: the caller is a service, and the only
 * thing proving it is the shared secret. So `permission_callback` verifies the
 * signature and nothing reaches a handler without it.
 *
 * NO ROUTE HERE RETURNS A 5xx. EasyWP and Cloudflare both replace a 5xx from
 * the origin with their own HTML error page, so a carefully worded JSON error
 * arrives at the Delivery API as "HTTP 502: <!DOCTYPE html>" and the real
 * reason is lost. That cost an hour on the first live test. Anything this
 * plugin knows about — a switched-off SMS gateway, a refused charge — is a
 * 409 carrying JSON, which passes through untouched.
 *
 * The delivery code never appears in any route in this file. It is generated
 * by the API, carried to the buyer inside an SMS body this plugin relays
 * without inspecting, and compared by the API. This plugin could not leak it
 * because it never holds it.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_REST {

	public static function register_routes(): void {
		$ns = POKBON_DELIVERY_REST_NAMESPACE;

		register_rest_route( $ns, '/delivery/messages/sms', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'send_sms' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/messages/inbox', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'send_inbox' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/payment/prompt', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'payment_prompt' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/payment/(?P<intent>[A-Za-z0-9_\-]+)', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'payment_status' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/payment/(?P<intent>[A-Za-z0-9_\-]+)/link', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'payment_link' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/callback', [
			'methods'             => 'POST',
			'callback'            => [ self::class, 'status_callback' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );

		register_rest_route( $ns, '/delivery/settings', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'settings' ],
			'permission_callback' => [ self::class, 'verify' ],
		] );
	}

	/**
	 * Signature gate. Stashes the event id on the request so handlers can be
	 * idempotent without verifying twice.
	 */
	public static function verify( WP_REST_Request $request ) {
		$result = Pokbon_Delivery_Signature::verify( $request );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'pokbon_delivery_unauthorised',
				$result->get_error_message(),
				[ 'status' => 401 ]
			);
		}

		$request->set_param( '_event_id', $result );
		return true;
	}

	// ─── messages ───────────────────────────────────────────────────────────

	public static function send_sms( WP_REST_Request $request ) {
		return self::once( $request, static function ( array $body ) {
			$to      = (string) ( $body['to'] ?? '' );
			$message = (string) ( $body['message'] ?? '' );

			if ( $to === '' || $message === '' ) {
				return new WP_Error( 'bad_request', 'Both to and message are required.', [ 'status' => 400 ] );
			}

			$reason = Pokbon_Delivery_Messages::sms_with_reason( $to, $message, [
				'purpose' => (string) ( $body['purpose'] ?? 'delivery' ),
				'job_id'  => (string) ( $body['jobId'] ?? '' ),
			] );

			// A failed code SMS leaves a rider at a door, so the reason travels
			// back rather than a bare failure.
			if ( $reason !== '' ) {
				Pokbon_Delivery_Audit::log( 'delivery.sms_failed', [
					'purpose' => (string) ( $body['purpose'] ?? '' ),
					'reason'  => $reason,
				] );
				return new WP_Error( 'sms_failed', $reason, [ 'status' => 409 ] );
			}

			return [ 'sent' => true ];
		} );
	}

	public static function send_inbox( WP_REST_Request $request ) {
		return self::once( $request, static function ( array $body ) {
			$user_id = (int) ( $body['buyerUserId'] ?? 0 );
			$title   = (string) ( $body['title'] ?? '' );
			$text    = (string) ( $body['body'] ?? '' );

			if ( $user_id <= 0 || $title === '' ) {
				return new WP_Error( 'bad_request', 'buyerUserId and title are required.', [ 'status' => 400 ] );
			}

			// 200 either way: SMS has already carried anything that matters,
			// and the inbox is a convenience copy (see class-messages.php).
			$delivered = Pokbon_Delivery_Messages::inbox( $user_id, $title, $text, [
				'purpose'  => (string) ( $body['purpose'] ?? 'delivery' ),
				'job_id'   => (string) ( $body['jobId'] ?? '' ),
				'order_id' => (string) ( $body['orderId'] ?? '' ),
			] );

			return [ 'delivered' => $delivered ];
		} );
	}

	// ─── the doorstep payment ───────────────────────────────────────────────

	public static function payment_prompt( WP_REST_Request $request ) {
		return self::once( $request, static function ( array $body ) {
			$job_id   = (string) ( $body['jobId'] ?? '' );
			$order_id = (int) ( $body['orderId'] ?? 0 );
			$reason   = (string) ( $body['reason'] ?? 'arrival' );

			if ( $job_id === '' || $order_id <= 0 ) {
				return new WP_Error( 'bad_request', 'jobId and orderId are required.', [ 'status' => 400 ] );
			}

			$intent = Pokbon_Delivery_Payments::prompt( $order_id, $job_id, $reason === 'retry' ? 'retry' : 'arrival' );

			if ( is_wp_error( $intent ) ) {
				return new WP_Error(
					$intent->get_error_code(),
					$intent->get_error_message(),
					[ 'status' => 409 ]
				);
			}

			return $intent;
		} );
	}

	public static function payment_status( WP_REST_Request $request ) {
		$status = Pokbon_Delivery_Payments::status( (string) $request->get_param( 'intent' ) );

		if ( is_wp_error( $status ) ) {
			return new WP_Error( $status->get_error_code(), $status->get_error_message(), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $status );
	}

	public static function payment_link( WP_REST_Request $request ) {
		return self::once( $request, static function ( array $body ) use ( $request ) {
			$phone = (string) ( $body['phone'] ?? '' );
			if ( $phone === '' ) {
				return new WP_Error( 'bad_request', 'phone is required.', [ 'status' => 400 ] );
			}

			$result = Pokbon_Delivery_Payments::pay_by_link( (string) $request->get_param( 'intent' ), $phone );

			if ( is_wp_error( $result ) ) {
				return new WP_Error( $result->get_error_code(), $result->get_error_message(), [ 'status' => 409 ] );
			}
			return $result;
		} );
	}

	// ─── status callbacks ───────────────────────────────────────────────────

	/**
	 * Every job status change. Contract § 5.
	 *
	 * Always answers 200 once the signature checks out, even for an order this
	 * install does not recognise. The API retries non-2xx, and retrying a
	 * callback for a deleted order forever helps nobody.
	 */
	public static function status_callback( WP_REST_Request $request ) {
		return self::once( $request, static function ( array $body ) {
			$job_id = (string) ( $body['jobId'] ?? '' );
			$status = (string) ( $body['status'] ?? '' );

			if ( $job_id === '' || $status === '' ) {
				return new WP_Error( 'bad_request', 'jobId and status are required.', [ 'status' => 400 ] );
			}

			$applied = false;
			if ( (string) ( $body['source'] ?? '' ) === 'MARKETPLACE' || ! empty( $body['orderId'] ) ) {
				$applied = Pokbon_Delivery_Orders::apply_status( $job_id, $body );
			}

			/**
			 * Anything else that wants to react to a delivery status — the
			 * vendor screens, a dashboard widget, an SMS this plugin does not
			 * own — hangs off here rather than editing this method.
			 */
			do_action( 'pokbon_delivery_status_changed', $status, $body, $applied );

			return [ 'ok' => true, 'applied' => $applied ];
		} );
	}

	// ─── settings pull ──────────────────────────────────────────────────────

	/** The API pulls this on boot when it is configured to. */
	public static function settings( WP_REST_Request $request ) {
		return rest_ensure_response( Pokbon_Delivery_Settings::sync_payload() );
	}

	// ─── idempotency ────────────────────────────────────────────────────────

	/**
	 * Run a handler exactly once per event id.
	 *
	 * Deliveries retry. A replayed payment prompt must not create a second
	 * charge and a replayed callback must not notify a buyer twice, so the
	 * first answer is stored and returned verbatim thereafter.
	 *
	 * Errors are NOT stored: a prompt that failed because Paystack was briefly
	 * unreachable should be retryable, and storing that failure would freeze
	 * the job for the life of the event id.
	 */
	private static function once( WP_REST_Request $request, callable $handler ) {
		$event_id = (string) $request->get_param( '_event_id' );

		if ( $event_id !== '' ) {
			$stored = Pokbon_Delivery_Signature::replayed( $event_id );
			if ( $stored !== null ) {
				return rest_ensure_response( $stored );
			}
		}

		$body = $request->get_json_params();
		$body = is_array( $body ) ? $body : [];

		$result = $handler( $body );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $event_id !== '' ) {
			Pokbon_Delivery_Signature::remember( $event_id, $result );
		}

		return rest_ensure_response( $result );
	}
}
