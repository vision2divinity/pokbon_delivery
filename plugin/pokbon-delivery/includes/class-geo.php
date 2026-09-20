<?php
/**
 * Zone resolution. PRD § 9a.
 *
 * A drop-off pin belongs to the nearest active zone whose radius contains it.
 * If no zone claims it, POKBON does not deliver there yet and the buyer is
 * told so — never quietly assigned to the closest zone regardless of distance,
 * which is how a rider ends up sent to Kumasi for an Accra price.
 *
 * Mirrors packages/shared/src/geo.ts in the API. Both sides must agree, or a
 * price quoted at checkout differs from the one the job is created with.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Geo {

	const EARTH_RADIUS_METRES = 6371000;

	/** Great-circle distance in metres. Good enough for zones and "nearby". */
	public static function distance( float $lat1, float $lng1, float $lat2, float $lng2 ): int {
		$d_lat = deg2rad( $lat2 - $lat1 );
		$d_lng = deg2rad( $lng2 - $lng1 );

		$a = sin( $d_lat / 2 ) ** 2
			+ cos( deg2rad( $lat1 ) ) * cos( deg2rad( $lat2 ) ) * sin( $d_lng / 2 ) ** 2;

		return (int) round( 2 * self::EARTH_RADIUS_METRES * asin( min( 1.0, sqrt( $a ) ) ) );
	}

	/** Nearest active zone containing the point, or null. */
	public static function resolve_zone( float $lat, float $lng ): ?array {
		// (0,0) is in the Gulf of Guinea. It is always an uninitialised value,
		// never a delivery address — the same guard the marketplace applies
		// when it stores checkout coordinates.
		if ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 ) {
			return null;
		}

		$best          = null;
		$best_distance = PHP_INT_MAX;

		foreach ( Pokbon_Delivery_Settings::active_zones() as $zone ) {
			$distance = self::distance( $lat, $lng, (float) $zone['lat'], (float) $zone['lng'] );
			if ( $distance <= (int) $zone['radiusMetres'] && $distance < $best_distance ) {
				$best          = $zone;
				$best_distance = $distance;
			}
		}

		return $best;
	}

	public static function resolve_zone_code( float $lat, float $lng ): string {
		$zone = self::resolve_zone( $lat, $lng );
		return $zone ? (string) $zone['code'] : '';
	}
}
