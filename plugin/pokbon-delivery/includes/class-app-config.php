<?php
/**
 * What the rider app asks the plugin to be, on every launch.
 *
 * THE POINT OF THIS FILE. A change to a colour, a button label, a warning, a
 * rule or a whole screen should be something the owner does in wp-admin and
 * sees on riders' phones the same hour — not a release, a store review and a
 * week of waiting for people to update. So the app ships as a shell that asks
 * this endpoint what to render, and almost nothing user-facing is baked into
 * the binary.
 *
 * What genuinely cannot live here: anything the phone needs before it can
 * reach the network (the splash screen), anything the operating system reads
 * from the package (the launcher icon, the app name, permission strings), and
 * native capability. Everything else belongs here.
 *
 * THE THEME IS THE MARKETPLACE'S THEME. These tokens are copied from the
 * marketplace app's `src/constants/theme.ts`, including the reasons its own
 * audit recorded — `primary` is a fill colour that fails contrast as text,
 * which is why `primaryText` exists and is darker. One brand, one palette,
 * both apps. Changing it here changes it in the rider app without a release;
 * the marketplace app keeps its compiled copy until it next ships.
 *
 * Public and cacheable on purpose. It carries no secret, no personal data and
 * nothing rider-specific, so it is served like the marketplace's own
 * `/app-config` — one cached read rather than a worker booting WordPress for
 * every launch on a host that has already been starved once.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_App_Config {

	const OPT_OVERRIDES = 'pokbon_delivery_app_config';

	/** Bumped whenever anything here changes, so the app can cache by it. */
	const CACHE_SECONDS = 300;

	public static function register_routes(): void {
		register_rest_route( POKBON_DELIVERY_REST_NAMESPACE, '/delivery/app-config', [
			'methods'             => 'GET',
			'callback'            => [ self::class, 'serve' ],
			'permission_callback' => '__return_true',
		] );
	}

	public static function serve( WP_REST_Request $request ) {
		$config = self::build();

		$response = rest_ensure_response( $config );
		$response->header( 'Cache-Control', 'public, max-age=' . self::CACHE_SECONDS );
		return $response;
	}

	/**
	 * The whole payload. Overrides saved in wp-admin are merged over the
	 * defaults, so an owner only ever stores what they actually changed and a
	 * later default improvement still reaches them.
	 */
	public static function build(): array {
		$defaults  = self::defaults();
		$overrides = get_option( self::OPT_OVERRIDES, [] );
		$overrides = is_array( $overrides ) ? $overrides : [];

		$config = self::merge( $defaults, $overrides );

		// Rules are derived from the operating settings rather than stored
		// twice. A code length changed on the settings screen must change the
		// app's input box, and two copies would eventually disagree.
		$config['rules'] = self::rules();
		$config['version'] = (int) get_option( 'pokbon_delivery_app_config_version', 1 );

		/** Last word for anything bespoke, e.g. a seasonal campaign. */
		return apply_filters( 'pokbon_delivery_app_config', $config );
	}

	public static function save_overrides( array $overrides ): void {
		update_option( self::OPT_OVERRIDES, $overrides, false );
		update_option(
			'pokbon_delivery_app_config_version',
			(int) get_option( 'pokbon_delivery_app_config_version', 1 ) + 1,
			false
		);
	}

	/**
	 * Business rules the app must know to behave correctly offline or to
	 * render a field. Never a second source of truth: every one of these is
	 * read from the settings the delivery service itself runs on.
	 */
	private static function rules(): array {
		return [
			'codeLength'          => (int) Pokbon_Delivery_Settings::get( 'code_length' ),
			'codeMaxSends'        => (int) Pokbon_Delivery_Settings::get( 'code_max_sends' ),
			'paymentMaxPrompts'   => (int) Pokbon_Delivery_Settings::get( 'payment_max_prompts' ),
			'paymentWaitMinutes'  => (int) Pokbon_Delivery_Settings::get( 'payment_wait_minutes' ),
			'offerTimeoutSeconds' => (int) Pokbon_Delivery_Settings::get( 'offer_timeout_seconds' ),
			'activeVehicleClasses' => (array) Pokbon_Delivery_Settings::get( 'active_vehicle_classes' ),
			'agreementVersion'    => (string) Pokbon_Delivery_Settings::get( 'agreement_version' ),
			'operatingHours'      => (array) Pokbon_Delivery_Settings::get( 'operating_hours' ),
			'currency'            => 'GHS',
			'currencySymbol'      => 'GH₵',
		];
	}

	public static function defaults(): array {
		return [
			'brand' => [
				'name'      => 'POKBON Delivery',
				'shortName' => 'POKBON',
				'supportPhone' => '+233556780200',
				'supportWhatsApp' => '+233574482260',
				'supportEmail' => 'info@pokbongroup.com',
			],

			/**
			 * One palette, shared with the marketplace app. The comments are
			 * the marketplace audit's own findings, kept so nobody "tidies"
			 * a token back to a value that fails contrast.
			 */
			'theme' => [
				'light' => [
					'primary'        => '#FF6B35',
					'primaryDark'    => '#E55A2B',
					'primaryLight'   => '#FF8C5F',
					// A fill colour measures 2.84:1 as text on white, below AA.
					// This is the brand orange for text, links and meaningful icons.
					'primaryText'    => '#C2410C',
					'primarySurface' => '#FFE5D9',
					'secondary'      => '#2D3436',
					'secondaryLight' => '#636E72',
					'background'     => '#FFFFFF',
					'backgroundSecondary' => '#F8F9FA',
					'backgroundTertiary'  => '#F1F2F6',
					'textPrimary'    => '#2D3436',
					'textSecondary'  => '#636E72',
					'textLight'      => '#64737A',
					'textWhite'      => '#FFFFFF',
					'success'        => '#00B894',
					'successSurface' => '#E6F4EE',
					'successText'    => '#065F46',
					'warning'        => '#FDCB6E',
					'warningSurface' => '#FFF7ED',
					'warningText'    => '#92400E',
					'error'          => '#E74C3C',
					'errorSurface'   => '#FFF5F5',
					'errorText'      => '#B91C1C',
					'info'           => '#74B9FF',
					'border'         => '#DFE6E9',
					'borderLight'    => '#F1F2F6',
					'overlay'        => 'rgba(0, 0, 0, 0.5)',
					'shadow'         => 'rgba(0, 0, 0, 0.1)',
				],
				'dark' => [
					'primary'        => '#FF8C5F',
					'primaryDark'    => '#FF6B35',
					'primaryLight'   => '#FFA77D',
					'primaryText'    => '#FF8C5F',
					'primarySurface' => '#3A2118',
					'secondary'      => '#E5E7EB',
					'secondaryLight' => '#9CA3AF',
					'background'     => '#0F1419',
					'backgroundSecondary' => '#1A2028',
					'backgroundTertiary'  => '#252C36',
					'textPrimary'    => '#F3F4F6',
					'textSecondary'  => '#9CA3AF',
					'textLight'      => '#808A96',
					'textWhite'      => '#FFFFFF',
					'success'        => '#34D399',
					'successSurface' => '#0F2A22',
					'successText'    => '#6EE7B7',
					'warning'        => '#FCD34D',
					'warningSurface' => '#2A2113',
					'warningText'    => '#FCD34D',
					'error'          => '#F87171',
					'errorSurface'   => '#2A1616',
					'errorText'      => '#FCA5A5',
					'info'           => '#93C5FD',
					'border'         => '#374151',
					'borderLight'    => '#252C36',
					'overlay'        => 'rgba(0, 0, 0, 0.7)',
					'shadow'         => 'rgba(0, 0, 0, 0.4)',
				],
				'radius'  => [ 'sm' => 8, 'md' => 12, 'lg' => 16, 'pill' => 999 ],
				'spacing' => [ 'xs' => 4, 'sm' => 8, 'md' => 16, 'lg' => 24, 'xl' => 32 ],
			],

			/**
			 * Every word a rider reads. Changing a button or a warning is an
			 * admin edit, not a release — which matters most for the screens
			 * below, where wording is the difference between a rider handing
			 * goods over correctly and handing them over too early.
			 */
			'copy' => [
				'duty' => [
					'goOnline'   => 'Go on duty',
					'goOffline'  => 'Go off duty',
					'onlineNote' => 'You will be offered jobs near you while you are on duty.',
					'offlineNote' => 'You are off duty. No jobs will be offered.',
				],
				'offer' => [
					'title'      => 'New delivery',
					'accept'     => 'Accept',
					'decline'    => 'Decline',
					'feeLabel'   => 'You earn',
					'expiresIn'  => 'Respond within %d seconds',
					'secondJobWarning' => 'You are already on a delivery. Taking this one means both are late if either goes wrong, and lateness affects the jobs you are offered.',
				],
				'pickup' => [
					'atPickup'   => 'I have arrived at pickup',
					'collected'  => 'I have collected the parcel',
					'photoHint'  => 'Photograph the parcel before you leave.',
				],
				'delivery' => [
					'arrived'       => 'I have arrived',
					'sendCode'      => 'Send code to customer',
					'codeSentNote'  => 'We sent a code to the customer by SMS. Ask them to read it to you. You will not see it.',
					'codePrompt'    => 'Type the code the customer reads to you',
					'codeWrong'     => 'That is not the code. Ask them to read it again.',
					'codeLocked'    => 'Too many wrong tries. Call the dispatcher.',
					'resendCode'    => 'Send the code again',
				],
				'payment' => [
					'waiting'       => 'Waiting for the customer to approve on their phone',
					'promptAgain'   => 'Send the prompt again',
					'payByLink'     => 'Let someone else pay',
					'paidBanner'    => 'PAID — hand over the item',
					'notPaidYet'    => 'Not paid yet. Do not hand over the item.',
					'failedNote'    => 'The payment did not go through. Try the prompt again, send a payment link, or mark the delivery failed.',
					'noCashNote'    => 'Never accept cash. If the customer insists, call the dispatcher.',
				],
				'complete' => [
					'handOver'   => 'I have handed over the item',
					'failed'     => 'Could not deliver',
					'returned'   => 'Returned to sender',
					'photoHint'  => 'Photograph the hand-over.',
				],
				'earnings' => [
					'title'        => 'Earnings',
					'balance'      => 'Your balance',
					'upliftNote'   => 'This job pays extra to make up for a delivery that failed through no fault of yours.',
					'payoutNote'   => 'Paid to your mobile money on the %s cycle.',
				],
				'onboarding' => [
					'contractorNote' => 'You are an independent contractor. You choose when to work and you can stop at any time.',
					'commissionZero' => 'You keep the whole delivery fee. POKBON takes no commission from riders at the moment.',
					'commissionNote' => 'POKBON takes %s%% of the delivery fee from %s.',
					'licenceNote'    => 'A valid rider licence is required.',
					'idNote'         => 'Your Ghana Card is required. It is stored securely and only reviewed by POKBON staff.',
				],
				'errors' => [
					'offline'    => 'No signal. Your last action is saved and will send when you are back online.',
					'codeOffline' => 'The code has to be checked by POKBON, so you need signal for this step. Move to where you have a bar or two and try again.',
					'generic'    => 'Something went wrong. Try again, or call the dispatcher.',
				],
			],

			/**
			 * Whole features on and off from wp-admin. A screen that turns out
			 * to confuse riders can be withdrawn the same day.
			 */
			'features' => [
				'requesterMode'    => false,
				'riderSelfSignup'  => true,
				'earningsScreen'   => true,
				'payByLink'        => true,
				'photoAtPickup'    => true,
				'photoAtDelivery'  => true,
				'sosButton'        => false,
				'darkMode'         => true,
				'multiJob'         => true,
			],

			/**
			 * The rider home screen, in order. The app renders what is listed
			 * and ignores anything it does not recognise, so a new card can be
			 * added here and appear on older builds that already understand it.
			 */
			'riderHome' => [
				[ 'type' => 'dutyToggle' ],
				[ 'type' => 'activeJobs' ],
				[ 'type' => 'offers' ],
				[ 'type' => 'earningsSummary' ],
				[ 'type' => 'notice', 'id' => 'no-cash', 'tone' => 'warning',
				  'text' => 'POKBON riders never collect cash. Customers pay on their own phone.' ],
			],
		];
	}

	/**
	 * Is this a plain list rather than a map?
	 *
	 * `array_is_list()` is PHP 8.1 and this plugin declares 7.4, which is what
	 * the marketplace plugin declares and therefore what the host may actually
	 * be running. Calling it on an older PHP is a fatal error on activation —
	 * the whole site's admin, not a quiet failure — so it is spelled out here
	 * instead.
	 */
	public static function is_list( array $value ): bool {
		if ( $value === [] ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/** Deep merge: an override replaces a leaf, never a whole branch. */
	private static function merge( array $base, array $over ): array {
		foreach ( $over as $key => $value ) {
			if ( is_array( $value ) && isset( $base[ $key ] ) && is_array( $base[ $key ] ) ) {
				$base[ $key ] = self::merge( $base[ $key ], $value );
			} else {
				$base[ $key ] = $value;
			}
		}
		return $base;
	}
}
