<?php
/**
 * The admin side. PRD § 12.
 *
 * Five screens: the live job board, the rider queue, zones, the price matrix,
 * and settings. Conventions copied from the marketplace plugin because they
 * were each learned the hard way — nonce and capability on every write, an
 * audit row for every admin-visible action, and a typed confirmation on
 * anything that cannot be undone.
 *
 * Every page degrades to a clear message when the Delivery API is unreachable.
 * An admin screen that shows an empty table when the service is down is worse
 * than one that says the service is down.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Admin {

	const SLUG = 'pokbon-delivery';

	public static function bootstrap(): void {
		add_action( 'admin_menu', [ self::class, 'menu' ] );
		add_action( 'admin_post_pokbon_delivery_action', [ self::class, 'handle_post' ] );
	}

	public static function menu(): void {
		add_menu_page(
			'POKBON Delivery',
			'POKBON Delivery',
			POKBON_DELIVERY_CAP,
			self::SLUG,
			[ self::class, 'render_jobs' ],
			'dashicons-location-alt',
			57
		);

		/*
		 * Every page has to be listed here to exist.
		 *
		 * A page file and a render method are not a page. Payouts and
		 * Collection points both shipped with a file, a render method, working
		 * forms and handlers — and no line in this array, so there was no way
		 * to open either of them. Nothing errored; they simply were not there.
		 * `scripts/check-plugin-forms.mjs` now fails when a file in
		 * admin/pages/ is not reachable from this map.
		 */
		$pages = [
			self::SLUG              => [ 'Job board', 'render_jobs' ],
			self::SLUG . '-riders'  => [ 'Riders', 'render_riders' ],
			self::SLUG . '-payouts' => [ 'Payouts', 'render_payouts' ],
			self::SLUG . '-zones'   => [ 'Zones', 'render_zones' ],
			self::SLUG . '-collection' => [ 'Collection points', 'render_collection_points' ],
			self::SLUG . '-matrix'  => [ 'Price matrix', 'render_matrix' ],
			self::SLUG . '-reconciliation' => [ 'Reconciliation', 'render_reconciliation' ],
			self::SLUG . '-messages' => [ 'Messages', 'render_messages' ],
			self::SLUG . '-brand'   => [ 'Brand & app', 'render_brand' ],
			self::SLUG . '-settings' => [ 'Settings', 'render_settings' ],
		];

		foreach ( $pages as $slug => [ $title, $callback ] ) {
			add_submenu_page(
				self::SLUG,
				'POKBON Delivery — ' . $title,
				$title,
				POKBON_DELIVERY_CAP,
				$slug,
				[ self::class, $callback ]
			);
		}
	}

	// ─── writes ─────────────────────────────────────────────────────────────

	/**
	 * One entry point for every form on every page.
	 *
	 * Capability first, then nonce, then the action. Anything that reaches the
	 * switch has already proved both.
	 */
	public static function handle_post(): void {
		if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
			wp_die( 'You do not have permission to manage delivery.', 'Forbidden', [ 'response' => 403 ] );
		}

		$action = isset( $_POST['pokbon_action'] ) ? sanitize_key( wp_unslash( $_POST['pokbon_action'] ) ) : '';
		check_admin_referer( 'pokbon_delivery_' . $action );

		$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::SLUG );
		$notice   = '';
		$error    = '';

		switch ( $action ) {

			case 'save_api':
				$base   = isset( $_POST['base_url'] ) ? esc_url_raw( wp_unslash( $_POST['base_url'] ) ) : '';
				$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : '';

				if ( $secret !== '' && strlen( $secret ) < 32 ) {
					$error = 'The shared secret must be at least 32 characters. Generate one with the button below.';
					break;
				}
				// An empty secret field means "leave it alone", so a careless
				// save cannot silently disconnect the service.
				if ( $secret === '' ) {
					$secret = Pokbon_Delivery_Settings::shared_secret();
				}

				Pokbon_Delivery_Settings::save_api( $base, $secret );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_API_SAVED, [ 'base_url' => $base ] );
				$notice = 'Connection saved.';
				break;

			case 'test_api':
				$result = Pokbon_Delivery_API_Client::effective_settings();
				if ( is_wp_error( $result ) ) {
					$error = 'Could not reach the Delivery API: ' . $result->get_error_message();
				} else {
					$notice = sprintf(
						'Connected. The API is running settings version %d with %d zone(s).',
						(int) ( $result['version'] ?? 0 ),
						is_array( $result['zones'] ?? null ) ? count( $result['zones'] ) : 0
					);
				}
				break;

			case 'push_sync':
				$ok = Pokbon_Delivery_Settings::push();
				if ( $ok ) {
					Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_SYNC_PUSHED, [
						'version' => Pokbon_Delivery_Settings::sync_version(),
					] );
					$notice = 'Zones, prices and settings pushed to the Delivery API.';
				} else {
					$last  = Pokbon_Delivery_Settings::last_sync();
					$error = 'The push failed: ' . ( $last['last_error'] ?? 'unknown error' );
				}
				break;

			case 'save_settings':
				$values = [
					'offer_timeout_seconds' => max( 10, (int) ( $_POST['offer_timeout_seconds'] ?? 45 ) ),
					'offer_cascade_depth'   => max( 1, (int) ( $_POST['offer_cascade_depth'] ?? 5 ) ),
					'offer_radius_metres'   => max( 500, (int) ( $_POST['offer_radius_metres'] ?? 8000 ) ),
					'code_expiry_minutes'   => max( 5, (int) ( $_POST['code_expiry_minutes'] ?? 120 ) ),
					'code_max_attempts'     => max( 1, (int) ( $_POST['code_max_attempts'] ?? 5 ) ),
					'code_max_sends'        => max( 1, (int) ( $_POST['code_max_sends'] ?? 3 ) ),
					'payment_wait_minutes'  => max( 1, (int) ( $_POST['payment_wait_minutes'] ?? 10 ) ),
					'payment_max_prompts'   => max( 1, (int) ( $_POST['payment_max_prompts'] ?? 3 ) ),
					'payout_cycle'          => in_array( ( $_POST['payout_cycle'] ?? '' ), [ 'daily', 'weekly', 'fortnightly' ], true )
						? sanitize_key( wp_unslash( $_POST['payout_cycle'] ) ) : 'weekly',
					'auto_create_jobs'      => ! empty( $_POST['auto_create_jobs'] ),
					// 'auto' and '' are both meaningful here, so this is not
					// run through a "pick one of the known statuses" guard.
					'payout_notify_phone'       => sanitize_text_field( (string) wp_unslash( $_POST['payout_notify_phone'] ?? '' ) ),
					'require_delivery_coverage' => ! empty( $_POST['require_delivery_coverage'] ),
					'order_status_on_assigned'  => sanitize_key( (string) wp_unslash( $_POST['order_status_on_assigned'] ?? 'ready-to-ship' ) ),
					'order_status_on_picked_up' => sanitize_key( (string) wp_unslash( $_POST['order_status_on_picked_up'] ?? 'in-transit' ) ),
					'order_status_on_delivered' => sanitize_key( (string) wp_unslash( $_POST['order_status_on_delivered'] ?? 'auto' ) ),
					'order_status_on_failed'    => sanitize_key( (string) wp_unslash( $_POST['order_status_on_failed'] ?? '' ) ),
					'rename_cod_label'      => ! empty( $_POST['rename_cod_label'] ),
					'default_pickup_zone'   => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_zone'] ?? '' ) ),
					'default_pickup_address' => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_address'] ?? '' ) ),
					'default_pickup_contact' => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_contact'] ?? '' ) ),
					'default_pickup_phone'  => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_phone'] ?? '' ) ),
					'default_pickup_note'   => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_note'] ?? '' ) ),
				];

				// The failed-trip uplift and the default markup are entered as
				// percentages because that is how the owner thinks about them.
				$values['failed_trip_uplift'] = [ 'rateBps' => (int) round( (float) ( $_POST['failed_trip_uplift_pct'] ?? 20 ) * 100 ) ];
				$values['default_markup']     = [ 'rateBps' => (int) round( (float) ( $_POST['default_markup_pct'] ?? 33.33 ) * 100 ) ];

				Pokbon_Delivery_Settings::save_settings( $values );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_SETTINGS_SAVED, [ 'keys' => array_keys( $values ) ] );
				$notice = 'Settings saved and queued for the Delivery API.';
				break;

			case 'save_commission':
				$rows = [];
				$dates = (array) ( $_POST['commission_from'] ?? [] );
				$rates = (array) ( $_POST['commission_pct'] ?? [] );

				foreach ( $dates as $i => $date ) {
					$date = sanitize_text_field( (string) wp_unslash( $date ) );
					$pct  = (float) ( $rates[ $i ] ?? 0 );
					if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || $pct <= 0 ) {
						continue;
					}
					$rows[] = [ 'effectiveFrom' => $date, 'rateBps' => (int) round( $pct * 100 ) ];
				}

				usort( $rows, static function ( $a, $b ) {
					return strcmp( $a['effectiveFrom'], $b['effectiveFrom'] );
				} );

				Pokbon_Delivery_Settings::save_settings( [ 'rider_commission_schedule' => $rows ] );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_SETTINGS_SAVED, [
					'rider_commission_schedule' => $rows,
				] );
				$notice = empty( $rows )
					? 'Commission schedule cleared. Riders pay nothing.'
					: sprintf( 'Commission schedule saved: %d step(s).', count( $rows ) );
				break;

			case 'save_zone':
				$code = strtoupper( sanitize_key( (string) wp_unslash( $_POST['code'] ?? '' ) ) );
				if ( $code === '' ) {
					$error = 'A zone needs a code.';
					break;
				}

				/*
				 * A centre that is not a place on Earth is refused here, in words.
				 *
				 * It used to be `(float) $_POST['lng']` and nothing else, so a
				 * lost decimal point — -0.1651 typed as -1651 — was saved
				 * without complaint. The zone then looked fine on this screen,
				 * and the only symptom appeared on a different page: the whole
				 * settings push to the delivery service was rejected, naming
				 * `zones.7.lng`, which is an array index and tells nobody which
				 * zone it means.
				 *
				 * The cost is not the typo. It is that ONE bad zone stops EVERY
				 * zone, price and setting from reaching the API — riders go on
				 * being dispatched from the last good copy, so new zones do
				 * nothing and changed radii do nothing, and the screen that says
				 * so is not the screen you were working on.
				 */
				$zone_lat = (float) ( $_POST['lat'] ?? 0 );
				$zone_lng = (float) ( $_POST['lng'] ?? 0 );
				if ( $zone_lat < -90 || $zone_lat > 90 || $zone_lng < -180 || $zone_lng > 180 ) {
					$error = sprintf(
						'That centre is not a place: %s, %s. Latitude runs -90 to 90 and longitude -180 to 180 — Ghana is about 4.7 to 11.2 and -3.3 to 1.2. A lost decimal point is the usual cause.',
						$zone_lat,
						$zone_lng
					);
					break;
				}

				Pokbon_Delivery_Settings::save_zone( [
					'code'         => $code,
					'name'         => (string) wp_unslash( $_POST['name'] ?? '' ),
					'region'       => (string) wp_unslash( $_POST['region'] ?? '' ),
					'lat'          => $zone_lat,
					'lng'          => $zone_lng,
					'radiusMetres' => (int) ( $_POST['radius'] ?? 5000 ),
					'band'         => (string) wp_unslash( $_POST['band'] ?? '' ),
					'active'       => ! empty( $_POST['active'] ),
				] );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_ZONE_SAVED, [ 'code' => $code ] );
				$notice = sprintf( 'Zone %s saved.', $code );
				break;

			case 'deactivate_zone':
				$code = strtoupper( sanitize_key( (string) wp_unslash( $_POST['code'] ?? '' ) ) );
				Pokbon_Delivery_Settings::deactivate_zone( $code );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_ZONE_DEACTIVATED, [ 'code' => $code ] );
				$notice = sprintf( 'Zone %s switched off. Its history and past prices are kept.', $code );
				break;

			case 'save_matrix':
				$saved   = 0;
				$cleared = 0;
				$rider   = (array) ( $_POST['rider'] ?? [] );
				$buyer   = (array) ( $_POST['buyer'] ?? [] );
				/*
				 * On/off per route, applied after the amounts are written.
				 *
				 * Off is not zero and not deleted: the price stays, and the
				 * route falls through to the band and then to distance. That
				 * is the point of being able to switch one off — you take a
				 * route out of service for a week without losing what you had
				 * decided to charge for it.
				 */
				$active  = (array) ( $_POST['active'] ?? [] );

				foreach ( $rider as $pair => $rider_raw ) {
					[ $from, $to ] = array_pad( explode( '|', sanitize_text_field( $pair ) ), 2, '' );
					if ( $from === '' || $to === '' ) {
						continue;
					}

					$rider_ghs = trim( (string) $rider_raw );
					$buyer_ghs = trim( (string) ( $buyer[ $pair ] ?? '' ) );

					// Both blank means "we do not serve this route".
					if ( $rider_ghs === '' && $buyer_ghs === '' ) {
						Pokbon_Delivery_Settings::clear_price( $from, $to );
						$cleared++;
						continue;
					}

					// Applied below, once the amounts are saved: save_price()
					// preserves the existing flag, so setting it afterwards is
					// what actually moves it.
					$want_active = ! array_key_exists( $pair, $active ) || ! empty( $active[ $pair ] );
					if ( $rider_ghs === '' ) {
						continue;
					}

					$rider_minor = Pokbon_Delivery_Settings::to_minor( (float) $rider_ghs );
					$buyer_minor = $buyer_ghs === ''
						? Pokbon_Delivery_Settings::marked_up( $rider_minor )
						: Pokbon_Delivery_Settings::to_minor( (float) $buyer_ghs );

					if ( $buyer_minor < $rider_minor ) {
						// Allowed — the owner may subsidise a route on purpose —
						// but it must be a decision, so it is reported back.
						$notice .= sprintf( ' %s → %s is priced below the rider fee.', $from, $to );
					}

					Pokbon_Delivery_Settings::save_price( $from, $to, $rider_minor / 100, $buyer_minor / 100 );
					Pokbon_Delivery_Settings::set_price_active( $from, $to, $want_active );
					$saved++;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PRICE_SAVED, [
					'saved'   => $saved,
					'cleared' => $cleared,
				] );
				$notice = sprintf( '%d route(s) priced, %d cleared.', $saved, $cleared ) . $notice;
				break;

			case 'save_messages':
				$texts = (array) ( $_POST['message_text'] ?? [] );
				$on    = (array) ( $_POST['message_on'] ?? [] );

				$defaults = Pokbon_Delivery_Settings::defaults()['messages'];
				$saved    = [];
				$warnings = [];

				foreach ( $defaults as $key => $default ) {
					$text = isset( $texts[ $key ] )
						? sanitize_textarea_field( (string) wp_unslash( $texts[ $key ] ) )
						: (string) $default['text'];
					$text = trim( $text );

					// An empty box means "use the shipped wording", not "send
					// an empty message". Somebody clearing a field to start
					// again should not silently disable it.
					if ( $text === '' ) {
						$text = (string) $default['text'];
					}

					// The link is the entire point of that message.
					if ( $key === 'pay_by_link' && strpos( $text, '{link}' ) === false ) {
						$warnings[] = 'The payment link message must contain {link}; the previous wording was kept.';
						$existing   = Pokbon_Delivery_Settings::get( 'messages' );
						$text       = (string) ( $existing[ $key ]['text'] ?? $default['text'] );
					}
					// Likewise the codes.
					if ( in_array( $key, [ 'rider_otp', 'delivery_code' ], true ) && strpos( $text, '{code}' ) === false ) {
						$warnings[] = sprintf( 'The %s message must contain {code}; the previous wording was kept.', str_replace( '_', ' ', $key ) );
						$existing   = Pokbon_Delivery_Settings::get( 'messages' );
						$text       = (string) ( $existing[ $key ]['text'] ?? $default['text'] );
					}

					$saved[ $key ] = [
						'enabled' => ! empty( $on[ $key ] ),
						'text'    => $text,
					];
				}

				Pokbon_Delivery_Settings::save_settings( [ 'messages' => $saved ] );

				$notice = 'Messages saved. They take effect on the next message sent.';
				if ( $warnings !== [] ) {
					$error = implode( ' ', $warnings );
				}
				break;

			case 'edit_rider':
				/*
				 * The same upsert as add_rider, minus the two things that make
				 * it a decision.
				 *
				 * `status` goes as UNCHANGED, because add_rider defaults it to
				 * APPROVED and leaves DRAFT when a box is unticked — so reusing
				 * that path to fix a registration plate would have approved a
				 * rider still waiting on their documents, or demoted an approved
				 * one, as a side effect. And the agreement is not touched at
				 * all: nobody signs anything by having their zone corrected.
				 */
				$edit_phone = trim( (string) wp_unslash( $_POST['phone'] ?? '' ) );
				$edit_name  = trim( (string) wp_unslash( $_POST['full_name'] ?? '' ) );
				if ( $edit_phone === '' || $edit_name === '' ) {
					$error = 'A rider needs at least a name and a phone number.';
					break;
				}

				$edit = array_filter(
					[
						'vehicleRegistration' => sanitize_text_field( (string) wp_unslash( $_POST['vehicle_registration'] ?? '' ) ),
						'baseZoneCode'        => strtoupper( sanitize_key( (string) wp_unslash( $_POST['base_zone'] ?? '' ) ) ),
						'momoNumber'          => trim( (string) wp_unslash( $_POST['momo_number'] ?? '' ) ),
						'licenceNumber'       => sanitize_text_field( (string) wp_unslash( $_POST['licence_number'] ?? '' ) ),
						'nextOfKinName'       => sanitize_text_field( (string) wp_unslash( $_POST['next_of_kin_name'] ?? '' ) ),
						'nextOfKinPhone'      => trim( (string) wp_unslash( $_POST['next_of_kin_phone'] ?? '' ) ),
					],
					static function ( $v ) {
						return $v !== '' && $v !== null;
					}
				);

				$edit['phone']        = $edit_phone;
				$edit['fullName']     = sanitize_text_field( $edit_name );
				$edit['vehicleClass'] = strtoupper( sanitize_key( (string) wp_unslash( $_POST['vehicle_class'] ?? 'MOTORBIKE' ) ) );
				$edit['status']       = 'UNCHANGED';
				$edit['actor']        = wp_get_current_user()->user_login;

				$edited = Pokbon_Delivery_API_Client::upsert_rider( $edit );
				if ( is_wp_error( $edited ) ) {
					$error = $edited->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( 'delivery.rider_edited', [
					'rider_id' => sanitize_text_field( (string) wp_unslash( $_POST['rider_id'] ?? '' ) ),
					'fields'   => array_keys( $edit ),
					'actor'    => wp_get_current_user()->user_login,
				] );
				$notice = 'Details saved. Their status is unchanged.';
				break;

			case 'add_rider':
				$phone = trim( (string) wp_unslash( $_POST['phone'] ?? '' ) );
				$name  = trim( (string) wp_unslash( $_POST['full_name'] ?? '' ) );

				if ( $phone === '' || $name === '' ) {
					$error = 'A rider needs at least a name and a phone number.';
					break;
				}

				$payload = array_filter(
					[
						'phone'                  => $phone,
						'fullName'               => sanitize_text_field( $name ),
						'vehicleClass'           => strtoupper( sanitize_key( (string) wp_unslash( $_POST['vehicle_class'] ?? 'MOTORBIKE' ) ) ),
						'vehicleRegistration'    => sanitize_text_field( (string) wp_unslash( $_POST['vehicle_registration'] ?? '' ) ),
						'baseZoneCode'           => strtoupper( sanitize_key( (string) wp_unslash( $_POST['base_zone'] ?? '' ) ) ),
						'momoNumber'             => trim( (string) wp_unslash( $_POST['momo_number'] ?? '' ) ),
						'licenceNumber'          => sanitize_text_field( (string) wp_unslash( $_POST['licence_number'] ?? '' ) ),
						'idType'                 => strtoupper( sanitize_key( (string) wp_unslash( $_POST['id_type'] ?? '' ) ) ),
						'idNumber'               => sanitize_text_field( (string) wp_unslash( $_POST['id_number'] ?? '' ) ),
						'nextOfKinName'          => sanitize_text_field( (string) wp_unslash( $_POST['next_of_kin_name'] ?? '' ) ),
						'nextOfKinPhone'         => trim( (string) wp_unslash( $_POST['next_of_kin_phone'] ?? '' ) ),
						'note'                   => sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) ),
					],
					static function ( $v ) {
						return $v !== '' && $v !== null;
					}
				);

				// Booleans and enums are set after the filter, because `false`
				// and a deliberate DRAFT would both be stripped by it.
				$payload['status']                 = ! empty( $_POST['approve_now'] ) ? 'APPROVED' : 'DRAFT';
				$payload['agreementSignedOnPaper'] = ! empty( $_POST['agreement_signed'] );
				$payload['actor']                  = wp_get_current_user()->user_login;

				$result = Pokbon_Delivery_API_Client::upsert_rider( $payload );

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_RIDER_DECISION, [
					'action'   => empty( $result['created'] ) ? 'updated' : 'created',
					'phone'    => $phone,
					'approved' => $payload['status'] === 'APPROVED',
					'paper'    => $payload['agreementSignedOnPaper'],
				] );

				$notice = empty( $result['created'] )
					? sprintf( '%s already had an account on that number, so it was updated rather than duplicated.', $name )
					: sprintf( '%s added. They sign in on the app with %s and will get a code by SMS.', $name, $phone );

				if ( ! empty( $result['statusHeld'] ) ) {
					$notice .= sprintf(
						' Their status stays %s — change it on their own screen rather than here.',
						strtolower( (string) $result['statusHeld'] )
					);
				}
				if ( empty( $payload['agreementSignedOnPaper'] ) ) {
					$notice .= ' They must accept the contractor agreement in the app before they can go on duty.';
				}
				break;

			case 'rider_decision':
				$rider_id = sanitize_text_field( (string) wp_unslash( $_POST['rider_id'] ?? '' ) );
				$decision = sanitize_key( (string) wp_unslash( $_POST['decision'] ?? '' ) );

				if ( ! in_array( $decision, [ 'approve', 'reject', 'suspend', 'reinstate' ], true ) ) {
					$error = 'Unknown decision.';
					break;
				}

				$result = Pokbon_Delivery_API_Client::rider_decision( $rider_id, [
					'decision' => $decision,
					'note'     => sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) ),
					'actor'    => wp_get_current_user()->user_login,
					'idVerificationLevel' => sanitize_text_field( (string) wp_unslash( $_POST['id_level'] ?? '' ) ) ?: null,
				] );

				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_RIDER_DECISION, [
					'rider_id' => $rider_id,
					'decision' => $decision,
				] );
				$notice = sprintf( 'Rider %s.', $decision . 'd' );
				break;

			case 'assign_job':
				$job_id   = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
				$rider_id = sanitize_text_field( (string) wp_unslash( $_POST['rider_id'] ?? '' ) );

				$result = Pokbon_Delivery_API_Client::assign_job( $job_id, $rider_id, wp_get_current_user()->user_login );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_ASSIGNED, [
					'job_id'   => $job_id,
					'rider_id' => $rider_id,
				] );
				$notice = 'Rider assigned.';
				break;

			case 'offer_job':
				$job_id = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
				$result = Pokbon_Delivery_API_Client::offer_job( $job_id );
				$error  = is_wp_error( $result ) ? $result->get_error_message() : '';
				if ( $error === '' ) {
					$notice = empty( $result['offered'] )
						? 'Nobody eligible to offer this to right now.'
						: 'Offered to the nearest eligible rider.';
				}
				break;

			case 'bypass_code':
				$job_id = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
				$reason = trim( (string) wp_unslash( $_POST['reason'] ?? '' ) );

				// A bypass lets a delivery complete without the buyer proving
				// who they are. It is sometimes genuinely necessary, and it is
				// the single most abusable action here — so it takes a real
				// reason, is audited by name, and is reported weekly.
				if ( strlen( $reason ) < 10 ) {
					$error = 'Give a real reason for bypassing the code — at least a sentence. It is shown to the buyer and reviewed weekly.';
					break;
				}

				$result = Pokbon_Delivery_API_Client::bypass_code( $job_id, $reason, wp_get_current_user()->user_login );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_CODE_BYPASSED, [
					'job_id' => $job_id,
					'reason' => $reason,
					'actor'  => wp_get_current_user()->user_login,
				] );
				$notice = 'Code bypassed. The buyer is told this was confirmed by POKBON, not by code.';
				break;

			case 'save_vendor_pickup':
				$vendor_id = (int) ( $_POST['vendor_id'] ?? 0 );
				if ( $vendor_id <= 0 ) {
					$error = 'No vendor.';
					break;
				}

				if ( ! empty( $_POST['clear'] ) ) {
					Pokbon_Delivery_Settings::save_vendor_pickup( $vendor_id, null );
					$notice = 'Collection point cleared. This vendor falls back to their own pin, then to your default.';
					break;
				}

				/*
				 * Every field is optional, and whatever is given wins.
				 *
				 * This used to demand a pin, then a phone, then a zone the pin
				 * fell inside, and refuse the save if any were missing. So the
				 * screen built to stop vendors falling back to the default
				 * collection point would not let you rescue the vendors who
				 * most needed it — the one with a shop outside every zone, the
				 * one whose store profile has no phone.
				 *
				 * It is a set of overrides now. Fill in what you know. A zone on
				 * its own is enough to price and dispatch; a pin on its own is
				 * enough to navigate; anything left blank falls through to the
				 * vendor's own store profile exactly as before.
				 */
				$pin_raw = trim( (string) wp_unslash( $_POST['pin'] ?? '' ) );
				$pin     = $pin_raw === '' ? null : Pokbon_Delivery_Orders::parse_pin( $pin_raw );
				if ( $pin_raw !== '' && $pin === null ) {
					$error = 'That does not look like a pin. Paste "5.6689, -0.1651" or a Google Maps link, or leave it blank.';
					break;
				}

				$pickup_phone = Pokbon_Delivery_Messages::normalise_ghana_phone(
					(string) wp_unslash( $_POST['contact_phone'] ?? '' )
				);

				$pickup_zone = strtoupper( sanitize_key( (string) wp_unslash( $_POST['zone_code'] ?? '' ) ) );
				if ( $pickup_zone !== '' && ! Pokbon_Delivery_Settings::zone( $pickup_zone ) ) {
					$error = 'That is not one of your zones.';
					break;
				}

				// A pin inside a zone you have set up names its own zone, so
				// there is no need to pick one as well. Only what the pin
				// cannot answer has to be typed.
				if ( $pickup_zone === '' && $pin !== null ) {
					$pickup_zone = Pokbon_Delivery_Geo::resolve_zone_code( $pin[0], $pin[1] );
				}

				$row = array_filter( [
					'lat'          => $pin === null ? null : $pin[0],
					'lng'          => $pin === null ? null : $pin[1],
					'zoneCode'     => $pickup_zone !== '' ? $pickup_zone : null,
					'address'      => sanitize_text_field( (string) wp_unslash( $_POST['address'] ?? '' ) ) ?: null,
					'contactName'  => ( (string) get_user_meta( $vendor_id, 'store_name', true ) ) ?: null,
					'contactPhone' => $pickup_phone !== '' ? $pickup_phone : null,
				], static function ( $v ) {
					return $v !== null;
				} );

				if ( $row === [] ) {
					$error = 'Nothing to save. Fill in at least one of the pin, the zone, the address or the phone.';
					break;
				}

				Pokbon_Delivery_Settings::save_vendor_pickup( $vendor_id, $row );

				Pokbon_Delivery_Audit::log( 'delivery.vendor_pickup_set', [
					'vendor_id' => $vendor_id,
					'zone'      => $pickup_zone,
					'fields'    => array_keys( $row ),
				] );

				// Say what will actually happen now, rather than "saved".
				$diag   = Pokbon_Delivery_Orders::pickup_diagnosis( $vendor_id );
				$notice = $diag['source'] === 'filter'
					? sprintf(
						'Saved. Riders collect from %s for this vendor.',
						$pickup_zone !== '' ? 'zone ' . $pickup_zone : 'the point you set'
					)
					: sprintf(
						'Saved, but this vendor still resolves to %s. %s',
						$diag['source'] === 'default' ? 'YOUR DEFAULT collection point' : $diag['source'],
						$diag['why']
					);
				break;

			case 'settle_payout':
				$request_id = sanitize_text_field( (string) wp_unslash( $_POST['request_id'] ?? '' ) );
				$amount     = (float) ( $_POST['amount'] ?? 0 );
				$pay_note   = sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) );

				if ( $amount <= 0 ) {
					$error = 'Enter what you actually sent the rider.';
					break;
				}

				$result = Pokbon_Delivery_API_Client::settle_payout(
					$request_id,
					$amount,
					wp_get_current_user()->user_login,
					$pay_note
				);
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( 'delivery.payout_settled', [
					'request_id' => $request_id,
					'amount'     => $amount,
				] );
				$notice = sprintf(
					'Recorded. The rider is owed %s less than before.',
					Pokbon_Delivery_Settings::format( (int) round( $amount * 100 ) )
				);
				break;

			case 'decline_payout':
				$request_id = sanitize_text_field( (string) wp_unslash( $_POST['request_id'] ?? '' ) );
				$pay_note   = sanitize_text_field( (string) wp_unslash( $_POST['note'] ?? '' ) );

				if ( strlen( trim( $pay_note ) ) < 5 ) {
					$error = 'Say why. The rider reads this, and "no" on its own is not something they can act on.';
					break;
				}

				$result = Pokbon_Delivery_API_Client::decline_payout(
					$request_id,
					wp_get_current_user()->user_login,
					$pay_note
				);
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}
				$notice = 'Declined, with your reason recorded against it.';
				break;

			case 'return_job':
				$job_id = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
				$reason = sanitize_text_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );

				$result = Pokbon_Delivery_API_Client::return_job(
					$job_id,
					$reason ?: 'Closed by POKBON: goods back with the sender',
					wp_get_current_user()->user_login
				);
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CANCELLED, [
					'job_id' => $job_id,
					'reason' => $reason,
					'closed' => 'returned',
				] );
				$notice = 'Job closed: the goods are back with the sender.';
				break;

			case 'cancel_job':
				$job_id = sanitize_text_field( (string) wp_unslash( $_POST['job_id'] ?? '' ) );
				$reason = sanitize_text_field( (string) wp_unslash( $_POST['reason'] ?? '' ) );

				$result = Pokbon_Delivery_API_Client::cancel_job( $job_id, $reason ?: 'Cancelled by POKBON', wp_get_current_user()->user_login );
				if ( is_wp_error( $result ) ) {
					$error = $result->get_error_message();
					break;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CANCELLED, [
					'job_id' => $job_id,
					'reason' => $reason,
				] );
				$notice = 'Job cancelled.';
				break;

			case 'dispatch_order':
				$order_id = (int) ( $_POST['order_id'] ?? 0 );
				$order    = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

				if ( ! $order ) {
					$error = 'Order not found.';
					break;
				}

				// A zone chosen on the order panel, for an order with no pin.
				$zone = strtoupper( sanitize_key( (string) wp_unslash( $_POST['dispatch_zone'] ?? '' ) ) );
				if ( $zone !== '' ) {
					$order->update_meta_data( Pokbon_Delivery_Orders::META_DISPATCH_ZONE, $zone );
					$order->save();
				}

				$result = Pokbon_Delivery_Orders::create_jobs_for_order( $order );
				if ( empty( $result['created'] ) ) {
					$error = 'No job was created. ' . implode( ' ', $result['skipped'] );
				} else {
					$fresh = count( $result['created'] ) - count( $result['reused'] ?? [] );
					if ( $fresh > 0 ) {
						$notice = sprintf( '%d delivery job(s) created for order #%d.', $fresh, $order_id );
					} else {
						$notice = sprintf(
							'Nothing new was created: order #%d already has %d delivery job(s) at the delivery service, and they were returned unchanged.',
							$order_id,
							count( $result['created'] )
						);
					}
				}
				break;

			case 'save_bands':
				$codes  = (array) ( $_POST['band_code'] ?? [] );
				$names  = (array) ( $_POST['band_name'] ?? [] );
				$active = (array) ( $_POST['band_active'] ?? [] );

				$bands = [];
				foreach ( $codes as $i => $code ) {
					$code = strtoupper( sanitize_key( (string) wp_unslash( $code ) ) );
					if ( $code === '' ) {
						continue;
					}
					$bands[] = [
						'code'   => $code,
						'name'   => sanitize_text_field( (string) wp_unslash( $names[ $i ] ?? $code ) ),
						// A brand-new row has no checkbox keyed to its code yet,
						// so an unknown code defaults to active rather than being
						// saved switched off the moment it is created.
						'active' => array_key_exists( $code, $active ) ? ! empty( $active[ $code ] ) : true,
					];
				}

				Pokbon_Delivery_Settings::save_bands( $bands );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_SETTINGS_SAVED, [ 'bands' => count( $bands ) ] );
				$notice = sprintf( '%d band(s) saved.', count( $bands ) );
				break;

			case 'save_band_matrix':
				$saved   = 0;
				$cleared = 0;
				$rider   = (array) ( $_POST['brider'] ?? [] );
				$buyer   = (array) ( $_POST['bbuyer'] ?? [] );
				// Off keeps the band price and steps it aside, so the route
				// falls through to distance.
				$active  = (array) ( $_POST['bactive'] ?? [] );

				foreach ( $rider as $pair => $rider_raw ) {
					[ $from, $to ] = array_pad( explode( '|', sanitize_text_field( $pair ) ), 2, '' );
					if ( $from === '' || $to === '' ) {
						continue;
					}

					$rider_ghs = trim( (string) $rider_raw );
					$buyer_ghs = trim( (string) ( $buyer[ $pair ] ?? '' ) );

					if ( $rider_ghs === '' && $buyer_ghs === '' ) {
						Pokbon_Delivery_Settings::clear_band_price( $from, $to );
						$cleared++;
						continue;
					}
					if ( $rider_ghs === '' ) {
						continue;
					}

					$rider_minor = Pokbon_Delivery_Settings::to_minor( (float) $rider_ghs );
					$buyer_minor = $buyer_ghs === ''
						? Pokbon_Delivery_Settings::marked_up( $rider_minor )
						: Pokbon_Delivery_Settings::to_minor( (float) $buyer_ghs );

					Pokbon_Delivery_Settings::save_band_price( $from, $to, $rider_minor / 100, $buyer_minor / 100 );
					Pokbon_Delivery_Settings::set_band_price_active(
						$from,
						$to,
						! array_key_exists( $pair, $active ) || ! empty( $active[ $pair ] )
					);
					$saved++;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PRICE_SAVED, [
					'band_pairs_saved'   => $saved,
					'band_pairs_cleared' => $cleared,
				] );
				$notice = sprintf( '%d band route(s) priced, %d cleared.', $saved, $cleared );
				break;

			case 'save_distance':
				$max   = (array) ( $_POST['dist_max'] ?? [] );
				$rider = (array) ( $_POST['dist_rider'] ?? [] );
				$buyer = (array) ( $_POST['dist_buyer'] ?? [] );

				// Keyed by row index, because the browser omits an unticked
				// checkbox entirely. An unkeyed array would therefore shift,
				// and every row after the first unticked one would take its
				// neighbour's flag — the kind of bug that switches off a price
				// nobody asked to switch off.
				$on = (array) ( $_POST['dist_active'] ?? [] );

				$bands = [];
				foreach ( $max as $i => $km ) {
					if ( trim( (string) $km ) === '' ) {
						continue;
					}
					$bands[] = [
						'maxKm'      => (float) $km,
						'riderFee'   => (float) ( $rider[ $i ] ?? 0 ),
						'buyerPrice' => (float) ( $buyer[ $i ] ?? 0 ),
						'active'     => ! array_key_exists( $i, $on ) || ! empty( $on[ $i ] ),
					];
				}

				Pokbon_Delivery_Settings::save_distance_bands( $bands );

				// Without a large final band a long route falls off the end of
				// the ladder and silently reads as "not served" at checkout.
				$largest = 0.0;
				foreach ( $bands as $b ) {
					$largest = max( $largest, (float) $b['maxKm'] );
				}
				if ( $largest < 100 ) {
					$notice = sprintf(
						'%d distance band(s) saved. Your longest band stops at %skm, so any route beyond that will show as "not served".',
						count( $bands ),
						rtrim( rtrim( number_format( $largest, 1 ), '0' ), '.' )
					);
				} else {
					$notice = sprintf( '%d distance band(s) saved.', count( $bands ) );
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PRICE_SAVED, [
					'distance_bands' => count( $bands ),
				] );
				break;

			case 'save_app_config':
				$posted = isset( $_POST['cfg'] ) ? wp_unslash( $_POST['cfg'] ) : [];
				if ( ! is_array( $posted ) ) {
					$error = 'Nothing to save.';
					break;
				}

				$posted = self::decode_json_leaves( $posted );
				if ( $posted === null ) {
					$error = 'One of the JSON boxes is not valid JSON, so nothing was saved. Fix it and save again.';
					break;
				}

				// Store only what differs from the shipped default, so a later
				// improvement to everything else still reaches this install.
				$diff = self::diff_against( $posted, Pokbon_Delivery_App_Config::defaults() );
				Pokbon_Delivery_App_Config::save_overrides( $diff );

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_APP_CONFIG_SAVED, [
					'changed' => count( $diff, COUNT_RECURSIVE ),
				] );
				$notice = empty( $diff )
					? 'Saved. Everything matches the defaults, so nothing is overridden.'
					: 'Saved and published. Riders pick this up on their next launch.';
				break;

			case 'reset_app_config':
				Pokbon_Delivery_App_Config::save_overrides( [] );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_APP_CONFIG_SAVED, [ 'reset' => true ] );
				$notice = 'Every value is back to the shipped default.';
				break;

			default:
				$error = 'Unknown action.';
		}

		$redirect = add_query_arg( array_filter( [
			'pkbd_notice' => $notice !== '' ? rawurlencode( $notice ) : null,
			'pkbd_error'  => $error !== '' ? rawurlencode( $error ) : null,
		] ), remove_query_arg( [ 'pkbd_notice', 'pkbd_error' ], $redirect ) );

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Turn the JSON textareas back into arrays.
	 *
	 * Returns null on the first thing that is not valid JSON, so a typo in one
	 * box does not silently wipe a list. Half-saving structure is worse than
	 * refusing to save at all.
	 */
	private static function decode_json_leaves( array $node ) {
		foreach ( $node as $key => $value ) {
			if ( ! is_array( $value ) ) {
				continue;
			}
			if ( array_key_exists( '__json', $value ) ) {
				$decoded = json_decode( (string) $value['__json'], true );
				if ( ! is_array( $decoded ) ) {
					return null;
				}
				$node[ $key ] = $decoded;
				continue;
			}
			$child = self::decode_json_leaves( $value );
			if ( $child === null ) {
				return null;
			}
			$node[ $key ] = $child;
		}
		return $node;
	}

	/**
	 * Keep only the leaves that differ from the default.
	 *
	 * Checkboxes post "1"/"0" and numbers post as strings, so values are
	 * compared after being cast to the default's own type — otherwise every
	 * boolean and every number would look "changed" on the first save and the
	 * override store would fill up with copies of the defaults.
	 */
	private static function diff_against( array $posted, array $defaults ): array {
		$diff = [];

		foreach ( $posted as $key => $value ) {
			if ( ! array_key_exists( $key, $defaults ) ) {
				continue; // Ignore anything not in the shipped shape.
			}
			$default = $defaults[ $key ];

			if ( is_array( $default ) && is_array( $value ) && ! Pokbon_Delivery_App_Config::is_list( $default ) ) {
				$child = self::diff_against( $value, $default );
				if ( ! empty( $child ) ) {
					$diff[ $key ] = $child;
				}
				continue;
			}

			if ( is_array( $default ) ) {
				if ( wp_json_encode( $value ) !== wp_json_encode( $default ) ) {
					$diff[ $key ] = $value;
				}
				continue;
			}

			if ( is_bool( $default ) ) {
				$cast = (bool) $value;
			} elseif ( is_int( $default ) ) {
				$cast = (int) $value;
			} elseif ( is_float( $default ) ) {
				$cast = (float) $value;
			} else {
				$cast = sanitize_text_field( (string) $value );
			}

			if ( $cast !== $default ) {
				$diff[ $key ] = $cast;
			}
		}

		return $diff;
	}

	// ─── shared rendering ───────────────────────────────────────────────────

	public static function notices(): void {
		if ( isset( $_GET['pkbd_notice'] ) ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['pkbd_notice'] ) ) ) )
			);
		}
		if ( isset( $_GET['pkbd_error'] ) ) {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['pkbd_error'] ) ) ) )
			);
		}

		if ( ! Pokbon_Delivery_API_Client::is_configured() ) {
			printf(
				'<div class="notice notice-warning"><p><strong>Not connected yet.</strong> '
				. 'Set the Delivery API address and shared secret in <a href="%s">Settings</a>. '
				. 'Until then, zones and prices are saved here but nothing dispatches.</p></div>',
				esc_url( admin_url( 'admin.php?page=' . self::SLUG . '-settings' ) )
			);
			return;
		}

		if ( Pokbon_Delivery_Settings::is_dirty() ) {
			$last = Pokbon_Delivery_Settings::last_sync();
			printf(
				'<div class="notice notice-warning"><p><strong>The Delivery API is behind.</strong> '
				. 'Zones, prices or settings changed here and the push has not succeeded%s. '
				. 'Riders are still being dispatched on the previous values. %s</p></div>',
				$last && ! empty( $last['last_error'] ) ? ': ' . esc_html( $last['last_error'] ) : '',
				self::button( 'push_sync', 'Push now', [], 'button-primary' )
			);
		}
	}

	/** A single-action form. Every write on every page goes through one of these. */
	public static function button( string $action, string $label, array $fields = [], string $class = 'button' ): string {
		$html = sprintf(
			'<form method="post" action="%s" style="display:inline">',
			esc_url( admin_url( 'admin-post.php' ) )
		);
		$html .= '<input type="hidden" name="action" value="pokbon_delivery_action">';
		$html .= sprintf( '<input type="hidden" name="pokbon_action" value="%s">', esc_attr( $action ) );
		$html .= wp_nonce_field( 'pokbon_delivery_' . $action, '_wpnonce', true, false );

		foreach ( $fields as $name => $value ) {
			$html .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( (string) $value )
			);
		}

		$html .= sprintf(
			'<button type="submit" class="%s">%s</button>',
			esc_attr( $class ),
			esc_html( $label )
		);
		return $html . '</form>';
	}

	public static function form_open( string $action ): void {
		printf( '<form method="post" action="%s">', esc_url( admin_url( 'admin-post.php' ) ) );
		echo '<input type="hidden" name="action" value="pokbon_delivery_action">';
		printf( '<input type="hidden" name="pokbon_action" value="%s">', esc_attr( $action ) );
		wp_nonce_field( 'pokbon_delivery_' . $action );
	}

	// ─── pages ──────────────────────────────────────────────────────────────

	public static function render_jobs(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/jobs.php';
	}

	public static function render_riders(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/riders.php';
	}

	public static function render_payouts(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/payouts.php';
	}

	public static function render_zones(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/zones.php';
	}

	public static function render_collection_points(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/collection-points.php';
	}

	public static function render_matrix(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/matrix.php';
	}

	public static function render_reconciliation(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/reconciliation.php';
	}

	public static function render_messages(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/messages.php';
	}

	public static function render_brand(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/brand.php';
	}

	public static function render_settings(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/settings.php';
	}
}
