<?php
/**
 * Pricing. PRD § 9, revised 2026-09-21.
 *
 * Three rungs on one page, in the order they are tried. A route takes the
 * first one that answers:
 *
 *   1. an exact route you priced yourself — Adenta → Kasoa
 *   2. the bands those zones belong to     — Inner Accra → Outer Accra
 *   3. how far it actually is              — 0–5km, 5–10km, …
 *
 * The matrix alone does not survive growth: six zones is 36 cells, twenty is
 * 400, and every new area means pricing it against every existing one. With
 * the ladder, adding a zone costs one decision — which band — and it prices
 * the same day.
 *
 * This prices the RIDER LEG only. Ship-from-abroad freight is weight-based
 * and belongs to the marketplace; store pickup is free.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$zones      = Pokbon_Delivery_Settings::active_zones();
$prices     = Pokbon_Delivery_Settings::prices();
$bands      = Pokbon_Delivery_Settings::active_bands();
$band_rows  = Pokbon_Delivery_Settings::band_prices();
$distance   = Pokbon_Delivery_Settings::distance_bands();
$markup     = (int) ( Pokbon_Delivery_Settings::get( 'default_markup' )['rateBps'] ?? 0 ) / 100;

/** The "try a route" box at the bottom, so a surprising price is explicable. */
$try_from = isset( $_GET['try_from'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['try_from'] ) ) ) : '';
$try_to   = isset( $_GET['try_to'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['try_to'] ) ) ) : '';
$tried    = ( $try_from && $try_to )
	? Pokbon_Delivery_Pricing::route( [ 'zoneCode' => $try_from ], [ 'zoneCode' => $try_to ] )
	: null;
?>
<div class="wrap">
	<h1>Pricing</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<p class="description" style="max-width:56em">
		A route takes the <strong>first rung that answers</strong>: an exact route you priced, then the bands
		those zones belong to, then how far it is. So nothing is ever unpriced, and adding a new area costs one
		decision rather than a price against every existing area.
		Everywhere below, the top number is the <strong>rider's fee</strong> and the bottom is what the
		<strong>buyer pays</strong>. The difference is POKBON's, and
		<strong>riders never see the buyer's price</strong> — the delivery service leaves it out of every rider
		response, not just the app screen.
	</p>
	<p class="description" style="max-width:56em">
		This is the rider leg only — the "POKBON Delivery Services" option. Ship-from-abroad freight keeps its
		own weight-based pricing, and store pickup stays free.
	</p>

	<?php if ( count( $zones ) === 0 ) : ?>
		<div class="notice notice-info"><p>
			No active zones yet.
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=pokbon-delivery-zones' ) ); ?>">Add a zone</a> first.
		</p></div>
		</div>
		<?php return; ?>
	<?php endif; ?>

	<hr>
	<h2>1 · Exact routes</h2>
	<p class="description" style="max-width:56em">
		For routes that are genuinely special — a bad road, a bridge, an area worth more. Leave a pair blank and
		it falls through to the band, then to distance. You do not need to fill this in.
		Blank buyer price is filled from your markup of <?php echo esc_html( number_format( $markup, 2 ) ); ?>%.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_matrix' ); ?>
	<div style="overflow-x:auto">
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
					<br><span class="description" style="font-weight:400"><?php echo esc_html( $from['band'] ?? '' ); ?></span>
				</th>
				<?php foreach ( $zones as $to ) :
					$pair  = $from['code'] . '|' . $to['code'];
					$row   = $prices[ $pair ] ?? null;
					$rider = $row ? number_format( $row['riderFeeMinor'] / 100, 2, '.', '' ) : '';
					$buyer = $row ? number_format( $row['buyerPriceMinor'] / 100, 2, '.', '' ) : '';
					?>
					<td style="text-align:center;padding:6px">
						<input type="number" step="0.01" min="0" style="width:6em"
							name="rider[<?php echo esc_attr( $pair ); ?>]"
							value="<?php echo esc_attr( $rider ); ?>" placeholder="rider">
						<br>
						<input type="number" step="0.01" min="0" style="width:6em;margin-top:4px"
							name="buyer[<?php echo esc_attr( $pair ); ?>]"
							value="<?php echo esc_attr( $buyer ); ?>" placeholder="buyer">
						<br>
						<?php if ( $row ) :
							$on = Pokbon_Delivery_Settings::row_active( $row );
							?>
							<label style="display:block;margin-top:2px;font-size:11px"
								title="Off keeps the price but stops using it, so this route falls through to the band, then to distance.">
								<input type="hidden" name="active[<?php echo esc_attr( $pair ); ?>]" value="0">
								<input type="checkbox" name="active[<?php echo esc_attr( $pair ); ?>]" value="1"
									<?php checked( $on ); ?>>
								<?php echo $on ? 'on' : 'off'; ?>
							</label>
						<?php endif; ?>
						<small class="description">
							<?php
							if ( ! $row ) {
								echo 'falls through';
							} elseif ( ! Pokbon_Delivery_Settings::row_active( $row ) ) {
								echo '<em>not in use</em>';
							} else {
								echo esc_html( Pokbon_Delivery_Settings::format( $row['buyerPriceMinor'] - $row['riderFeeMinor'] ) );
							}
							?>
						</small>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
	<p><button type="submit" class="button button-primary">Save exact routes</button></p>
	</form>

	<hr>
	<h2>2 · Bands</h2>
	<p class="description" style="max-width:56em">
		Group zones that price alike. Four bands cover any number of zones with sixteen prices, so a new area is
		one decision instead of twenty. A zone's band is set on the
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=pokbon-delivery-zones' ) ); ?>">Zones</a> screen.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_bands' ); ?>
	<table class="widefat striped" style="max-width:46em">
		<thead><tr><th>Code</th><th>Name</th><th>Active</th></tr></thead>
		<tbody>
		<?php
		$rows = Pokbon_Delivery_Settings::bands();
		$rows[] = [ 'code' => '', 'name' => '', 'active' => true ];
		foreach ( $rows as $band ) :
			?>
			<tr>
				<td><input type="text" name="band_code[]" value="<?php echo esc_attr( $band['code'] ); ?>" placeholder="INNER"></td>
				<td><input type="text" name="band_name[]" value="<?php echo esc_attr( $band['name'] ); ?>" class="regular-text" placeholder="Inner Accra"></td>
				<td>
					<input type="hidden" name="band_active[<?php echo esc_attr( $band['code'] ); ?>]" value="0">
					<input type="checkbox" name="band_active[<?php echo esc_attr( $band['code'] ); ?>]" value="1"
						<?php checked( ! empty( $band['active'] ) ); ?>>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p><button type="submit" class="button">Save bands</button></p>
	</form>

	<?php if ( count( $bands ) ) : ?>
		<h3>Band prices</h3>
		<?php Pokbon_Delivery_Admin::form_open( 'save_band_matrix' ); ?>
		<div style="overflow-x:auto">
		<table class="widefat striped" style="width:auto">
			<thead>
				<tr>
					<th style="text-align:right">From \ To</th>
					<?php foreach ( $bands as $to ) : ?>
						<th style="text-align:center"><?php echo esc_html( $to['code'] ); ?></th>
					<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $bands as $from ) : ?>
				<tr>
					<th style="text-align:right;white-space:nowrap"><?php echo esc_html( $from['code'] ); ?></th>
					<?php foreach ( $bands as $to ) :
						$pair  = $from['code'] . '|' . $to['code'];
						$row   = $band_rows[ $pair ] ?? null;
						$rider = $row ? number_format( $row['riderFeeMinor'] / 100, 2, '.', '' ) : '';
						$buyer = $row ? number_format( $row['buyerPriceMinor'] / 100, 2, '.', '' ) : '';
						?>
						<td style="text-align:center;padding:6px">
							<input type="number" step="0.01" min="0" style="width:6em"
								name="brider[<?php echo esc_attr( $pair ); ?>]"
								value="<?php echo esc_attr( $rider ); ?>" placeholder="rider">
							<br>
							<input type="number" step="0.01" min="0" style="width:6em;margin-top:4px"
								name="bbuyer[<?php echo esc_attr( $pair ); ?>]"
								value="<?php echo esc_attr( $buyer ); ?>" placeholder="buyer">
							<?php if ( $row ) :
								$on = Pokbon_Delivery_Settings::row_active( $row );
								?>
								<label style="display:block;margin-top:2px;font-size:11px"
									title="Off keeps the price but stops using it, so these bands fall through to distance.">
									<input type="hidden" name="bactive[<?php echo esc_attr( $pair ); ?>]" value="0">
									<input type="checkbox" name="bactive[<?php echo esc_attr( $pair ); ?>]" value="1"
										<?php checked( $on ); ?>>
									<?php echo $on ? 'on' : 'off'; ?>
								</label>
							<?php endif; ?>
						</td>
					<?php endforeach; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<p><button type="submit" class="button button-primary">Save band prices</button></p>
		</form>
	<?php endif; ?>

	<hr>
	<h2>3 · Distance</h2>
	<p class="description" style="max-width:56em">
		The catch-all, so a zone you add this morning prices this morning. Distance is measured straight-line
		between the two points, or between the two zone centres when there is no pin.
		<strong>Keep a very large last band</strong> — without one, a long route falls off the end of the ladder
		and reads as "not served".
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_distance' ); ?>
	<table class="widefat striped" style="max-width:46em">
		<thead><tr><th>Up to (km)</th><th>Rider fee</th><th>Buyer pays</th><th>Using it?</th><th>POKBON</th></tr></thead>
		<tbody>
		<?php
		// all_ so a switched-off band is still shown and can be switched back on.
		$rows = Pokbon_Delivery_Settings::all_distance_bands();
		$rows[] = [ 'maxKm' => '', 'riderFeeMinor' => 0, 'buyerPriceMinor' => 0 ];
		foreach ( $rows as $i => $band ) :
			$margin = (int) $band['buyerPriceMinor'] - (int) $band['riderFeeMinor'];
			?>
			<tr>
				<td><input type="number" step="0.1" min="0" style="width:8em" name="dist_max[]"
					value="<?php echo esc_attr( $band['maxKm'] ); ?>"></td>
				<td><input type="number" step="0.01" min="0" style="width:8em" name="dist_rider[]"
					value="<?php echo esc_attr( $band['maxKm'] === '' ? '' : number_format( $band['riderFeeMinor'] / 100, 2, '.', '' ) ); ?>"></td>
				<td><input type="number" step="0.01" min="0" style="width:8em" name="dist_buyer[]"
					value="<?php echo esc_attr( $band['maxKm'] === '' ? '' : number_format( $band['buyerPriceMinor'] / 100, 2, '.', '' ) ); ?>"></td>
				<td>
					<?php if ( $band['maxKm'] !== '' ) : ?>
						<label title="Off keeps the band but stops using it, so a route this far falls to the next band up.">
							<?php /* Keyed, not appended: an unkeyed hidden+checkbox pair posts
							        one value when unticked and two when ticked, so every row
							        after the first unticked one would take the wrong flag. With
							        an explicit index the checkbox overwrites the hidden. */ ?>
							<input type="hidden" name="dist_active[<?php echo (int) $i; ?>]" value="0">
							<input type="checkbox" name="dist_active[<?php echo (int) $i; ?>]" value="1"
								<?php checked( Pokbon_Delivery_Settings::row_active( $band ) ); ?>>
							in use
						</label>
					<?php endif; ?>
				</td>
				<td class="description">
					<?php
					if ( $band['maxKm'] === '' ) {
						echo '';
					} elseif ( ! Pokbon_Delivery_Settings::row_active( $band ) ) {
						echo '<em>not in use</em>';
					} else {
						echo esc_html( Pokbon_Delivery_Settings::format( $margin ) );
					}
					?>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<button type="submit" class="button button-primary">Save distance bands</button>
		<span class="description" style="margin-left:1em">
			Clearing a row&rsquo;s km removes that band. Unticking <strong>in use</strong> keeps it but
			stops pricing from it, so a route that far falls to the next band up.
		</span>
	</p>
	</form>

	<hr>
	<h2>Try a route</h2>
	<p class="description" style="max-width:56em">
		Check what a route would cost and, more usefully, <strong>which rung decided it</strong>. A price nobody
		can explain is a price nobody trusts.
	</p>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
		<input type="hidden" name="page" value="pokbon-delivery-matrix">
		<select name="try_from">
			<?php foreach ( $zones as $z ) : ?>
				<option value="<?php echo esc_attr( $z['code'] ); ?>" <?php selected( $try_from, $z['code'] ); ?>>
					<?php echo esc_html( $z['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		&rarr;
		<select name="try_to">
			<?php foreach ( $zones as $z ) : ?>
				<option value="<?php echo esc_attr( $z['code'] ); ?>" <?php selected( $try_to, $z['code'] ); ?>>
					<?php echo esc_html( $z['name'] ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="button">Price it</button>
	</form>

	<?php if ( $try_from && $try_to ) : ?>
		<?php if ( $tried === null ) : ?>
			<div class="notice notice-warning inline" style="margin-top:1em"><p>
				<strong>Not served.</strong> No rung answered for <?php echo esc_html( $try_from . ' → ' . $try_to ); ?>.
				Checkout will not offer rider delivery on this route. Add a distance band with a large enough
				limit to catch it.
			</p></div>
		<?php else : ?>
			<div class="notice notice-success inline" style="margin-top:1em"><p>
				<strong><?php echo esc_html( $try_from . ' → ' . $try_to ); ?></strong>:
				buyer pays <?php echo esc_html( Pokbon_Delivery_Settings::format( $tried['buyerPriceMinor'] ) ); ?>,
				rider earns <?php echo esc_html( Pokbon_Delivery_Settings::format( $tried['riderFeeMinor'] ) ); ?>,
				POKBON keeps
				<?php echo esc_html( Pokbon_Delivery_Settings::format( $tried['buyerPriceMinor'] - $tried['riderFeeMinor'] ) ); ?>.
				<br>
				Decided by <strong><?php echo esc_html( Pokbon_Delivery_Pricing::rung_label( $tried['rung'] ) ); ?></strong>
				(<?php echo esc_html( $tried['matched'] ); ?>)<?php
				if ( ! empty( $tried['distanceMetres'] ) ) {
					echo ', about ' . esc_html( number_format( $tried['distanceMetres'] / 1000, 1 ) ) . 'km apart';
				}
				?>.
			</p></div>
		<?php endif; ?>
	<?php endif; ?>
</div>
