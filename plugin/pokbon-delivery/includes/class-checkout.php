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
 *
 * Asked two ways (2026-09-22). The website asks mid-session and the answer can
 * read WC()->cart; the mobile app asks over REST, where there is no session and
 * no cart at all. So every question here takes an optional list of product ids
 * and only falls back to the cart when none is given. Without that the app
 * could not be told a price before the order existed — and a price quoted after
 * the order exists is not a price, it is a surprise.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Checkout {

	/** Order meta: the zone the buyer chose, not one a dispatcher guessed. */
	const META_CHOSEN_ZONE = '_pokbon_delivery_chosen_zone';

	public static function bootstrap(): void {
		// The checkout plugin asks; this answers. Registered whether or not
		// that plugin is present, so installing it later needs no change here.
		add_filter( 'pokbon_checkout_delivery_zones', [ self::class, 'zones_for_region' ], 10, 3 );
		add_filter( 'pokbon_checkout_shipping_rate', [ self::class, 'rate_for_zone' ], 10, 4 );
		add_action( 'pokbon_checkout_order_created', [ self::class, 'remember_zone' ], 10, 2 );

		/*
		 * The same two questions, asked by something that is not a browser.
		 *
		 * Filters rather than public methods on purpose: the mobile app plugin
		 * must not have to know this class exists, or load in a particular
		 * order, or handle it being deactivated. An unanswered filter returns
		 * what it was given, which is exactly the fallback wanted.
		 */
		add_filter( 'pokbon_delivery_zone_areas', [ self::class, 'zones_for_region' ], 10, 3 );
		add_filter( 'pokbon_delivery_zone_price', [ self::class, 'filter_zone_price' ], 10, 3 );

		/*
		 * Every region at once, and whether an unserved region should be
		 * refused. The checkout renders its area list server-side and then
		 * cannot change it when the buyer changes region — so it needs all of
		 * them up front, not one region's worth.
		 */
		add_filter( 'pokbon_delivery_zone_areas_by_region', [ self::class, 'areas_by_region' ], 10, 3 );
		add_filter( 'pokbon_delivery_coverage_required', [ self::class, 'coverage_required' ] );
	}

	/**
	 * The areas a buyer in this region can choose from.
	 *
	 * Empty means "this region has no zones yet", and the caller falls back to
	 * its own regional rate — which is why turning this on cannot strand a
	 * customer in a region nobody has zoned.
	 *
	 * $product_ids describes the basket being priced. Null means "ask the
	 * cart", which is what the website wants and what a REST caller cannot
	 * have. Left untyped so a two-argument apply_filters() from POKBON
	 * Checkout still lands on the default instead of fatalling.
	 */
	public static function zones_for_region( array $zones, string $region_code, $product_ids = null ): array {
		$region_code = strtoupper( trim( $region_code ) );
		if ( $region_code === '' ) {
			return [];
		}

		$pickups = self::pickup_zones_for( $product_ids );

		$out = [];
		foreach ( Pokbon_Delivery_Settings::active_zones() as $zone ) {
			if ( ! self::region_matches( (string) ( $zone['region'] ?? '' ), $region_code ) ) {
				continue;
			}

			$price = self::quote( $zone, $pickups );
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
	 * Every region's areas in one pass.
	 *
	 * The web checkout renders its area list server-side, which meant the list
	 * was correct for the region the page loaded with and stayed that way:
	 * choosing Ho still showed Accra's environs, because nothing re-ran the
	 * filter. The mobile app was right all along — it holds every area and
	 * filters on the device.
	 *
	 * Asking region by region would have priced the same basket sixteen times.
	 * The pickup zones are resolved once here and reused, which is the
	 * expensive half.
	 *
	 * @param array $regions WooCommerce state codes the site sells to.
	 * @return array<string,array> region code => priced areas, regions with
	 *                             none included as empty so the caller can
	 *                             tell "nothing here" from "never asked".
	 */
	public static function areas_by_region( array $out, array $regions, $product_ids = null ): array {
		$pickups = self::pickup_zones_for( $product_ids );
		$zones   = Pokbon_Delivery_Settings::active_zones();

		foreach ( $regions as $region_code ) {
			$region_code = strtoupper( trim( (string) $region_code ) );
			if ( $region_code === '' ) {
				continue;
			}

			$rows = [];
			foreach ( $zones as $zone ) {
				if ( ! self::region_matches( (string) ( $zone['region'] ?? '' ), $region_code ) ) {
					continue;
				}
				$price = self::quote( $zone, $pickups );
				if ( $price === null ) {
					continue;
				}
				$rows[] = [
					'code'   => (string) $zone['code'],
					'name'   => (string) $zone['name'],
					'amount' => $price,
				];
			}

			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['amount'] <=> $b['amount'] ?: strcmp( $a['name'], $b['name'] );
				}
			);

			$out[ $region_code ] = $rows;
		}

		return $out;
	}

	/**
	 * Should a region POKBON does not serve be refused outright?
	 *
	 * Off by default, and that default matters. Everything else in this file
	 * falls back rather than blocking, on the rule that turning area pricing on
	 * must never make an address unsellable. This setting deliberately breaks
	 * that rule, so it has to be switched on by somebody who means it — turning
	 * it on by default would start refusing orders a shop is currently taking,
	 * without anybody asking for that.
	 *
	 * On, the buyer is told plainly that POKBON does not deliver there yet,
	 * which is kinder than charging a regional rate for a delivery no rider can
	 * perform.
	 */
	public static function coverage_required( $current = false ): bool {
		return (bool) Pokbon_Delivery_Settings::get( 'require_delivery_coverage' );
	}

	/**
	 * Does a zone belong to the region the buyer picked?
	 *
	 * The two fields speak different languages. WooCommerce passes a state
	 * code — AA, BE — while a zone's region was captured as free text,
	 * so somebody typed "Greater Accra". Comparing them directly matched
	 * nothing, every zone was filtered out, and the checkout silently fell
	 * back to the flat regional rate: the exact bug this was built to fix,
	 * reintroduced one layer up.
	 *
	 * So a zone matches on either the code or the region's name, and a zone
	 * with no region set belongs everywhere — hiding a zone somebody carefully
	 * priced is the worse failure of the two.
	 */
	private static function region_matches( string $zone_region, string $wc_code ): bool {
		$zone_region = strtoupper( trim( $zone_region ) );
		if ( $zone_region === '' ) {
			return true;
		}

		$wc_code = strtoupper( trim( $wc_code ) );
		if ( $zone_region === $wc_code ) {
			return true;
		}

		return $zone_region === strtoupper( self::region_name( $wc_code ) );
	}

	/** The human name of a WooCommerce Ghana state code, or ''. */
	public static function region_name( string $code ): string {
		if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
			return '';
		}
		$states = WC()->countries->get_states( 'GH' );
		return is_array( $states ) ? (string) ( $states[ strtoupper( $code ) ] ?? '' ) : '';
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
	 * Filter form of price_for_zone(), for callers holding no reference here.
	 *
	 * Keeps $current when this cannot price the route, so an unpriced zone
	 * leaves the caller's own number in place rather than zeroing it.
	 */
	public static function filter_zone_price( $current, $zone_code, $product_ids = null ) {
		$price = self::price_for_zone( (string) $zone_code, $product_ids );
		return $price === null ? $current : $price;
	}

	/**
	 * What this basket costs to deliver into one zone.
	 *
	 * Null when nothing can price it, which the caller reads as "use the
	 * regional rate". Deliberately not zero: a free delivery and an unpriced
	 * one look identical to a buyer and completely different to the books.
	 */
	public static function price_for_zone( string $zone_code, $product_ids = null ): ?float {
		$zone = Pokbon_Delivery_Settings::zone( strtoupper( trim( $zone_code ) ) );
		if ( ! $zone || empty( $zone['active'] ) ) {
			return null;
		}

		return self::quote( $zone, self::pickup_zones_for( $product_ids ) );
	}

	/**
	 * Sum the legs from each collection point to one drop-off zone.
	 *
	 * Split out so a list of areas prices every one of them against pickup
	 * zones resolved once, rather than re-reading the basket per row.
	 */
	private static function quote( array $zone, array $pickups ): ?float {
		$total  = 0.0;
		$priced = false;

		foreach ( $pickups as $pickup ) {
			/*
			 * Coordinates, not just a zone code.
			 *
			 * This passed the code alone, which quietly removed the third rung:
			 * distance needs somewhere to measure from, and a bare code gives it
			 * nothing. So any collection point whose zone had no explicit pair
			 * and no band to the buyer's area failed the whole quote, the area
			 * list came back empty, and checkout fell to the flat regional rate
			 * — the exact bug the areas exist to fix, reintroduced one layer in.
			 *
			 * It hid because the DEFAULT collection point has priced pairs to
			 * every zone, so the one case anybody tested worked perfectly. Every
			 * real vendor was falling through.
			 */
			$leg = Pokbon_Delivery_Pricing::route(
				[
					'zoneCode' => (string) ( $pickup['zoneCode'] ?? '' ),
					'lat'      => $pickup['lat'] ?? null,
					'lng'      => $pickup['lng'] ?? null,
				],
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
	 * One pickup zone per distinct vendor in the basket.
	 *
	 * Null asks the cart, which is what a page render can do. A list of
	 * product ids asks them directly, which is what a REST call has to do —
	 * an app quoting from the default pickup point alone would be right for
	 * the single-shop basket and quietly wrong for every multi-vendor one,
	 * and "quietly wrong about money" is the failure this whole file exists
	 * to stop.
	 *
	 * Vendors sharing a pickup point are charged once, because that is one
	 * collection.
	 */
	private static function pickup_zones_for( $product_ids ): array {
		// Resolved once per request per basket. areas_by_region() asks for
		// every region in one go, and without this each region would re-read
		// the cart and re-resolve every vendor's collection point.
		static $memo = [];
		$key = is_array( $product_ids ) ? implode( ',', $product_ids ) : '__cart__';
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}

		$ids = null;

		if ( is_array( $product_ids ) ) {
			$ids = [];
			foreach ( $product_ids as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}

		if ( $ids === null ) {
			$ids = self::cart_product_ids();
			if ( $ids === null ) {
				return []; // No cart and no list: nothing to price against.
			}
		}

		$vendors = [];
		foreach ( $ids as $product_id ) {
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

		/*
		 * One leg per VENDOR, even when two of them share an address.
		 *
		 * This used to key by pickup zone, so two vendors in the same building
		 * were charged once — while dispatch went on creating a job per vendor
		 * and paying a rider fee for each. Order #87712 charged for two
		 * collection points and produced three jobs: that collection point ran
		 * at zero margin, and nothing said so.
		 *
		 * Per vendor is Francis's rule (2026-09-21, reaffirmed 2026-09-23), and
		 * it is the honest one: two vendors is two parcels, two handovers and
		 * two jobs to point at when one of them goes missing. The job is the
		 * unit of accountability, so it has to be the unit of pricing too.
		 *
		 * A list, not a set. Two vendors at OLDASHOGMAN appear twice, and the
		 * quote sums twice — which is the entire point.
		 */
		$legs = [];
		foreach ( array_keys( $vendors ) as $vendor_id ) {
			// The whole pickup, so the quote can reach the distance rung.
			$pickup = Pokbon_Delivery_Orders::pickup_for_vendor( $vendor_id );
			if ( $pickup === null || ( $pickup['zoneCode'] ?? '' ) === '' ) {
				continue;
			}
			$legs[] = $pickup;
		}

		$memo[ $key ] = $legs;
		return $memo[ $key ];
	}

	/** Product ids in the session cart, or null when there is no cart. */
	private static function cart_product_ids(): ?array {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return null;
		}

		$ids = [];
		foreach ( WC()->cart->get_cart() as $line ) {
			$product_id = (int) ( $line['product_id'] ?? 0 );
			if ( $product_id > 0 ) {
				$ids[] = $product_id;
			}
		}

		return $ids;
	}

	/**
	 * Keep the buyer's own choice on the order.
	 *
	 * This is the quiet win. A website order has never carried a map pin, so a
	 * dispatcher had to choose the zone by reading the address — the guess the
	 * dispatch screen pre-selects and asks them to check. When the buyer has
	 * already said which area they are in, that guess is unnecessary and the
	 * job can be priced and dispatched without anyone interpreting anything.
	 *
	 * The app fires this same action for the same reason, so an order placed
	 * on a phone and an order placed in a browser arrive at dispatch alike.
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
