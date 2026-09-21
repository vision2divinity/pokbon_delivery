<?php
/**
 * Version-tracked setup, same pattern as the marketplace plugin.
 *
 * Runs on activation AND on load when the stored version trails, because a
 * zip upload never fires activation — the mistake this codebase has paid for
 * more than once. Every step must be idempotent.
 *
 * There are no custom tables yet. Zones, the matrix and the settings are
 * options (see class-settings.php for why), and the jobs themselves live in
 * the Delivery API, which owns them. This class exists so that the first
 * table, when one is needed, has somewhere correct to go.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Migrations {

	const VERSION_OPTION = 'pokbon_delivery_schema_version';
	const LATEST_VERSION = 2;

	public static function bootstrap(): void {
		add_action( 'plugins_loaded', [ self::class, 'run_if_needed' ], 15 );
	}

	public static function run_if_needed(): void {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) >= self::LATEST_VERSION ) {
			return;
		}
		self::run();
	}

	public static function run(): void {
		$current = (int) get_option( self::VERSION_OPTION, 0 );

		for ( $v = $current + 1; $v <= self::LATEST_VERSION; $v++ ) {
			switch ( $v ) {
				case 1:
					self::migrate_to_1();
					break;
				case 2:
					self::migrate_to_2();
					break;
			}
			update_option( self::VERSION_OPTION, $v, false );
		}
	}

	public static function current_version(): int {
		return (int) get_option( self::VERSION_OPTION, 0 );
	}

	/**
	 * Seed the six coverage areas from pokbongroup.com/local-delivery so the
	 * zones screen opens with something real rather than an empty grid.
	 *
	 * Seeded only when no zones exist at all: re-running must never overwrite
	 * a centre point or radius Francis has corrected.
	 *
	 * Deliberately no prices. A seeded price would be a guess presented as a
	 * decision, and the whole point of the matrix is that the owner sets it.
	 */
	private static function migrate_to_1(): void {
		if ( get_option( Pokbon_Delivery_Settings::OPT_ZONES, null ) !== null ) {
			return;
		}
		update_option(
			Pokbon_Delivery_Settings::OPT_ZONES,
			Pokbon_Delivery_Settings::seed_zones(),
			false
		);
	}

	/**
	 * Seed the price ladder's lower rungs.
	 *
	 * Bands and distance bands are seeded; the zone matrix deliberately is
	 * not. A seeded zone-pair price would be a guess presented as a decision,
	 * while a seeded distance band is a floor that stops a fresh install
	 * refusing every route. Both are editable before anything dispatches.
	 */
	private static function migrate_to_2(): void {
		if ( get_option( Pokbon_Delivery_Settings::OPT_BANDS, null ) === null ) {
			update_option( Pokbon_Delivery_Settings::OPT_BANDS, Pokbon_Delivery_Settings::seed_bands(), false );
		}
		if ( get_option( Pokbon_Delivery_Settings::OPT_DISTANCE, null ) === null ) {
			update_option( Pokbon_Delivery_Settings::OPT_DISTANCE, Pokbon_Delivery_Settings::seed_distance_bands(), false );
		}

		// Give the seeded zones a band so rung 2 works out of the box.
		$zones = get_option( Pokbon_Delivery_Settings::OPT_ZONES, [] );
		if ( is_array( $zones ) ) {
			$map = [
				'MADINA'   => 'OUTER',
				'CIRCLE'   => 'INNER',
				'ASHAIMAN' => 'OUTER',
				'LAPAZ'    => 'INNER',
				'KUMASI'   => 'KUMASI',
				'SANTASI'  => 'KUMASI',
			];
			foreach ( $zones as $i => $zone ) {
				if ( empty( $zone['band'] ) && isset( $map[ $zone['code'] ] ) ) {
					$zones[ $i ]['band'] = $map[ $zone['code'] ];
				}
			}
			update_option( Pokbon_Delivery_Settings::OPT_ZONES, $zones, false );
		}
	}
}
