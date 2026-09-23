<?php
/**
 * Turning a marketplace order into a delivery job. Contract § 4.
 *
 * Fired when the order reaches `processing`, which is the marketplace's
 * existing signal that a vendor has confirmed and is packing. One job per
 * vendor, because the commission engine already reasons per vendor and a
 * buyer with two vendors genuinely has two deliveries.
 *
 * NOTHING HERE MAY FAIL AN ORDER. A delivery service that is down, or a route
 * that is not priced, must leave the order exactly as it was and tell the
 * admin. The marketplace worked before delivery existed and has to keep
 * working when delivery is unavailable.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Orders {

	const META_LAT        = '_pokbon_delivery_lat';
	const META_LNG        = '_pokbon_delivery_lng';
	const META_GHANAPOST  = '_pokbon_delivery_ghanapost';
	const META_NOTE       = '_pokbon_delivery_note';
	const META_JOB_IDS    = '_pokbon_delivery_job_ids';
	/** What the zone matrix would have charged, beside what checkout did. */
	const META_MATRIX_PRICE = '_pokbon_delivery_matrix_price';
	const META_STATUS     = '_pokbon_delivery_status';
	const META_FEE        = '_pokbon_delivery_fee';
	const META_SKIPPED    = '_pokbon_delivery_skipped_reason';
	/**
	 * The zone a dispatcher chose for an order that has no coordinates.
	 *
	 * Stored separately from the buyer's own pin, never over it. The
	 * marketplace keeps `_pokbon_delivery_lat/lng` read-only on purpose —
	 * letting an admin retype them would create a second version of what the
	 * buyer meant. Choosing which zone to dispatch into is a different act,
	 * and this records that it was POKBON's decision rather than the buyer's.
	 */
	const META_DISPATCH_ZONE = '_pokbon_delivery_dispatch_zone';

	public static function bootstrap(): void {
		add_action( 'woocommerce_order_status_processing', [ self::class, 'on_processing' ], 20, 1 );
		/*
		 * An order that is called off must not leave a rider riding to it.
		 *
		 * Until this existed the marketplace could cancel an order — a customer
		 * asks, a vendor runs out, somebody in the office decides — and nothing
		 * told the delivery service. The rider carried on, and the first anyone
		 * knew was a conversation at somebody's door about an order that no
		 * longer existed.
		 */
		add_action( 'woocommerce_order_status_cancelled', [ self::class, 'on_cancelled' ], 20, 1 );
	}

	/**
	 * The job ids on an order, as an array, with nothing empty in it.
	 *
	 * Worth its own method because the obvious `(array) $order->get_meta(...)`
	 * is a trap: an order with no delivery meta returns '', and casting that
	 * to an array gives [''] — one element, not none. Every screen that asked
	 * "has this been dispatched?" that way answered yes for every order that
	 * had never been near a rider.
	 */
	public static function job_ids_for( $order ): array {
		$raw = $order->get_meta( self::META_JOB_IDS );
		if ( ! is_array( $raw ) ) {
			$raw = ( $raw === '' || $raw === null ) ? [] : [ $raw ];
		}
		return array_values( array_filter( array_map( 'strval', $raw ), 'strlen' ) );
	}

	public static function on_processing( $order_id ): void {
		if ( ! Pokbon_Delivery_API_Client::is_configured() ) {
			return; // Not set up yet. Silent by design: nothing is broken.
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Automatic creation is opt-in. Phase 0 is manual dispatch (PRD § 15),
		// and turning this on before the zones and matrix are set would create
		// jobs nobody can price.
		if ( ! Pokbon_Delivery_Settings::get( 'auto_create_jobs' ) ) {
			return;
		}

		if ( self::job_ids_for( $order ) !== [] ) {
			return; // Already dispatched. Status can revisit processing.
		}

		/**
		 * Freight and pickup are never dispatched automatically.
		 *
		 * An item shipped from abroad is paid for at checkout and lands weeks
		 * later; creating the rider job on `processing` would send somebody to
		 * collect a parcel that is still on a ship. Store pickup needs no
		 * rider at all. Both are dispatched by hand from the order screen, on
		 * the day the goods are actually there.
		 */
		$kind = Pokbon_Delivery_Order_Panel::classify( (string) $order->get_shipping_method() );
		if ( $kind !== 'local' ) {
			$order->update_meta_data(
				self::META_SKIPPED,
				sprintf(
					'Not dispatched automatically: the buyer chose %s. Use "Send to riders now" on this order when the goods are ready.',
					$kind === 'freight' ? 'shipping from abroad' : 'store pickup'
				)
			);
			$order->save();
			return;
		}

		self::create_jobs_for_order( $order );
	}

	/**
	 * Create one job per vendor. Also the entry point for the admin's
	 * "Dispatch this order" button, so a skipped order can be retried by hand
	 * once its route is priced.
	 *
	 * Returns [ 'created' => [jobId...], 'skipped' => [ reason... ] ].
	 */
	public static function create_jobs_for_order( $order ): array {
		$order_id = (int) $order->get_id();
		$created  = [];
		$reused   = [];
		$skipped  = [];

		$dropoff = self::dropoff_for( $order );
		if ( $dropoff === null ) {
			$reason = 'The order has no delivery coordinates, so no rider could be routed to it.';
			$order->update_meta_data( self::META_SKIPPED, $reason );
			$order->save();
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
				'order_id' => $order_id,
				'reason'   => 'no_coordinates',
			] );
			return [ 'created' => [], 'skipped' => [ $reason ] ];
		}

		$is_cod = self::is_pay_on_delivery( $order );

		foreach ( self::vendors_for( $order ) as $vendor_id => $items ) {
			$pickup = self::pickup_for( $vendor_id, $order );
			if ( $pickup === null ) {
				$skipped[] = sprintf( 'Vendor %s has no pickup location set.', $vendor_id );
				continue;
			}

			$payload = [
				'source'      => 'marketplace',
				'orderId'     => $order_id,
				'vendorId'    => (string) $vendor_id,
				'riderSource' => 'pokbon',
				'pickup'      => $pickup,
				'dropoff'     => $dropoff,
				'parcel'      => [
					'sizeClass'     => 'small',
					// What the rider is actually carrying, in the buyer's own
					// order. Someone collecting "2 x cement" packs differently
					// from someone collecting "1 x phone case", and a courier
					// who cannot see the item before accepting will decline.
					'description'   => self::parcel_description( $order, $vendor_id ),
					'declaredValue' => (float) $order->get_total(),
					'itemCount'     => max( 1, (int) $items ),
				],
				'payment'     => [
					'method'    => $is_cod ? 'cod' : 'prepaid',
					// What the buyer approves at the door: the whole order.
					// The API never shows this to a rider (PRD § 9c).
					'codAmount' => $is_cod ? (float) $order->get_total() : 0,
					'currency'  => 'GHS',
				],
				'buyerUserId' => (string) $order->get_customer_id(),
			];

			/*
			 * Two different numbers, and only one of them is revenue.
			 *
			 * The rider fee comes from the matrix: it is what this route costs
			 * to ride, and it is the same whoever ordered and whatever they
			 * were charged.
			 *
			 * The buyer price is what the customer actually paid for delivery,
			 * read off the order. It used to be taken from the matrix too, and
			 * labelled "quoted at checkout" — which was not true. Checkout
			 * charges a flat rate per region; the matrix prices zone to zone.
			 * On #87619 the buyer paid GH¢30, the job recorded GH¢50, and the
			 * board showed a GH¢10 margin on a delivery that lost GH¢22.
			 * Recording revenue that never arrived is worse than recording a
			 * loss, because a loss can be acted on.
			 *
			 * Freight and store pickup pay nothing toward a rider leg: the
			 * shipping line on those orders is air or sea freight, and
			 * counting it as delivery revenue would flatter every one of them.
			 * Those jobs carry zero, which is the truth — the rider leg was a
			 * cost POKBON chose to absorb.
			 */
			$price = Pokbon_Delivery_Settings::price( $pickup['zoneCode'], $dropoff['zoneCode'] );
			$kind  = Pokbon_Delivery_Order_Panel::classify( (string) $order->get_shipping_method() );

			$charged = $kind === 'local' ? (float) $order->get_shipping_total() : 0.0;

			if ( $price !== null ) {
				$payload['pricing'] = [
					'riderFee'     => Pokbon_Delivery_Settings::from_minor( $price['riderFeeMinor'] ),
					'buyerPrice'   => $charged,
					'priceVersion' => Pokbon_Delivery_Settings::sync_version(),
				];

				// What the matrix would have charged, kept beside what was
				// actually charged so the gap is a number rather than a
				// suspicion. The reconciliation screen reads this.
				$order->update_meta_data(
					self::META_MATRIX_PRICE,
					Pokbon_Delivery_Settings::from_minor( $price['buyerPriceMinor'] )
				);
			}

			$result = Pokbon_Delivery_API_Client::create_job( $payload );

			if ( is_wp_error( $result ) ) {
				$skipped[] = $result->get_error_message();
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
					'order_id'  => $order_id,
					'vendor_id' => $vendor_id,
					'error'     => $result->get_error_message(),
				] );
				continue;
			}

			$job_id = (string) ( $result['jobId'] ?? '' );
			if ( $job_id === '' ) {
				$skipped[] = 'The delivery service did not return a job id.';
				continue;
			}

			$created[] = $job_id;

			// The delivery service is idempotent per order and vendor, so a
			// second dispatch can hand back the job that already exists. Say
			// which happened rather than reporting both as "created".
			if ( array_key_exists( 'created', $result ) && ! $result['created'] ) {
				$reused[] = $job_id;
			}

			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATED, [
				'order_id'  => $order_id,
				'vendor_id' => $vendor_id,
				'job_id'    => $job_id,
				'buyerFee'  => $result['quotedFee'] ?? null,
				'riderFee'  => $result['riderFee'] ?? null,
			] );
		}

		if ( ! empty( $created ) ) {
			$order->update_meta_data( self::META_JOB_IDS, $created );
			// Single-job orders also carry the flat key the payments class and
			// the contract use.
			if ( count( $created ) === 1 ) {
				$order->update_meta_data( Pokbon_Delivery_Payments::META_JOB_ID, $created[0] );
			}
			$order->update_meta_data( self::META_STATUS, 'created' );
			$order->delete_meta_data( self::META_SKIPPED );
		}
		if ( ! empty( $skipped ) ) {
			$order->update_meta_data( self::META_SKIPPED, implode( ' | ', $skipped ) );
		}
		$order->save();

		// Creating a job is not dispatching it. See offer_new_jobs().
		$offers = self::offer_new_jobs( $created, $order );

		return [ 'created' => $created, 'reused' => $reused, 'skipped' => $skipped, 'offers' => $offers ];
	}

	/**
	 * Put each newly created job in front of a rider.
	 *
	 * Creating a job used to be the end of this function, and nothing anywhere
	 * offered it. The API's sweep expires offers that already exist and
	 * cascades from them; it never makes a FIRST offer. So an automatically
	 * created job sat at CREATED until somebody happened to open the Jobs page
	 * and press "Offer to the nearest rider" — while the button that made it
	 * said "Send to riders now" and the order screen said it had been
	 * dispatched.
	 *
	 * Observed 2026-09-22 on order #87684: job created at 20:53, still with
	 * zero offers at 21:10. The rider toggled duty, signed out and signed back
	 * in, and of course saw nothing, because there was nothing to see. Two
	 * hours went into the rider's app and the rider's phone before anybody
	 * looked at whether an offer had ever been made.
	 *
	 * Reused jobs are deliberately left alone: one already exists for this
	 * order, and it may be assigned, collected or at somebody's door. Offering
	 * it again would take it off the rider carrying it.
	 *
	 * Best effort, and loud when it fails. A job that nobody can be offered is
	 * a real situation — every rider off duty at midnight is the ordinary
	 * case — and the dispatcher needs to see that as a sentence on the order
	 * rather than as a job that looks dispatched and never moves.
	 */
	private static function offer_new_jobs( array $created, $order ): array {
		$out = [];
		if ( $created === [] ) {
			return $out;
		}

		$unoffered = [];
		foreach ( $created as $job_id ) {
			$job_id = (string) $job_id;
			if ( $job_id === '' ) {
				continue;
			}

			$result = Pokbon_Delivery_API_Client::offer_job( $job_id );

			if ( is_wp_error( $result ) ) {
				$out[ $job_id ] = 'error';
				$unoffered[]    = sprintf( 'could not be offered (%s)', $result->get_error_message() );
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
					'order_id' => (int) $order->get_id(),
					'job_id'   => $job_id,
					'reason'   => 'offer_failed',
					'detail'   => $result->get_error_message(),
				] );
				continue;
			}

			if ( empty( $result['offered'] ) ) {
				// Not an error: nobody was eligible. Usually every rider is off
				// duty, out of range, or has not reported a position recently.
				$out[ $job_id ] = 'nobody';
				$unoffered[]    = 'no rider was available to take it';
				continue;
			}

			$out[ $job_id ] = 'offered';
		}

		if ( $unoffered !== [] ) {
			$order->add_order_note(
				sprintf(
					'[POKBON Delivery] Job created, but %s. Nothing will happen on its own — use "Offer to the nearest rider" on the job once a rider is on duty.',
					implode( '; ', array_unique( $unoffered ) )
				)
			);
		}

		return $out;
	}

	/**
	 * A status change arriving from the Delivery API. Contract § 5.
	 *
	 * Writes what the buyer sees and, on delivery, the real cost the 1.20
	 * margin maths has never had.
	 */
	public static function apply_status( string $job_id, array $payload ): bool {
		$order_id = (int) ( $payload['orderId'] ?? 0 );
		if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
			return false;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return false;
		}

		$status = sanitize_key( (string) ( $payload['status'] ?? '' ) );
		if ( $status === '' ) {
			return false;
		}

		$order->update_meta_data( self::META_STATUS, $status );

		if ( isset( $payload['deliveryFee'] ) ) {
			// The number the commission engine needs for true margin.
			$order->update_meta_data( self::META_FEE, (float) $payload['deliveryFee'] );
		}

		$note = self::buyer_note_for( $status, $payload );
		if ( $note !== '' ) {
			$order->add_order_note( $note, 0 );
		}

		$order->save();

		self::close_marketplace_order( $order, $status, $payload );

		return true;
	}

	/**
	 * The order was called off. Tell the delivery service, and say what happened.
	 *
	 * The plugin does not decide the outcome — it cannot, because the answer
	 * depends on where the parcel is, and the delivery service is the only
	 * thing that knows. It reports back one of three:
	 *
	 *   cancelled  nobody had collected it yet; the job is simply off.
	 *   recalled   a rider is carrying the goods. The job is failed with
	 *              ORDER_CANCELLED, their screen now tells them to bring it
	 *              back, and the parcel is still out there until they do.
	 *   ignored    already delivered, returned or cancelled.
	 *
	 * Each writes an order note, because "recalled" in particular is not a
	 * closed matter: somebody has to receive a parcel that is still moving, and
	 * an order that quietly went quiet is how stock goes missing.
	 */
	public static function on_cancelled( $order_id ): void {
		if ( ! Pokbon_Delivery_API_Client::is_configured() || ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$job_ids = self::job_ids_for( $order );
		if ( $job_ids === [] ) {
			return; // Never dispatched. Nothing to recall.
		}

		$actor  = is_user_logged_in() ? wp_get_current_user()->user_login : 'marketplace';
		$reason = sprintf( 'Order #%s was cancelled.', $order->get_order_number() );

		foreach ( $job_ids as $job_id ) {
			$result = Pokbon_Delivery_API_Client::recall_job( $job_id, $reason, $actor );

			if ( is_wp_error( $result ) ) {
				// Loud, because the alternative is a rider still on their way
				// and nobody aware of it.
				$order->add_order_note( sprintf(
					'[POKBON Delivery] Could not call off the delivery (%s). A rider may still be on the way — check the job and stop it by hand.',
					$result->get_error_message()
				) );
				Pokbon_Delivery_Audit::log( 'delivery.recall_failed', [
					'order_id' => (int) $order->get_id(),
					'job_id'   => $job_id,
					'error'    => $result->get_error_message(),
				] );
				continue;
			}

			switch ( (string) ( $result['outcome'] ?? '' ) ) {
				case 'recalled':
					$order->add_order_note( '[POKBON Delivery] The rider had already collected this. They have been told to take it back to the sender — the parcel is still out until they confirm it has been returned.' );
					break;
				case 'cancelled':
					$order->add_order_note( '[POKBON Delivery] Delivery called off. Nobody had collected the goods.' );
					break;
				default:
					$order->add_order_note( '[POKBON Delivery] The delivery had already finished, so nothing was changed.' );
			}
		}
	}

	/**
	 * Which setting decides the order status for a given job status.
	 *
	 * A table rather than a chain of ifs, because this is a mapping and the
	 * next person needs to see the whole of it at once. Anything absent means
	 * "this step does not move the order" — `arrived` is deliberately absent,
	 * since a rider at the door is still in transit as far as the customer's
	 * timeline is concerned, and `en_route` is covered by `picked_up`.
	 */
	private const ORDER_STATUS_SETTINGS = [
		'assigned'  => 'order_status_on_assigned',
		'picked_up' => 'order_status_on_picked_up',
		'delivered' => 'order_status_on_delivered',
		'failed'    => 'order_status_on_failed',
		'returned'  => 'order_status_on_failed',
	];

	/**
	 * Move the WooCommerce order as the delivery progresses.
	 *
	 * The order is what the marketplace shows a customer. Recording each step
	 * as meta and a note, and leaving the order in `processing`, left somebody
	 * who had just signed for their parcel looking at "ongoing" in the app —
	 * the delivery service knew, the vendor knew, and the only person who
	 * cared did not.
	 *
	 * Until 2026-09-23 this moved the order only at the END, which fixed the
	 * "ongoing after delivery" complaint and left a subtler one behind: the
	 * customer watched "Processing" from payment until hand-over, because the
	 * app's timeline is driven by the order status and the journey never
	 * touched it. Observed on #87692 — unchanged through several manual
	 * refreshes, then straight to Completed. A progress bar that only moves
	 * when the thing is already finished is not a progress bar.
	 *
	 * Only marketplace orders. A courier job for somebody who is not buying
	 * anything has no WooCommerce order behind it to move, and a freight order
	 * that a rider delivered locally is still a marketplace order, so `source`
	 * is the right test rather than the shipping method.
	 *
	 * Every step is a setting, because a site that drives its own statuses
	 * elsewhere should be able to say "leave my orders alone" without a code
	 * change — and because a vendor who moves their own orders by hand should
	 * be able to stop this fighting them.
	 */
	private static function close_marketplace_order( $order, string $status, array $payload ): void {
		$source = strtoupper( (string) ( $payload['source'] ?? '' ) );
		if ( $source !== 'MARKETPLACE' ) {
			return;
		}

		$key = self::ORDER_STATUS_SETTINGS[ $status ] ?? null;
		if ( $key === null ) {
			return;
		}

		/*
		 * A cancelled order stays cancelled.
		 *
		 * Cancelling an order recalls the delivery, which fails the job, which
		 * calls back here — and a shop that had set a status for failed
		 * deliveries would find its cancelled order quietly moved somewhere
		 * else by the very act of cancelling it. The order has already been
		 * decided by a human; the delivery is reporting, not deciding.
		 */
		if ( $order->has_status( 'cancelled' ) ) {
			return;
		}

		$target = trim( (string) Pokbon_Delivery_Settings::get( $key ) );
		if ( $target === '' ) {
			return; // Configured to leave the order alone.
		}

		if ( $target === 'auto' ) {
			$target = self::auto_completion_status();
		}

		// A setting may hold either form. WooCommerce's has_status() and
		// update_status() want the bare slug; wc_get_order_statuses() keys
		// carry the wc- prefix.
		$bare = preg_replace( '/^wc-/', '', $target );

		if ( $order->has_status( $bare ) ) {
			return; // Already there. Saying so twice would add a second note.
		}

		$known = array_keys( wc_get_order_statuses() );
		if ( ! in_array( 'wc-' . $bare, $known, true ) ) {
			Pokbon_Delivery_Audit::log( 'delivery.order_status_skipped', [
				'order_id' => $order->get_id(),
				'wanted'   => $bare,
				'reason'   => 'not a registered order status',
			] );
			return;
		}

		$order->update_status( $bare, sprintf( 'POKBON Delivery: %s.', self::status_note_for( $status ) ), true );

		Pokbon_Delivery_Audit::log( 'delivery.order_status_set', [
			'order_id' => $order->get_id(),
			'status'   => $bare,
			'from'     => $status,
		] );
	}

	/**
	 * A sensible completion status for this site.
	 *
	 * Prefers a `delivered` status when the site registers one — this
	 * marketplace does, alongside "ready to ship" and "in transit" — because
	 * "delivered" is what actually happened and `completed` may mean something
	 * else to the vendor payout flow. Falls back to `completed`.
	 */
	private static function auto_completion_status(): string {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : [];
		foreach ( [ 'wc-delivered', 'wc-completed' ] as $candidate ) {
			if ( in_array( $candidate, $statuses, true ) ) {
				return preg_replace( '/^wc-/', '', $candidate );
			}
		}
		return 'completed';
	}

	// ─── where things are ───────────────────────────────────────────────────

	/**
	 * Where the parcel goes.
	 *
	 * Two sources, and the difference matters. The app's checkout captures a
	 * pin (plugin 1.20.1), which is the best answer. The website's checkout
	 * does not — a buyer there types a city and a street, and the rider works
	 * it out, which is how POKBON has always run. For those orders a
	 * dispatcher picks the zone, and the zone's centre stands in as the map
	 * target while the typed address and the phone number do the real work.
	 *
	 * That is not a degraded answer for pricing: the ladder prices by zone,
	 * so a chosen zone prices exactly as a pin in that zone would.
	 */
	private static function dropoff_for( $order ): ?array {
		$lat = (float) $order->get_meta( self::META_LAT );
		$lng = (float) $order->get_meta( self::META_LNG );
		$has_pin = ! ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 );

		if ( $has_pin ) {
			$zone = Pokbon_Delivery_Geo::resolve_zone_code( $lat, $lng );
			if ( $zone === '' ) {
				return null; // A real pin, outside every coverage area.
			}
		} else {
			$chosen = (string) $order->get_meta( self::META_DISPATCH_ZONE );
			$picked = $chosen !== '' ? Pokbon_Delivery_Settings::zone( $chosen ) : null;
			if ( ! $picked || empty( $picked['active'] ) ) {
				return null; // Nothing to route to, and nothing worth guessing.
			}
			$zone = (string) $picked['code'];
			// The zone centre is the map target. The rider is shown the typed
			// address and the customer's number, which is what they will
			// actually use.
			$lat = (float) $picked['lat'];
			$lng = (float) $picked['lng'];
		}

		// Whoever is receiving it, not whoever paid. On most orders these are
		// the same person; when they are not, the rider needs the one standing
		// at the door.
		$raw_phone = '';
		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$raw_phone = (string) $order->get_shipping_phone();
		}
		if ( $raw_phone === '' ) {
			$raw_phone = (string) $order->get_billing_phone();
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( $raw_phone );
		if ( $phone === '' ) {
			return null; // No number means no code and no payment prompt.
		}

		$name = trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() );
		if ( $name === '' ) {
			$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		}

		/*
		 * What the buyer said about finding them.
		 *
		 * The app writes its own note to META_NOTE. A website order has no
		 * such meta — what the buyer typed sits in WooCommerce's own customer
		 * note field, and on this marketplace that box is labelled "Order
		 * notes" and is where people put the landmark and the gate colour.
		 * Reading only the app's key silently threw that away on every
		 * website order, which is the one place the rider needs it most.
		 */
		$note = trim( (string) $order->get_meta( self::META_NOTE ) );
		if ( $note === '' ) {
			$note = trim( (string) $order->get_customer_note() );
		}
		if ( ! $has_pin ) {
			$note = trim( $note . ' [No map pin on this order — go by the address and call the customer.]' );
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => trim( $order->get_shipping_address_1() . ' ' . $order->get_shipping_city() ) ?: $order->get_billing_address_1(),
			'zoneCode'     => $zone,
			'ghanaPost'    => (string) $order->get_meta( self::META_GHANAPOST ),
			'note'         => $note,
			'contactName'  => $name,
			'contactPhone' => $phone,
		];
	}

	/**
	 * Where the rider collects.
	 *
	 * Four sources, best first. A vendor with real coordinates beats a default
	 * every time, because a rider sent to the wrong end of Accra is the most
	 * expensive mistake this system can make.
	 *
	 *   1. The `pokbon_delivery_vendor_pickup` filter, for anything bespoke.
	 *   2. The vendor's own WCFM store location, which is where the marketplace
	 *      already reads vendor pickup addresses from.
	 *   3. A configured pickup point belonging to this vendor.
	 *   4. The default pickup in Settings, correct for a single-warehouse start.
	 *
	 * Returns null rather than a guess. A job with the wrong pickup is worse
	 * than a job that was never created, because a rider is dispatched on it.
	 */
	/**
	 * Which zone a vendor is collected from, without needing an order.
	 *
	 * Checkout has to price before an order exists, so it asks the same
	 * question the dispatch path asks — deliberately through the same
	 * resolution, rather than a second copy that could drift. Returns '' when
	 * the vendor cannot be placed, which the caller reads as "cannot price
	 * this" rather than "free".
	 */
	public static function pickup_zone_for_vendor( $vendor_id ): string {
		$pickup = self::pickup_for( $vendor_id, null );
		return $pickup === null ? '' : (string) ( $pickup['zoneCode'] ?? '' );
	}

	private static function pickup_for( $vendor_id, $order ): ?array {
		$vendor_id = (int) $vendor_id;

		foreach ( [
			apply_filters( 'pokbon_delivery_vendor_pickup', null, $vendor_id, $order ),
			self::vendor_store_pickup( $vendor_id ),
			self::pickup_point_for_vendor( $vendor_id ),
			self::default_pickup(),
		] as $candidate ) {
			$resolved = self::validate_pickup( $candidate );
			if ( $resolved !== null ) {
				return $resolved;
			}
		}

		return null;
	}

	/**
	 * A pickup is only usable with coordinates inside a served zone and a phone
	 * a rider can actually call. Anything missing one of those is discarded here
	 * rather than surfacing as a job nobody can complete.
	 */
	private static function validate_pickup( $candidate ): ?array {
		if ( ! is_array( $candidate ) ) {
			return null;
		}

		$lat = (float) ( $candidate['lat'] ?? 0 );
		$lng = (float) ( $candidate['lng'] ?? 0 );
		if ( abs( $lat ) < 0.0001 && abs( $lng ) < 0.0001 ) {
			return null;
		}

		$zone = (string) ( $candidate['zoneCode'] ?? '' );
		if ( $zone === '' ) {
			$zone = Pokbon_Delivery_Geo::resolve_zone_code( $lat, $lng );
		}
		if ( $zone === '' ) {
			return null; // Collection point outside every coverage area.
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( (string) ( $candidate['contactPhone'] ?? '' ) );
		if ( $phone === '' ) {
			return null;
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => sanitize_text_field( (string) ( $candidate['address'] ?? '' ) ),
			'zoneCode'     => $zone,
			'note'         => sanitize_text_field( (string) ( $candidate['note'] ?? '' ) ),
			'contactName'  => sanitize_text_field( (string) ( $candidate['contactName'] ?? '' ) ),
			'contactPhone' => $phone,
		];
	}

	/**
	 * The vendor's store location as WCFM stores it.
	 *
	 * Key shapes copied from the marketplace's own pickup-points endpoint, which
	 * already solved this: the modern serialised profile array first, then the
	 * legacy individual meta keys. Reading it the same way means a vendor who
	 * appears as a pickup point in the app is dispatchable here too.
	 */
	private static function vendor_store_pickup( int $vendor_id ): ?array {
		if ( $vendor_id <= 0 ) {
			return null;
		}
		$user = get_userdata( $vendor_id );
		if ( ! $user ) {
			return null;
		}

		$profile = get_user_meta( $vendor_id, 'wcfmmp_profile_settings', true );
		$address = '';
		$city    = '';
		$phone   = '';

		if ( is_array( $profile ) ) {
			$a       = is_array( $profile['address'] ?? null ) ? $profile['address'] : [];
			$address = (string) ( $a['addr_1'] ?? '' );
			$city    = (string) ( $a['city'] ?? '' );
			$phone   = (string) ( $profile['phone'] ?? '' );
		}

		if ( $address === '' ) {
			$address = (string) get_user_meta( $vendor_id, '_wcfmmp_address', true );
		}
		if ( $city === '' ) {
			$city = (string) get_user_meta( $vendor_id, '_wcfmmp_city', true );
		}
		if ( $phone === '' ) {
			$phone = (string) get_user_meta( $vendor_id, '_wcfmmp_phone', true );
		}

		$lat = (float) get_user_meta( $vendor_id, '_wcfmmp_lat', true );
		if ( $lat === 0.0 ) {
			$lat = (float) get_user_meta( $vendor_id, '_wcfm_lat', true );
		}
		$lng = (float) get_user_meta( $vendor_id, '_wcfmmp_lng', true );
		if ( $lng === 0.0 ) {
			$lng = (float) get_user_meta( $vendor_id, '_wcfm_lng', true );
		}

		$store_name = (string) get_user_meta( $vendor_id, 'store_name', true );
		if ( $store_name === '' ) {
			$store_name = $user->display_name;
		}

		return [
			'lat'          => $lat,
			'lng'          => $lng,
			'address'      => trim( $address . ( $city !== '' ? ', ' . $city : '' ) ),
			'contactName'  => $store_name,
			'contactPhone' => $phone,
		];
	}

	/**
	 * A configured pickup point belonging to this vendor.
	 *
	 * These already carry latitude and longitude and are already curated by the
	 * owner, which makes them more trustworthy than a vendor-entered pin.
	 */
	private static function pickup_point_for_vendor( int $vendor_id ): ?array {
		if ( ! class_exists( 'Pokbon_App_Pickup_Points_Endpoint' ) ) {
			return null;
		}

		$store  = get_option( Pokbon_App_Pickup_Points_Endpoint::OPTION_KEY, [] );
		$points = is_array( $store['points'] ?? null ) ? $store['points'] : [];

		foreach ( $points as $point ) {
			if ( empty( $point['enabled'] ) ) {
				continue;
			}
			if ( (int) ( $point['vendorId'] ?? 0 ) !== $vendor_id ) {
				continue;
			}
			return [
				'lat'          => (float) ( $point['latitude'] ?? 0 ),
				'lng'          => (float) ( $point['longitude'] ?? 0 ),
				'address'      => (string) ( $point['address'] ?? $point['name'] ?? '' ),
				'contactName'  => (string) ( $point['name'] ?? '' ),
				'contactPhone' => (string) ( $point['phone'] ?? '' ),
			];
		}

		return null;
	}

	/** The single collection point configured in Settings. */
	private static function default_pickup(): ?array {
		$code = (string) Pokbon_Delivery_Settings::get( 'default_pickup_zone' );
		if ( $code === '' ) {
			return null;
		}
		$zone = Pokbon_Delivery_Settings::zone( $code );
		if ( ! $zone ) {
			return null;
		}

		return [
			'lat'          => (float) $zone['lat'],
			'lng'          => (float) $zone['lng'],
			'zoneCode'     => (string) $zone['code'],
			'address'      => (string) ( Pokbon_Delivery_Settings::get( 'default_pickup_address' ) ?: $zone['name'] ),
			'note'         => (string) Pokbon_Delivery_Settings::get( 'default_pickup_note' ),
			'contactName'  => (string) ( Pokbon_Delivery_Settings::get( 'default_pickup_contact' ) ?: 'POKBON' ),
			'contactPhone' => (string) Pokbon_Delivery_Settings::get( 'default_pickup_phone' ),
		];
	}

	/**
	 * Items grouped by vendor. Falls back to a single job when the install has
	 * no vendor concept, which is the right behaviour rather than no job.
	 */
	public static function vendors_for( $order ): array {
		$vendors = [];

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$vendor_id  = (int) apply_filters(
				'pokbon_delivery_product_vendor',
				get_post_field( 'post_author', $product_id ),
				$product_id,
				$item
			);
			$key = $vendor_id > 0 ? $vendor_id : 0;
			$vendors[ $key ] = ( $vendors[ $key ] ?? 0 ) + (int) $item->get_quantity();
		}

		return empty( $vendors ) ? [ 0 => 1 ] : $vendors;
	}

	/**
	 * What the rider is carrying, from the order itself.
	 *
	 * Only this vendor's lines, because a multi-vendor order is several
	 * deliveries and a rider should not be told about a parcel they are not
	 * collecting. Kept short: this is read on a phone, at a counter, in a
	 * hurry, and a wall of text is the same as no text.
	 */
	private static function parcel_description( $order, $vendor_id ): string {
		$parts = [];

		foreach ( $order->get_items() as $item ) {
			$product_id = (int) $item->get_product_id();
			$owner      = (int) apply_filters(
				'pokbon_delivery_product_vendor',
				get_post_field( 'post_author', $product_id ),
				$product_id,
				$item
			);
			// Vendor 0 is the "no vendor concept" fallback; it takes everything.
			if ( (int) $vendor_id !== 0 && $owner !== (int) $vendor_id ) {
				continue;
			}
			$parts[] = sprintf( '%d x %s', (int) $item->get_quantity(), $item->get_name() );
			if ( count( $parts ) >= 5 ) {
				$parts[] = '…';
				break;
			}
		}

		return mb_substr( sanitize_text_field( implode( ', ', $parts ) ), 0, 500 );
	}

	/**
	 * Is this the pay-at-the-door case?
	 *
	 * The buyer still picks "cash on delivery" at checkout — the label changes
	 * to "Pay on delivery" with the rollout (PRD § 2), but the method id does
	 * not, because changing it would strand every historic order.
	 */
	public static function is_pay_on_delivery( $order ): bool {
		$method = (string) $order->get_payment_method();
		if ( ! in_array( $method, [ 'cod', 'pokbon_cod' ], true ) ) {
			return false;
		}

		/*
		 * Deliberately not is_paid().
		 *
		 * is_paid() asks whether the order has reached a paid *status*, and
		 * WooCommerce counts `processing` as one. A cash-on-delivery order is
		 * moved to processing the moment it is placed, with no money taken —
		 * so is_paid() answered true for exactly the orders where cash is
		 * still owed, every job left as PREPAID with nothing to collect, and
		 * a rider would have handed the goods over for free.
		 *
		 * get_date_paid() is only set when a payment was actually captured,
		 * which is the question being asked.
		 */
		return $order->get_date_paid() === null;
	}

	/** Why the order status moved, in the order's own history. */
	private static function status_note_for( string $status ): string {
		switch ( $status ) {
			case 'assigned':
				return 'a rider has accepted this delivery';
			case 'picked_up':
				return 'the rider has collected the parcel';
			case 'delivered':
				return 'delivered and confirmed by code';
			default:
				return 'delivery ' . $status;
		}
	}

	private static function buyer_note_for( string $status, array $payload ): string {
		$rider = (string) ( $payload['rider']['name'] ?? 'Your rider' );

		switch ( $status ) {
			case 'assigned':
				return sprintf( '%s is on the way with this delivery.', $rider );
			case 'picked_up':
				return 'The parcel has left the vendor.';
			case 'arrived':
				return sprintf( '%s has arrived at the delivery address.', $rider );
			case 'paid':
				return 'Payment approved at the door.';
			case 'delivered':
				return 'Delivered and confirmed by code.';
			case 'payment_failed':
				return sprintf( 'The doorstep payment did not go through: %s', (string) ( $payload['failureReason'] ?? 'unknown' ) );
			case 'failed':
				return sprintf( 'Delivery failed: %s', (string) ( $payload['failureReason'] ?? 'unknown' ) );
			case 'returned':
				return 'The parcel was returned to the vendor.';
			default:
				return '';
		}
	}
}
