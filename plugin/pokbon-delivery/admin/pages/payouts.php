<?php
/**
 * What POKBON owes its riders, and a way to settle it.
 *
 * Until this existed there was nowhere to record that a rider had been paid.
 * `PAYOUT` and `ADJUSTMENT` had been in the schema since the beginning and
 * nothing ever wrote one, so the "balance" on every screen — including the
 * rider's own — was lifetime gross earnings. Sending somebody GH¢300 by mobile
 * money changed nothing anywhere, and after a few weeks neither side could say
 * what had been settled and what had not.
 *
 * A rider cannot chase a number they do not understand, and an owner cannot
 * defend one. This page exists so both are looking at the same figure.
 */

defined( 'ABSPATH' ) || exit;

$status   = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : 'REQUESTED';
$requests = Pokbon_Delivery_API_Client::payout_requests( $status );
?>
<div class="wrap">
	<h1>Payouts</h1>

	<?php if ( is_wp_error( $requests ) ) : ?>
		<div class="notice notice-error"><p><?php echo esc_html( $requests->get_error_message() ); ?></p></div>
	<?php else : ?>

		<ul class="subsubsub">
			<?php
			foreach ( [ 'REQUESTED' => 'Waiting', 'PAID' => 'Paid', 'DECLINED' => 'Declined', 'ALL' => 'All' ] as $key => $label ) :
				?>
				<li>
					<a href="<?php echo esc_url( add_query_arg( 'status', $key ) ); ?>"
						class="<?php echo $status === $key ? 'current' : ''; ?>"><?php echo esc_html( $label ); ?></a>
					<?php echo $key === 'ALL' ? '' : ' |'; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<div style="clear:both"></div>

		<?php if ( empty( $requests ) ) : ?>
			<p class="description" style="margin-top:1.5em">
				Nothing here. Riders ask to be paid from their own app, and the request lands on this
				page &mdash; you also get an SMS and an email when one arrives.
			</p>
		<?php else : ?>

			<table class="widefat striped" style="max-width:80em;margin-top:1em">
				<thead>
					<tr>
						<th>Rider</th>
						<th>Asked for</th>
						<th>Owed now</th>
						<th>Pay to</th>
						<th>When</th>
						<th style="width:26em">Settle</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( (array) $requests as $row ) :
					$rider   = is_array( $row['rider'] ?? null ) ? $row['rider'] : [];
					$waiting = ( $row['status'] ?? '' ) === 'REQUESTED';
					?>
					<tr>
						<td>
							<strong><?php echo esc_html( $rider['fullName'] ?? '—' ); ?></strong><br>
							<span class="description"><?php echo esc_html( $rider['phone'] ?? '' ); ?>
								&middot; <?php echo (int) ( $rider['completedJobs'] ?? 0 ); ?> deliveries</span>
							<?php if ( ! empty( $row['note'] ) ) : ?>
								<br><em>&ldquo;<?php echo esc_html( $row['note'] ); ?>&rdquo;</em>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( 'GHS ' . number_format( (float) ( $row['amount'] ?? 0 ), 2 ) ); ?></td>
						<td>
							<?php
							/*
							 * The two figures are shown side by side on purpose. A
							 * rider who asks on Monday and works on Tuesday is owed
							 * more than they asked for, and paying the request
							 * rather than the balance is how a rider ends up chasing
							 * the difference.
							 */
							echo esc_html( 'GHS ' . number_format( (float) ( $row['balanceNow'] ?? 0 ), 2 ) );
							?>
						</td>
						<td>
							<?php
							$momo = (string) ( $rider['momoNumber'] ?? '' );
							echo $momo !== ''
								? esc_html( $momo )
								: '<span class="description">no MoMo number on file</span>';
							?>
						</td>
						<td class="description"><?php echo esc_html( $row['requestedAt'] ?? '' ); ?></td>
						<td>
							<?php if ( ! $waiting ) : ?>
								<strong><?php echo esc_html( ucfirst( strtolower( (string) ( $row['status'] ?? '' ) ) ) ); ?></strong>
								<?php if ( ! empty( $row['settledBy'] ) ) : ?>
									<span class="description">by <?php echo esc_html( $row['settledBy'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $row['ownerNote'] ) ) : ?>
									<br><span class="description"><?php echo esc_html( $row['ownerNote'] ); ?></span>
								<?php endif; ?>
							<?php else : ?>
								<?php Pokbon_Delivery_Admin::form_open( 'settle_payout' ); ?>
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $row['id'] ); ?>">
									<p style="margin:0 0 .4em">
										<input type="number" step="0.01" min="0.01" name="amount" style="width:8em"
											value="<?php echo esc_attr( number_format( (float) ( $row['balanceNow'] ?? $row['amount'] ?? 0 ), 2, '.', '' ) ); ?>">
										<button type="submit" class="button button-primary">I have paid this</button>
									</p>
									<p style="margin:0">
										<input type="text" name="note" class="regular-text" style="width:22em"
											placeholder="MoMo reference, or anything worth remembering">
									</p>
								</form>
								<?php Pokbon_Delivery_Admin::form_open( 'decline_payout' ); ?>
									<input type="hidden" name="request_id" value="<?php echo esc_attr( $row['id'] ); ?>">
									<p style="margin:.6em 0 0">
										<input type="text" name="note" class="regular-text" style="width:22em"
											placeholder="Why not — the rider reads this" required minlength="5">
										<button type="submit" class="button">Decline</button>
									</p>
								</form>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<p class="description" style="max-width:60em;margin-top:1em">
				<strong>Pay the rider first, then record it here.</strong> This page does not move money &mdash;
				it writes down that you did, which is what makes the balance in the rider&rsquo;s app mean
				&ldquo;what you are owed&rdquo; instead of &ldquo;what you have ever earned&rdquo;. Recording a
				payout you have not sent leaves a rider short with no way to show it.
			</p>

		<?php endif; ?>
	<?php endif; ?>
</div>
