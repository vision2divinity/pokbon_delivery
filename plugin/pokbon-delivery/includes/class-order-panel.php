<?php
/**
 * "Send this order to riders", on the order screen itself.
 *
 * WHY THIS EXISTS. Automatic dispatch fires when an order reaches
 * `processing`, which is right for something sitting on a shelf in Accra and
 * wrong for everything else. An item shipped from abroad is paid for at
 * checkout and lands weeks later; the rider leg belongs to the day it
 * arrives, not the day it was ordered. Same for anything held back, waiting
 * on a part, or collected late.
 *
 * So the owner gets a button, on the screen they are already looking at when
 * the goods turn up. It does exactly what automatic dispatch does — one job
 * per vendor, priced by the ladder — just when a person says so.
 *
 * The panel shows what it is about to do BEFORE it does it: which delivery
 * method the buyer chose, whether there are usable coordinates, which zones
 * the route resolves to, what it will cost and what the rider will earn. A
 * dispatch button that just says "Dispatch" invites a click that sends a
 * rider to the wrong end of Accra.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Order_Panel {

	public static function bootstrap(): void {
		add_action( 'add_meta_boxes', [ self::class, 'register' ], 30, 2 );
	}

	public static function register( $screen_id, $order = null ): void {
		// Both storage modes: the classic post screen and High-Performance
		// Order Storage, which renames the screen. Registering for only one
		// means the panel silently vanishes when the store switches.
		foreach ( [ 'shop_order', 'woocommerce_page_wc-orders' ] as $screen ) {
			add_meta_box(
				'pokbon-delivery-order',
				'POKBON Delivery',
				[ self::class, 'render' ],
				$screen,
				'side',
				'default'
			);
		}
	}

	public static function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order
			? $post_or_order
			: ( function_exists( 'wc_get_order' ) ? wc_get_order( $post_or_order->ID ?? 0 ) : null );

		if ( ! $order ) {
			echo '<p>Not an order.</p>';
			return;
		}

		$order_id = (int) $order->get_id();
		$job_ids  = Pokbon_Delivery_Orders::job_ids_for( $order );
		$status   = (string) $order->get_meta( Pokbon_Delivery_Orders::META_STATUS );
		$skipped  = (string) $order->get_meta( Pokbon_Delivery_Orders::META_SKIPPED );
		$method   = (string) $order->get_shipping_method();

		echo '<p><strong>Buyer chose:</strong><br>' . esc_html( $method ?: 'nothing recorded' ) . '</p>';

		// Freight and pickup never become rider jobs on their own. Say so
		// plainly rather than leaving the button looking broken.
		$kind = self::classify( $method );
		if ( $kind === 'freight' ) {
			echo '<p class="description">This is a <strong>freight</strong> order. The rider leg belongs to the day '
				. 'it lands in Ghana, so nothing is dispatched automatically. Use the button below when it arrives.</p>';
		} elseif ( $kind === 'pickup' ) {
			echo '<p class="description">This is a <strong>store pickup</strong>. No rider is needed unless you '
				. 'decide to deliver it anyway.</p>';
		}

		if ( ! empty( $job_ids ) ) {
			printf(
				'<p><strong>Dispatched.</strong> %d job(s), currently <em>%s</em>.</p>',
				count( $job_ids ),
				esc_html( $status ?: 'created' )
			);

			self::render_doorstep_money( $order, $job_ids );

			printf(
				'<p><a class="button" href="%s">Open the job board</a></p>',
				esc_url( admin_url( 'admin.php?page=pokbon-delivery' ) )
			);
			return;
		}

		// What it would do, before it does it.
		$preview = self::preview( $order );

		if ( $preview['error'] !== '' ) {
			echo '<p style="color:#b32d2e"><strong>Cannot dispatch yet.</strong><br>'
				. esc_html( $preview['error'] ) . '</p>';
		} else {
			printf(
				'<p><strong>Route:</strong> %s<br><strong>Buyer pays:</strong> %s<br>'
				. '<strong>Rider earns:</strong> %s<br><span class="description">Priced by %s</span></p>',
				esc_html( $preview['route'] ),
				esc_html( $preview['buyer'] ),
				esc_html( $preview['rider'] ),
				esc_html( $preview['why'] )
			);
			if ( $preview['vendors'] > 1 ) {
				printf(
					'<p class="description">%d vendors, so %d separate collections and %d rider fees. '
					. 'The buyer sees one total.</p>',
					(int) $preview['vendors'],
					(int) $preview['vendors'],
					(int) $preview['vendors']
				);
			}
		}

		if ( $skipped !== '' ) {
			echo '<p class="description" style="color:#996800">Last attempt: ' . esc_html( $skipped ) . '</p>';
		}

		/*
		 * A LINK, NOT A FORM, AND THAT IS NOT A STYLE CHOICE.
		 *
		 * WooCommerce renders this panel inside the order edit form. HTML has
		 * no nested forms: a form emitted here is merged into that one by the
		 * browser, so the button would submit the order screen and carry our
		 * hidden action along with it. The same mistake made "Save connection"
		 * silently run the connection test on the settings page.
		 *
		 * So the control goes to a screen of its own, which also gives room to
		 * choose a zone for an order that has no pin.
		 */
		printf(
			'<p><a class="button button-primary" href="%s">Send to riders&hellip;</a></p>',
			esc_url( admin_url( 'admin.php?page=pokbon-delivery&dispatch=' . $order_id ) )
		);

		echo '<p class="description">Creates the delivery job immediately, whatever the order status. Use it when '
			. 'goods arrive, or when an order needs a rider sooner than the normal flow.</p>';
	}

	/**
	 * Which of the checkout options this was.
	 *
	 * Matched on the method title because that is what the buyer actually saw
	 * and what both the website and the app record. Anything unrecognised is
	 * treated as a local delivery, which is the safe default: the worst case
	 * is a dispatch button offered on an order that did not need one.
	 */
	/**
	 * Who has paid at which door, on an order the buyer settles on arrival.
	 *
	 * An order with three deliveries is paid three times, at three different
	 * moments, and between the first rider and the last it is genuinely
	 * part-paid. Without this the screen says only "unpaid" while real money
	 * has already been taken from a real person — which is the state somebody
	 * rings about, and the state nobody could previously answer.
	 *
	 * Silent on an order nobody pays at the door, and silent on a single-leg
	 * order that has not been collected: neither has anything to explain.
	 */
	private static function render_doorstep_money( $order, array $job_ids ): void {
		if ( ! Pokbon_Delivery_Orders::is_pay_on_delivery( $order ) ) {
			return;
		}

		$paid        = Pokbon_Delivery_Payments::legs_paid( $order );
		$outstanding = Pokbon_Delivery_Payments::outstanding_minor( $order );
		$collected   = Pokbon_Delivery_Payments::collected_minor( $order );

		if ( $paid === [] && count( $job_ids ) < 2 ) {
			return;
		}

		echo '<p style="margin-bottom:.3em"><strong>At the door</strong></p><ul style="margin:0 0 .8em 1.2em;list-style:disc">';

		foreach ( $job_ids as $i => $job_id ) {
			$due  = Pokbon_Delivery_Payments::leg_amount_minor( $order, $job_id );
			$done = $paid[ $job_id ] ?? null;

			printf(
				'<li>Delivery %d &mdash; %s</li>',
				(int) $i + 1,
				is_array( $done )
					? sprintf(
						'<span style="color:#1a7f37">collected GHS %s</span>',
						esc_html( number_format( ( (int) ( $done['amountMinor'] ?? 0 ) ) / 100, 2 ) )
					)
					: sprintf(
						'to collect GHS %s',
						esc_html( number_format( $due / 100, 2 ) )
					)
			);
		}

		echo '</ul>';

		printf(
			'<p class="description">Collected GHS %s of GHS %s. %s</p>',
			esc_html( number_format( $collected / 100, 2 ) ),
			esc_html( number_format( (float) $order->get_total(), 2 ) ),
			$outstanding > 0
				? esc_html( sprintf( 'GHS %s is still with the buyer.', number_format( $outstanding / 100, 2 ) ) )
				: '<strong>Settled in full.</strong>'
		);
	}

	public static function classify( string $method ): string {
		$m = strtolower( $method );
		if ( strpos( $m, 'abroad' ) !== false || strpos( $m, 'freight' ) !== false ) {
			return 'freight';
		}
		if ( strpos( $m, 'pickup' ) !== false || strpos( $m, 'pick up' ) !== false ) {
			return 'pickup';
		}
		return 'local';
	}

	/**
	 * A likely zone from the order's own city and region text.
	 *
	 * Only ever a suggestion, pre-selected for the dispatcher to confirm. Text
	 * matching is not good enough to dispatch a rider on by itself — "Accra"
	 * covers every zone POKBON serves — but it removes most of the typing and
	 * puts the right answer first in the list.
	 */
	private static function guess_zone( $order ): string {
		$haystack = strtolower( implode( ' ', array_filter( [
			(string) $order->get_shipping_city(),
			(string) $order->get_shipping_address_1(),
			(string) $order->get_shipping_address_2(),
			(string) $order->get_billing_city(),
			(string) $order->get_billing_address_1(),
		] ) ) );

		if ( $haystack === '' ) {
			return '';
		}

		foreach ( Pokbon_Delivery_Settings::active_zones() as $zone ) {
			// Match on the zone's own code and the first word of its name, so
			// "Madina & environs" is found by "madina".
			$needles = array_filter( [
				strtolower( (string) $zone['code'] ),
				strtolower( strtok( (string) $zone['name'], ' &,' ) ),
			] );
			foreach ( $needles as $needle ) {
				if ( strlen( $needle ) > 2 && strpos( $haystack, $needle ) !== false ) {
					return (string) $zone['code'];
				}
			}
		}
		return '';
	}

	/** What dispatching would produce, without producing it. */
	public static function preview( $order ): array {
		$out = [
			'error' => '', 'route' => '', 'buyer' => '', 'rider' => '', 'why' => '',
			'vendors' => 0, 'hasPin' => false, 'needsZone' => false, 'suggested' => '',
		];

		$lat = (float) $order->get_meta( Pokbon_Delivery_Orders::META_LAT );
		$lng = (float) $order->get_meta( Pokbon_Delivery_Orders::META_LNG );

		$has_pin = ! ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 );
		$out['hasPin'] = $has_pin;

		if ( $has_pin ) {
			$to_zone = Pokbon_Delivery_Geo::resolve_zone( $lat, $lng );
			if ( ! $to_zone ) {
				$out['error'] = 'The delivery address is outside every active zone, so POKBON does not deliver '
					. 'there yet. Add a zone that covers it, or widen an existing one.';
				return $out;
			}
		} else {
			// Website orders carry no pin: a buyer there types a city and a
			// street and the rider works it out. So a zone is chosen rather
			// than derived, and the guess below is only a suggestion.
			$chosen  = (string) $order->get_meta( Pokbon_Delivery_Orders::META_DISPATCH_ZONE );
			$to_zone = $chosen !== '' ? Pokbon_Delivery_Settings::zone( $chosen ) : null;

			if ( ! $to_zone ) {
				$out['needsZone'] = true;
				$out['suggested'] = self::guess_zone( $order );
				$out['error']     = 'This order has no map pin, which is normal for a website order. '
					. 'Choose the zone to deliver into below.';
				return $out;
			}
			$lat = (float) $to_zone['lat'];
			$lng = (float) $to_zone['lng'];
		}

		$from_code = (string) Pokbon_Delivery_Settings::get( 'default_pickup_zone' );
		$from_zone = $from_code !== '' ? Pokbon_Delivery_Settings::zone( $from_code ) : null;
		if ( ! $from_zone ) {
			$out['error'] = 'No default pickup zone is set, so there is nowhere to collect from. '
				. 'Set one in POKBON Delivery → Settings.';
			return $out;
		}

		$priced = Pokbon_Delivery_Pricing::route(
			[ 'zoneCode' => $from_zone['code'] ],
			[ 'zoneCode' => $to_zone['code'], 'lat' => $lat, 'lng' => $lng ]
		);

		if ( $priced === null ) {
			$out['error'] = sprintf(
				'No rung prices %s → %s. Add an exact route, a band price, or a distance band that reaches it.',
				$from_zone['code'],
				$to_zone['code']
			);
			return $out;
		}


		$out['route']   = $from_zone['code'] . ' → ' . $to_zone['code'];
		$out['buyer']   = Pokbon_Delivery_Settings::format( $priced['buyerPriceMinor'] );
		$out['rider']   = Pokbon_Delivery_Settings::format( $priced['riderFeeMinor'] );
		$out['why']     = Pokbon_Delivery_Pricing::rung_label( $priced['rung'] ) . ' (' . $priced['matched'] . ')';
		// The same grouping dispatch will use, so the panel's warning about
		// several collections matches what actually happens.
		$out['vendors'] = count( Pokbon_Delivery_Orders::vendors_for( $order ) );

		return $out;
	}
}
