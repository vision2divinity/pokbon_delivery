<?php
/**
 * What a route costs. PRD § 9, revised 2026-09-21.
 *
 * THREE RUNGS, FIRST MATCH WINS:
 *
 *   1. An explicit zone pair — Adenta → Kasoa, because you priced it.
 *   2. The bands those zones belong to — Inner Accra → Outer Accra.
 *   3. How far it actually is — 0–5km, 5–10km, and so on.
 *
 * WHY NOT JUST THE MATRIX. Six zones is 36 cells, twelve is 144, twenty is
 * 400. Every new area would mean pricing it against every existing one, which
 * nobody keeps up with — and a stale price is worse than none. With the
 * ladder, adding a zone costs one decision (which band) and it is priced the
 * same day. The matrix stays for the routes that genuinely are special.
 *
 * This mirrors `priceRoute()` in packages/shared exactly. Two copies is a
 * risk taken deliberately: the plugin must be able to quote at checkout
 * without a round trip to the delivery service, because a slow quote is a
 * lost sale and the service may not be reachable at all. They are kept honest
 * by scripts/check-price-parity.mjs, which prices the same routes through
 * both and fails on any disagreement.
 *
 * WHAT THIS DOES NOT PRICE. Ship-from-abroad freight is weight-based and
 * belongs to the marketplace; store pickup is free. This is the rider leg
 * only — the "POKBON Delivery Services" option — whether that is the whole
 * journey for a local item or the last leg of something that has just landed.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Pricing {

	/**
	 * Price a route. Returns null when no rung answers, which means POKBON
	 * does not serve it yet — never a guessed number.
	 *
	 * @param array $from [ 'zoneCode' => ?string, 'lat' => ?float, 'lng' => ?float ]
	 * @param array $to   same shape
	 * @return array|null [ riderFeeMinor, buyerPriceMinor, rung, matched, distanceMetres ]
	 */
	public static function route( array $from, array $to ): ?array {
		$from_zone = self::resolve( $from );
		$to_zone   = self::resolve( $to );

		$distance = self::distance_between( $from, $to, $from_zone, $to_zone );

		// 1. An explicit pair. Directional on purpose: Adenta → Kasoa and
		//    Kasoa → Adenta are different journeys at different times of day.
		if ( $from_zone && $to_zone ) {
			$pair = Pokbon_Delivery_Settings::price( $from_zone['code'], $to_zone['code'] );
			if ( $pair !== null ) {
				return [
					'riderFeeMinor'   => $pair['riderFeeMinor'],
					'buyerPriceMinor' => $pair['buyerPriceMinor'],
					'rung'            => 'zone-pair',
					'matched'         => $from_zone['code'] . ' → ' . $to_zone['code'],
					'distanceMetres'  => $distance,
				];
			}
		}

		// 2. The bands those zones belong to.
		$from_band = $from_zone['band'] ?? '';
		$to_band   = $to_zone['band'] ?? '';
		if ( $from_band !== '' && $to_band !== '' ) {
			$band = Pokbon_Delivery_Settings::band_price( $from_band, $to_band );
			if ( $band !== null ) {
				return [
					'riderFeeMinor'   => $band['riderFeeMinor'],
					'buyerPriceMinor' => $band['buyerPriceMinor'],
					'rung'            => 'band-pair',
					'matched'         => $from_band . ' → ' . $to_band,
					'distanceMetres'  => $distance,
				];
			}
		}

		// 3. How far it is. The catch-all, so a zone added this morning prices.
		if ( $distance !== null ) {
			$km    = $distance / 1000;
			$bands = Pokbon_Delivery_Settings::distance_bands();
			usort( $bands, static function ( $a, $b ) {
				return $a['maxKm'] <=> $b['maxKm'];
			} );
			foreach ( $bands as $band ) {
				if ( $km <= (float) $band['maxKm'] ) {
					return [
						'riderFeeMinor'   => (int) $band['riderFeeMinor'],
						'buyerPriceMinor' => (int) $band['buyerPriceMinor'],
						'rung'            => 'distance',
						// number_format() would insert a thousands separator and label this
						// "up to 2,000km" where the delivery service says "up to 2000km".
						// The label is stored on the job, so the two must match exactly.
						'matched'         => sprintf( 'up to %skm', rtrim( rtrim( sprintf( '%.1f', (float) $band['maxKm'] ), '0' ), '.' ) ),
						'distanceMetres'  => $distance,
					];
				}
			}
		}

		return null;
	}

	/**
	 * The whole delivery charge for an order, one job per vendor.
	 *
	 * Charged per vendor, as decided 2026-09-21: two vendors is two
	 * collections and two rider fees however close they are. The buyer sees
	 * one total rather than a split — the same way Hubtel does it — because a
	 * basket broken into delivery lines reads as several orders going wrong.
	 *
	 * Returns null when ANY leg is unserved: quoting a partial delivery would
	 * take money for something POKBON cannot complete.
	 */
	public static function order_total( array $legs ): ?array {
		if ( empty( $legs ) ) {
			return null;
		}

		$rider = 0;
		$buyer = 0;
		$parts = [];

		foreach ( $legs as $leg ) {
			$priced = self::route( $leg['from'], $leg['to'] );
			if ( $priced === null ) {
				return null;
			}
			$rider += $priced['riderFeeMinor'];
			$buyer += $priced['buyerPriceMinor'];
			$parts[] = $priced;
		}

		return [
			'riderFeeMinor'   => $rider,
			'buyerPriceMinor' => $buyer,
			'legs'            => $parts,
			'vendorCount'     => count( $legs ),
		];
	}

	// ─── helpers ────────────────────────────────────────────────────────────

	private static function resolve( array $point ): ?array {
		// An explicit zone wins over a pin: somebody chose it, and a pin near
		// a boundary should not silently overrule that choice.
		if ( ! empty( $point['zoneCode'] ) ) {
			$zone = Pokbon_Delivery_Settings::zone( (string) $point['zoneCode'] );
			if ( $zone && ! empty( $zone['active'] ) ) {
				return $zone;
			}
		}
		if ( isset( $point['lat'], $point['lng'] ) ) {
			return Pokbon_Delivery_Geo::resolve_zone( (float) $point['lat'], (float) $point['lng'] );
		}
		return null;
	}

	/**
	 * Real distance where both pins are known; otherwise the distance between
	 * the two zone centres.
	 *
	 * A zone centre is a fair stand-in for "somewhere in Madina", and it is
	 * what lets someone who could not get a GPS fix — or who typed an address
	 * instead — still be priced. Because pricing is zone-based rather than
	 * per-kilometre, that is the same answer, not a degraded one.
	 */
	private static function distance_between( array $from, array $to, ?array $from_zone, ?array $to_zone ): ?int {
		if ( isset( $from['lat'], $from['lng'], $to['lat'], $to['lng'] ) ) {
			return Pokbon_Delivery_Geo::distance(
				(float) $from['lat'],
				(float) $from['lng'],
				(float) $to['lat'],
				(float) $to['lng']
			);
		}
		if ( $from_zone && $to_zone ) {
			return Pokbon_Delivery_Geo::distance(
				(float) $from_zone['lat'],
				(float) $from_zone['lng'],
				(float) $to_zone['lat'],
				(float) $to_zone['lng']
			);
		}
		return null;
	}

	/** Human wording for which rung answered, for the admin screens. */
	public static function rung_label( string $rung ): string {
		switch ( $rung ) {
			case 'zone-pair':
				return 'a price you set for this exact route';
			case 'band-pair':
				return 'the band price';
			case 'distance':
				return 'the distance band';
			case 'plugin':
				return 'quoted at checkout';
			default:
				return $rung;
		}
	}
}
