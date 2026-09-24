<?php
/**
 * Run the doorstep split over a set of orders. Called by check-cod-split.mjs.
 *
 * Loads the real class rather than a copy of the arithmetic, because a test
 * that reimplements the thing it is testing proves only that two wrongs agree.
 */

define( 'ABSPATH', __DIR__ );

require_once __DIR__ . '/../plugin/pokbon-delivery/includes/class-orders.php';

$cases = json_decode( stream_get_contents( STDIN ), true );

$out = [];
foreach ( $cases as $case ) {
	// JSON object keys are strings; the real caller passes integer vendor ids.
	$goods = [];
	foreach ( $case['goods'] as $vendor => $minor ) {
		$goods[ (int) $vendor ] = (int) $minor;
	}
	$legs = [];
	foreach ( ( $case['legs'] ?? [] ) as $vendor => $minor ) {
		$legs[ (int) $vendor ] = (int) $minor;
	}

	$out[] = Pokbon_Delivery_Orders::cod_split( (int) $case['total'], $goods, $legs );
}

echo json_encode( $out );
