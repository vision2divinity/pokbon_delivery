<?php
/**
 * The doorstep payment. PRD § 2 and § 11, contract § 4b.
 *
 * The rider never touches money. When the delivery code matches, the Delivery
 * API asks this class to push a mobile-money approval to the buyer's own
 * phone. The buyer approves, Paystack confirms, and only then does the rider's
 * screen say PAID and the goods change hands.
 *
 * WHY THE CHARGE API AND NOT INITIALIZE
 * `initialize` returns a checkout URL — it needs a browser, and there isn't
 * one at a doorstep. `charge` with a mobile_money payload asks the telco to
 * push the approval prompt straight to the handset, which is the interaction
 * the owner described.
 *
 * HOW A SECOND CHARGE IS PREVENTED
 * The contract says one intent per job and never a double charge. Paystack
 * will not accept a repeated reference, and a mobile-money prompt expires, so
 * a retry genuinely needs a new Paystack transaction. The protection is
 * therefore not a reused reference but an ORDER OF OPERATIONS: before every
 * retry the previous reference is verified with Paystack, and if it already
 * succeeded no new charge is created and the job is reported paid. One
 * logical intent (`pkbd_<order>`) spans however many Paystack attempts it
 * takes, and the buyer can only ever be captured once.
 *
 * HOW THE MONEY GETS RECONCILED
 * The reference is written to `_pokbon_paystack_reference`, the same meta the
 * marketplace plugin's own webhook looks orders up by. So `charge.success`
 * lands on the existing, proven handler, which marks the order paid. This
 * class then hooks `woocommerce_payment_complete` and tells the Delivery API.
 * No change to the marketplace webhook, and any path that pays the order —
 * webhook, app verify, or an admin marking it paid — reaches delivery.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Payments {

	const META_INTENT     = '_pokbon_delivery_payment_intent';
	const META_STATUS     = '_pokbon_delivery_payment_status';
	/** What Paystack asked for: pay_offline, send_otp, success, failed. */
	const META_STAGE       = '_pokbon_delivery_payment_stage';
	/** Paystack's own wording for the customer, kept verbatim. */
	const META_INSTRUCTION = '_pokbon_delivery_payment_instruction';
	const META_JOB_ID     = '_pokbon_delivery_job_id';
	const META_PROMPTS    = '_pokbon_delivery_prompt_count';
	const META_PAID_AT    = '_pokbon_paid_on_delivery';
	const META_PAYMENT_REF = '_pokbon_delivery_payment_ref';

	/*
	 * Per leg, because an order can be several deliveries.
	 *
	 * Everything above is order-shaped, and that was the whole problem: one
	 * amount, one reference, one paid flag for an order that three riders
	 * deliver at three different moments. The buyer pays each rider for what
	 * that rider hands over, so each leg needs its own amount, its own Paystack
	 * attempts and its own record of having been settled.
	 *
	 * All keyed by job id. The order-level keys above still mean what they
	 * always meant — the order as a whole — and are written when the legs add
	 * up to it.
	 */
	/** job id => what to collect at that door, minor units. Written at dispatch. */
	const META_LEG_COD     = '_pokbon_delivery_leg_cod';
	/** job id => list of attempts, newest first: [ ref, amountMinor ]. */
	const META_LEG_REF     = '_pokbon_delivery_leg_ref';
	/** job id => [ amountMinor, reference, paidAt ] once that leg is settled. */
	const META_LEG_PAID    = '_pokbon_delivery_leg_paid';
	/** job id => how many prompts that leg has sent. */
	const META_LEG_PROMPTS = '_pokbon_delivery_leg_prompts';

	const API_BASE = 'https://api.paystack.co';

	public static function bootstrap(): void {
		// Whatever marks the order paid, delivery hears about it.
		add_action( 'woocommerce_payment_complete', [ self::class, 'on_payment_complete' ], 20, 1 );
	}

	// ─── credentials ────────────────────────────────────────────────────────

	private static function secret_key(): string {
		if ( ! class_exists( 'Pokbon_App_Payments_Endpoint' ) ) {
			return '';
		}
		$settings = Pokbon_App_Payments_Endpoint::get_settings();
		return (string) ( $settings['mode'] === 'live'
			? $settings['live_secret_key']
			: $settings['test_secret_key'] );
	}

	private static function currency(): string {
		if ( ! class_exists( 'Pokbon_App_Payments_Endpoint' ) ) {
			return 'GHS';
		}
		$settings = Pokbon_App_Payments_Endpoint::get_settings();
		return (string) ( $settings['currency'] ?? 'GHS' );
	}

	// ─── one order, several doors ───────────────────────────────────────────

	/** An order meta that holds a map, always as an array. */
	private static function map( $order, string $key ): array {
		$raw = $order->get_meta( $key );
		return is_array( $raw ) ? $raw : [];
	}

	private static function order_total_minor( $order ): int {
		return (int) round( ( (float) $order->get_total() ) * 100 );
	}

	/**
	 * What this rider collects at this door, in minor units.
	 *
	 * Written at dispatch by Orders::create_jobs_for_order(), so it is the
	 * amount that was quoted when the job was made rather than one recomputed
	 * now — prices and settings move, and the buyer was told a figure.
	 *
	 * An order dispatched before this existed has no record. One leg means the
	 * whole order and always did, so that case is exact. Several legs fall back
	 * to an even split: arbitrary, obviously so, and conserving — which is the
	 * property that matters, because the alternative is charging somebody twice
	 * or not at all while an upgrade is mid-flight.
	 */
	public static function leg_amount_minor( $order, string $job_id ): int {
		$cod = self::map( $order, self::META_LEG_COD );
		if ( isset( $cod[ $job_id ] ) ) {
			return max( 0, (int) $cod[ $job_id ] );
		}

		$jobs  = Pokbon_Delivery_Orders::job_ids_for( $order );
		$total = self::order_total_minor( $order );
		$count = max( 1, count( $jobs ) );
		if ( $count === 1 ) {
			return $total;
		}

		// The last leg takes the remainder, so the even split still conserves.
		$each = intdiv( $total, $count );
		$last = array_key_last( $jobs );
		return ( $last !== null && $jobs[ $last ] === $job_id ) ? $total - $each * ( $count - 1 ) : $each;
	}

	/** Legs already settled: job id => [ amountMinor, reference, paidAt ]. */
	public static function legs_paid( $order ): array {
		return self::map( $order, self::META_LEG_PAID );
	}

	/** Collected so far across every leg, in minor units. */
	public static function collected_minor( $order ): int {
		$sum = 0;
		foreach ( self::legs_paid( $order ) as $leg ) {
			$sum += (int) ( $leg['amountMinor'] ?? 0 );
		}
		return $sum;
	}

	/** Still to collect on the order as a whole. Never negative. */
	public static function outstanding_minor( $order ): int {
		return max( 0, self::order_total_minor( $order ) - self::collected_minor( $order ) );
	}

	/**
	 * What to actually charge at this door, right now.
	 *
	 * The leg's own amount, but never more than the order still owes. The cap
	 * is what makes every retry, re-dispatch and duplicate prompt safe: however
	 * many times this is called, the legs together cannot take more than the
	 * order total from the buyer.
	 */
	public static function chargeable_minor( $order, string $job_id ): int {
		$paid = self::legs_paid( $order );
		if ( isset( $paid[ $job_id ] ) ) {
			return 0; // This door is settled.
		}
		return min( self::leg_amount_minor( $order, $job_id ), self::outstanding_minor( $order ) );
	}

	/**
	 * Remember a Paystack attempt against the leg that made it.
	 *
	 * The amount is stored beside the reference on purpose. Verification has to
	 * compare what Paystack captured against what we ASKED for, not against a
	 * figure recomputed later — that is how a partly-paid order would otherwise
	 * talk itself into accepting the wrong amount.
	 */
	private static function remember_attempt( $order, string $job_id, string $reference, int $amount_minor ): void {
		$refs  = self::map( $order, self::META_LEG_REF );
		$mine  = isset( $refs[ $job_id ] ) && is_array( $refs[ $job_id ] ) ? $refs[ $job_id ] : [];
		array_unshift( $mine, [ 'ref' => $reference, 'amountMinor' => $amount_minor ] );
		// Paystack expires a mobile-money prompt; older attempts than this
		// cannot still be open, and an unbounded list would grow for ever.
		$refs[ $job_id ] = array_slice( $mine, 0, 5 );
		$order->update_meta_data( self::META_LEG_REF, $refs );

		/*
		 * The marketplace's own webhook looks orders up by this meta and marks
		 * the order paid on charge.success. That is right for a charge covering
		 * the whole order and wrong for one leg of three, so a partial charge
		 * stays out of it and is reconciled here instead.
		 */
		if ( $amount_minor === self::order_total_minor( $order ) ) {
			$previous = (string) $order->get_meta( '_pokbon_paystack_reference' );
			if ( $previous !== '' && $previous !== $reference ) {
				$order->update_meta_data( '_pokbon_paystack_reference_prev', $previous );
			}
			$order->update_meta_data( '_pokbon_paystack_reference', $reference );
		}
	}

	/**
	 * This door is settled. Write it down, and close the order if that was the
	 * last one.
	 *
	 * Idempotent per leg: the rider's poll, Paystack's webhook and a
	 * dispatcher's retry all reach this within seconds of each other.
	 */
	public static function record_leg_payment(
		$order,
		string $job_id,
		string $reference,
		int $amount_minor,
		string $paid_at = '',
		array $verify = []
	): void {
		$paid = self::legs_paid( $order );
		if ( isset( $paid[ $job_id ] ) ) {
			return;
		}

		$paid_at            = $paid_at !== '' ? gmdate( 'c', strtotime( $paid_at ) ) : gmdate( 'c' );
		$paid[ $job_id ]    = [
			'amountMinor' => $amount_minor,
			'reference'   => $reference,
			'paidAt'      => $paid_at,
		];
		$order->update_meta_data( self::META_LEG_PAID, $paid );
		$order->save();

		$legs = Pokbon_Delivery_Orders::job_ids_for( $order );
		if ( count( $legs ) > 1 ) {
			// Only worth saying on an order that has more than one door. On a
			// single-leg order the note below says the same thing better.
			$order->add_order_note( sprintf(
				'[POKBON Delivery] Collected GHS %s at the door for one of %d deliveries. Paystack reference %s. GHS %s of this order is still to collect.',
				number_format( $amount_minor / 100, 2 ),
				count( $legs ),
				$reference,
				number_format( self::outstanding_minor( $order ) / 100, 2 )
			) );
		}

		Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PAID, [
			'order_id'    => $order->get_id(),
			'job_id'      => $job_id,
			'reference'   => $reference,
			'amountMinor' => $amount_minor,
			'outstanding' => self::outstanding_minor( $order ),
		] );

		// Tell this rider's job, whether or not the order is finished.
		$result = Pokbon_Delivery_API_Client::report_payment( $job_id, [
			'intentId'  => self::intent_for( (int) $order->get_id(), $job_id ),
			'status'    => 'paid',
			'reference' => $reference,
			'paidAt'    => $paid_at,
		] );
		if ( is_wp_error( $result ) ) {
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
				'order_id' => $order->get_id(),
				'job_id'   => $job_id,
				'stage'    => 'report_payment',
				'error'    => $result->get_error_message(),
			] );
		}

		if ( self::outstanding_minor( $order ) <= 0 ) {
			self::record_doorstep_payment( $order, $reference, $paid_at, $verify );
		}
	}

	// ─── the prompt ─────────────────────────────────────────────────────────

	/**
	 * Push (or re-push) the approval prompt for an order.
	 *
	 * $reason is 'arrival' for the first prompt and 'retry' when the rider
	 * taps Send prompt again. Returns the intent shape the API expects, or a
	 * WP_Error the API turns into "prompt failed, try again".
	 */
	public static function prompt( int $order_id, string $job_id, string $reason = 'arrival' ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'wc_missing', 'WooCommerce is not active.' );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'no_order', 'Order not found.' );
		}

		$secret = self::secret_key();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'Paystack is not configured in POKBON App → Payments.' );
		}

		$intent_id = self::intent_for( $order_id, $job_id );

		// Already paid? Say so and charge nothing. This is the guard that
		// makes a retry safe, and it runs before anything else.
		$already = self::confirm_existing_payment( $order, $job_id, $secret );
		if ( is_array( $already ) ) {
			return $already;
		}

		/*
		 * This door, not the whole order.
		 *
		 * This was $order->get_total() on every leg. On a one-vendor order that
		 * is exactly right and nobody noticed. On the three-vendor order that
		 * was actually tested, each of three riders would raise a prompt for
		 * the ENTIRE order at their own doorstep — the first to arrive taking
		 * all of it, before the buyer had seen the other two parcels, and two
		 * riders arriving together both passing the guard above and both
		 * charging in full.
		 *
		 * A rider collects for what that rider is carrying. chargeable_minor()
		 * caps it at what the order still owes, so however many prompts,
		 * retries and re-dispatches happen, the legs together can never take
		 * more than the total.
		 */
		$amount_minor = self::chargeable_minor( $order, $job_id );
		if ( $amount_minor <= 0 ) {
			return new WP_Error(
				'nothing_to_collect',
				'There is nothing to collect at this door — this delivery has already been paid for. Hand the parcel over.'
			);
		}

		$phone = Pokbon_Delivery_Messages::normalise_ghana_phone( (string) $order->get_billing_phone() );
		if ( $phone === '' ) {
			return new WP_Error( 'no_phone', 'The order has no usable Ghana mobile number for the prompt.' );
		}
		$provider = Pokbon_Delivery_Messages::momo_provider( $phone );
		if ( $provider === null ) {
			// Guessing the network sends the prompt nowhere and the rider waits
			// at the door for something that will never arrive.
			return new WP_Error( 'unknown_network', 'That number does not map to a known mobile-money network. Use pay by link instead.' );
		}

		/*
		 * Counted per leg. The order-level count is still kept, because the
		 * order panel shows it, but the limit belongs to the door: three
		 * riders sharing one allowance meant the third arrived to find the
		 * prompt already exhausted by deliveries that were not theirs.
		 */
		$leg_prompts = self::map( $order, self::META_LEG_PROMPTS );
		$prompts     = (int) ( $leg_prompts[ $job_id ] ?? 0 );
		$max         = (int) Pokbon_Delivery_Settings::get( 'payment_max_prompts' );
		if ( $max > 0 && $prompts >= $max ) {
			return new WP_Error( 'too_many_prompts', sprintf( 'The prompt has already been sent %d times for this delivery. Use pay by link, or mark the delivery failed.', $prompts ) );
		}

		// A fresh Paystack reference per attempt, rotated exactly the way the
		// marketplace's own re-init does — so the webhook can still reconcile
		// a charge completed against the previous one.
		$reference = 'pkb_' . $order_id . '_' . wp_generate_password( 8, false );

		$response = self::paystack( 'POST', '/charge', [
			'email'        => self::buyer_email( $order ),
			'amount'       => $amount_minor,
			'currency'     => self::currency(),
			'reference'    => $reference,
			'mobile_money' => [
				'phone'    => $phone,
				'provider' => $provider,
			],
			'metadata'     => [
				'pokbon_delivery_job_id' => $job_id,
				'pokbon_order_id'        => $order_id,
				'channel'                => 'pay_on_delivery',
			],
		], $secret );

		if ( is_wp_error( $response ) ) {
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
				'order_id' => $order_id,
				'job_id'   => $job_id,
				'stage'    => 'charge',
				'error'    => $response->get_error_message(),
			] );

			/*
			 * A charge that cannot be created is not the end of the delivery.
			 *
			 * This used to return the error and stop, so a rider stood at a
			 * door with a customer willing to pay and nothing to offer them —
			 * the screen showed "send the prompt again" and the prompt would
			 * fail again for the same reason. Seen on 2026-09-22 when Paystack
			 * refused the charge outright with "Charge attempted".
			 *
			 * A checkout link is a different call to a different endpoint and
			 * routinely works when a direct mobile-money charge will not: a
			 * number Paystack will not debit directly can still pay on their
			 * hosted page, by card, by another wallet, or by USSD. So the link
			 * is tried before giving up, and only a failure of both is
			 * reported as a failure.
			 */
			$link = self::create_payment_link( $intent_id );
			if ( is_wp_error( $link ) || empty( $link['url'] ) ) {
				return $response; // Both routes gone: the original error is the useful one.
			}

			$text = Pokbon_Delivery_Settings::message( 'pay_by_link', [
				'amount' => number_format( $amount_minor / 100, 2 ),
				'order'  => $order_id,
				'link'   => $link['url'],
			] );
			if ( $text !== '' ) {
				Pokbon_Delivery_Messages::sms( $phone, $text, [
					'purpose'  => 'pay_by_link',
					'order_id' => $order_id,
				] );
			}

			$order->update_meta_data( self::META_INTENT, $intent_id );
			$order->update_meta_data( self::META_JOB_ID, $job_id );
			$order->update_meta_data( self::META_STATUS, 'pending' );
			$order->update_meta_data( self::META_STAGE, 'link_only' );
			$order->update_meta_data(
				self::META_INSTRUCTION,
				'The mobile money request was refused, so a payment link was sent instead.'
			);
			$leg_prompts[ $job_id ] = $prompts + 1;
			$order->update_meta_data( self::META_LEG_PROMPTS, $leg_prompts );
			$order->update_meta_data( self::META_PROMPTS, (int) $order->get_meta( self::META_PROMPTS ) + 1 );
			$order->save();

			$wait = (int) Pokbon_Delivery_Settings::get( 'payment_wait_minutes' );

			return [
				'intentId'    => $intent_id,
				'status'      => 'pending',
				'amount'      => round( $amount_minor / 100, 2 ),
				'currency'    => self::currency(),
				'expiresAt'   => gmdate( 'c', time() + max( 1, $wait ) * MINUTE_IN_SECONDS ),
				'reference'   => $link['reference'],
				'stage'       => 'link_only',
				'instruction' => 'Mobile money was refused; the customer has a payment link by SMS.',
				'payUrl'      => $link['url'],
			];
		}

		/*
		 * What Paystack said to do next.
		 *
		 * The charge reply carries data.status, which is the whole point of
		 * the call: `pay_offline` means the customer gets a request on their
		 * handset and we wait, while `send_otp` means Paystack has texted them
		 * a code that has to be submitted BACK to Paystack. This code used to
		 * read none of it and record 'pending' either way — so when Paystack
		 * asked for the code, nobody was listening, the customer got an SMS
		 * with nowhere to type it, and the charge sat pending until it died.
		 * display_text is Paystack's own wording for the customer.
		 */
		$data        = is_array( $response['data'] ?? null ) ? $response['data'] : [];
		$stage       = strtolower( (string) ( $data['status'] ?? '' ) );
		$instruction = trim( (string) ( $data['display_text'] ?? $data['message'] ?? '' ) );

		self::remember_attempt( $order, $job_id, $reference, $amount_minor );
		$leg_prompts[ $job_id ] = $prompts + 1;
		$order->update_meta_data( self::META_LEG_PROMPTS, $leg_prompts );
		$order->update_meta_data( '_pokbon_paystack_channel', 'mobile_money' );
		$order->update_meta_data( self::META_INTENT, $intent_id );
		$order->update_meta_data( self::META_JOB_ID, $job_id );
		$order->update_meta_data( self::META_STATUS, 'pending' );
		$order->update_meta_data( self::META_STAGE, $stage );
		$order->update_meta_data( self::META_INSTRUCTION, $instruction );
		$order->update_meta_data( self::META_PROMPTS, (int) $order->get_meta( self::META_PROMPTS ) + 1 );
		$order->save();

		Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PROMPTED, [
			'order_id'  => $order_id,
			'job_id'    => $job_id,
			'reason'    => $reason,
			'attempt'   => $prompts + 1,
			'provider'  => $provider,
			'reference' => $reference,
		] );

		$wait = (int) Pokbon_Delivery_Settings::get( 'payment_wait_minutes' );

		/*
		 * Where the handset request cannot finish on its own, put a link in
		 * the customer's hand instead.
		 *
		 * `send_otp` asks for a code the customer would otherwise have to read
		 * aloud to the rider standing in front of them, which is a payment
		 * authorisation and not something to say out loud. Anything we do not
		 * recognise gets the same treatment, because the alternative is a
		 * customer holding an instruction nobody can act on. The link is tied
		 * to the order, so a payment made through it reconciles itself —
		 * which a USSD menu asking only for an amount cannot do.
		 */
		$pay_url = '';
		if ( $stage !== '' && ! in_array( $stage, [ 'pay_offline', 'pending', 'success' ], true ) ) {
			$link = self::create_payment_link( $intent_id );
			if ( ! is_wp_error( $link ) && ! empty( $link['url'] ) ) {
				$pay_url = (string) $link['url'];
			}
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PROMPTED, [
				'order_id' => $order_id,
				'job_id'   => $job_id,
				'stage'    => $stage,
				'fallback' => $pay_url !== '' ? 'link_sent' : 'link_failed',
			] );
		}

		// One message, carrying whatever the customer actually has to do.
		// The opening line is editable under POKBON Delivery -> Messages; the
		// rest is Paystack's own wording and the link, which are not ours to
		// rewrite.
		$opening = Pokbon_Delivery_Settings::message( 'payment_prompt', [
			'amount' => number_format( $amount_minor / 100, 2 ),
			'order'  => $order_id,
		] );
		$lines = $opening === '' ? [] : [ $opening ];
		if ( $instruction !== '' ) {
			$lines[] = $instruction;
		} elseif ( $pay_url === '' ) {
			$lines[] = 'Check your phone for the mobile money request and approve it.';
		}
		if ( $pay_url !== '' ) {
			$lines[] = 'Or pay here: ' . $pay_url;
		}
		if ( $lines === [] ) {
			// Switched off entirely. The prompt still went to the handset;
			// this only suppresses our own covering message.
			return [
				'intentId'    => $intent_id,
				'status'      => 'pending',
				'amount'      => round( $amount_minor / 100, 2 ),
				'currency'    => self::currency(),
				'expiresAt'   => gmdate( 'c', time() + max( 1, $wait ) * MINUTE_IN_SECONDS ),
				'reference'   => $reference,
				'stage'       => $stage,
				'instruction' => $instruction,
				'payUrl'      => $pay_url,
			];
		}

		Pokbon_Delivery_Messages::sms( $phone, implode( ' ', $lines ), [
			'purpose'  => 'payment_prompt',
			'order_id' => $order_id,
		] );

		return [
			'intentId'    => $intent_id,
			'status'      => 'pending',
			'amount'      => round( $amount_minor / 100, 2 ),
			'currency'    => self::currency(),
			'expiresAt'   => gmdate( 'c', time() + max( 1, $wait ) * MINUTE_IN_SECONDS ),
			'reference'   => $reference,
			// So the dispatcher and the job log can see what was actually
			// asked of the customer, rather than a bare "pending".
			'stage'       => $stage,
			'instruction' => $instruction,
			'payUrl'      => $pay_url,
		];
	}

	/**
	 * Current state of a job's payment, for the API's poll.
	 *
	 * Asks Paystack rather than trusting local state: the webhook may simply
	 * be late, and a rider should not be told "failed" for a payment that in
	 * fact went through.
	 */
	public static function status( string $intent_id ) {
		$leg = self::leg_for_intent( $intent_id );
		if ( ! $leg ) {
			return new WP_Error( 'no_intent', 'No order carries that payment intent.' );
		}
		$order  = $leg['order'];
		$job_id = (string) $leg['jobId'];

		// This door first. An order can be settled as a whole while this leg
		// was never charged — and the reverse, which is the normal state of a
		// multi-vendor order between the first rider and the last.
		$mine = self::legs_paid( $order )[ $job_id ] ?? null;
		if ( is_array( $mine ) ) {
			return [
				'intentId'  => $intent_id,
				'status'    => 'paid',
				'reference' => (string) ( $mine['reference'] ?? '' ),
				'paidAt'    => (string) ( $mine['paidAt'] ?? '' ),
				'amount'    => round( ( (int) ( $mine['amountMinor'] ?? 0 ) ) / 100, 2 ),
				'currency'  => self::currency(),
			];
		}

		$paid_at = (string) $order->get_meta( self::META_PAID_AT );
		if ( $paid_at !== '' ) {
			// The whole order is settled — by the webhook, by an admin, or at
			// checkout. Nothing left to collect at any door.
			return [
				'intentId'  => $intent_id,
				'status'    => 'paid',
				'reference' => (string) $order->get_meta( self::META_PAYMENT_REF ),
				'paidAt'    => $paid_at,
				'amount'    => round( ( (float) $order->get_total() ), 2 ),
				'currency'  => self::currency(),
			];
		}

		$secret = self::secret_key();
		if ( $secret !== '' ) {
			$confirmed = self::confirm_existing_payment( $order, $job_id, $secret );
			if ( is_array( $confirmed ) ) {
				return $confirmed;
			}
		}

		return [
			'intentId'  => $intent_id,
			'status'    => (string) ( $order->get_meta( self::META_STATUS ) ?: 'pending' ),
			'amount'    => round( self::chargeable_minor( $order, $job_id ) / 100, 2 ),
			'currency'  => self::currency(),
			'expiresAt' => null,
		];
	}

	/**
	 * Pay by link (§ 11 step 2): someone else settles it from their own phone.
	 *
	 * A hosted checkout is right here — the payer is not the buyer, may be on
	 * any network, and may want to use a card.
	 */
	/**
	 * A Paystack checkout link for this order, created but not sent.
	 *
	 * Split out from pay_by_link() so the doorstep prompt can put the link
	 * into its own single message. Before this, asking for a link always sent
	 * a second SMS, so the prompt path could not offer one without texting
	 * the customer twice about the same money.
	 *
	 * The link carries the order's reference, which is what makes a payment
	 * made this way reconcile itself — the reason it beats a USSD menu that
	 * asks only for an amount.
	 */
	public static function create_payment_link( string $intent_id ) {
		$leg = self::leg_for_intent( $intent_id );
		if ( ! $leg ) {
			return new WP_Error( 'no_intent', 'No order carries that payment intent.' );
		}
		$order  = $leg['order'];
		$job_id = (string) $leg['jobId'];

		$secret = self::secret_key();
		if ( $secret === '' ) {
			return new WP_Error( 'not_configured', 'Paystack is not configured.' );
		}

		$already = self::confirm_existing_payment( $order, $job_id, $secret );
		if ( is_array( $already ) ) {
			return $already;
		}

		$order_id = $order->get_id();

		// The same figure the handset prompt would have asked for. A link that
		// charges the whole order while the prompt beside it charges one leg
		// would be two prices for one parcel, and the customer would be right
		// to pick whichever they preferred.
		$amount_minor = self::chargeable_minor( $order, $job_id );
		if ( $amount_minor <= 0 ) {
			return new WP_Error( 'nothing_to_collect', 'There is nothing left to collect for this delivery.' );
		}

		$reference = 'pkb_' . $order_id . '_' . wp_generate_password( 8, false );

		$response = self::paystack( 'POST', '/transaction/initialize', [
			'email'     => self::buyer_email( $order ),
			'amount'    => $amount_minor,
			'currency'  => self::currency(),
			'reference' => $reference,
			/*
			 * Where the customer's browser lands after paying.
			 *
			 * Set here rather than left to the Paystack dashboard, because the
			 * dashboard's Callback URL had been set to the webhook address —
			 * a POST-only route — so every customer who paid was shown
			 * {"code":"rest_no_route"} as their receipt. A person who has just
			 * handed over money and been given a JSON error has no way to know
			 * the payment worked.
			 */
			'callback_url' => $order->get_checkout_order_received_url(),
			'metadata'  => [
				'pokbon_delivery_job_id' => $job_id,
				'pokbon_order_id'        => $order_id,
				'channel'                => 'pay_on_delivery_link',
			],
		], $secret );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$url = (string) ( $response['data']['authorization_url'] ?? '' );
		if ( $url === '' ) {
			return new WP_Error( 'no_link', 'Paystack did not return a payment link.' );
		}

		self::remember_attempt( $order, $job_id, $reference, $amount_minor );
		$order->save();

		return [
			'url'       => $url,
			'reference' => $reference,
			'orderId'   => $order_id,
			'amount'    => round( $amount_minor / 100, 2 ),
		];
	}

	public static function pay_by_link( string $intent_id, string $phone ) {
		$to = Pokbon_Delivery_Messages::normalise_ghana_phone( $phone );
		if ( $to === '' ) {
			return new WP_Error( 'bad_phone', 'That is not a valid Ghana mobile number.' );
		}

		$link = self::create_payment_link( $intent_id );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		// Already settled: confirm_existing_payment() answers in full.
		if ( empty( $link['url'] ) ) {
			return $link;
		}

		$text = Pokbon_Delivery_Settings::message( 'pay_by_link', [
			'amount' => number_format( (float) $link['amount'], 2 ),
			'order'  => $link['orderId'],
			'link'   => $link['url'],
		] );
		if ( $text === '' ) {
			return new WP_Error( 'message_off', 'The pay-by-link message is switched off under POKBON Delivery -> Messages.' );
		}

		$sent = Pokbon_Delivery_Messages::sms(
			$to,
			$text,
			[ 'purpose' => 'pay_by_link', 'order_id' => $link['orderId'] ]
		);

		if ( ! $sent ) {
			return new WP_Error( 'sms_failed', 'The payment link could not be sent by SMS.' );
		}

		Pokbon_Delivery_Audit::log( 'delivery.pay_by_link_sent', [
			'order_id'  => $link['orderId'],
			'reference' => $link['reference'],
		] );

		return [ 'sent' => true, 'intentId' => $intent_id, 'reference' => $link['reference'] ];
	}

	// ─── reconciliation ─────────────────────────────────────────────────────

	/**
	 * The order was paid, by whatever route. Tell the Delivery API, and write
	 * the meta that reclassifies a `cod` order as digitally settled.
	 *
	 * `_pokbon_paid_on_delivery` is what plugin 1.20.2's `settled_digitally()`
	 * reads. Writing it is the whole reason the commission engine stops
	 * creating a vendor debt and starts counting the gateway fee.
	 */
	/**
	 * Write down that the buyer paid at the door, whatever the order status is.
	 *
	 * Everything on_payment_complete() did, minus the dependence on a
	 * WooCommerce hook that cannot fire for these orders, plus the two things
	 * it never did: date_paid, and working on a multi-vendor order.
	 *
	 * Idempotent on META_PAID_AT, because the rider's poll, the webhook and a
	 * dispatcher's retry can all arrive at this within seconds of each other.
	 *
	 * @param mixed  $order     The WooCommerce order.
	 * @param string $reference The Paystack reference that succeeded.
	 * @param string $paid_at   Paystack's own timestamp, when it gave one.
	 * @param array  $verify    The verify response, for the gateway fee.
	 */
	public static function record_doorstep_payment( $order, string $reference, string $paid_at = '', array $verify = [] ): void {
		if ( ! $order || (string) $order->get_meta( self::META_PAID_AT ) !== '' ) {
			return; // Already recorded.
		}

		$paid_at = $paid_at !== '' ? gmdate( 'c', strtotime( $paid_at ) ) : gmdate( 'c' );

		$order->update_meta_data( self::META_PAID_AT, $paid_at );
		$order->update_meta_data( self::META_PAYMENT_REF, $reference );
		$order->update_meta_data( self::META_STATUS, 'paid' );

		/*
		 * Paystack's cut, recorded where the marketplace's own webhook records
		 * it. Absorbed invisibly until now, because the branch that writes it
		 * sat behind the same guard.
		 */
		$fee = $verify['data']['fees'] ?? null;
		if ( is_numeric( $fee ) ) {
			$order->update_meta_data( '_pokbon_gateway_fee', round( ( (float) $fee ) / 100, 2 ) );
		}

		/*
		 * date_paid is the durable signal everything else keys on, and its
		 * absence is not cosmetic: is_pay_on_delivery() reads get_date_paid()
		 * precisely so it does not trust a status. Without this a paid order
		 * stays "pay on delivery" for ever, and re-dispatching it builds a
		 * second job demanding the whole total again.
		 */
		if ( method_exists( $order, 'set_date_paid' ) && ! $order->get_date_paid() ) {
			$order->set_date_paid( time() );
		}

		$order->save();

		$order->add_order_note( sprintf(
			'[POKBON Delivery] Paid at the door by mobile money. Paystack reference %s.',
			$reference
		) );

		Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_PAID, [
			'order_id'  => $order->get_id(),
			'reference' => $reference,
		] );

		/*
		 * Every job on the order, not one. META_JOB_ID is only written when an
		 * order produced exactly one job, so a multi-vendor order had nothing
		 * here at all — the orders most likely to need the record were the ones
		 * guaranteed not to get it.
		 */
		foreach ( Pokbon_Delivery_Orders::job_ids_for( $order ) as $job_id ) {
			$result = Pokbon_Delivery_API_Client::report_payment( $job_id, [
				// Each job's OWN intent. The API refuses an outcome that names
				// an intent the job does not hold — `Intent x is not this job's
				// intent` — so sending the order-level id here would have been
				// rejected for every leg but the first.
				'intentId'  => self::intent_for( (int) $order->get_id(), $job_id ),
				'status'    => 'paid',
				'reference' => $reference,
				'paidAt'    => $paid_at,
			] );

			if ( is_wp_error( $result ) ) {
				// The money is in and the meta is written; only the rider's
				// screen is behind, and the API polls its own payment status
				// so it recovers by itself. It must still be visible.
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
					'order_id' => $order->get_id(),
					'job_id'   => $job_id,
					'stage'    => 'report_payment',
					'error'    => $result->get_error_message(),
				] );
			}
		}
	}

	public static function on_payment_complete( $order_id ): void {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Not a delivery-collected payment. Nothing here to record.
		if ( Pokbon_Delivery_Orders::job_ids_for( $order ) === [] ) {
			return;
		}

		/*
		 * Delegates, so there is one implementation of "the buyer paid".
		 *
		 * This hook still fires for the paths where WooCommerce legitimately
		 * completes a payment, and it should keep working. It is simply no
		 * longer the only way the record gets written — which it could not be,
		 * because it never fired for a doorstep payment at all.
		 */
		self::record_doorstep_payment( $order, (string) $order->get_meta( '_pokbon_paystack_reference' ) );
	}

	/**
	 * Ask Paystack whether the reference already on the order succeeded.
	 *
	 * Returns the paid intent array when it did, null when it did not. This is
	 * the single guard that makes every retry path safe.
	 */
	private static function confirm_existing_payment( $order, string $job_id, string $secret ) {
		/*
		 * This leg's own attempts, newest first, and then the order-level
		 * references for anything charged before legs existed.
		 *
		 * Each attempt carries the amount it asked for, because that is what
		 * Paystack will have captured. Comparing a capture against a figure
		 * recomputed now is how a partly-paid order talks itself into accepting
		 * the wrong amount.
		 */
		$attempts = [];
		$refs     = self::map( $order, self::META_LEG_REF );
		if ( isset( $refs[ $job_id ] ) && is_array( $refs[ $job_id ] ) ) {
			foreach ( $refs[ $job_id ] as $attempt ) {
				if ( ! empty( $attempt['ref'] ) ) {
					$attempts[] = [
						'ref'      => (string) $attempt['ref'],
						'expected' => (int) ( $attempt['amountMinor'] ?? 0 ),
					];
				}
			}
		}

		/*
		 * Orders charged before this version kept one reference for the whole
		 * order, and the whole order is what it was for.
		 *
		 * Consulted while no leg has been recorded — which covers the order
		 * that matters: one dispatched under the old code, whose buyer has
		 * already paid in full, whose riders are out right now. Without this
		 * the upgrade would make that payment invisible and every remaining
		 * rider would ask for money the buyer had already handed over. The
		 * amount still has to match the order exactly, so a leg-sized capture
		 * can never be mistaken for one.
		 */
		if ( self::legs_paid( $order ) === [] ) {
			foreach ( [ '_pokbon_paystack_reference', '_pokbon_paystack_reference_prev' ] as $meta_key ) {
				$legacy = (string) $order->get_meta( $meta_key );
				if ( $legacy !== '' ) {
					$attempts[] = [ 'ref' => $legacy, 'expected' => self::order_total_minor( $order ) ];
				}
			}
		}

		foreach ( $attempts as $attempt ) {
			$reference = $attempt['ref'];
			$expected  = $attempt['expected'] > 0 ? $attempt['expected'] : self::order_total_minor( $order );

			$verify = self::paystack( 'GET', '/transaction/verify/' . rawurlencode( $reference ), null, $secret );
			if ( is_wp_error( $verify ) ) {
				continue; // Cannot confirm; fall through and do not assume paid.
			}

			$status = (string) ( $verify['data']['status'] ?? '' );
			if ( $status !== 'success' ) {
				continue;
			}

			// Amount must match what this attempt asked for.
			$paid = (int) ( $verify['data']['amount'] ?? 0 );
			if ( abs( $paid - $expected ) > 1 ) {
				Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_PAYMENT_FAILED, [
					'order_id' => $order->get_id(),
					'stage'    => 'amount_mismatch',
					'paid'     => $paid,
					'expected' => $expected,
				] );
				continue;
			}

			/*
			 * Record it here, not through WooCommerce's hook.
			 *
			 * This used to be `if ( ! $order->is_paid() ) payment_complete()`,
			 * on the reasoning that payment_complete() fires
			 * woocommerce_payment_complete, which fires on_payment_complete(),
			 * which writes the meta. Every link in that chain is real. The
			 * first condition is not.
			 *
			 * A delivery job only exists because the order reached
			 * `processing` — that is what creates it — and is_paid() counts
			 * `processing` as paid. So the guard was always true and
			 * payment_complete() was never called for a doorstep payment. Even
			 * forced, WooCommerce only fires that hook and stamps date_paid
			 * from on-hold, pending, failed or cancelled; `processing`,
			 * `ready-to-ship` and `in-transit` are none of those.
			 *
			 * So on_payment_complete() could never run for the one payment
			 * method it was written for. The money reached Paystack, the rider
			 * handed over, both screens said paid — and `_pokbon_paid_on_delivery`
			 * was never written, so the commission engine went on booking a
			 * vendor debt for cash the vendor never touched and POKBON already
			 * held. Nothing about that is visible from any screen.
			 *
			 * This file's own is_pay_on_delivery() carries a comment warning
			 * that is_paid() counts `processing`. The lesson was learned, written
			 * down, and then repeated three hundred lines away.
			 */
			// This door, with whatever was actually captured at it. The order as
			// a whole is closed by record_leg_payment() once the legs add up.
			self::record_leg_payment(
				$order,
				$job_id,
				$reference,
				$paid,
				(string) ( $verify['data']['paid_at'] ?? '' ),
				$verify
			);

			return [
				'intentId'  => self::intent_for( (int) $order->get_id(), $job_id ),
				'status'    => 'paid',
				'reference' => $reference,
				'paidAt'    => (string) ( $verify['data']['paid_at'] ?? gmdate( 'c' ) ),
				'amount'    => round( $paid / 100, 2 ),
				'currency'  => self::currency(),
			];
		}

		return null;
	}

	// ─── plumbing ───────────────────────────────────────────────────────────

	/** One stable logical intent per order, however many attempts it takes. */
	public static function intent_id( int $order_id ): string {
		return 'pkbd_' . $order_id;
	}

	/**
	 * One stable logical intent per LEG, however many attempts it takes.
	 *
	 * `pkbd_<order>_<n>`, n being the leg's position on the order. The API
	 * already stores whatever intent id it is handed on the job itself
	 * (`job.paymentIntentId`), so nothing there had to change — only this
	 * plugin was assuming one payment per order.
	 *
	 * A single-leg order still answers to the bare `pkbd_<order>`, so an intent
	 * created before this version keeps resolving.
	 */
	public static function intent_for( int $order_id, string $job_id ): string {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return self::intent_id( $order_id );
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return self::intent_id( $order_id );
		}

		$index = array_search( $job_id, Pokbon_Delivery_Orders::job_ids_for( $order ), true );
		return $index === false
			? self::intent_id( $order_id )
			: self::intent_id( $order_id ) . '_' . ( (int) $index + 1 );
	}

	/**
	 * The order and the leg an intent refers to.
	 *
	 * Returns [ 'order' => , 'jobId' => ]. A bare `pkbd_<order>` means the
	 * order's first leg, which is what it always meant when there was only ever
	 * one.
	 */
	private static function leg_for_intent( string $intent_id ): ?array {
		$order = self::order_for_intent( $intent_id );
		if ( ! $order ) {
			return null;
		}

		$jobs = Pokbon_Delivery_Orders::job_ids_for( $order );
		if ( $jobs === [] ) {
			return [ 'order' => $order, 'jobId' => (string) $order->get_meta( self::META_JOB_ID ) ];
		}

		$leg = 1;
		if ( preg_match( '/^pkbd_\d+_(\d+)$/', $intent_id, $m ) ) {
			$leg = (int) $m[1];
		}

		return [ 'order' => $order, 'jobId' => (string) ( $jobs[ $leg - 1 ] ?? $jobs[0] ) ];
	}

	private static function order_for_intent( string $intent_id ) {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return null;
		}
		// `pkbd_<order>` and `pkbd_<order>_<leg>` both name the same order.
		if ( preg_match( '/^pkbd_(\d+)/', $intent_id, $m ) ) {
			$order = wc_get_order( (int) $m[1] );
			if ( $order ) {
				return $order;
			}
		}
		$orders = wc_get_orders( [ 'limit' => 1, 'meta_key' => self::META_INTENT, 'meta_value' => $intent_id ] );
		return $orders[0] ?? null;
	}

	private static function buyer_email( $order ): string {
		$email = (string) $order->get_billing_email();
		if ( $email !== '' ) {
			return $email;
		}
		// Paystack requires one. A per-order alias keeps their dashboard
		// meaningful without inventing a person's address.
		return 'order-' . $order->get_id() . '@' . wp_parse_url( home_url(), PHP_URL_HOST );
	}

	/** One place that talks to Paystack, with a bounded timeout. */
	private static function paystack( string $method, string $path, ?array $body, string $secret ) {
		$args = [
			'method'  => $method,
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Bearer ' . $secret,
				'Content-Type'  => 'application/json',
				'Cache-Control' => 'no-cache',
			],
		];
		if ( $body !== null ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::API_BASE . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'paystack_unreachable', 'Paystack unreachable: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$message = is_array( $data ) ? (string) ( $data['message'] ?? 'Unknown error' ) : 'Unparseable response';
			return new WP_Error( 'paystack_error', sprintf( 'Paystack %s %s → HTTP %d: %s', $method, $path, $code, $message ) );
		}

		return $data;
	}
}
