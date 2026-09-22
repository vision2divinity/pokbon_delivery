<?php
/**
 * Does the delivery service make money?
 *
 * Every other screen answers whether the software works. This one answers
 * whether the business does, and it exists because on 2026-09-21 a real
 * delivery went out where the buyer paid GH¢30 for delivery, the job recorded
 * GH¢50 of delivery revenue, and GH¢52 left in rider earnings. The job board
 * showed a GH¢10 margin. The truth was a GH¢22 loss.
 *
 * So the first column that matters is not the job's price, it is what the
 * customer was actually charged — which lives on the WooCommerce order and
 * nowhere else. The delivery service cannot see it. This screen is the only
 * place the two halves meet, which is why it lives in the plugin.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$today = current_time( 'Y-m-d' );
$from  = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : gmdate( 'Y-m-d', strtotime( $today . ' -6 days' ) );
$to    = isset( $_GET['to'] ) ? sanitize_text_field( wp_unslash( $_GET['to'] ) ) : $today;

$result = Pokbon_Delivery_API_Client::jobs( [ 'from' => $from, 'to' => $to, 'limit' => 500 ] );
?>
<div class="wrap">
	<h1>Reconciliation</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<form method="get" style="margin:1em 0">
		<input type="hidden" name="page" value="<?php echo esc_attr( Pokbon_Delivery_Admin::SLUG . '-reconciliation' ); ?>">
		<label>From <input type="date" name="from" value="<?php echo esc_attr( $from ); ?>"></label>
		<label style="margin-left:.5em">To <input type="date" name="to" value="<?php echo esc_attr( $to ); ?>"></label>
		<button class="button" style="margin-left:.5em">Show</button>
	</form>

	<?php if ( is_wp_error( $result ) ) : ?>
		<div class="notice notice-error"><p>
			<strong>Could not read the delivery service.</strong><br>
			<?php echo esc_html( $result->get_error_message() ); ?>
		</p></div>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<?php
	$jobs = isset( $result['jobs'] ) && is_array( $result['jobs'] ) ? $result['jobs'] : [];

	$totals = [
		'charged'   => 0.0,  // what buyers actually paid for delivery
		'quoted'    => 0.0,  // what the jobs think delivery was worth
		'payout'    => 0.0,  // what riders earned, after commission
		'delivered' => 0,
		'failed'    => 0,
		'mismatch'  => 0,
	];
	$rows = [];

	foreach ( $jobs as $job ) {
		$source   = strtoupper( (string) ( $job['source'] ?? '' ) );
		$is_pokbon = $source === 'MARKETPLACE';
		$order_id = (string) ( $job['externalRef'] ?? '' );
		$money    = isset( $job['money'] ) && is_array( $job['money'] ) ? $job['money'] : [];

		$rider_fee  = (float) ( $money['riderFee'] ?? 0 );
		$uplift     = (float) ( $money['uplift'] ?? 0 );
		$commission = (float) ( $money['commission'] ?? 0 );
		$quoted     = (float) ( $money['buyerPrice'] ?? 0 );

		// What the rider is actually owed for this job. The same arithmetic
		// the delivery service uses when it writes the earnings rows.
		$payout = $rider_fee + $uplift - $commission;

		// What the customer actually paid for delivery. Only a POKBON order
		// has one; a courier job is priced by the ladder and nothing else.
		$charged = null;
		$vendor  = '';
		if ( $is_pokbon && $order_id !== '' && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order ) {
				$charged = (float) $order->get_shipping_total();
				$vendor  = (string) $order->get_meta( '_wcfmmp_store_name' );
			}
		}

		$status    = strtoupper( (string) ( $job['status'] ?? '' ) );
		$completed = $status === 'DELIVERED';
		$lost      = in_array( $status, [ 'FAILED', 'RETURNED' ], true );

		if ( $completed ) {
			$totals['delivered']++;
		} elseif ( $lost ) {
			$totals['failed']++;
		}

		// Only settled work counts toward the money. A job still in flight is
		// neither revenue nor cost yet, and counting it would make a busy
		// afternoon look like a profitable one.
		if ( $completed || $lost ) {
			$totals['payout']  += $payout;
			$totals['quoted']  += $quoted;
			if ( $charged !== null ) {
				$totals['charged'] += $charged;
			}
		}

		$mismatch = $charged !== null && abs( $charged - $quoted ) >= 0.01;
		if ( $mismatch && ( $completed || $lost ) ) {
			$totals['mismatch']++;
		}

		$rows[] = compact( 'job', 'is_pokbon', 'order_id', 'vendor', 'rider_fee', 'uplift', 'commission', 'quoted', 'payout', 'charged', 'status', 'completed', 'lost', 'mismatch' );
	}

	$margin = $totals['charged'] - $totals['payout'];
	?>

	<?php if ( $totals['mismatch'] > 0 ) : ?>
		<div class="notice notice-error" style="margin-bottom:1em">
			<p>
				<strong><?php echo (int) $totals['mismatch']; ?> deliver<?php echo $totals['mismatch'] === 1 ? 'y was' : 'ies were'; ?>
				charged a different amount at checkout than the job was priced at.</strong>
			</p>
			<p>
				Checkout uses the website's own shipping rate; the job uses your zone matrix. When they
				disagree, every margin below is wrong in the same direction, and the job board will
				cheerfully show a profit on a delivery that lost money. Until they agree, trust the
				<em>Charged</em> column and ignore <em>Quoted</em>.
			</p>
		</div>
	<?php endif; ?>

	<div style="display:flex;gap:1em;flex-wrap:wrap;margin-bottom:1.5em">
		<?php
		$cards = [
			[ 'Collected for delivery', Pokbon_Delivery_Settings::format( (int) round( $totals['charged'] * 100 ) ), 'What buyers actually paid' ],
			[ 'Paid to riders', Pokbon_Delivery_Settings::format( (int) round( $totals['payout'] * 100 ) ), 'Fees and uplift, less commission' ],
			[ $margin >= 0 ? 'Kept' : 'Lost', Pokbon_Delivery_Settings::format( (int) round( abs( $margin ) * 100 ) ), 'Collected minus paid out' ],
			[ 'Delivered', (string) $totals['delivered'], sprintf( '%d failed or returned', $totals['failed'] ) ],
		];
		foreach ( $cards as $i => $card ) :
			$is_margin = $i === 2;
			$colour    = $is_margin ? ( $margin >= 0 ? '#136c39' : '#b32d2e' ) : '#1d2327';
			?>
			<div style="flex:1;min-width:13em;border:1px solid #c3c4c7;background:#fff;padding:1em">
				<div style="font-size:12px;text-transform:uppercase;letter-spacing:.04em;color:#646970">
					<?php echo esc_html( $card[0] ); ?>
				</div>
				<div style="font-size:22px;font-weight:600;margin:.2em 0;color:<?php echo esc_attr( $colour ); ?>">
					<?php echo esc_html( $card[1] ); ?>
				</div>
				<div class="description"><?php echo esc_html( $card[2] ); ?></div>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="description" style="max-width:60em">
		Only delivered, failed and returned jobs count toward the money. A delivery still in progress is
		neither revenue nor cost yet, and counting it would make a busy afternoon look like a profitable
		one. A failed trip still costs — the rider rode there — which is why those rows carry a payout.
	</p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th>When</th>
				<th>Order</th>
				<th>Vendor</th>
				<th>Rider</th>
				<th>Route</th>
				<th>Status</th>
				<th style="text-align:right">Charged</th>
				<th style="text-align:right">Quoted</th>
				<th style="text-align:right">Rider paid</th>
				<th style="text-align:right">Kept</th>
			</tr>
		</thead>
		<tbody>
		<?php if ( $rows === [] ) : ?>
			<tr><td colspan="10">No deliveries in that period.</td></tr>
		<?php endif; ?>
		<?php foreach ( $rows as $r ) : ?>
			<?php
			$job  = $r['job'];
			$kept = $r['charged'] !== null ? $r['charged'] - $r['payout'] : null;
			?>
			<tr<?php echo $r['mismatch'] ? ' style="background:#fcf0f1"' : ''; ?>>
				<td><?php echo esc_html( gmdate( 'j M H:i', strtotime( (string) ( $job['createdAt'] ?? '' ) ) ) ); ?></td>
				<td>
					<?php if ( $r['is_pokbon'] ) : ?>
						<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#FF6B35;color:#fff;font-size:11px">POKBON</span>
						<?php if ( $r['order_id'] !== '' ) : ?>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-orders&action=edit&id=' . rawurlencode( $r['order_id'] ) ) ); ?>">#<?php echo esc_html( $r['order_id'] ); ?></a>
						<?php endif; ?>
					<?php else : ?>
						<span style="display:inline-block;padding:1px 6px;border-radius:3px;background:#646970;color:#fff;font-size:11px">OUTSIDE</span>
						<?php echo esc_html( $r['order_id'] ); ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( $r['vendor'] !== '' ? $r['vendor'] : '—' ); ?></td>
				<td><?php echo esc_html( $job['rider']['fullName'] ?? '—' ); ?></td>
				<td><?php echo esc_html( ( $job['pickupZoneCode'] ?? '?' ) . ' → ' . ( $job['dropoffZoneCode'] ?? '?' ) ); ?></td>
				<td>
					<?php echo esc_html( strtolower( str_replace( '_', ' ', $r['status'] ) ) ); ?>
				</td>
				<td style="text-align:right">
					<?php echo $r['charged'] === null ? '—' : esc_html( Pokbon_Delivery_Settings::format( (int) round( $r['charged'] * 100 ) ) ); ?>
				</td>
				<td style="text-align:right<?php echo $r['mismatch'] ? ';color:#b32d2e;font-weight:600' : ''; ?>">
					<?php echo esc_html( Pokbon_Delivery_Settings::format( (int) round( $r['quoted'] * 100 ) ) ); ?>
				</td>
				<td style="text-align:right">
					<?php echo esc_html( Pokbon_Delivery_Settings::format( (int) round( $r['payout'] * 100 ) ) ); ?>
					<?php if ( $r['uplift'] > 0 ) : ?>
						<br><span class="description">incl. <?php echo esc_html( Pokbon_Delivery_Settings::format( (int) round( $r['uplift'] * 100 ) ) ); ?> uplift</span>
					<?php endif; ?>
				</td>
				<td style="text-align:right<?php echo ( $kept !== null && $kept < 0 ) ? ';color:#b32d2e;font-weight:600' : ''; ?>">
					<?php echo $kept === null ? '—' : esc_html( Pokbon_Delivery_Settings::format( (int) round( $kept * 100 ) ) ); ?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<h2 style="margin-top:2em">What this screen still cannot tell you</h2>
	<ul style="list-style:disc;margin-left:2em;max-width:60em">
		<li>
			<strong>Whether a rider has been paid.</strong> Earnings accrue and are never cleared —
			nothing records a payout run. So "Rider paid" is what they have <em>earned</em>, not what
			has left your account.
		</li>
		<li>
			<strong>What is owed to a sender on an outside job.</strong> Courier jobs for people who are
			not buying anything are not built yet, so that column has nothing to show. When they exist,
			the money collected on their behalf belongs here.
		</li>
		<li>
			<strong>Refunds.</strong> A delivered order refunded afterwards still reads as revenue here.
		</li>
	</ul>
</div>
