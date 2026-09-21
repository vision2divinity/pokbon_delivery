<?php
/**
 * Fold a set of messages through the PLUGIN's copy of the GSM rules.
 *
 * The rules exist twice: the API folds every message it queues, and the plugin
 * folds every message it sends directly — including text that comes back from
 * Paystack, which is not ours to make plain. Two copies is a real risk, and
 * this is how it is contained: scripts/check-sms-text.mjs runs the same
 * strings through both and fails on any disagreement.
 *
 * WordPress is stubbed with just enough to load the class. Loaded with
 * require, not eval, so this stays a harness around real plugin source.
 *
 * Run indirectly: node scripts/check-sms-text.mjs
 */

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ) {}
function apply_filters( $tag, $value ) { return $value; }
function get_option( $key, $default = false ) { return $default; }

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-messages.php';

$input = stream_get_contents( STDIN );
$cases = json_decode( $input, true );
if ( ! is_array( $cases ) ) {
	fwrite( STDERR, "expected a JSON array of strings on stdin\n" );
	exit( 2 );
}

$out = [];
foreach ( $cases as $text ) {
	$out[] = Pokbon_Delivery_Messages::gsm_safe( (string) $text );
}

echo json_encode( $out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
