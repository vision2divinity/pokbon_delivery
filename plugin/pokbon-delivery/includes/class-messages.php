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
	 * The in-app inbox copy of a message, for buyers who have the app.
	 *
	 * NOT WIRED YET, AND DELIBERATELY NOT FAKED. The marketplace plugin's push
	 * system (`Pokbon_App_Push_Broadcasts`) is an audience broadcast tool with
	 * a draft/sent workflow and an admin list — the wrong vehicle for a
	 * one-to-one transactional message, which would appear in the owner's
	 * broadcast history and could be re-sent by accident.
	 *
	 * So this fires an action and says plainly that nothing was delivered.
	 * SMS has already carried the code by the time this runs (see
	 * Pokbon_Delivery_REST::send_sms), so no buyer is left without it.
	 *
	 * To wire it: hook `pokbon_delivery_inbox_message` to whatever writes a
	 * single user's notification row, and return true from the filter below.
	 */
	public static function inbox( int $user_id, string $title, string $body, array $context = [] ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}

		do_action( 'pokbon_delivery_inbox_message', $user_id, $title, $body, $context );

		/**
		 * Filter: return true once a handler above actually delivered it, so
		 * the API stops being told the inbox is unavailable.
		 */
		$delivered = (bool) apply_filters( 'pokbon_delivery_inbox_delivered', false, $user_id, $context );

		if ( ! $delivered ) {
			Pokbon_Delivery_Audit::log( 'delivery.inbox_not_wired', [
				'user_id' => $user_id,
				'purpose' => $context['purpose'] ?? '',
			] );
		}

		return $delivered;
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
