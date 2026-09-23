<?php
/**
 * The live job board. PRD § 12a.
 *
 * Reads from the Delivery API, which owns jobs. Nothing is cached: a
 * dispatcher looking at this page is deciding whether to intervene, and a
 * board that is a minute stale is worse than no board.
 *
 * Everything on this screen shows the buyer price and the margin. That is the
 * opposite of the rider's view, which structurally cannot contain either.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$detail_id = isset( $_GET['job'] ) ? sanitize_text_field( wp_unslash( $_GET['job'] ) ) : '';
$filter    = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';

/** Statuses a dispatcher wants at a glance, in lifecycle order. */
$live_statuses = 'CREATED,OFFERED,UNFULFILLED,ASSIGNED,AT_PICKUP,PICKED_UP,EN_ROUTE,ARRIVED,CODE_SENT,CODE_VERIFIED,PAYMENT_PENDING,PAYMENT_FAILED,PAID';
?>
<div class="wrap">
	<h1>Job board</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<?php if ( ! Pokbon_Delivery_API_Client::is_configured() ) : ?>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	/*
	 * Dispatching one order by hand.
	 *
	 * Reached from the POKBON Delivery panel on the order screen. It lives
	 * here rather than there because a meta box is rendered inside
	 * WooCommerce's own form, and a form inside a form is merged by the
	 * browser — which would make this button submit the order screen. Here
	 * there is no outer form, so there is room for a real one and for the
	 * zone choice a website order needs.
	 */
	$dispatch_id = isset( $_GET['dispatch'] ) ? (int) $_GET['dispatch'] : 0;
	if ( $dispatch_id > 0 ) :
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $dispatch_id ) : null;
		?>
		<p>
			<a href="<?php echo esc_url( remove_query_arg( 'dispatch' ) ); ?>">&larr; Back to the board</a>
		</p>

		<?php if ( ! $order ) : ?>
			<div class="notice notice-error"><p>Order #<?php echo (int) $dispatch_id; ?> was not found.</p></div>
			</div>
			<?php return; ?>
		<?php endif; ?>

		<?php
		/*
		 * A dispatcher stuck on a white "critical error" page cannot dispatch
		 * and cannot tell anyone why. Whatever breaks while working out the
		 * route, it is better said out loud on this screen than left to the
		 * site admin's inbox.
		 */
		$fatal = null;
		try {
			$preview   = Pokbon_Delivery_Order_Panel::preview( $order );
			$kind      = Pokbon_Delivery_Order_Panel::classify( (string) $order->get_shipping_method() );
			$zones_all = Pokbon_Delivery_Settings::active_zones();
			$existing  = Pokbon_Delivery_Orders::job_ids_for( $order );

			/*
			 * A cancelled job is not a reason to refuse.
			 *
			 * The order meta only records which jobs were created, not what
			 * became of them, and the delivery service is the one that knows.
			 * Blocking on the bare list meant that cancelling a job — the
			 * exact thing the old message told you to do — still left the
			 * order permanently undispatchable.
			 *
			 * If the service cannot be reached the block stays, deliberately:
			 * not knowing whether a rider is already carrying this is a much
			 * worse position than waiting a minute.
			 */
			$blocking = [];
			foreach ( $existing as $job_id ) {
				$job = Pokbon_Delivery_API_Client::job( $job_id );
				if ( is_wp_error( $job ) ) {
					$blocking[ $job_id ] = 'could not be checked';
					continue;
				}
				$job_status = strtoupper( (string) ( $job['status'] ?? '' ) );
				if ( $job_status === 'CANCELLED' ) {
					continue;
				}
				$blocking[ $job_id ] = strtolower( str_replace( '_', ' ', $job_status ) );
			}
		} catch ( Throwable $e ) {
			$fatal     = $e;
			$preview   = [ 'error' => '', 'route' => '', 'buyer' => '', 'rider' => '', 'why' => '', 'vendors' => 0, 'hasPin' => false, 'needsZone' => false, 'suggested' => '' ];
			$kind      = 'local';
			$zones_all = [];
			$existing  = [];
			$blocking  = [];
		}

		if ( $fatal !== null ) :
			Pokbon_Delivery_Audit::log( Pokbon_Delivery_Audit::EVENT_JOB_CREATE_FAILED, [
				'order_id' => $dispatch_id,
				'reason'   => 'dispatch_screen_error',
				'error'    => $fatal->getMessage(),
			] );
			?>
			<div class="notice notice-error"><p>
				<strong>This order cannot be priced right now.</strong><br>
				<?php echo esc_html( $fatal->getMessage() ); ?>
				<br><span class="description">
					<?php echo esc_html( basename( $fatal->getFile() ) . ':' . $fatal->getLine() ); ?>
				</span>
			</p></div>
			</div>
			<?php
			return;
		endif;
		?>

		<h2>Send order #<?php echo (int) $dispatch_id; ?> to riders</h2>

		<table class="widefat striped" style="max-width:60em">
			<tr>
				<th style="width:14em">Customer</th>
				<td>
					<?php echo esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ); ?>
					· <?php echo esc_html( $order->get_billing_phone() ); ?>
				</td>
			</tr>
			<tr>
				<th>Delivering to</th>
				<td>
					<?php
					echo esc_html( trim( implode( ', ', array_filter( [
						$order->get_shipping_address_1() ?: $order->get_billing_address_1(),
						$order->get_shipping_address_2() ?: $order->get_billing_address_2(),
						$order->get_shipping_city() ?: $order->get_billing_city(),
						$order->get_shipping_state() ?: $order->get_billing_state(),
					] ) ) ) );
					?>
				</td>
			</tr>
			<tr>
				<th>Buyer chose</th>
				<td>
					<?php echo esc_html( $order->get_shipping_method() ?: 'nothing recorded' ); ?>
					<?php if ( $kind === 'freight' ) : ?>
						<br><span class="description">Shipped from abroad. Send this only once the goods have landed.</span>
					<?php elseif ( $kind === 'pickup' ) : ?>
						<br><span class="description">Store pickup. No rider is needed unless you decide to deliver it anyway.</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th>Map pin</th>
				<td>
					<?php if ( ! empty( $preview['hasPin'] ) ) : ?>
						Captured at checkout.
					<?php else : ?>
						None — normal for a website order. Choose a zone below.
					<?php endif; ?>
				</td>
			</tr>
		</table>

		<?php if ( ! empty( $blocking ) ) : ?>
			<div class="notice notice-warning inline" style="margin-top:1em">
				<p>
					This order already has <?php echo count( $blocking ); ?> live delivery job(s), so the button is
					not offered — sending it again would put two riders on one parcel. Cancel what is there first
					if you need to redo it.
				</p>
				<ul style="margin:0 0 .5em 2em;list-style:disc">
					<?php foreach ( $blocking as $job_id => $job_status ) : ?>
						<li>
							<code><?php echo esc_html( substr( $job_id, 0, 8 ) ); ?></code>
							— <?php echo esc_html( $job_status ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			</div>
			</div>
			<?php return; ?>
		<?php elseif ( ! empty( $existing ) ) : ?>
			<div class="notice notice-info inline" style="margin-top:1em"><p>
				This order has been dispatched before and every one of those jobs was cancelled, so it can be
				sent again.
			</p></div>
		<?php endif; ?>

		<?php if ( ! empty( $preview['needsZone'] ) ) : ?>
			<h3>Which zone?</h3>
			<p class="description" style="max-width:52em">
				A website order carries no map pin, which is how POKBON has always worked: the buyer types a city
				and a street, and the rider finds it. Pick the zone so the route can be priced. The rider is shown
				the full address and the customer's number, and the zone centre is only the map target.
			</p>
			<?php Pokbon_Delivery_Admin::form_open( 'dispatch_order' ); ?>
				<input type="hidden" name="order_id" value="<?php echo (int) $dispatch_id; ?>">
				<select name="dispatch_zone" required>
					<option value="">Choose a zone…</option>
					<?php foreach ( $zones_all as $zone ) : ?>
						<option value="<?php echo esc_attr( $zone['code'] ); ?>"
							<?php selected( $preview['suggested'] ?? '', $zone['code'] ); ?>>
							<?php echo esc_html( $zone['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! empty( $preview['suggested'] ) ) : ?>
					<span class="description">
						Suggested from the address. Check it — text matching is a hint, not an answer.
					</span>
				<?php endif; ?>
				<p><button type="submit" class="button button-primary">Price it and send to riders</button></p>
			</form>
		<?php elseif ( $preview['error'] !== '' ) : ?>
			<div class="notice notice-error inline" style="margin-top:1em"><p>
				<strong>Cannot dispatch.</strong> <?php echo esc_html( $preview['error'] ); ?>
			</p></div>
		<?php else : ?>
			<h3>What this will do</h3>
			<table class="widefat striped" style="max-width:60em">
				<tr><th style="width:14em">Route</th><td><?php echo esc_html( $preview['route'] ); ?></td></tr>
				<tr><th>Buyer pays</th><td><strong><?php echo esc_html( $preview['buyer'] ); ?></strong></td></tr>
				<tr><th>Rider earns</th><td><?php echo esc_html( $preview['rider'] ); ?></td></tr>
				<tr><th>Priced by</th><td><?php echo esc_html( $preview['why'] ); ?></td></tr>
				<?php if ( (int) $preview['vendors'] > 1 ) : ?>
					<tr>
						<th>Vendors</th>
						<td>
							<?php echo (int) $preview['vendors']; ?> — that is
							<?php echo (int) $preview['vendors']; ?> separate collections and
							<?php echo (int) $preview['vendors']; ?> rider fees. The buyer sees one total.
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<?php Pokbon_Delivery_Admin::form_open( 'dispatch_order' ); ?>
				<input type="hidden" name="order_id" value="<?php echo (int) $dispatch_id; ?>">
				<p><button type="submit" class="button button-primary">Send to riders now</button></p>
			</form>
		<?php endif; ?>

		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( $detail_id !== '' ) :
		$job = Pokbon_Delivery_API_Client::job( $detail_id );
		?>
		<p><a href="<?php echo esc_url( remove_query_arg( 'job' ) ); ?>">&larr; Back to the board</a></p>

		<?php if ( is_wp_error( $job ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $job->get_error_message() ); ?></p></div>
		<?php else :
			$money = $job['money'] ?? [];
			$rider = $job['rider'] ?? null;
			?>
			<h2>
				<?php echo esc_html( $job['source'] ?? '' ); ?>
				order <?php echo esc_html( $job['externalRef'] ?? '' ); ?>
				<span class="description">— <?php echo esc_html( $job['status'] ?? '' ); ?></span>
			</h2>

			<?php
			/*
			 * What went wrong, and the proof, first.
			 *
			 * The rider app could always pick a reason and the API has always
			 * stored one, along with a note and a photo — and none of it was
			 * ever shown here. So a rider who photographed a damaged box at
			 * somebody's door was sending it into a screen nobody could read,
			 * which is indistinguishable from not asking for it at all.
			 *
			 * Above the table rather than inside it: a dispatcher opening a
			 * failed job is opening it for this, and should not have to scroll
			 * past the route and the money to find out why.
			 */
			$failure_reason = (string) ( $job['failureReason'] ?? '' );
			$failure_detail = (string) ( $job['failureDetail'] ?? '' );
			$photos         = is_array( $job['photos'] ?? null ) ? $job['photos'] : [];
			$failure_photos = array_values( array_filter( $photos, static function ( $p ) {
				return strtoupper( (string) ( $p['kind'] ?? '' ) ) === 'FAILURE';
			} ) );
			?>
			<?php if ( $failure_reason !== '' || $failure_detail !== '' || $failure_photos ) : ?>
				<div class="notice notice-warning inline" style="max-width:60em;padding:1em">
					<h3 style="margin-top:0">Reported as not delivered</h3>
					<?php if ( $failure_reason !== '' ) : ?>
						<p style="font-size:1.1em;margin:0 0 .5em">
							<strong><?php echo esc_html( ucfirst( strtolower( str_replace( '_', ' ', $failure_reason ) ) ) ); ?></strong>
						</p>
					<?php endif; ?>
					<?php if ( $failure_detail !== '' ) : ?>
						<p style="margin:0 0 .5em"><em>&ldquo;<?php echo esc_html( $failure_detail ); ?>&rdquo;</em>
							<span class="description">&mdash; the rider&rsquo;s own words</span></p>
					<?php endif; ?>
					<?php if ( $failure_photos ) : ?>
						<p style="margin:0">
							<?php foreach ( $failure_photos as $photo ) :
								$url = (string) ( $photo['url'] ?? '' );
								if ( $url === '' ) { continue; }
								// Absolute already, or relative to the API.
								if ( ! preg_match( '#^https?://#i', $url ) ) {
									$url = rtrim( Pokbon_Delivery_Settings::api_base_url(), '/' ) . '/' . ltrim( $url, '/' );
								}
								?>
								<a href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">
									<img src="<?php echo esc_url( $url ); ?>" alt="Photo taken by the rider"
										style="max-height:170px;border-radius:6px;margin-right:.5em;vertical-align:top">
								</a>
							<?php endforeach; ?>
						</p>
						<p class="description" style="margin:.5em 0 0">
							Taken by the rider at the time. Click to open full size.
						</p>
					<?php elseif ( $failure_reason !== '' ) : ?>
						<p class="description" style="margin:0">No photo was attached.</p>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<table class="widefat striped" style="max-width:60em">
				<tr>
					<th style="width:14em">Route</th>
					<td>
						<?php echo esc_html( ( $job['pickupZoneCode'] ?? '?' ) . ' → ' . ( $job['dropoffZoneCode'] ?? '?' ) ); ?><br>
						<span class="description">
							<?php echo esc_html( $job['pickupAddress'] ?? '' ); ?><br>
							<?php echo esc_html( $job['dropoffAddress'] ?? '' ); ?>
							<?php if ( ! empty( $job['dropoffNote'] ) ) : ?>
								<br><em><?php echo esc_html( $job['dropoffNote'] ); ?></em>
							<?php endif; ?>
						</span>
					</td>
				</tr>
				<tr>
					<th>What it is</th>
					<td>
						<?php echo esc_html( $job['parcelDescription'] ?: '—' ); ?>
						<?php if ( ! empty( $job['pickupNote'] ) ) : ?>
							<br><span class="description">Collection: <?php echo esc_html( $job['pickupNote'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Money</th>
					<td>
						Buyer <strong>GH₵<?php echo esc_html( number_format( (float) ( $money['buyerPrice'] ?? 0 ), 2 ) ); ?></strong>,
						rider GH₵<?php echo esc_html( number_format( (float) ( $money['riderFee'] ?? 0 ), 2 ) ); ?>,
						POKBON GH₵<?php echo esc_html( number_format( (float) ( $money['margin'] ?? 0 ), 2 ) ); ?>
						<?php if ( ( $job['paymentMethod'] ?? '' ) === 'PAY_ON_DELIVERY' ) : ?>
							<br><span class="description">
								Due at the door: GH₵<?php echo esc_html( number_format( (float) ( $money['amountDue'] ?? 0 ), 2 ) ); ?>
								— paid by mobile money, never cash.
							</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Rider</th>
					<td>
						<?php if ( $rider ) : ?>
							<?php echo esc_html( $rider['fullName'] ?? '' ); ?>
							(<?php echo esc_html( $rider['phone'] ?? '' ); ?>)
						<?php else : ?>
							<em>Not assigned</em>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( ! empty( $job['codeBypassedBy'] ) ) : ?>
					<tr>
						<th>Code bypassed</th>
						<td style="color:#b32d2e">
							by <?php echo esc_html( $job['codeBypassedBy'] ); ?> —
							<?php echo esc_html( $job['codeBypassReason'] ?? '' ); ?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

			<h3>Intervene</h3>
			<p class="description" style="max-width:52em">
				Assigning by hand is normal. Bypassing the code is not: it completes a delivery without the
				buyer proving who they are, the buyer is told POKBON confirmed it rather than they did, and
				every bypass is recorded against your name.
			</p>

			<?php
			/*
			 * What a dispatcher may do, decided by the API.
			 *
			 * The lifecycle lives in the delivery service, so it says which
			 * actions are open rather than this page keeping a second copy of
			 * the transition table and drifting out of step with it. Before
			 * this, every control showed on every job: "Bypass the code" on a
			 * delivered one, "Assign a rider" on a cancelled one. A button that
			 * exists to be pressed and then refuses is the same failure as
			 * demanding a photo the app cannot take.
			 *
			 * Defaults are permissive so an older API that does not send the
			 * field leaves the page working exactly as it did.
			 */
			$can = is_array( $job['allowedActions'] ?? null ) ? $job['allowedActions'] : [];
			$may = static function ( $what ) use ( $can ) {
				return ! array_key_exists( $what, $can ) || ! empty( $can[ $what ] );
			};
			?>

			<?php
			$riders = Pokbon_Delivery_API_Client::riders( 'APPROVED' );
			if ( $may( 'assign' ) && ! is_wp_error( $riders ) && ! empty( $riders['riders'] ) ) :
				?>
				<?php Pokbon_Delivery_Admin::form_open( 'assign_job' ); ?>
					<input type="hidden" name="job_id" value="<?php echo esc_attr( $detail_id ); ?>">
					<select name="rider_id" required>
						<option value="">Assign a rider…</option>
						<?php foreach ( $riders['riders'] as $r ) : ?>
							<option value="<?php echo esc_attr( $r['id'] ); ?>">
								<?php echo esc_html( ( $r['fullName'] ?? $r['phone'] ) . ( empty( $r['onDuty'] ) ? ' (off duty)' : '' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<button class="button button-primary" type="submit">Assign</button>
				</form>
			<?php endif; ?>

			<?php if ( $may( 'offer' ) ) : ?>
				<p style="margin-top:1em">
					<?php echo Pokbon_Delivery_Admin::button( 'offer_job', 'Offer to the nearest rider', [ 'job_id' => $detail_id ] ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $may( 'bypassCode' ) ) : ?>
				<?php Pokbon_Delivery_Admin::form_open( 'bypass_code' ); ?>
					<input type="hidden" name="job_id" value="<?php echo esc_attr( $detail_id ); ?>">
					<input type="text" name="reason" class="regular-text" style="width:32em"
						placeholder="Why the code cannot be used — at least a sentence" required minlength="10">
					<button class="button" type="submit">Bypass the code</button>
				</form>
			<?php endif; ?>

			<?php if ( $may( 'markReturned' ) || $may( 'cancel' ) ) : ?>
				<h3 style="margin-top:2em">Close this delivery</h3>
				<p class="description" style="max-width:56em">
					For when a rider has told you they cannot finish it. Both need a reason, because
					the reason is what somebody reads in a month when they are working out what
					happened &mdash; and because a delivery that ends with no explanation is
					indistinguishable from one that was forgotten.
				</p>

				<?php if ( $may( 'markReturned' ) ) : ?>
					<?php Pokbon_Delivery_Admin::form_open( 'return_job' ); ?>
						<input type="hidden" name="job_id" value="<?php echo esc_attr( $detail_id ); ?>">
						<p>
							<input type="text" name="reason" class="regular-text" style="width:32em"
								placeholder="Where the goods went, and who confirmed it" required minlength="10">
							<button class="button" type="submit">Goods are back with the sender</button>
						</p>
					</form>
					<p class="description" style="max-width:56em">
						Closes a failed delivery once the parcel is accounted for. The rider normally
						does this themselves; do it here when their phone has died or they have
						stopped answering, and the record will say it was you rather than them.
					</p>
				<?php endif; ?>

				<?php if ( $may( 'cancel' ) ) : ?>
					<?php Pokbon_Delivery_Admin::form_open( 'cancel_job' ); ?>
						<input type="hidden" name="job_id" value="<?php echo esc_attr( $detail_id ); ?>">
						<p>
							<input type="text" name="reason" class="regular-text" style="width:32em"
								placeholder="Why this delivery is being called off" required minlength="10">
							<button class="button" type="submit">Call it off</button>
						</p>
					</form>
					<p class="description" style="max-width:56em">
						Only while the goods are still with the vendor. Once a rider has collected the
						parcel there is nothing to cancel &mdash; it has to come back, which is the
						failed-then-returned path above. That is the lifecycle's rule, not this
						page&rsquo;s: where a parcel physically is cannot be undone by a status.
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<h3>Event log</h3>
			<table class="widefat striped" style="max-width:60em">
				<thead><tr><th>When</th><th>Event</th><th>Who</th></tr></thead>
				<tbody>
				<?php foreach ( (array) ( $job['events'] ?? [] ) as $event ) : ?>
					<tr>
						<td><?php echo esc_html( $event['occurredAt'] ?? '' ); ?></td>
						<td><?php echo esc_html( $event['type'] ?? '' ); ?></td>
						<td><?php echo esc_html( $event['actor'] ?? '' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		</div>
		<?php return; ?>
	<?php endif; ?>

	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( remove_query_arg( 'status' ) ); ?>"
			<?php echo $filter === '' ? 'class="current"' : ''; ?>>Live</a> |</li>
		<li><a href="<?php echo esc_url( add_query_arg( 'status', 'DELIVERED' ) ); ?>"
			<?php echo $filter === 'DELIVERED' ? 'class="current"' : ''; ?>>Delivered</a> |</li>
		<li><a href="<?php echo esc_url( add_query_arg( 'status', 'FAILED,RETURNED,CANCELLED' ) ); ?>"
			<?php echo strpos( $filter, 'FAILED' ) === 0 ? 'class="current"' : ''; ?>>Failed</a></li>
	</ul>
	<div style="clear:both"></div>

	<?php
	$jobs = Pokbon_Delivery_API_Client::jobs( [ 'status' => $filter !== '' ? $filter : $live_statuses ] );

	if ( is_wp_error( $jobs ) ) : ?>
		<div class="notice notice-error">
			<p>
				<strong>The Delivery API is not answering.</strong>
				<?php echo esc_html( $jobs->get_error_message() ); ?>
			</p>
			<p>Riders in the field are unaffected until they next need the service. Nothing here is lost.</p>
		</div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Status</th><th>Route</th><th>Order</th><th>Rider</th>
					<th>Buyer pays</th><th>Rider gets</th><th>POKBON</th><th></th>
				</tr>
			</thead>
			<tbody>
			<?php if ( empty( $jobs['jobs'] ) ) : ?>
				<tr><td colspan="8">Nothing here.</td></tr>
			<?php else : ?>
				<?php foreach ( $jobs["jobs"] as $job ) :
					$money = $job['money'] ?? []; ?>
					<tr>
						<td><strong><?php echo esc_html( $job['status'] ?? '' ); ?></strong></td>
						<td><?php echo esc_html( ( $job['pickupZoneCode'] ?? '?' ) . ' → ' . ( $job['dropoffZoneCode'] ?? '?' ) ); ?></td>
						<td><?php echo esc_html( $job['externalRef'] ?? '' ); ?></td>
						<td><?php echo esc_html( $job['rider']['fullName'] ?? '—' ); ?></td>
						<td>GH₵<?php echo esc_html( number_format( (float) ( $money['buyerPrice'] ?? 0 ), 2 ) ); ?></td>
						<td>GH₵<?php echo esc_html( number_format( (float) ( $money['riderFee'] ?? 0 ), 2 ) ); ?></td>
						<td>GH₵<?php echo esc_html( number_format( (float) ( $money['margin'] ?? 0 ), 2 ) ); ?></td>
						<td>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'job', $job['id'] ) ); ?>">Open</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
