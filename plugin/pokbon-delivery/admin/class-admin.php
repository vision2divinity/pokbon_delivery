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

		$pages = [
			self::SLUG              => [ 'Job board', 'render_jobs' ],
			self::SLUG . '-riders'  => [ 'Riders', 'render_riders' ],
			self::SLUG . '-zones'   => [ 'Zones', 'render_zones' ],
			self::SLUG . '-matrix'  => [ 'Price matrix', 'render_matrix' ],
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
					'default_pickup_zone'   => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_zone'] ?? '' ) ),
					'default_pickup_address' => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_address'] ?? '' ) ),
					'default_pickup_contact' => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_contact'] ?? '' ) ),
					'default_pickup_phone'  => sanitize_text_field( (string) wp_unslash( $_POST['default_pickup_phone'] ?? '' ) ),
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
				Pokbon_Delivery_Settings::save_zone( [
					'code'         => $code,
					'name'         => (string) wp_unslash( $_POST['name'] ?? '' ),
					'region'       => (string) wp_unslash( $_POST['region'] ?? '' ),
					'lat'          => (float) ( $_POST['lat'] ?? 0 ),
					'lng'          => (float) ( $_POST['lng'] ?? 0 ),
					'radiusMetres' => (int) ( $_POST['radius'] ?? 5000 ),
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
					$saved++;
				}

				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PRICE_SAVED, [
					'saved'   => $saved,
					'cleared' => $cleared,
				] );
				$notice = sprintf( '%d route(s) priced, %d cleared.', $saved, $cleared ) . $notice;
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

				$result = Pokbon_Delivery_Orders::create_jobs_for_order( $order );
				if ( empty( $result['created'] ) ) {
					$error = 'No job was created. ' . implode( ' ', $result['skipped'] );
				} else {
					$notice = sprintf( '%d delivery job(s) created for order #%d.', count( $result['created'] ), $order_id );
				}
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

	public static function render_zones(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/zones.php';
	}

	public static function render_matrix(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/matrix.php';
	}

	public static function render_settings(): void {
		require POKBON_DELIVERY_DIR . 'admin/pages/settings.php';
	}
}
