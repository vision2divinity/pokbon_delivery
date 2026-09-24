<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Shipping {
	private static $instance = null;

	const DELIVERY_PICKUP = 'pickup';
	const DELIVERY_HOME   = 'home';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public static function get_region_rates() {
		$rates = get_option( 'pokbon_checkout_region_rates' );
		if ( ! is_array( $rates ) ) {
			$rates = Pokbon_Installer::default_region_rates();
		}
		return $rates;
	}

	public static function get_free_threshold() {
		return (float) get_option( 'pokbon_checkout_free_threshold', 0 );
	}

	/**
	 * @param string $delivery_type pickup|home
	 * @param string $region_code WooCommerce GH state code (e.g. AA, AH).
	 * @param float  $cart_subtotal
	 * @return array{ amount:float, label:string, free:bool, valid:bool, error?:string }
	 */
	public static function calculate( $delivery_type, $region_code, $cart_subtotal, $zone_code = '' ) {
		$delivery_type = self::normalize_type( $delivery_type );

		if ( self::DELIVERY_PICKUP === $delivery_type ) {
			return array(
				'amount' => 0.0,
				'label'  => (string) get_option( 'pokbon_checkout_pickup_label', 'Store Pickup (Free)' ),
				'free'   => true,
				'valid'  => true,
			);
		}

		if ( self::DELIVERY_HOME === $delivery_type ) {
			$rates = self::get_region_rates();
			$code  = strtoupper( (string) $region_code );

			if ( ! isset( $rates[ $code ] ) ) {
				return array(
					'amount' => 0.0,
					'label'  => '',
					'free'   => false,
					'valid'  => false,
					'error'  => __( 'Please select a valid region for home delivery.', 'pokbon-checkout' ),
				);
			}

			$rate       = (float) $rates[ $code ]['rate'];
			$threshold  = self::get_free_threshold();
			$is_free    = ( $threshold > 0 && (float) $cart_subtotal >= $threshold );
			$home_label = (string) get_option( 'pokbon_checkout_home_delivery_label', 'Home Delivery' );
			$area_label = $rates[ $code ]['name'];

			/*
			 * A flat rate per region charges the same for a run across the
			 * street and a run across the city. Where POKBON Delivery has a
			 * priced zone for the area the buyer picked, that price is used
			 * instead — so the number the buyer is charged is the number the
			 * delivery is actually worth.
			 *
			 * The regional rate stays as the fallback. A region nobody has
			 * zoned, or an area with no price yet, still sells.
			 */
			$zone_code = strtoupper( trim( (string) $zone_code ) );
			$zoned     = apply_filters( 'pokbon_checkout_shipping_rate', null, $delivery_type, $zone_code, WC()->cart ?? null );

			if ( null !== $zoned ) {
				$rate = (float) $zoned;
				$zones = apply_filters( 'pokbon_checkout_delivery_zones', array(), $code );
				foreach ( $zones as $zone ) {
					if ( strtoupper( $zone['code'] ) === $zone_code ) {
						$area_label = $zone['name'];
						break;
					}
				}
			}

			return array(
				'amount' => $is_free ? 0.0 : $rate,
				'label'  => $home_label . ' – ' . $area_label,
				'free'   => $is_free,
				'valid'  => true,
				'zone'   => null !== $zoned ? $zone_code : '',
			);
		}

		return array(
			'amount' => 0.0,
			'label'  => '',
			'free'   => false,
			'valid'  => false,
			'error'  => __( 'Please choose a delivery option.', 'pokbon-checkout' ),
		);
	}

	public static function normalize_type( $value ) {
		$v = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		if ( in_array( $v, array( 'pickup', 'store_pickup', 'pick-up' ), true ) ) {
			return self::DELIVERY_PICKUP;
		}
		if ( in_array( $v, array( 'home', 'home_delivery', 'delivery' ), true ) ) {
			return self::DELIVERY_HOME;
		}
		return '';
	}

	public static function get_region_name( $code ) {
		$rates = self::get_region_rates();
		$code  = strtoupper( (string) $code );
		return isset( $rates[ $code ]['name'] ) ? $rates[ $code ]['name'] : $code;
	}

	public static function regions_for_select() {
		$rates = self::get_region_rates();
		$out   = array();
		foreach ( $rates as $code => $row ) {
			$out[ $code ] = $row['name'];
		}
		return $out;
	}
}
