<?php
/**
 * "Cash on delivery" that forbids cash. PRD § 2 and § 12a.
 *
 * A buyer who chose those words expects to hand over notes, and the rider is
 * going to ask for a mobile-money PIN instead. That argument happens on a
 * doorstep, in front of a stranger, and it is a copy problem rather than an
 * engineering one.
 *
 * WHY THIS IS A SWITCH AND NOT JUST A RENAME. Today there really is cash: the
 * marketplace takes COD orders and vendors collect notes. Renaming before
 * riders exist would make the checkout lie to every buyer. So the wording is
 * written, tested and ready, and flips the day the first rider goes out.
 *
 * The WooCommerce gateway id stays `cod`, deliberately. Changing it would
 * strand every historic order and every report that groups by payment method.
 * Only what a person reads changes.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Labels {

	const TITLE = 'Pay on delivery';

	const DESCRIPTION = 'Pay by mobile money or card when the rider arrives. No cash — please have the amount ready on your phone.';

	public static function bootstrap(): void {
		if ( ! self::enabled() ) {
			return;
		}

		// Covers the website checkout, the order-received page, every
		// WooCommerce email and the admin order screen in one hook.
		add_filter( 'woocommerce_gateway_title', [ self::class, 'title' ], 20, 2 );
		add_filter( 'woocommerce_gateway_description', [ self::class, 'description' ], 20, 2 );

		// Orders already placed still read "Cash on delivery" in their stored
		// payment_method_title, so relabel those on display too. Without this
		// the checkout says one thing and the order history says another.
		add_filter( 'woocommerce_order_get_payment_method_title', [ self::class, 'order_title' ], 20, 2 );
	}

	public static function enabled(): bool {
		return (bool) Pokbon_Delivery_Settings::get( 'rename_cod_label' );
	}

	public static function title( $title, $gateway_id ) {
		return self::is_cod( $gateway_id ) ? self::TITLE : $title;
	}

	public static function description( $description, $gateway_id ) {
		return self::is_cod( $gateway_id ) ? self::DESCRIPTION : $description;
	}

	public static function order_title( $title, $order ) {
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return $title;
		}
		return self::is_cod( $order->get_payment_method() ) ? self::TITLE : $title;
	}

	private static function is_cod( $gateway_id ): bool {
		return in_array( (string) $gateway_id, [ 'cod', 'pokbon_cod' ], true );
	}

	/**
	 * The mobile app carries its own copy of this string and cannot be
	 * relabelled from here — it ships in the binary. Both places are listed on
	 * the settings screen so the app release goes out with the switch rather
	 * than a week after it.
	 */
	public static function app_files(): array {
		return [
			'src/constants/config.ts (around line 113)',
			'src/screens/checkout/CheckoutScreen.tsx (around line 123)',
		];
	}
}
