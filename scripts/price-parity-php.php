<?php
/**
 * Price a set of routes through the PLUGIN's copy of the ladder.
 *
 * The plugin has to price at checkout without calling the delivery service —
 * a slow quote is a lost sale, and the service may not be reachable at all —
 * so the rules exist twice. Two copies of pricing logic is a real risk, and
 * this is how it is contained: scripts/check-price-parity.mjs runs the same
 * routes through both and fails on any disagreement.
 *
 * WordPress is stubbed with just enough to load the classes. Options are held
 * in memory and seeded from the fixture on stdin.
 *
 * Run indirectly: node scripts/check-price-parity.mjs
 */

define( 'ABSPATH', __DIR__ );

$GLOBALS['pkbd_options'] = [];

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
function esc_url_raw( $url ) {
	return (string) $url;
}
function add_action( ...$args ) {}
function has_action( ...$args ) { return false; }
function apply_filters( $tag, $value ) { return $value; }
function wp_json_encode( $value ) { return json_encode( $value ); }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 0; }

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-settings.php';
require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-geo.php';
require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-pricing.php';

// Audit is a no-op here; pricing must not depend on it.
class Pokbon_Delivery_Audit {
	public static function log( string $event, array $data = [] ): void {}
}

$fixture = json_decode( stream_get_contents( STDIN ), true );

$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_ZONES ]       = $fixture['zones'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_PRICES ]      = $fixture['zonePairs'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_BAND_PRICES ] = $fixture['bandPrices'];
$GLOBALS['pkbd_options'][ Pokbon_Delivery_Settings::OPT_DISTANCE ]    = $fixture['distanceBands'];

$out = [];
foreach ( $fixture['routes'] as $route ) {
	$priced = Pokbon_Delivery_Pricing::route( $route['from'], $route['to'] );
	$out[]  = $priced === null ? null : [
		'riderFeeMinor'   => $priced['riderFeeMinor'],
		'buyerPriceMinor' => $priced['buyerPriceMinor'],
		'rung'            => $priced['rung'],
		'matched'         => $priced['matched'],
	];
}

echo json_encode( $out );
