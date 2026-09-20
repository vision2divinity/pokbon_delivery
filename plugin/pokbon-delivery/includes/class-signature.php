<?php
/**
 * Service-to-service signing, both directions.
 *
 * Marketplace contract § 1:
 *   X-Pokbon-Delivery-Signature  HMAC-SHA256 of the RAW body, hex
 *   X-Pokbon-Delivery-Timestamp  unix seconds, rejected beyond 5 minutes
 *   X-Pokbon-Delivery-Event-Id   UUID per event; a replay returns the stored answer
 *
 * The body is signed as raw bytes. Never re-encode before verifying: WordPress
 * and Node order JSON keys differently and the HMAC would never match — the
 * same trap the Paystack webhook documents in the marketplace plugin.
 *
 * Rotation follows the origin edge lock in the marketplace's security page:
 * generate the next secret, accept both for a window, then retire the old one.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Signature {

	const HEADER_SIGNATURE = 'X-Pokbon-Delivery-Signature';
	const HEADER_TIMESTAMP = 'X-Pokbon-Delivery-Timestamp';
	const HEADER_EVENT_ID  = 'X-Pokbon-Delivery-Event-Id';

	const MAX_SKEW_SECONDS = 300;

	/** Replayed event ids are remembered this long. Comfortably past any retry. */
	const REPLAY_TTL = DAY_IN_SECONDS;

	public static function sign( string $secret, string $raw_body ): string {
		return hash_hmac( 'sha256', $raw_body, $secret );
	}

	/** Headers for an outbound call to the Delivery API. */
	public static function headers( string $secret, string $raw_body, string $event_id = '' ): array {
		if ( $event_id === '' ) {
			$event_id = wp_generate_uuid4();
		}
		return [
			'Content-Type'         => 'application/json',
			self::HEADER_SIGNATURE => self::sign( $secret, $raw_body ),
			self::HEADER_TIMESTAMP => (string) time(),
			self::HEADER_EVENT_ID  => $event_id,
		];
	}

	/**
	 * Verify an inbound call from the Delivery API.
	 *
	 * Returns the event id on success, or a WP_Error naming the reason. The
	 * caller decides the status code; every failure here is a 401.
	 */
	public static function verify( WP_REST_Request $request ) {
		$secret = Pokbon_Delivery_Settings::shared_secret();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'The delivery shared secret is not set.' );
		}

		$signature = (string) $request->get_header( self::HEADER_SIGNATURE );
		$timestamp = (string) $request->get_header( self::HEADER_TIMESTAMP );
		$event_id  = (string) $request->get_header( self::HEADER_EVENT_ID );

		if ( $signature === '' || $timestamp === '' || $event_id === '' ) {
			return new WP_Error( 'missing_headers', 'Missing signature headers.' );
		}

		if ( ! ctype_digit( $timestamp ) || abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return new WP_Error( 'stale_timestamp', 'Timestamp outside the accepted window.' );
		}

		$raw = (string) $request->get_body();

		// Accept the previous secret during a rotation window.
		foreach ( array_filter( [ $secret, Pokbon_Delivery_Settings::previous_secret() ] ) as $candidate ) {
			if ( hash_equals( self::sign( $candidate, $raw ), $signature ) ) {
				return $event_id;
			}
		}

		return new WP_Error( 'bad_signature', 'Invalid service signature.' );
	}

	/**
	 * Idempotency. Returns the stored response for an event already handled,
	 * or null when this is the first time we have seen it.
	 *
	 * Deliveries retry: a replayed "paid" must not charge or notify twice.
	 */
	public static function replayed( string $event_id ) {
		$stored = get_transient( self::replay_key( $event_id ) );
		return $stored === false ? null : $stored;
	}

	public static function remember( string $event_id, $response ): void {
		set_transient( self::replay_key( $event_id ), $response, self::REPLAY_TTL );
	}

	private static function replay_key( string $event_id ): string {
		return 'pkbd_evt_' . md5( $event_id );
	}
}
