<?php
/**
 * Fill message templates through the PLUGIN's copy of the rules.
 *
 * The plugin composes its own messages and the delivery service composes the
 * rest, so the substitution rules exist twice — the third pair in this project
 * after the price ladder and the GSM folder. check-message-templates.mjs feeds
 * the same templates to both and fails on any disagreement.
 *
 * WordPress is stubbed with just enough to load the class, and the options
 * store is seeded from the fixture on stdin. Loaded with require, not eval.
 *
 * Run indirectly: node scripts/check-message-templates.mjs
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
function add_action( ...$args ) {}
function apply_filters( $tag, $value ) { return $value; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function esc_url_raw( $url ) { return (string) $url; }
function current_time( $type, $gmt = 0 ) { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id() { return 0; }

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-settings.php';

$input = json_decode( stream_get_contents( STDIN ), true );
if ( ! is_array( $input ) ) {
	fwrite( STDERR, "expected {messages, cases} on stdin\n" );
	exit( 2 );
}

// Seed the stored settings with the fixture's message table.
update_option( 'pokbon_delivery_settings', [ 'messages' => $input['messages'] ?? [] ] );

$out = [];
foreach ( $input['cases'] as $case ) {
	$out[] = Pokbon_Delivery_Settings::message( (string) $case['key'], (array) ( $case['vars'] ?? [] ) );
}

echo json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
