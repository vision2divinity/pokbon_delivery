<?php
/**
 * What delivery costs, answered at checkout instead of after it.
 *
 * Until now the website priced delivery from a flat rate per region — every
 * Greater Accra address paid the same — while this plugin priced the rider's
 * route zone to zone. The two never met, so an order could take GH¢30 from a
 * buyer and cost GH¢40 to deliver, and nothing said so until somebody added up
 * a week's jobs by hand.
 *
 * This is the other half of the fix. A buyer picks the area they are actually
 * in, the matrix prices that route, and the number they are charged is the
 * number the job is worth. The region stays: it filters the list of areas, and
 * it remains the fallback anywhere that has no zone yet, so switching this on
 * cannot make an address unsellable.
 *
 * Charged per vendor, summed into one figure. Two vendors is two collections
 * and two rider fees however close they are, which is the rule agreed on
 * 2026-09-21 — but a buyer sees one delivery line, because a basket broken
 * into several delivery charges reads as several orders going wrong.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Checkout {

	/** Order meta: the zone the buyer chose, not one a dispatcher guessed. */
	const META_CHOSEN_ZONE = '_pokbon_delivery_chosen_zone';

	public static function bootstrap(): void {
		// The checkout plugin asks; this answers. Registered whether or not
		// that plugin is present, so installing it later needs no change here.
		add_filter( 'pokbon_checkout_delivery_zones', [ self::class, 'zones_for_region' ], 10, 2 );
		add_filter( 'pokbon_checkout_shipping_rate', [ self::class, 'rate_for_zone' ], 10, 4 );
		add_action( 'pokbon_checkout_order_created', [ self::class, 'remember_zone' ], 10, 2 );
	}

	/**
	 * The areas a buyer in this region can choose from.
	 *
	 * Empty means "this region has no zones yet", and the checkout falls back
	 * to its own regional rate — which is why turning this on cannot strand a
	 * customer in a region nobody has zoned.
	 */
	public static function zones_for_region( array $zones, string $region_code ): array {
		$region_code = strtoupper( trim( $region_code ) );
		if ( $region_code === '' ) {
			return [];
		}

		$out = [];
		foreach ( Pokbon_Delivery_Settings::active_zones() as $zone ) {
			$zone_region = strtoupper( trim( (string) ( $zone['region'] ?? '' ) ) );

			// A zone with no region set is offered everywhere rather than
			// nowhere. Hiding it would make a zone somebody carefully priced
			// silently unreachable, which is the worse failure.
			if ( $zone_region !== '' && $zone_region !== $region_code ) {
				continue;
			}

			$price = self::price_for_zone( (string) $zone['code'] );
			if ( $price === null ) {
				continue; // Nothing prices this route yet; do not offer it.
			}

			$out[] = [
				'code'   => (string) $zone['code'],
				'name'   => (string) $zone['name'],
				'amount' => $price,
			];
		}

		usort(
			$out,
			static function ( $a, $b ) {
				return $a['amount'] <=> $b['amount'] ?: strcmp( $a['name'], $b['name'] );
			}
		);

		return $out;
	}

	/**
	 * The rate for a chosen zone, or null to leave the regional rate alone.
	 *
	 * $current is what the checkout worked out by itself. Returning it
	 * unchanged is the right answer whenever this plugin cannot do better —
	 * no zone chosen, a zone that no longer prices, an empty cart.
	 */
	public static function rate_for_zone( $current, string $delivery_type, string $zone_code, $cart ) {
		if ( $delivery_type !== 'home' || trim( $zone_code ) === '' ) {
			return $current;
		}

		$price = self::price_for_zone( $zone_code );
		return $price === null ? $current : $price;
	}

	/**
	 * What this cart costs to deliver into one zone.
	 *
	 * Null when nothing can price it, which the caller reads as "use the
	 * regional rate". Deliberately not zero: a free delivery and an unpriced
	 * one look identical to a buyer and completely different to the books.
	 */
	public static function price_for_zone( string $zone_code ): ?float {
		$zone = Pokbon_Delivery_Settings::zone( strtoupper( trim( $zone_code ) ) );
		if ( ! $zone || empty( $zone['active'] ) ) {
			return null;
		}

		$total   = 0.0;
		$priced  = false;

		foreach ( self::cart_pickup_zones() as $pickup_zone ) {
			$leg = Pokbon_Delivery_Pricing::route(
				[ 'zoneCode' => $pickup_zone ],
				[ 'zoneCode' => $zone['code'], 'lat' => $zone['lat'], 'lng' => $zone['lng'] ]
			);
			if ( $leg === null ) {
				// One unpriced leg makes the whole quote a guess, and quoting
				// a guess is how a buyer is charged for a delivery POKBON
				// cannot complete. Fall back rather than part-price.
				return null;
			}
			$total += Pokbon_Delivery_Settings::from_minor( (int) $leg['buyerPriceMinor'] );
			$priced = true;
		}

		return $priced ? round( $total, 2 ) : null;
	}

	/**
	 * One pickup zone per distinct vendor in the cart.
	 *
	 * The order-side code resolves this from order items; at checkout there is
	 * no order yet, so the same question is asked of the cart. Vendors sharing
	 * a pickup point are charged once, because that is one collection.
	 */
	private static function cart_pickup_zones(): array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return [];
		}

		$vendors = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			$product_id = (int) ( $line['product_id'] ?? 0 );
			if ( $product_id <= 0 ) {
				continue;
			}
			$vendor_id = (int) apply_filters(
				'pokbon_delivery_product_vendor',
				get_post_field( 'post_author', $product_id ),
				$product_id,
				null
			);
			$vendors[ $vendor_id > 0 ? $vendor_id : 0 ] = true;
		}

		if ( $vendors === [] ) {
			$vendors = [ 0 => true ];
		}

		$zones = [];
		foreach ( array_keys( $vendors ) as $vendor_id ) {
			$pickup = Pokbon_Delivery_Orders::pickup_zone_for_vendor( $vendor_id );
			if ( $pickup === '' ) {
				continue;
			}
			$zones[ $pickup ] = true; // One collection per place, not per vendor.
		}

		return array_keys( $zones );
	}

	/**
	 * Keep the buyer's own choice on the order.
	 *
	 * This is the quiet win. A website order has never carried a map pin, so a
	 * dispatcher had to choose the zone by reading the address — the guess the
	 * dispatch screen pre-selects and asks them to check. When the buyer has
	 * already said which area they are in, that guess is unnecessary and the
	 * job can be priced and dispatched without anyone interpreting anything.
	 */
	public static function remember_zone( $order_id, $zone_code ): void {
		$zone_code = strtoupper( trim( (string) $zone_code ) );
		if ( $zone_code === '' || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$order->update_meta_data( self::META_CHOSEN_ZONE, $zone_code );
		// The dispatch path reads this key, so a chosen zone removes the
		// "which zone?" step entirely.
		$order->update_meta_data( Pokbon_Delivery_Orders::META_DISPATCH_ZONE, $zone_code );
		$order->save();
	}
}
