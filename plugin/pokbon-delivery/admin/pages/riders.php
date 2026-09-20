<?php
/**
 * Riders: the application queue and the roster. PRD § 4a and § 10.
 *
 * Riders are independent contractors who can leave whenever they choose, so
 * this screen approves and suspends — it does not manage staff.
 *
 * The identity column is deliberately blunt. A photographed Ghana Card is NOT
 * a verified one, and showing "verified" for an OCR scan would be the kind of
 * quiet untruth that matters the first time a rider disappears with a parcel.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$detail_id = isset( $_GET['rider'] ) ? sanitize_text_field( wp_unslash( $_GET['rider'] ) ) : '';
$filter    = isset( $_GET['status'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['status'] ) ) ) : 'APPLIED';

$id_levels = [
	'PHOTO' => 'Photographed only — not verified',
	'NFC'   => 'Chip read — the card is genuine',
	'NIA'   => 'Checked against the national register',
];
?>
<div class="wrap">
	<h1>Riders</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<?php if ( ! Pokbon_Delivery_API_Client::is_configured() ) : ?>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php if ( $detail_id !== '' ) :
		$rider = Pokbon_Delivery_API_Client::rider( $detail_id );
		?>
		<p><a href="<?php echo esc_url( remove_query_arg( 'rider' ) ); ?>">&larr; Back to riders</a></p>

		<?php if ( is_wp_error( $rider ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $rider->get_error_message() ); ?></p></div>
		<?php else : ?>
			<h2>
				<?php echo esc_html( $rider['fullName'] ?? $rider['phone'] ?? 'Rider' ); ?>
				<span class="description">— <?php echo esc_html( $rider['status'] ?? '' ); ?></span>
			</h2>

			<table class="widefat striped" style="max-width:60em">
				<tr><th style="width:14em">Phone</th><td><?php echo esc_html( $rider['phone'] ?? '' ); ?></td></tr>
				<tr><th>Vehicle</th>
					<td>
						<?php echo esc_html( $rider['vehicleClass'] ?? '—' ); ?>
						<?php echo esc_html( $rider['vehicleRegistration'] ?? '' ); ?>
					</td>
				</tr>
				<tr><th>Licence</th><td><?php echo esc_html( $rider['licenceNumber'] ?? '—' ); ?></td></tr>
				<tr>
					<th>Identity</th>
					<td>
						<?php echo esc_html( $rider['idType'] ?? '—' ); ?>
						<?php echo esc_html( $rider['idNumber'] ?? '' ); ?><br>
						<strong><?php echo esc_html( $id_levels[ $rider['idVerificationLevel'] ?? 'PHOTO' ] ?? '' ); ?></strong>
					</td>
				</tr>
				<tr><th>Base zone</th><td><?php echo esc_html( $rider['baseZoneCode'] ?? '—' ); ?></td></tr>
				<tr><th>Payouts to</th><td><?php echo esc_html( $rider['momoNumber'] ?? '—' ); ?></td></tr>
				<tr>
					<th>Agreement</th>
					<td>
						<?php echo esc_html( $rider['agreementVersion'] ?? 'not accepted' ); ?>
						<?php if ( ! empty( $rider['agreementAcceptedAt'] ) ) : ?>
							<span class="description">on <?php echo esc_html( $rider['agreementAcceptedAt'] ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th>Record</th>
					<td>
						<?php echo (int) ( $rider['completedJobs'] ?? 0 ); ?> delivered ·
						balance GH₵<?php echo esc_html( number_format( (float) ( $rider['balance'] ?? 0 ), 2 ) ); ?>
						<?php if ( ! empty( $rider['pendingUplift'] ) ) : ?>
							· <span class="description">
								GH₵<?php echo esc_html( number_format( (float) $rider['pendingUplift'], 2 ) ); ?>
								uplift owed on their next delivery
							</span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h3>Documents</h3>
			<p>
				<?php foreach ( [ 'photoUrl' => 'Selfie', 'idPhotoUrl' => 'ID', 'licencePhotoUrl' => 'Licence' ] as $key => $label ) : ?>
					<?php if ( ! empty( $rider[ $key ] ) ) : ?>
						<a class="button" target="_blank" rel="noopener"
							href="<?php echo esc_url( $rider[ $key ] ); ?>"><?php echo esc_html( $label ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</p>

			<h3>Decision</h3>
			<?php Pokbon_Delivery_Admin::form_open( 'rider_decision' ); ?>
				<input type="hidden" name="rider_id" value="<?php echo esc_attr( $detail_id ); ?>">
				<p>
					<select name="id_level">
						<?php foreach ( $id_levels as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>"
								<?php selected( $rider['idVerificationLevel'] ?? 'PHOTO', $value ); ?>>
								<?php echo esc_html( $label ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<input type="text" name="note" class="regular-text" style="width:32em"
						placeholder="Note — what you checked, or why you are refusing">
				</p>
				<p>
					<button class="button button-primary" name="decision" value="approve" type="submit">Approve</button>
					<button class="button" name="decision" value="suspend" type="submit">Suspend</button>
					<button class="button" name="decision" value="reinstate" type="submit">Reinstate</button>
					<button class="button" name="decision" value="reject" type="submit">Reject</button>
				</p>
			</form>
		<?php endif; ?>

		</div>
		<?php return; ?>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php foreach ( [ 'APPLIED' => 'Waiting', 'APPROVED' => 'Approved', 'SUSPENDED' => 'Suspended', '' => 'Everyone' ] as $value => $label ) : ?>
			<li>
				<a href="<?php echo esc_url( $value === '' ? remove_query_arg( 'status' ) : add_query_arg( 'status', $value ) ); ?>"
					<?php echo $filter === $value ? 'class="current"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			</li>
		<?php endforeach; ?>
	</ul>
	<div style="clear:both"></div>

	<?php
	$riders = Pokbon_Delivery_API_Client::riders( $filter );

	if ( is_wp_error( $riders ) ) : ?>
		<div class="notice notice-error">
			<p><strong>The Delivery API is not answering.</strong> <?php echo esc_html( $riders->get_error_message() ); ?></p>
		</div>
	<?php else : ?>
		<table class="widefat striped">
			<thead>
				<tr><th>Name</th><th>Phone</th><th>Vehicle</th><th>Base</th><th>Identity</th><th>Status</th><th>Duty</th><th></th></tr>
			</thead>
			<tbody>
			<?php if ( empty( $riders['riders'] ) ) : ?>
				<tr><td colspan="8">Nobody here.</td></tr>
			<?php else : ?>
				<?php foreach ( $riders['riders'] as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r['fullName'] ?? '—' ); ?></strong></td>
						<td><?php echo esc_html( $r['phone'] ?? '' ); ?></td>
						<td><?php echo esc_html( $r['vehicleClass'] ?? '—' ); ?></td>
						<td><?php echo esc_html( $r['baseZoneCode'] ?? '—' ); ?></td>
						<td>
							<?php
							$level = $r['idVerificationLevel'] ?? 'PHOTO';
							echo $level === 'PHOTO'
								? '<span style="color:#996800">photo only</span>'
								: esc_html( strtolower( $level ) );
							?>
						</td>
						<td><?php echo esc_html( $r['status'] ?? '' ); ?></td>
						<td><?php echo empty( $r['onDuty'] ) ? '—' : 'on duty'; ?></td>
						<td>
							<a class="button" href="<?php echo esc_url( add_query_arg( 'rider', $r['id'] ) ); ?>">Review</a>
						</td>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
	<?php endif; ?>
</div>
