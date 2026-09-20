<?php
/**
 * The price matrix. PRD § 9b and § 12c.
 *
 * Zones down the side, zones across the top, every cell holding what the rider
 * is offered and what the buyer is shown. The margin is never stored — it is
 * the difference, computed here, so the two numbers can never disagree with it.
 *
 * Madina → Circle, rider GH₵30, buyer GH₵40, POKBON GH₵10 is the owner's own
 * worked example and the shape everything else follows.
 *
 * A blank pair means POKBON does not serve that route yet. Checkout says so
 * rather than inventing a price, which is why clearing a cell is a real action
 * and not an oversight.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$zones  = Pokbon_Delivery_Settings::active_zones();
$prices = Pokbon_Delivery_Settings::prices();
$markup = (int) ( Pokbon_Delivery_Settings::get( 'default_markup' )['rateBps'] ?? 0 ) / 100;
?>
<div class="wrap">
	<h1>Price matrix</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<?php if ( count( $zones ) === 0 ) : ?>
		<div class="notice notice-info">
			<p>
				No active zones yet.
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=pokbon-delivery-zones' ) ); ?>">Add a zone</a>
				before pricing routes.
			</p>
		</div>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<p class="description" style="max-width:52em">
		The top number is what the rider is offered. The bottom is what the buyer pays.
		The difference is POKBON's. <strong>Riders never see the buyer's price</strong> — the
		delivery service leaves it out of every rider response, not just the app screen.
		Leave both blank for a route you do not serve. Leave only the buyer price blank and it
		is filled from your default markup of <?php echo esc_html( number_format( $markup, 2 ) ); ?>%.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_matrix' ); ?>

	<table class="widefat striped" style="width:auto">
		<thead>
			<tr>
				<th style="text-align:right">From \ To</th>
				<?php foreach ( $zones as $to ) : ?>
					<th style="text-align:center"><?php echo esc_html( $to['code'] ); ?></th>
				<?php endforeach; ?>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( $zones as $from ) : ?>
			<tr>
				<th style="text-align:right;white-space:nowrap">
					<?php echo esc_html( $from['code'] ); ?>
					<br><span class="description" style="font-weight:normal"><?php echo esc_html( $from['name'] ); ?></span>
				</th>

				<?php foreach ( $zones as $to ) :
					$pair        = $from['code'] . '|' . $to['code'];
					$row         = $prices[ $pair ] ?? null;
					$rider_value = $row ? number_format( $row['riderFeeMinor'] / 100, 2, '.', '' ) : '';
					$buyer_value = $row ? number_format( $row['buyerPriceMinor'] / 100, 2, '.', '' ) : '';
					$margin      = $row ? $row['buyerPriceMinor'] - $row['riderFeeMinor'] : null;
					?>
					<td style="text-align:center;padding:6px">
						<label class="screen-reader-text">
							Rider fee <?php echo esc_attr( $from['code'] . ' to ' . $to['code'] ); ?>
						</label>
						<input type="number" step="0.01" min="0" style="width:6.5em"
							name="rider[<?php echo esc_attr( $pair ); ?>]"
							value="<?php echo esc_attr( $rider_value ); ?>"
							placeholder="rider">
						<br>
						<label class="screen-reader-text">
							Buyer price <?php echo esc_attr( $from['code'] . ' to ' . $to['code'] ); ?>
						</label>
						<input type="number" step="0.01" min="0" style="width:6.5em;margin-top:4px"
							name="buyer[<?php echo esc_attr( $pair ); ?>]"
							value="<?php echo esc_attr( $buyer_value ); ?>"
							placeholder="buyer">
						<br>
						<small class="description">
							<?php
							if ( $margin === null ) {
								echo 'not served';
							} else {
								echo esc_html( Pokbon_Delivery_Settings::format( $margin ) );
								if ( $margin < 0 ) {
									echo ' <span style="color:#b32d2e">below cost</span>';
								}
							}
							?>
						</small>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<p>
		<button type="submit" class="button button-primary">Save the matrix</button>
		<span class="description" style="margin-left:1em">
			Saved prices are pushed to the Delivery API. A job freezes its price when it is created,
			so changing a cell never alters a delivery already under way.
		</span>
	</p>
	</form>
</div>
