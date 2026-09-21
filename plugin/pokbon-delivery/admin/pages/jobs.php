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
		$preview   = Pokbon_Delivery_Order_Panel::preview( $order );
		$kind      = Pokbon_Delivery_Order_Panel::classify( (string) $order->get_shipping_method() );
		$zones_all = Pokbon_Delivery_Settings::active_zones();
		$existing  = (array) $order->get_meta( Pokbon_Delivery_Orders::META_JOB_IDS );
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

		<?php if ( ! empty( $existing ) ) : ?>
			<div class="notice notice-warning inline" style="margin-top:1em"><p>
				This order already has <?php echo count( $existing ); ?> delivery job(s). Sending it again would
				create duplicates, so the button is not offered. Cancel the existing job first if you need to redo it.
			</p></div>
			</div>
			<?php return; ?>
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
			$riders = Pokbon_Delivery_API_Client::riders( 'APPROVED' );
			if ( ! is_wp_error( $riders ) && ! empty( $riders['riders'] ) ) :
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

			<p style="margin-top:1em">
				<?php echo Pokbon_Delivery_Admin::button( 'offer_job', 'Offer to the nearest rider', [ 'job_id' => $detail_id ] ); ?>
			</p>

			<?php Pokbon_Delivery_Admin::form_open( 'bypass_code' ); ?>
				<input type="hidden" name="job_id" value="<?php echo esc_attr( $detail_id ); ?>">
				<input type="text" name="reason" class="regular-text" style="width:32em"
					placeholder="Why the code cannot be used — at least a sentence" required minlength="10">
				<button class="button" type="submit">Bypass the code</button>
			</form>

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
