<?php
/**
 * Zones. PRD § 9a.
 *
 * A zone is a named area with a centre and a radius. A drop-off pin belongs to
 * the nearest active zone that contains it; a pin no zone claims is not
 * deliverable yet, and checkout says so rather than guessing.
 *
 * Zones are switched off, never deleted, so a job priced against one last month
 * stays explicable.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$zones = Pokbon_Delivery_Settings::zones();
$edit  = isset( $_GET['zone'] ) ? Pokbon_Delivery_Settings::zone( strtoupper( sanitize_key( wp_unslash( $_GET['zone'] ) ) ) ) : null;
$bands = Pokbon_Delivery_Settings::active_bands();
?>
<div class="wrap">
	<h1>Zones</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<table class="widefat striped" style="max-width:60em">
		<thead>
			<tr>
				<th>Code</th>
				<th>Name</th>
				<th>Region</th>
				<th>Band</th>
				<th>Centre</th>
				<th>Radius</th>
				<th>Status</th>
				<th>At checkout</th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( empty( $zones ) ) : ?>
			<tr><td colspan="9">No zones yet. Add the first one below.</td></tr>
		<?php else : ?>
			<?php foreach ( $zones as $zone ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $zone['code'] ); ?></strong></td>
					<td><?php echo esc_html( $zone['name'] ); ?></td>
					<td><?php echo esc_html( $zone['region'] ?? '' ); ?></td>
					<td><?php echo esc_html( $zone['band'] ?? '—' ); ?></td>
					<td><?php echo esc_html( sprintf( '%.4f, %.4f', $zone['lat'], $zone['lng'] ) ); ?></td>
					<td><?php echo esc_html( number_format( $zone['radiusMetres'] / 1000, 1 ) ); ?> km</td>
					<td>
						<?php if ( ! empty( $zone['active'] ) ) : ?>
							<span style="color:#1a7f37">Active</span>
						<?php else : ?>
							<span style="color:#8a8a8a">Off</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						/*
						 * Whether a buyer would actually be offered this area,
						 * and at what price. Without it, "the selector is not
						 * showing" is a mystery to be debugged by placing test
						 * orders; with it the answer is on the screen where
						 * the zone is configured.
						 *
						 * Priced from the default pickup, which is what a
						 * single-warehouse order uses. A cart spanning vendors
						 * in other places can cost more.
						 */
						$from  = (string) Pokbon_Delivery_Settings::get( 'default_pickup_zone' );
						$quote = null;
						if ( $from !== '' ) {
							$quote = Pokbon_Delivery_Pricing::route(
								[ 'zoneCode' => $from ],
								[ 'zoneCode' => $zone['code'], 'lat' => $zone['lat'], 'lng' => $zone['lng'] ]
							);
						}
						?>
						<?php if ( $from === '' ) : ?>
							<span style="color:#b32d2e">No default pickup set</span>
						<?php elseif ( $quote === null ) : ?>
							<span style="color:#b32d2e">Not priced</span>
							<br><span class="description">from <?php echo esc_html( $from ); ?> — buyers get the regional rate</span>
						<?php else : ?>
							<strong><?php echo esc_html( Pokbon_Delivery_Settings::format( (int) $quote['buyerPriceMinor'] ) ); ?></strong>
							<br><span class="description"><?php echo esc_html( Pokbon_Delivery_Pricing::rung_label( (string) $quote['rung'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button" href="<?php echo esc_url( add_query_arg( 'zone', $zone['code'] ) ); ?>">Edit</a>
						<?php if ( ! empty( $zone['active'] ) ) : ?>
							<?php echo Pokbon_Delivery_Admin::button( 'deactivate_zone', 'Switch off', [ 'code' => $zone['code'] ] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		<?php endif; ?>
		</tbody>
	</table>

	<h2><?php echo $edit ? 'Edit ' . esc_html( $edit['code'] ) : 'Add a zone'; ?></h2>

	<?php Pokbon_Delivery_Admin::form_open( 'save_zone' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="pkbd-code">Code</label></th>
			<td>
				<input id="pkbd-code" name="code" type="text" class="regular-text"
					value="<?php echo esc_attr( $edit['code'] ?? '' ); ?>"
					<?php echo $edit ? 'readonly' : ''; ?> required>
				<p class="description">Short and permanent, like MADINA. Used in the price matrix and on every job.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-name">Name</label></th>
			<td><input id="pkbd-name" name="name" type="text" class="regular-text"
				value="<?php echo esc_attr( $edit['name'] ?? '' ); ?>" required></td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-region">Region</label></th>
			<td>
				<?php
				/*
				 * A list, not a text box.
				 *
				 * This was free text, and checkout compares it against
				 * WooCommerce's state code — AA, BE. "Greater Accra" typed
				 * here matched nothing, so every zone was hidden at checkout
				 * and buyers were quietly charged the flat regional rate
				 * instead of the price set for their area. Saving the code
				 * removes the class of mistake; the matching still accepts
				 * the old text so nothing already saved breaks.
				 */
				$wc_regions = ( function_exists( 'WC' ) && WC()->countries ) ? WC()->countries->get_states( 'GH' ) : [];
				$current    = (string) ( $edit['region'] ?? '' );
				$known      = is_array( $wc_regions ) && ( isset( $wc_regions[ strtoupper( $current ) ] ) || $current === '' );
				?>
				<select id="pkbd-region" name="region">
					<option value="" <?php selected( $current, '' ); ?>>— every region —</option>
					<?php foreach ( (array) $wc_regions as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( strtoupper( $current ), strtoupper( (string) $code ) ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
					<?php if ( ! $known ) : ?>
						<option value="<?php echo esc_attr( $current ); ?>" selected>
							<?php echo esc_html( $current ); ?> (typed in)
						</option>
					<?php endif; ?>
				</select>
				<p class="description">
					Which region a buyer must be in for this area to be offered at checkout.
					<strong>Every region</strong> offers it everywhere, which is right for a zone that
					serves more than one.
					<?php if ( ! $known ) : ?>
						<br><span style="color:#b32d2e">
							&ldquo;<?php echo esc_html( $current ); ?>&rdquo; was typed in rather than chosen.
							It still works, but pick it from the list to be sure.
						</span>
					<?php endif; ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-band">Band</label></th>
			<td>
				<select id="pkbd-band" name="band">
					<option value="">— none —</option>
					<?php foreach ( $bands as $band ) : ?>
						<option value="<?php echo esc_attr( $band['code'] ); ?>"
							<?php selected( $edit['band'] ?? '', $band['code'] ); ?>>
							<?php echo esc_html( $band['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">
					How this zone prices when no exact route is set for it. This one choice is what makes
					adding a zone cheap instead of a price against every other zone.
					Leave it blank and the zone falls straight through to the distance bands.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Centre</th>
			<td>
				<input name="lat" type="number" step="0.0001" placeholder="latitude" style="width:12em"
					value="<?php echo esc_attr( $edit['lat'] ?? '' ); ?>" required>
				<input name="lng" type="number" step="0.0001" placeholder="longitude" style="width:12em"
					value="<?php echo esc_attr( $edit['lng'] ?? '' ); ?>" required>
				<p class="description">
					From Google Maps: right-click the spot and the first line of the menu is the pair.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-radius">Radius</label></th>
			<td>
				<input id="pkbd-radius" name="radius" type="number" min="100" step="100" style="width:12em"
					value="<?php echo esc_attr( $edit['radiusMetres'] ?? 5000 ); ?>" required> metres
				<p class="description">
					How far "and environs" reaches. Too wide and a far address gets a near price;
					too narrow and real customers are told POKBON does not deliver to them.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Active</th>
			<td>
				<label>
					<input name="active" type="checkbox" value="1"
						<?php checked( $edit ? ! empty( $edit['active'] ) : true ); ?>>
					Offer deliveries in this zone
				</label>
			</td>
		</tr>
	</table>
	<p>
		<button type="submit" class="button button-primary">
			<?php echo $edit ? 'Save zone' : 'Add zone'; ?>
		</button>
		<?php if ( $edit ) : ?>
			<a class="button" href="<?php echo esc_url( remove_query_arg( 'zone' ) ); ?>">Cancel</a>
		<?php endif; ?>
	</p>
	</form>
</div>
