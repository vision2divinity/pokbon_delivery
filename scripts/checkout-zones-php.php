<?php
/**
 * Ask the plugin what delivery costs, the two ways it is asked.
 *
 * The website asks mid-session, with two arguments and a WC cart to read. The
 * mobile app asks over REST, with three arguments and no cart at all. Those are
 * different enough that one can work while the other silently answers "no areas
 * here" — and "no areas here" is indistinguishable from a region nobody has
 * zoned, so it would fall back to the flat regional rate and look completely
 * normal while quietly losing money on every order. That is exactly the bug
 * this feature was written to fix, and exactly how it would come back.
 *
 * So both callers are exercised here against the same zones and the same
 * matrix, and check-checkout-zones.mjs asserts what each must answer.
 *
 * WordPress is stubbed with just enough to load the classes, including a real
 * filter dispatcher — a stub that ignored accepted_args would hide the very
 * thing being tested.
 *
 * Run indirectly: node scripts/check-checkout-zones.mjs
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pkbd_options'] = [];
$GLOBALS['pkbd_filters'] = [];

function get_option( $key, $default = false ) {
	return array_key_exists( $key, $GLOBALS['pkbd_options'] ) ? $GLOBALS['pkbd_options'][ $key ] : $default;
}
function update_option( $key, $value, $autoload = true ) {
	$GLOBALS['pkbd_options'][ $key ] = $value;
	return true;
}
function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function sanitize_text_field( $text ) {
	return trim( strip_tags( (string) $text ) );
}
function esc_url_raw( $url ) { return (string) $url; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 0; }
function add_action( ...$args ) {}
function do_action( ...$args ) {}
function has_action( ...$args ) { return false; }

/**
 * A real dispatcher, because accepted_args is the point.
 *
 * WordPress slices the argument list to accepted_args, so a callback declared
 * for three arguments still receives two from a two-argument call and falls on
 * its default. Faking that away would make this harness agree with itself and
 * disagree with the site.
 */
function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ) {
	$GLOBALS['pkbd_filters'][ $tag ][] = [ 'cb' => $callback, 'n' => $accepted_args ];
	return true;
}
function apply_filters( $tag, $value, ...$rest ) {
	$args = array_merge( [ $value ], $rest );
	foreach ( $GLOBALS['pkbd_filters'][ $tag ] ?? [] as $hook ) {
		$args[0] = call_user_func_array( $hook['cb'], array_slice( $args, 0, (int) $hook['n'] ) );
	}
	return $args[0];
}

/** Which vendor authored a product. The fixture says. */
function get_post_field( $field, $post_id ) {
	return $GLOBALS['pkbd_fixture']['productVendors'][ (string) $post_id ] ?? 0;
}

/**
 * WooCommerce as a REST request sees it: countries loaded, cart absent.
 *
 * Not "no WooCommerce at all", because that is not the situation being tested.
 * On a REST call WC()->countries answers perfectly well and WC()->cart is the
 * only thing missing — and region matching depends on countries while pricing
 * depends on the cart, so collapsing the two would test neither.
 */
class Pokbon_Test_Countries {
	public function get_states( $country ) {
		return $country === 'GH' ? [
			'AA' => 'Greater Accra',
			'AH' => 'Ashanti',
			'CP' => 'Central',
		] : [];
	}
}
class Pokbon_Test_WC {
	public $countries;
	public $cart = null;
	public function __construct() { $this->countries = new Pokbon_Test_Countries(); }
}
function WC() {
	static $wc = null;
	if ( $wc === null ) { $wc = new Pokbon_Test_WC(); }
	return $wc;
}

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-settings.php';
require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-geo.php';
require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-pricing.php';

class Pokbon_Delivery_Audit {
	public static function log( string $event, array $data = [] ): void {}
}

/**
 * Stubbed rather than loaded: the real class reaches into WooCommerce, WCFM
 * and order meta, none of which this is testing. The fixture decides which
 * collection point each vendor is at.
 */
class Pokbon_Delivery_Orders {
	const META_DISPATCH_ZONE = '_pokbon_delivery_dispatch_zone';

	public static function pickup_zone_for_vendor( $vendor_id ): string {
		$map = $GLOBALS['pkbd_fixture']['vendorPickups'] ?? [];
		return (string) ( $map[ (string) (int) $vendor_id ] ?? '' );
	}

	/**
	 * The whole collection point, coordinates included.
	 *
	 * The real one returns lat/lng so a quote can reach the distance rung. A
	 * stub that returned only a code would let the bug this now guards against
	 * pass unnoticed — which is exactly how it shipped.
	 */
	public static function pickup_for_vendor( $vendor_id ): ?array {
		$code = self::pickup_zone_for_vendor( $vendor_id );
		if ( $code === '' ) {
			return null;
		}
		foreach ( $GLOBALS['pkbd_fixture']['zones'] as $zone ) {
			if ( $zone['code'] === $code ) {
				return [ 'zoneCode' => $code, 'lat' => $zone['lat'], 'lng' => $zone['lng'] ];
			}
		}
		return [ 'zoneCode' => $code, 'lat' => null, 'lng' => null ];
	}
}

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-checkout.php';

$GLOBALS['pkbd_fixture'] = json_decode( stream_get_contents( STDIN ), true );
$fixture                 = $GLOBALS['pkbd_fixture'];

$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_ZONES ]       = $fixture['zones'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_PRICES ]      = $fixture['zonePairs'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_BAND_PRICES ] = $fixture['bandPrices'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_DISTANCE ]    = $fixture['distanceBands'];

Pokbon_Delivery_Checkout::bootstrap();

$out = [];
foreach ( $fixture['cases'] as $case ) {
	if ( $case['ask'] === 'areas_two_args' ) {
		// The website's call, unchanged by any of this.
		$out[] = apply_filters( 'pokbon_checkout_delivery_zones', [], $case['region'] );
	} elseif ( $case['ask'] === 'areas_three_args' ) {
		// The app's call.
		$out[] = apply_filters( 'pokbon_delivery_zone_areas', [], $case['region'], $case['productIds'] );
	} elseif ( $case['ask'] === 'price' ) {
		$out[] = apply_filters( 'pokbon_delivery_zone_price', null, $case['zone'], $case['productIds'] );
	} else {
		$out[] = 'unknown ask';
	}
}

echo json_encode( $out );
