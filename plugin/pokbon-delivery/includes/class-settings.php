<?php
/**
 * Everything Francis sets, and the only place it lives. PRD § 9 and § 12b.
 *
 * The owner's instruction was explicit: the amounts, the locations, the
 * commission timing — none of it hardcoded. So this class is the source of
 * truth for zones, the zone-to-zone price matrix, and the tunables, and it
 * pushes all three to the Delivery API whenever they are saved.
 *
 * Money is stored in PESEWAS (integer). Floats do not survive repeated
 * arithmetic and GH₵0.01 lost per job is a real number at volume. The admin
 * screens take and show GHS; conversion happens at the edge, here.
 *
 * Zones and the matrix are options rather than tables: six zones and their
 * pairs is a few kilobytes, an option is one indexed read, and this host is
 * already short of database headroom. If the matrix ever outgrows that, it
 * moves to a table behind a numbered migration.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Settings {

	const OPT_ZONES      = 'pokbon_delivery_zones';
	const OPT_PRICES     = 'pokbon_delivery_prices';
	const OPT_SETTINGS   = 'pokbon_delivery_settings';
	const OPT_API        = 'pokbon_delivery_api';
	const OPT_SYNC       = 'pokbon_delivery_sync';
	const OPT_BANDS      = 'pokbon_delivery_bands';
	const OPT_BAND_PRICES = 'pokbon_delivery_band_prices';
	const OPT_DISTANCE   = 'pokbon_delivery_distance_bands';

	/**
	 * Launch defaults. Mirrors packages/shared/src/settings.ts in the API, and
	 * the API falls back to its own copy for anything never synced — so the two
	 * must agree. When you change one, change the other.
	 */
	public static function defaults(): array {
		return [
			'offer_timeout_seconds'   => 45,
			'offer_cascade_depth'     => 5,
			'offer_radius_metres'     => 8000,
			'location_stale_seconds'  => 300,
			'code_length'             => 6,
			'code_expiry_minutes'     => 120,
			'code_max_attempts'       => 5,
			'code_max_sends'          => 3,
			'payment_wait_minutes'    => 10,
			'payment_max_prompts'     => 3,
			// Empty schedule means riders pay nothing. That is the launch
			// position for about six months (PRD § 9d); add a row with an
			// effective date to phase commission in, no release required.
			'rider_commission_schedule' => [],
			'failed_trip_uplift'      => [ 'rateBps' => 2000 ],
			'default_markup'          => [ 'rateBps' => 3333 ],
			'concurrency_caps'        => [
				[ 'minCompletedJobs' => 0,   'maxConcurrent' => 1 ],
				[ 'minCompletedJobs' => 30,  'maxConcurrent' => 2 ],
				[ 'minCompletedJobs' => 100, 'maxConcurrent' => 3 ],
			],
			'operating_hours'         => [ 'open' => '06:00', 'close' => '22:00', 'days' => [ 0, 1, 2, 3, 4, 5, 6 ] ],
			'payout_cycle'            => 'weekly',
			'standalone_refund_on_failure' => [ 'refundBps' => 10000 ],
			'agreement_version'       => '2026-09-20',
			'active_vehicle_classes'  => [ 'MOTORBIKE' ],

			// Plugin-side only; the API has no use for these.
			//
			// Automatic job creation stays OFF until the zones and the matrix
			// are set. Phase 0 is manual dispatch (PRD § 15), and switching
			// this on early would create jobs nobody can price.
			'auto_create_jobs'        => false,
			/*
			 * What a completed delivery does to the WooCommerce order.
			 *
			 * Until 2026-09-22 a delivered job recorded meta and an order note
			 * and left the order alone, so a customer who had just signed for
			 * their parcel still saw it "ongoing" in the app. The order is
			 * what the marketplace shows; leaving it untouched means the
			 * delivery service knows the job is done and the only person who
			 * cares does not.
			 *
			 * Empty means "leave the order alone", which is the right answer
			 * for a site that drives its own statuses elsewhere. 'auto' picks
			 * a registered `delivered` status if the site has one and falls
			 * back to `completed`.
			 */
			/*
			 * Every message this system sends a person, as editable text.
			 *
			 * These were hardcoded until 2026-09-22 — the rider's sign-in text
			 * inside the delivery service, the rest scattered through the PHP.
			 * Changing a word meant a release, which is exactly what the
			 * backend-first rule exists to avoid. The delivery service reads
			 * these through the settings sync and composes from them.
			 *
			 * Keep them plain. Messages are folded to the GSM alphabet before
			 * sending, so a curly quote or a cedi sign will be replaced.
			 */
			'messages' => [
				'rider_otp' => [
					'enabled' => true,
					'text'    => 'Your POKBON Delivery code is {code}. It expires in {minutes} minutes. Never share it.',
				],
				'delivery_code' => [
					'enabled' => true,
					'text'    => 'POKBON: your rider is at your door. Your delivery code is {code}. Read it to the rider only.{amount}',
				],
				'assigned' => [
					'enabled' => true,
					'text'    => 'POKBON: {rider} is on the way with your delivery.{amount}',
				],
				'payment_prompt' => [
					'enabled' => true,
					'text'    => 'POKBON: approve GHS {amount} for order #{order}.',
				],
				'pay_by_link' => [
					'enabled' => true,
					'text'    => 'POKBON: pay GHS {amount} for order #{order} here: {link}',
				],
			],
			'order_status_on_delivered' => 'auto',
			// Deliberately empty by default: a failed delivery is not a
			// cancelled order, and what should happen to the money is a
			// decision for a human, not a default.
			'order_status_on_failed'    => '',
			// Where a rider collects when the vendor has no coordinates of
			// its own. Correct for a single-warehouse start; wire the
			// `pokbon_delivery_vendor_pickup` filter for real vendor stores.
			'default_pickup_zone'     => '',
			'default_pickup_address'  => '',
			'default_pickup_contact'  => 'POKBON',
			'default_pickup_phone'    => '',
			// Standing instructions for the collection point: which gate, who to
			// ask for. Shown to the rider on the job.
			'default_pickup_note'     => '',
			// Flip on the day the first rider goes out, not before: until then
			// there really is cash and the checkout would be lying (PRD § 12a).
			'rename_cod_label'        => false,
		];
	}

	/** The six areas already advertised on pokbongroup.com/local-delivery. */
	public static function seed_zones(): array {
		return [
			[ 'code' => 'MADINA',   'name' => 'Madina & environs',   'region' => 'Greater Accra', 'lat' => 5.6689, 'lng' => -0.1651, 'radiusMetres' => 5000, 'active' => true ],
			[ 'code' => 'CIRCLE',   'name' => 'Circle & environs',   'region' => 'Greater Accra', 'lat' => 5.5717, 'lng' => -0.2115, 'radiusMetres' => 5000, 'active' => true ],
			[ 'code' => 'ASHAIMAN', 'name' => 'Ashaiman & environs', 'region' => 'Greater Accra', 'lat' => 5.6928, 'lng' => -0.0341, 'radiusMetres' => 5000, 'active' => true ],
			[ 'code' => 'LAPAZ',    'name' => 'Lapaz & environs',    'region' => 'Greater Accra', 'lat' => 5.6076, 'lng' => -0.2436, 'radiusMetres' => 5000, 'active' => true ],
			[ 'code' => 'KUMASI',   'name' => 'Kumasi & environs',   'region' => 'Ashanti',       'lat' => 6.6885, 'lng' => -1.6244, 'radiusMetres' => 8000, 'active' => true ],
			[ 'code' => 'SANTASI',  'name' => 'Santasi & environs',  'region' => 'Ashanti',       'lat' => 6.6585, 'lng' => -1.6553, 'radiusMetres' => 5000, 'active' => true ],
		];
	}

	// ─── settings ───────────────────────────────────────────────────────────

	public static function all(): array {
		$stored = get_option( self::OPT_SETTINGS, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function get( string $key ) {
		$all = self::all();
		return $all[ $key ] ?? null;
	}

	public static function save_settings( array $values ): void {
		$stored = get_option( self::OPT_SETTINGS, [] );
		$stored = is_array( $stored ) ? $stored : [];
		update_option( self::OPT_SETTINGS, array_merge( $stored, $values ), false );
		self::mark_dirty();
	}

	// ─── zones ──────────────────────────────────────────────────────────────

	public static function zones(): array {
		$zones = get_option( self::OPT_ZONES, null );
		if ( ! is_array( $zones ) ) {
			return [];
		}
		return $zones;
	}

	public static function active_zones(): array {
		return array_values( array_filter( self::zones(), static function ( $z ) {
			return ! empty( $z['active'] );
		} ) );
	}

	public static function zone( string $code ): ?array {
		foreach ( self::zones() as $zone ) {
			if ( $zone['code'] === $code ) {
				return $zone;
			}
		}
		return null;
	}

	public static function save_zone( array $zone ): void {
		$zones = self::zones();
		$code  = sanitize_key( $zone['code'] );
		$code  = strtoupper( $code );
		if ( $code === '' ) {
			return;
		}
		$clean = [
			'code'         => $code,
			'name'         => sanitize_text_field( (string) ( $zone['name'] ?? $code ) ),
			'region'       => sanitize_text_field( (string) ( $zone['region'] ?? '' ) ),
			'lat'          => (float) $zone['lat'],
			'lng'          => (float) $zone['lng'],
			'radiusMetres' => max( 100, (int) $zone['radiusMetres'] ),
			// Which band this zone prices under when no explicit pair exists.
			// The one decision that makes adding a zone cheap.
			'band'         => strtoupper( sanitize_key( (string) ( $zone['band'] ?? '' ) ) ),
			'active'       => ! empty( $zone['active'] ),
		];

		$replaced = false;
		foreach ( $zones as $i => $existing ) {
			if ( $existing['code'] === $code ) {
				$zones[ $i ] = $clean;
				$replaced    = true;
				break;
			}
		}
		if ( ! $replaced ) {
			$zones[] = $clean;
		}

		update_option( self::OPT_ZONES, array_values( $zones ), false );
		self::mark_dirty();
	}

	/**
	 * Deactivate, never delete. PRD § 12c: a removed zone keeps its history,
	 * and a job that already priced against it must stay explicable.
	 */
	public static function deactivate_zone( string $code ): void {
		$zones = self::zones();
		foreach ( $zones as $i => $zone ) {
			if ( $zone['code'] === $code ) {
				$zones[ $i ]['active'] = false;
			}
		}
		update_option( self::OPT_ZONES, array_values( $zones ), false );
		self::mark_dirty();
	}

	// ─── the price matrix ───────────────────────────────────────────────────

	/**
	 * Stored as [ "FROM|TO" => [ riderFeeMinor, buyerPriceMinor ] ].
	 * An absent pair means POKBON does not serve that route yet, and checkout
	 * says so rather than inventing a price.
	 */
	public static function prices(): array {
		$prices = get_option( self::OPT_PRICES, [] );
		return is_array( $prices ) ? $prices : [];
	}

	/**
	 * One message's text, or '' when it is switched off.
	 *
	 * Falls back to the shipped default when a key is missing, so adding a
	 * message in a release does not require the admin to save the page before
	 * anything is sent.
	 */
	public static function message( string $key, array $vars = [] ): string {
		$all     = self::get( 'messages' );
		$all     = is_array( $all ) ? $all : [];
		$message = isset( $all[ $key ] ) && is_array( $all[ $key ] ) ? $all[ $key ] : null;

		if ( $message !== null && empty( $message['enabled'] ) ) {
			return '';
		}

		$text = (string) ( $message['text'] ?? '' );
		if ( trim( $text ) === '' ) {
			$defaults = self::defaults();
			$text     = (string) ( $defaults['messages'][ $key ]['text'] ?? '' );
		}

		foreach ( $vars as $name => $value ) {
			$text = str_replace( '{' . $name . '}', (string) $value, $text );
		}

		// Any placeholder the caller did not supply is dropped rather than
		// shown: "{rider} is on the way" reaching a customer is worse than
		// the sentence reading a little short.
		$text = preg_replace( '/\{[a-z_]+\}/', '', $text );
		return trim( preg_replace( '/ {2,}/', ' ', $text ) );
	}

	public static function price( string $from, string $to ): ?array {
		$prices = self::prices();
		$key    = $from . '|' . $to;
		if ( empty( $prices[ $key ] ) ) {
			return null;
		}
		$row = $prices[ $key ];
		return [
			'riderFeeMinor'   => (int) $row['riderFeeMinor'],
			'buyerPriceMinor' => (int) $row['buyerPriceMinor'],
		];
	}

	/** Amounts arrive from the admin form in GHS. */
	public static function save_price( string $from, string $to, float $rider_fee_ghs, float $buyer_price_ghs ): void {
		$prices = self::prices();
		$prices[ $from . '|' . $to ] = [
			'riderFeeMinor'   => self::to_minor( $rider_fee_ghs ),
			'buyerPriceMinor' => self::to_minor( $buyer_price_ghs ),
		];
		update_option( self::OPT_PRICES, $prices, false );
		self::mark_dirty();
	}

	public static function clear_price( string $from, string $to ): void {
		$prices = self::prices();
		unset( $prices[ $from . '|' . $to ] );
		update_option( self::OPT_PRICES, $prices, false );
		self::mark_dirty();
	}

	/**
	 * The buyer price implied by a rider fee and the default markup, for
	 * pre-filling a cell Francis has not set by hand. He can override any of
	 * them; this only saves typing.
	 */
	public static function marked_up( int $rider_fee_minor ): int {
		$markup = self::get( 'default_markup' );
		$rate   = (int) ( $markup['rateBps'] ?? 0 );
		$flat   = (int) ( $markup['flatMinor'] ?? 0 );
		return $rider_fee_minor + (int) round( $rider_fee_minor * $rate / 10000 ) + $flat;
	}

	// ─── rung 2: bands ──────────────────────────────────────────────────────

	/** Launch bands. Enough structure to be useful, few enough to reason about. */
	public static function seed_bands(): array {
		return [
			[ 'code' => 'INNER',    'name' => 'Inner Accra',   'active' => true ],
			[ 'code' => 'OUTER',    'name' => 'Outer Accra',   'active' => true ],
			[ 'code' => 'KUMASI',   'name' => 'Kumasi metro',  'active' => true ],
			[ 'code' => 'REGIONAL', 'name' => 'Other regions', 'active' => true ],
		];
	}

	public static function bands(): array {
		$bands = get_option( self::OPT_BANDS, null );
		return is_array( $bands ) ? $bands : [];
	}

	public static function active_bands(): array {
		return array_values( array_filter( self::bands(), static function ( $b ) {
			return ! empty( $b['active'] );
		} ) );
	}

	public static function save_bands( array $bands ): void {
		$clean = [];
		foreach ( $bands as $band ) {
			$code = strtoupper( sanitize_key( (string) ( $band['code'] ?? '' ) ) );
			if ( $code === '' ) {
				continue;
			}
			$clean[] = [
				'code'   => $code,
				'name'   => sanitize_text_field( (string) ( $band['name'] ?? $code ) ),
				'active' => ! empty( $band['active'] ),
			];
		}
		update_option( self::OPT_BANDS, $clean, false );
		self::mark_dirty();
	}

	/** Stored as [ "FROM|TO" => [ riderFeeMinor, buyerPriceMinor ] ], like the zone matrix. */
	public static function band_prices(): array {
		$prices = get_option( self::OPT_BAND_PRICES, [] );
		return is_array( $prices ) ? $prices : [];
	}

	public static function band_price( string $from, string $to ): ?array {
		$prices = self::band_prices();
		$key    = $from . '|' . $to;
		if ( empty( $prices[ $key ] ) ) {
			return null;
		}
		return [
			'riderFeeMinor'   => (int) $prices[ $key ]['riderFeeMinor'],
			'buyerPriceMinor' => (int) $prices[ $key ]['buyerPriceMinor'],
		];
	}

	public static function save_band_price( string $from, string $to, float $rider_ghs, float $buyer_ghs ): void {
		$prices = self::band_prices();
		$prices[ $from . '|' . $to ] = [
			'riderFeeMinor'   => self::to_minor( $rider_ghs ),
			'buyerPriceMinor' => self::to_minor( $buyer_ghs ),
		];
		update_option( self::OPT_BAND_PRICES, $prices, false );
		self::mark_dirty();
	}

	public static function clear_band_price( string $from, string $to ): void {
		$prices = self::band_prices();
		unset( $prices[ $from . '|' . $to ] );
		update_option( self::OPT_BAND_PRICES, $prices, false );
		self::mark_dirty();
	}

	// ─── rung 3: distance ───────────────────────────────────────────────────

	/**
	 * The catch-all. Seeded with something sane so a fresh install can price
	 * any route on day one rather than refusing everything.
	 */
	public static function seed_distance_bands(): array {
		return [
			[ 'maxKm' => 5,    'riderFeeMinor' => 1500,  'buyerPriceMinor' => 2000 ],
			[ 'maxKm' => 10,   'riderFeeMinor' => 2500,  'buyerPriceMinor' => 3500 ],
			[ 'maxKm' => 20,   'riderFeeMinor' => 4000,  'buyerPriceMinor' => 5500 ],
			[ 'maxKm' => 50,   'riderFeeMinor' => 7000,  'buyerPriceMinor' => 9500 ],
			// Deliberately huge: without a final catch-all a long route falls
			// off the end of the ladder and reads as "not served".
			[ 'maxKm' => 2000, 'riderFeeMinor' => 15000, 'buyerPriceMinor' => 20000 ],
		];
	}

	public static function distance_bands(): array {
		$bands = get_option( self::OPT_DISTANCE, null );
		return is_array( $bands ) ? $bands : [];
	}

	public static function save_distance_bands( array $bands ): void {
		$clean = [];
		foreach ( $bands as $band ) {
			$max = (float) ( $band['maxKm'] ?? 0 );
			if ( $max <= 0 ) {
				continue;
			}
			$clean[] = [
				'maxKm'           => $max,
				'riderFeeMinor'   => self::to_minor( (float) ( $band['riderFee'] ?? 0 ) ),
				'buyerPriceMinor' => self::to_minor( (float) ( $band['buyerPrice'] ?? 0 ) ),
			];
		}
		usort( $clean, static function ( $a, $b ) {
			return $a['maxKm'] <=> $b['maxKm'];
		} );
		update_option( self::OPT_DISTANCE, $clean, false );
		self::mark_dirty();
	}

	// ─── the API connection ─────────────────────────────────────────────────

	public static function api(): array {
		$api = get_option( self::OPT_API, [] );
		return is_array( $api ) ? $api : [];
	}

	public static function api_base_url(): string {
		return untrailingslashit( (string) ( self::api()['base_url'] ?? '' ) );
	}

	/**
	 * Decrypted through the marketplace plugin's vault when it is available.
	 * A shared secret in plaintext in wp_options is reachable by any plugin
	 * holding manage_options, or by SQL injection anywhere in the install.
	 */
	public static function shared_secret(): string {
		return self::decrypt( (string) ( self::api()['secret'] ?? '' ) );
	}

	public static function previous_secret(): string {
		return self::decrypt( (string) ( self::api()['secret_prev'] ?? '' ) );
	}

	public static function save_api( string $base_url, string $secret, bool $keep_previous = true ): void {
		$api = self::api();
		$current = (string) ( $api['secret'] ?? '' );

		if ( $keep_previous && $current !== '' && self::decrypt( $current ) !== $secret ) {
			$api['secret_prev'] = $current;
		}

		$api['base_url'] = esc_url_raw( $base_url );
		$api['secret']   = self::encrypt( $secret );
		update_option( self::OPT_API, $api, false );
	}

	public static function retire_previous_secret(): void {
		$api = self::api();
		unset( $api['secret_prev'] );
		update_option( self::OPT_API, $api, false );
	}

	private static function encrypt( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		return class_exists( 'Pokbon_App_Secret_Vault' )
			? Pokbon_App_Secret_Vault::encrypt( $value )
			: $value;
	}

	private static function decrypt( string $value ): string {
		if ( $value === '' ) {
			return '';
		}
		return class_exists( 'Pokbon_App_Secret_Vault' )
			? Pokbon_App_Secret_Vault::decrypt( $value )
			: $value;
	}

	// ─── sync to the API ────────────────────────────────────────────────────

	/**
	 * Every save bumps the version and flags the API as behind. The push
	 * happens on shutdown so an admin save is never held up by an HTTP call to
	 * a service that might be slow or down — this host has no worker to spare.
	 */
	public static function mark_dirty(): void {
		$sync = get_option( self::OPT_SYNC, [] );
		$sync = is_array( $sync ) ? $sync : [];
		$sync['version'] = (int) ( $sync['version'] ?? 0 ) + 1;
		$sync['dirty']   = true;
		update_option( self::OPT_SYNC, $sync, false );

		if ( ! has_action( 'shutdown', [ 'Pokbon_Delivery_Settings', 'push_if_dirty' ] ) ) {
			add_action( 'shutdown', [ 'Pokbon_Delivery_Settings', 'push_if_dirty' ] );
		}
	}

	public static function sync_version(): int {
		$sync = get_option( self::OPT_SYNC, [] );
		return (int) ( $sync['version'] ?? 0 );
	}

	public static function is_dirty(): bool {
		$sync = get_option( self::OPT_SYNC, [] );
		return ! empty( $sync['dirty'] );
	}

	public static function last_sync(): array {
		$sync = get_option( self::OPT_SYNC, [] );
		return is_array( $sync ) ? $sync : [];
	}

	public static function push_if_dirty(): void {
		if ( ! self::is_dirty() ) {
			return;
		}
		self::push();
	}

	/** The payload shape the API's POST /plugin/settings/sync expects. */
	public static function sync_payload(): array {
		$prices = [];
		foreach ( self::prices() as $key => $row ) {
			[ $from, $to ] = array_pad( explode( '|', $key ), 2, '' );
			if ( $from === '' || $to === '' ) {
				continue;
			}
			$prices[] = [
				'fromZoneCode' => $from,
				'toZoneCode'   => $to,
				'riderFee'     => self::from_minor( (int) $row['riderFeeMinor'] ),
				'buyerPrice'   => self::from_minor( (int) $row['buyerPriceMinor'] ),
				'active'       => true,
			];
		}

		$band_prices = [];
		foreach ( self::band_prices() as $key => $row ) {
			[ $from, $to ] = array_pad( explode( '|', $key ), 2, '' );
			if ( $from === '' || $to === '' ) {
				continue;
			}
			$band_prices[] = [
				'fromBand'   => $from,
				'toBand'     => $to,
				'riderFee'   => self::from_minor( (int) $row['riderFeeMinor'] ),
				'buyerPrice' => self::from_minor( (int) $row['buyerPriceMinor'] ),
				'active'     => true,
			];
		}

		$distance = [];
		foreach ( self::distance_bands() as $row ) {
			$distance[] = [
				'maxKm'      => (float) $row['maxKm'],
				'riderFee'   => self::from_minor( (int) $row['riderFeeMinor'] ),
				'buyerPrice' => self::from_minor( (int) $row['buyerPriceMinor'] ),
				'active'     => true,
			];
		}

		return [
			'version'       => self::sync_version(),
			'zones'         => array_values( self::zones() ),
			'prices'        => $prices,
			'bands'         => array_values( self::bands() ),
			'bandPrices'    => $band_prices,
			'distanceBands' => $distance,
			'settings'      => self::all(),
		];
	}

	/** Returns true when the API accepted the push. */
	public static function push(): bool {
		$result = Pokbon_Delivery_API_Client::post( '/plugin/settings/sync', self::sync_payload() );

		$sync = get_option( self::OPT_SYNC, [] );
		$sync = is_array( $sync ) ? $sync : [];

		if ( is_wp_error( $result ) ) {
			$sync['dirty']      = true;
			$sync['last_error'] = $result->get_error_message();
			$sync['tried_at']   = gmdate( 'c' );
			update_option( self::OPT_SYNC, $sync, false );
			return false;
		}

		$sync['dirty']      = false;
		$sync['last_error'] = '';
		$sync['synced_at']  = gmdate( 'c' );
		update_option( self::OPT_SYNC, $sync, false );
		return true;
	}

	// ─── money ──────────────────────────────────────────────────────────────

	public static function to_minor( float $ghs ): int {
		return (int) round( $ghs * 100 );
	}

	public static function from_minor( int $minor ): float {
		return round( $minor / 100, 2 );
	}

	public static function format( int $minor ): string {
		return 'GH₵' . number_format( $minor / 100, 2 );
	}
}
