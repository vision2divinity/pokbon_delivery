<?php
/**
 * Calls out to the Delivery API. Signed, short-timeout, never fatal.
 *
 * The API owns riders, jobs, offers and the delivery code. This plugin owns
 * settings, money and messages. Everything crossing between them goes through
 * here so there is one place that signs, one place that times out, and one
 * place that decides what a failure means.
 *
 * TIMEOUTS ARE DELIBERATELY SHORT. Every one of these runs inside a PHP worker
 * on shared hosting, and the July 2026 sizing note records the payment path
 * being starved when workers queued. A delivery service that is slow must not
 * be able to hold a worker long enough to hurt checkout, so an unreachable API
 * fails fast and the caller decides.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_API_Client {

	/** Reads and ordinary writes. */
	const TIMEOUT = 8;

	/** Job creation runs during an order transition; shorter still. */
	const TIMEOUT_ORDER_PATH = 5;

	public static function is_configured(): bool {
		return Pokbon_Delivery_Settings::api_base_url() !== ''
			&& Pokbon_Delivery_Settings::shared_secret() !== '';
	}

	public static function get( string $path, array $query = [] ) {
		if ( ! empty( $query ) ) {
			$path .= ( strpos( $path, '?' ) === false ? '?' : '&' ) . http_build_query( $query );
		}
		return self::request( 'GET', $path, null, self::TIMEOUT );
	}

	public static function post( string $path, array $body, int $timeout = self::TIMEOUT, string $event_id = '' ) {
		return self::request( 'POST', $path, $body, $timeout, $event_id );
	}

	/**
	 * Returns the decoded body on success, or a WP_Error. Never throws, never
	 * echoes, never dies: callers are order transitions and admin pages.
	 */
	private static function request( string $method, string $path, ?array $body, int $timeout, string $event_id = '' ) {
		$base = Pokbon_Delivery_Settings::api_base_url();
		if ( $base === '' ) {
			return new WP_Error( 'not_configured', 'The Delivery API URL is not set. POKBON Delivery → Settings.' );
		}
		$secret = Pokbon_Delivery_Settings::shared_secret();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'The Delivery API shared secret is not set.' );
		}

		// Sign the exact bytes sent. Re-encoding would change key order and
		// the HMAC would never match on the other side.
		$raw = $body === null ? '' : wp_json_encode( $body );
		if ( $raw === false ) {
			return new WP_Error( 'encode_failed', 'Could not encode the request body.' );
		}

		$args = [
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => Pokbon_Delivery_Signature::headers( $secret, $raw, $event_id ),
			// Never let a slow delivery service block the page being rendered
			// any longer than the timeout above.
			'blocking' => true,
		];
		if ( $body !== null ) {
			$args['body'] = $raw;
		}

		$response = wp_remote_request( $base . $path, $args );

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'unreachable',
				sprintf( 'Delivery API unreachable (%s %s): %s', $method, $path, $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$text = (string) wp_remote_retrieve_body( $response );
		$data = json_decode( $text, true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && isset( $data['message'] )
				? ( is_array( $data['message'] ) ? implode( '; ', $data['message'] ) : (string) $data['message'] )
				: substr( $text, 0, 300 );
			return new WP_Error( 'api_error', sprintf( '%s %s → HTTP %d: %s', $method, $path, $code, $message ), [ 'status' => $code ] );
		}

		return is_array( $data ) ? $data : [];
	}

	// ─── the calls this plugin actually makes ───────────────────────────────

	/** Contract § 4. Fired when an order becomes dispatchable. */
	public static function create_job( array $payload ) {
		return self::post( '/jobs', $payload, self::TIMEOUT_ORDER_PATH );
	}

	/**
	 * Tell the delivery service an order has been called off.
	 *
	 * Deliberately not cancel_job(): what a cancellation means depends on where
	 * the parcel is, and only the delivery service knows that. It answers with
	 * an outcome — cancelled, recalled, or ignored.
	 */
	public static function recall_job( string $job_id, string $reason, string $actor ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/recall', [
			'reason' => $reason,
			'actor'  => $actor,
		] );
	}

	/** Payout requests waiting on the owner. */
	public static function payout_requests( string $status = 'REQUESTED' ) {
		return self::get( '/plugin/riders/payouts', [ 'status' => $status ] );
	}

	/** Record that the money has been sent. Writes the rider's PAYOUT entry. */
	public static function settle_payout( string $id, float $amount, string $actor, string $note = '' ) {
		return self::post( '/plugin/riders/payouts/' . rawurlencode( $id ) . '/settle', [
			'amount' => $amount,
			'actor'  => $actor,
			'note'   => $note,
		] );
	}

	/** Turn a request down, with a reason the rider can read. */
	public static function decline_payout( string $id, string $actor, string $note ) {
		return self::post( '/plugin/riders/payouts/' . rawurlencode( $id ) . '/decline', [
			'actor' => $actor,
			'note'  => $note,
		] );
	}

	/** Close a failed job from the office: the goods are back with the sender. */
	public static function return_job( string $job_id, string $reason, string $actor ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/returned', [
			'reason' => $reason,
			'actor'  => $actor,
		] );
	}

	public static function cancel_job( string $job_id, string $reason, string $actor ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/cancel', [
			'reason' => $reason,
			'actor'  => $actor,
		] );
	}

	/** Tell the API what happened to the doorstep payment. Contract § 4b. */
	public static function report_payment( string $job_id, array $outcome ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/payment', $outcome );
	}

	public static function assign_job( string $job_id, string $rider_id, string $actor ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/assign', [
			'riderId' => $rider_id,
			'actor'   => $actor,
		] );
	}

	public static function offer_job( string $job_id ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/offer', [] );
	}

	public static function bypass_code( string $job_id, string $reason, string $actor ) {
		return self::post( '/jobs/' . rawurlencode( $job_id ) . '/bypass-code', [
			'reason' => $reason,
			'actor'  => $actor,
		] );
	}

	public static function quote( array $pickup, array $dropoff ) {
		return self::get( '/quote', [
			'pickup.lat'        => $pickup['lat'] ?? '',
			'pickup.lng'        => $pickup['lng'] ?? '',
			'pickup.zoneCode'   => $pickup['zoneCode'] ?? '',
			'dropoff.lat'       => $dropoff['lat'] ?? '',
			'dropoff.lng'       => $dropoff['lng'] ?? '',
			'dropoff.zoneCode'  => $dropoff['zoneCode'] ?? '',
		] );
	}

	public static function jobs( array $filters = [] ) {
		return self::get( '/jobs', $filters );
	}

	public static function job( string $job_id ) {
		return self::get( '/jobs/' . rawurlencode( $job_id ) );
	}

	public static function tracking_for_order( $order_id ) {
		return self::get( '/orders/' . rawurlencode( (string) $order_id ) . '/tracking' );
	}

	public static function riders( string $status = '' ) {
		return self::get( '/plugin/riders', $status !== '' ? [ 'status' => $status ] : [] );
	}

	public static function rider( string $rider_id ) {
		return self::get( '/plugin/riders/' . rawurlencode( $rider_id ) );
	}

	/** Create or update a rider from the admin. Keyed on the phone number. */
	public static function upsert_rider( array $rider ) {
		return self::post( '/plugin/riders', $rider );
	}

	public static function rider_decision( string $rider_id, array $decision ) {
		return self::post( '/plugin/riders/' . rawurlencode( $rider_id ) . '/decision', $decision );
	}

	/** What the API is actually running on, for the settings screen to show. */
	public static function effective_settings() {
		return self::get( '/plugin/settings' );
	}
}
