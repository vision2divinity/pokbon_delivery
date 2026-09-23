<?php
/**
 * Run the fee split over a set of orders. Called by check-fee-split.mjs.
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
	$weights = [];
	foreach ( $case['weights'] as $vendor => $minor ) {
		$weights[ (int) $vendor ] = (int) $minor;
	}
	$out[] = Pokbon_Delivery_Orders::split_fee( (int) $case['total'], $weights );
}

echo json_encode( $out );
