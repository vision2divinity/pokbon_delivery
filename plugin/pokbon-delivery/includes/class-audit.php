<?php
/**
 * Audit rows, through the marketplace plugin's log when it is present.
 *
 * Every admin-visible action writes one — the convention the marketplace
 * repo enforces everywhere. Delivery adds the ones that matter for money and
 * for trust: a price change, a rider decision, and above all a code bypass.
 */

defined( 'ABSPATH' ) || exit;

class Pokbon_Delivery_Audit {

	const EVENT_ZONE_SAVED       = 'delivery.zone_saved';
	const EVENT_ZONE_DEACTIVATED = 'delivery.zone_deactivated';
	const EVENT_PRICE_SAVED      = 'delivery.price_saved';
	const EVENT_PRICE_CLEARED    = 'delivery.price_cleared';
	const EVENT_SETTINGS_SAVED   = 'delivery.settings_saved';
	const EVENT_API_SAVED        = 'delivery.api_saved';
	const EVENT_SYNC_PUSHED      = 'delivery.sync_pushed';

	const EVENT_RIDER_DECISION   = 'delivery.rider_decision';
	const EVENT_JOB_ASSIGNED     = 'delivery.job_assigned';
	const EVENT_JOB_CANCELLED    = 'delivery.job_cancelled';

	/** The one that must never be quiet. Reported weekly; a rider who
	 *  accumulates these is the signal (PRD § 7). */
	const EVENT_CODE_BYPASSED    = 'delivery.code_bypassed';

	const EVENT_PAYMENT_PROMPTED = 'delivery.payment_prompted';
	const EVENT_PAYMENT_PAID     = 'delivery.payment_paid';
	const EVENT_PAYMENT_FAILED   = 'delivery.payment_failed';
	const EVENT_JOB_CREATED      = 'delivery.job_created';
	const EVENT_JOB_CREATE_FAILED = 'delivery.job_create_failed';

	public static function log( string $event, array $data = [] ): void {
		if ( class_exists( 'Pokbon_App_Audit_Log' ) ) {
			Pokbon_App_Audit_Log::log( $event, get_current_user_id(), $data );
			return;
		}
		// No marketplace plugin: keep the trail somewhere rather than nowhere.
		error_log( '[pokbon-delivery] ' . $event . ' ' . wp_json_encode( $data ) );
	}
}
