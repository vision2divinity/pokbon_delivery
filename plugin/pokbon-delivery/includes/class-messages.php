<?php
/**
 * Everything the Delivery API asks this plugin to send.
 *
 * The API never talks to Zenoph or to a device. It calls here, so there is one
 * SMS account, one sender id, one set of credentials in the vault, and one
 * place to look when a buyer says they never got the code.
 *
 * SMS IS THE CHANNEL THAT MATTERS. The delivery code reaches the buyer by SMS
 * or it does not reach them at all, and a rider is standing at a door when it
 * fails — so a failed send is reported to the caller rather than swallowed.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Messages {

	/**
	 * Purposes where the buyer is being told about the code.
	 *
	 * For these the in-app body is written here, never passed through, because
	 * the app's inbox is filled by push and a push body is readable on a locked
	 * screen by whoever is holding the phone (PRD § 7).
	 */
	const CODE_PURPOSES = [ 'delivery_code', 'arrival_no_code', 'arrival' ];

	/**
	 * Send an SMS through the marketplace plugin's Zenoph integration.
	 *
	 * Returns true when the gateway accepted it. The API turns false into a
	 * retry, and the rider's screen keeps offering "Send code again".
	 */
	public static function sms( string $phone_e164, string $message, array $context = [] ): bool {
		if ( ! class_exists( 'Pokbon_App_SMS' ) ) {
			Pokbon_Delivery_Audit::log( 'delivery.sms_unavailable', [
				'purpose' => $context['purpose'] ?? '',
				'reason'  => 'marketplace plugin inactive',
			] );
			return false;
		}

		$phone = self::normalise_ghana_phone( $phone_e164 );
		if ( $phone === '' ) {
			Pokbon_Delivery_Audit::log( 'delivery.sms_bad_number', [
				'purpose' => $context['purpose'] ?? '',
			] );
			return false;
		}

		return Pokbon_App_SMS::send( $phone, $message, array_merge(
			[ 'source' => 'pokbon-delivery' ],
			$context
		) );
	}

	/**
	 * The in-app copy of a delivery message.
	 *
	 * HOW THE APP'S INBOX ACTUALLY WORKS. There is no server-side inbox table.
	 * The marketplace app builds its notification list from two things: admin
	 * announcements, and push notifications that arrived on the device
	 * (`src/store/slices/notificationsSlice.ts` mirrors each one). So the way to
	 * put a message in one buyer's inbox is to push it to that buyer's devices,
	 * which `Pokbon_App_Push_Endpoint::send_to_user()` already does.
	 *
	 * THE DELIVERY CODE IS NEVER IN HERE. Because the inbox is fed by push, the
	 * body of this message is readable on a locked screen by whoever is holding
	 * the phone — which is the one thing the code exists to prevent (PRD § 7).
	 * The API sends the code by SMS and sends a code-free "your rider is at the
	 * door" message here. This method additionally refuses to push anything that
	 * looks like a code, so a future change on either side cannot quietly leak
	 * one.
	 *
	 * Returns false when the buyer has no registered device, which is normal and
	 * not an error: they got the SMS.
	 */
	public static function inbox( int $user_id, string $title, string $body, array $context = [] ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		// Belt and braces against a leaked code, done by PURPOSE rather than by
		// inspecting the text. A digit-matching guard looked safer and was worse:
		// "GH₵1600.00" and "order #537313" both contain a run of digits, so it
		// would have mangled ordinary messages while still being fooled by any
		// wording it did not anticipate. The API names what each message is for,
		// and for the moment the code exists this plugin supplies its own copy.
		$purpose = (string) ( $context['purpose'] ?? '' );

		if ( in_array( $purpose, self::CODE_PURPOSES, true ) ) {
			$title = 'Your rider has arrived';
			$body  = 'Your delivery code has been sent to you by SMS. Read it to the rider.';
		}

		/**
		 * Anything that wants to handle delivery notifications itself — a different
		 * push provider, an email, a webhook — hangs off here.
		 */
		do_action( 'pokbon_delivery_inbox_message', $user_id, $title, $body, $context );

		$delivered = false;

		if ( class_exists( 'Pokbon_App_Push_Endpoint' ) ) {
			$delivered = (bool) Pokbon_App_Push_Endpoint::send_to_user(
				$user_id,
				$title,
				$body,
				[
					// `kind` and `data` are what the app's inbox reads to route a tap
					// to the right screen, the same way the OS banner does.
					'type'     => 'delivery',
					'kind'     => 'order',
					'orderId'  => (string) ( $context['order_id'] ?? '' ),
					'jobId'    => (string) ( $context['job_id'] ?? '' ),
					'purpose'  => (string) ( $context['purpose'] ?? 'delivery' ),
				]
			);
		}

		return (bool) apply_filters( 'pokbon_delivery_inbox_delivered', $delivered, $user_id, $context );
	}

	/**
	 * Ghana numbers, normalised to E.164.
	 *
	 * Mirrors packages/shared/src/phone.ts in the API. Accepts what people
	 * actually type; rejects rather than guesses, because a mis-normalised
	 * number is a code that silently never arrives.
	 */
	public static function normalise_ghana_phone( string $raw ): string {
		$digits = preg_replace( '/[^\d+]/', '', $raw );

		if ( strpos( $digits, '+233' ) === 0 ) {
			$national = substr( $digits, 4 );
		} elseif ( strpos( $digits, '233' ) === 0 ) {
			$national = substr( $digits, 3 );
		} elseif ( strpos( $digits, '0' ) === 0 ) {
			$national = substr( $digits, 1 );
		} else {
			$national = $digits;
		}

		return preg_match( '/^[25]\d{8}$/', (string) $national ) ? '+233' . $national : '';
	}

	/**
	 * Mobile-money network from the prefix, for the Paystack charge payload.
	 * Null where the prefix is not confidently one network — then the buyer is
	 * asked rather than guessed at, because the wrong network means the prompt
	 * never appears on their handset.
	 */
	public static function momo_provider( string $e164 ): ?string {
		$prefix = substr( str_replace( '+233', '', $e164 ), 0, 2 );

		if ( in_array( $prefix, [ '24', '54', '55', '59', '25' ], true ) ) {
			return 'mtn';
		}
		if ( in_array( $prefix, [ '20', '50' ], true ) ) {
			return 'vod';
		}
		if ( in_array( $prefix, [ '26', '27', '56', '57' ], true ) ) {
			return 'atl';
		}
		return null;
	}
}
