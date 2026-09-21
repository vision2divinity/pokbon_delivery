<?php
/**
 * Turning a marketplace order into a delivery job. Contract § 4.
 *
 * Fired when the order reaches `processing`, which is the marketplace's
 * existing signal that a vendor has confirmed and is packing. One job per
 * vendor, because the commission engine already reasons per vendor and a
 * buyer with two vendors genuinely has two deliveries.
 *
 * NOTHING HERE MAY FAIL AN ORDER. A delivery service that is down, or a route
 * that is not priced, must leave the order exactly as it was and tell the
 * admin. The marketplace worked before delivery existed and has to keep
 * working when delivery is unavailable.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Orders {

	const META_LAT        = '_pokbon_delivery_lat';
	const META_LNG        = '_pokbon_delivery_lng';
	const META_GHANAPOST  = '_pokbon_delivery_ghanapost';
	const META_NOTE       = '_pokbon_delivery_note';
	const META_JOB_IDS    = '_pokbon_delivery_job_ids';
	const META_STATUS     = '_pokbon_delivery_status';
	const META_FEE        = '_pokbon_delivery_fee';
	const META_SKIPPED    = '_pokbon_delivery_skipped_reason';

	public static function bootstrap(): void {
		add_action( 'woocommerce_order_status_processing', [ self::class, 'on_processing' ], 20, 1 );
	}

	public static function on_processing( $order_id ): void {
		if ( ! Pokbon_Delivery_API_Client::is_configured() ) {
			return; // Not set up yet. Silent by design: nothing is broken.
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Automatic creation is opt-in. Phase 0 is manual dispatch (PRD § 15),
		// and turning this on before the zones and matrix are set would create
		// jobs nobody can price.
		if ( ! Pokbon_Delivery_Settings::get( 'auto_create_jobs' ) ) {
			return;
		}

		$existing = $order->get_meta( self::META_JOB_IDS );
		if ( ! empty( $existing ) ) {
			return; // Already dispatched. Status can revisit processing.
		}

		self::create_jobs_for_order( $order );
	}

	/**
	 * Create one job per vendor. Also the entry point for the admin's
	 * "Dispatch this order" button, so a skipped order can be retried by hand
	 * once its route is priced.
	 *
	 * Returns [ 'created' => [jobId...], 'skipped' => [ reason... ] ].
	 */
	public static function create_jobs_for_order( $order ): array {
		$order_id = (int) $order->get_id();
		$created  = [];
		$skipped  = [];

		$dropoff = self::dropoff_for( $order );
		if ( $dropoff === null ) {
			$reason = 'The order has no delivery coordinates, so no rider could be routed to it.';
			$order->update_meta_data( self::META_SKIPPED, $reason );
			$order->save();
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
				'order_id' => $order_id,
				'reason'   => 'no_coordinates',
			] );
			return [ 'created' => [], 'skipped' => [ $reason ] ];
		}

		$is_cod = self::is_pay_on_delivery( $order );

		foreach ( self::vendors_for( $order ) as $vendor_id => $items ) {
			$pickup = self::pickup_for( $vendor_id, $order );
			if ( $pickup === null ) {
				$skipped[] = sprintf( 'Vendor %s has no pickup location set.', $vendor_id );
				continue;
			}

			$payload = [
				'source'      => 'marketplace',
				'orderId'     => $order_id,
				'vendorId'    => (string) $vendor_id,
				'riderSource' => 'pokbon',
				'pickup'      => $pickup,
				'dropoff'     => $dropoff,
				'parcel'      => [
					'sizeClass'     => 'small',
					// What the rider is actually carrying, in the buyer's own
					// order. Someone collecting "2 x cement" packs differently
					// from someone collecting "1 x phone case", and a courier
					// who cannot see the item before accepting will decline.
					'description'   => self::parcel_description( $order, $vendor_id ),
					'declaredValue' => (float) $order->get_total(),
					'itemCount'     => max( 1, (int) $items ),
				],
				'payment'     => [
					'method'    => $is_cod ? 'cod' : 'prepaid',
					// What the buyer approves at the door: the whole order.
					// The API never shows this to a rider (PRD § 9c).
					'codAmount' => $is_cod ? (float) $order->get_total() : 0,
					'currency'  => 'GHS',
				],
				'buyerUserId' => (string) $order->get_customer_id(),
			];

			// Price it here when both ends are in the matrix, so the job
			// freezes the same numbers checkout showed the buyer.
			$price = Pokbon_Delivery_Settings::price( $pickup['zoneCode'], $dropoff['zoneCode'] );
			if ( $price !== null ) {
				$payload['pricing'] = [
					'riderFee'     => Pokbon_Delivery_Settings::from_minor( $price['riderFeeMinor'] ),
					'buyerPrice'   => Pokbon_Delivery_Settings::from_minor( $price['buyerPriceMinor'] ),
					'priceVersion' => Pokbon_Delivery_Settings::sync_version(),
				];
			}

			$result = Pokbon_Delivery_API_Client::create_job( $payload );

			if ( is_wp_error( $result ) ) {
				$skipped[] = $result->get_error_message();
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
					'order_id'  => $order_id,
					'vendor_id' => $vendor_id,
					'error'     => $result->get_error_message(),
				] );
				continue;
			}

			$job_id = (string) ( $result['jobId'] ?? '' );
			if ( $job_id === '' ) {
				$skipped[] = 'The delivery service did not return a job id.';
				continue;
			}

			$created[] = $job_id;

			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATED, [
				'order_id'  => $order_id,
				'vendor_id' => $vendor_id,
				'job_id'    => $job_id,
				'buyerFee'  => $result['quotedFee'] ?? null,
				'riderFee'  => $result['riderFee'] ?? null,
			] );
		}

		if ( ! empty( $created ) ) {
			$order->update_meta_data( self::META_JOB_IDS, $created );
			// Single-job orders also carry the flat key the payments class and
			// the contract use.
			if ( count( $created ) === 1 ) {
				$order->update_meta_data( Pokbon_Delivery_Payments::META_JOB_ID, $created[0] );
			}
			$order->update_meta_data( self::META_STATUS, 'created' );
			$order->delete_meta_data( self::META_SKIPPED );
		}
		if ( ! empty( $skipped ) ) {
			$order->update_meta_data( self::META_SKIPPED, implode( ' | ', $skipped ) );
		}
		$order->save();

		return [ 'created' => $created, 'skipped' => $skipped ];
	}

	/**
	 * A status change arriving from the Delivery API. Contract § 5.
	 *
	 * Writes what the buyer sees and, on delivery, the real cost the 1.20
	 * margin maths has never had.
	 */
	public static function apply_status( string $job_id, array $payload ): bool {
		$order_id = (int) ( $payload['orderId'] ?? 0 );
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		if ( $status === '' ) {
			return false;
		}

		$order->update_meta_data( self::META_STATUS, $status );

		if ( isset( $payload['deliveryFee'] ) ) {
			// The number the commission engine needs for true margin.
			$order->update_meta_data( self::META_FEE, (float) $payload['deliveryFee'] );
		}

		$note = self::buyer_note_for( $status, $payload );
		if ( $note !== '' ) {
			$order->add_order_note( $note, 0 );
		}

		$order->save();

		return true;
	}

	// ─── where things are ───────────────────────────────────────────────────

	/** The buyer's pin, captured at checkout since plugin 1.20.1. */
	private static function dropoff_for( $order ): ?array {
		$lat = (float) $order->get_meta( self::META_LAT );
		$lng = (float) $order->get_meta( self::META_LNG );

		if ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 ) {
			return null;
		}

		$zone = Pokbon_Delivery_Geo::resolve_zone_code( $lat, $lng );
		if ( $zone === '' ) {
			return null; // Outside every coverage area: not deliverable yet.
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( (string) $order->get_billing_phone() );
		if ( $phone === '' ) {
			return null; // No number means no code and no payment prompt.
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_city() ) ?: $order->get_billing_address_1(),
			'zoneCode'     => $zone,
			'ghanaPost'    => (string) $order->get_meta( self::META_GHANAPOST ),
			'note'         => (string) $order->get_meta( self::META_NOTE ),
			'contactName'  => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'contactPhone' => $phone,
		];
	}

	/**
	 * Where the rider collects.
	 *
	 * Four sources, best first. A vendor with real coordinates beats a default
	 * every time, because a rider sent to the wrong end of Accra is the most
	 * expensive mistake this system can make.
	 *
	 *   1. The `pokbon_delivery_vendor_pickup` filter, for anything bespoke.
	 *   2. The vendor's own WCFM store location, which is where the marketplace
	 *      already reads vendor pickup addresses from.
	 *   3. A configured pickup point belonging to this vendor.
	 *   4. The default pickup in Settings, correct for a single-warehouse start.
	 *
	 * Returns null rather than a guess. A job with the wrong pickup is worse
	 * than a job that was never created, because a rider is dispatched on it.
	 */
	private static function pickup_for( $vendor_id, $order ): ?array {
		$vendor_id = (int) $vendor_id;

		foreach ( [
			apply_filters( 'pokbon_delivery_vendor_pickup', null, $vendor_id, $order ),
			self::vendor_store_pickup( $vendor_id ),
			self::pickup_point_for_vendor( $vendor_id ),
			self::default_pickup(),
		] as $candidate ) {
			$resolved = self::validate_pickup( $candidate );
			if ( $resolved !== null ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * A pickup is only usable with coordinates inside a served zone and a phone
	 * a rider can actually call. Anything missing one of those is discarded here
	 * rather than surfacing as a job nobody can complete.
	 */
	private static function validate_pickup( $candidate ): ?array {
		if ( ! is_array( $candidate ) ) {
			return null;
		}

		$lat = (float) ( $candidate['lat'] ?? 0 );
		$lng = (float) ( $candidate['lng'] ?? 0 );
		if ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 ) {
			return null;
		}

		$zone = (string) ( $candidate['zoneCode'] ?? '' );
		if ( $zone === '' ) {
			$zone = Pokbon_Delivery_Geo::resolve_zone_code( $lat, $lng );
		}
		if ( $zone === '' ) {
			return null; // Collection point outside every coverage area.
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( (string) ( $candidate['contactPhone'] ?? '' ) );
		if ( $phone === '' ) {
			return null;
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => sanitize_text_field( (string) ( $candidate['address'] ?? '' ) ),
			'zoneCode'     => $zone,
			'note'         => sanitize_text_field( (string) ( $candidate['note'] ?? '' ) ),
			'contactName'  => sanitize_text_field( (string) ( $candidate['contactName'] ?? '' ) ),
			'contactPhone' => $phone,
		];
	}

	/**
	 * The vendor's store location as WCFM stores it.
	 *
	 * Key shapes copied from the marketplace's own pickup-points endpoint, which
	 * already solved this: the modern serialised profile array first, then the
	 * legacy individual meta keys. Reading it the same way means a vendor who
	 * appears as a pickup point in the app is dispatchable here too.
	 */
	private static function vendor_store_pickup( int $vendor_id ): ?array {
		if ( $vendor_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return null;
		}

		$profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
		$address = '';
		$city    = '';
		$phone   = '';

		if ( is_array( $profile ) ) {
			$a       = is_array( $profile['address'] ?? null ) ? $profile['address'] : [];
			$address = (string) ( $a['addr_1'] ?? '' );
			$city    = (string) ( $a['city'] ?? '' );
			$phone   = (string) ( $profile['phone'] ?? '' );
		}

		if ( $address === '' ) {
			$address = (string) get_user_meta( $vendor_id, '_wcfmmp_address', true );
		}
		if ( $city === '' ) {
			$city = (string) get_user_meta( $vendor_id, '_wcfmmp_city', true );
		}
		if ( $phone === '' ) {
			$phone = (string) get_user_meta( $vendor_id, '_wcfmmp_phone', true );
		}

		$lat = (float) get_user_meta( $vendor_id, '_wcfmmp_lat', true );
		if ( $lat === 0.0 ) {
			$lat = (float) get_user_meta( $vendor_id, '_wcfm_lat', true );
		}
		$lng = (float) get_user_meta( $vendor_id, '_wcfmmp_lng', true );
		if ( $lng === 0.0 ) {
			$lng = (float) get_user_meta( $vendor_id, '_wcfm_lng', true );
		}

		$store_name = (string) get_user_meta( $vendor_id, 'store_name', true );
		if ( $store_name === '' ) {
			$store_name = $user->display_name;
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => trim( $address . ( $city !== '' ? ', ' . $city : '' ) ),
			'contactName'  => $store_name,
			'contactPhone' => $phone,
		];
	}

	/**
	 * A configured pickup point belonging to this vendor.
	 *
	 * These already carry latitude and longitude and are already curated by the
	 * owner, which makes them more trustworthy than a vendor-entered pin.
	 */
	private static function pickup_point_for_vendor( int $vendor_id ): ?array {
		if ( ! class_exists( 'Pokbon_App_Pickup_Points_Endpoint' ) ) {
			return null;
		}

		$store  = get_option( Pokbon_App_Pickup_Points_Endpoint::OPTION_KEY, [] );
		$points = is_array( $store['points'] ?? null ) ? $store['points'] : [];

		foreach ( $points as $point ) {
			if ( empty( $point['enabled'] ) ) {
				continue;
			}
			if ( (int) ( $point['vendorId'] ?? 0 ) !== $vendor_id ) {
				continue;
			}
			return [
				'lat'          => (float) ( $point['latitude'] ?? 0 ),
				'lng'          => (float) ( $point['longitude'] ?? 0 ),
				'address'      => (string) ( $point['address'] ?? $point['name'] ?? '' ),
				'contactName'  => (string) ( $point['name'] ?? '' ),
				'contactPhone' => (string) ( $point['phone'] ?? '' ),
			];
		}

		return null;
	}

	/** The single collection point configured in Settings. */
	private static function default_pickup(): ?array {
		$code = (string) Pokbon_Delivery_Settings::get( 'default_pickup_zone' );
		if ( $code === '' ) {
			return null;
		}
		$zone = Pokbon_Delivery_Settings::zone( $code );
		if ( ! $zone ) {
			return null;
		}

		return [
			'lat'          => (float) $zone['lat'],
			'lng'          => (float) $zone['lng'],
			'zoneCode'     => (string) $zone['code'],
			'address'      => (string) ( Pokbon_Delivery_Settings::get( 'default_pickup_address' ) ?: $zone['name'] ),
			'note'         => (string) Pokbon_Delivery_Settings::get( 'default_pickup_note' ),
			'contactName'  => (string) ( Pokbon_Delivery_Settings::get( 'default_pickup_contact' ) ?: 'POKBON' ),
			'contactPhone' => (string) Pokbon_Delivery_Settings::get( 'default_pickup_phone' ),
		];
	}

	/**
	 * Items grouped by vendor. Falls back to a single job when the install has
	 * no vendor concept, which is the right behaviour rather than no job.
	 */
	private static function vendors_for( $order ): array {
		$vendors = [];

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$vendor_id  = (int) apply_filters(
				'pokbon_delivery_product_vendor',
				get_post_field( 'post_author', $product_id ),
				$product_id,
				$item
			);
			$key = $vendor_id > 0 ? $vendor_id : 0;
			$vendors[ $key ] = ( $vendors[ $key ] ?? 0 ) + (int) $item->get_quantity();
		}

		return empty( $vendors ) ? [ 0 => 1 ] : $vendors;
	}

	/**
	 * What the rider is carrying, from the order itself.
	 *
	 * Only this vendor's lines, because a multi-vendor order is several
	 * deliveries and a rider should not be told about a parcel they are not
	 * collecting. Kept short: this is read on a phone, at a counter, in a
	 * hurry, and a wall of text is the same as no text.
	 */
	private static function parcel_description( $order, $vendor_id ): string {
		$parts = [];

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$owner      = (int) apply_filters(
				'pokbon_delivery_product_vendor',
				get_post_field( 'post_author', $product_id ),
				$product_id,
				$item
			);
			// Vendor 0 is the "no vendor concept" fallback; it takes everything.
			if ( (int) $vendor_id !== 0 && $owner !== (int) $vendor_id ) {
				continue;
			}
			$parts[] = sprintf( '%d x %s', (int) $item->get_quantity(), $item->get_name() );
			if ( count( $parts ) >= 5 ) {
				$parts[] = '…';
				break;
			}
		}

		return mb_substr( sanitize_text_field( implode( ', ', $parts ) ), 0, 500 );
	}

	/**
	 * Is this the pay-at-the-door case?
	 *
	 * The buyer still picks "cash on delivery" at checkout — the label changes
	 * to "Pay on delivery" with the rollout (PRD § 2), but the method id does
	 * not, because changing it would strand every historic order.
	 */
	public static function is_pay_on_delivery( $order ): bool {
		$method = (string) $order->get_payment_method();
		return in_array( $method, [ 'cod', 'pokbon_cod' ], true ) && ! $order->is_paid();
	}

	private static function buyer_note_for( string $status, array $payload ): string {
		$rider = (string) ( $payload['rider']['name'] ?? 'Your rider' );

		switch ( $status ) {
			case 'assigned':
				return sprintf( '%s is on the way with this delivery.', $rider );
			case 'picked_up':
				return 'The parcel has left the vendor.';
			case 'arrived':
				return sprintf( '%s has arrived at the delivery address.', $rider );
			case 'paid':
				return 'Payment approved at the door.';
			case 'delivered':
				return 'Delivered and confirmed by code.';
			case 'payment_failed':
				return sprintf( 'The doorstep payment did not go through: %s', (string) ( $payload['failureReason'] ?? 'unknown' ) );
			case 'failed':
				return sprintf( 'Delivery failed: %s', (string) ( $payload['failureReason'] ?? 'unknown' ) );
			case 'returned':
				return 'The parcel was returned to the vendor.';
			default:
				return '';
		}
	}
}
