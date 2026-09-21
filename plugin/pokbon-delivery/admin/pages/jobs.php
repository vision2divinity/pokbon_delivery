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
