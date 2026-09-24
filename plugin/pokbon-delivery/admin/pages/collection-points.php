<?php
/**
 * Where a rider collects from each vendor.
 *
 * An address is not a location. None of the three places a vendor's address
 * comes from — their billing details, what they typed at registration, or the
 * profile somebody filled in for them — contains coordinates, and text cannot
 * be routed to. Only a pin can.
 *
 * Most vendors never open the WCFM store manager to drop one. Until now the
 * consequence was silent: their collection point fell through to POKBON's own
 * default and a rider was sent, confidently, to the wrong business, with a
 * real address and a real phone number belonging to somebody else.
 *
 * So this is where somebody at POKBON looks at a map once per vendor and
 * settles it. It sits above the vendor's own WCFM pin deliberately: a person
 * here decided, and a field a vendor never touched did not.
 *
 * It replaces logging into a vendor's account to set it for them, which is
 * faster, leaves a record, and does not need their password.
 */

defined( 'ABSPATH' ) || exit;

global $wpdb;

/*
 * The vendors who can actually generate a job: whoever authors a product
 * somebody can buy. Asking the roles instead would list accounts that have
 * never sold anything and miss any install with a differently named role.
 */
$vendor_ids = $wpdb->get_col(
	"SELECT DISTINCT post_author FROM {$wpdb->posts}
	 WHERE post_type = 'product' AND post_status = 'publish'
	 ORDER BY post_author ASC"
);

$configured = Pokbon_Delivery_Settings::vendor_pickups();
?>
<div class="wrap">
	<h1>Collection points</h1>

	<p class="description" style="max-width:62em">
		Where a rider goes to collect each vendor&rsquo;s parcels. <strong>Everything here is an
		override</strong> &mdash; fill in what you know and leave the rest blank. Whatever you set wins
		over what the vendor put in their WCFM dashboard; whatever you leave blank falls through to
		their own profile, field by field.
	</p>
	<p class="description" style="max-width:62em">
		<strong>The zone is the important one.</strong> It is what prices the route and decides which
		riders are offered the job, and you can set it on its own &mdash; a vendor with a zone and no
		pin is dispatched from the middle of that zone, and the rider&rsquo;s app is told to search for
		the shop by name rather than ride to the middle of a suburb. The pin is for navigation: paste
		the <code>5.6689, -0.1651</code> Google Maps shows when you long-press a spot, or the whole
		link from the address bar.
	</p>
	<p class="description" style="max-width:62em">
		<strong>A vendor with nothing here and nothing in their dashboard is collected from your
		default collection point</strong>, which means a rider is sent to your shop for their goods.
		That still happens rather than blocking the order, and the order gets a note saying so &mdash;
		but the rider has already set off by then, so it is worth settling here first.
	</p>

	<?php if ( empty( $vendor_ids ) ) : ?>
		<p>No vendors with published products yet.</p>
	<?php else : ?>

		<table class="widefat striped" style="max-width:88em;margin-top:1em">
			<thead>
				<tr>
					<th style="width:18em">Vendor</th>
					<th style="width:16em">Using now</th>
					<th>Set a collection point</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $vendor_ids as $vendor_id ) :
				$vendor_id = (int) $vendor_id;
				if ( $vendor_id <= 0 ) {
					continue;
				}
				$user  = get_userdata( $vendor_id );
				$store = (string) get_user_meta( $vendor_id, 'store_name', true );
				$name  = $store !== '' ? $store : ( $user ? $user->display_name : 'Vendor ' . $vendor_id );

				$diag   = Pokbon_Delivery_Orders::pickup_diagnosis( $vendor_id );
				$row    = $configured[ $vendor_id ] ?? null;
				$hasPin = is_array( $row ) && ( abs( (float) ( $row['lat'] ?? 0 ) ) >= 0.0001 || abs( (float) ( $row['lng'] ?? 0 ) ) >= 0.0001 );

				// What the vendor typed when they registered, as a starting
				// point for somebody about to look it up on a map.
				$hint = trim( (string) get_user_meta( $vendor_id, 'billing_address_1', true ) . ' '
					. (string) get_user_meta( $vendor_id, 'billing_city', true ) );
				?>
				<tr>
					<td>
						<strong><?php echo esc_html( $name ); ?></strong><br>
						<span class="description">
							#<?php echo (int) $vendor_id; ?>
							<?php if ( $user ) : ?>&middot; <?php echo esc_html( $user->user_email ); ?><?php endif; ?>
						</span>
						<?php if ( $hint !== '' ) : ?>
							<br><span class="description">Registered: <?php echo esc_html( $hint ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						switch ( $diag['source'] ) {
							case 'filter':
								echo '<strong style="color:#1a7f37">Your pin</strong>';
								break;
							case 'vendor_store':
								echo '<strong>Their own WCFM pin</strong>';
								break;
							case 'pickup_point':
								echo '<strong>A configured pickup point</strong>';
								break;
							case 'default':
								echo '<strong style="color:#a00">Your default &mdash; wrong shop</strong>';
								echo '<br><span class="description">' . esc_html( $diag['why'] ) . '</span>';
								break;
							default:
								echo '<strong style="color:#a00">Nothing resolves</strong>';
								echo '<br><span class="description">This vendor&rsquo;s orders cannot be dispatched.</span>';
						}
						if ( ! empty( $diag['pickup']['zoneCode'] ) ) {
							echo '<br><span class="description">Zone ' . esc_html( $diag['pickup']['zoneCode'] ) . '</span>';
						}
						// A zone with no pin is dispatchable and not navigable.
						// Saying so here is the difference between a rider who
						// searches for the shop and one who rides into a field.
						if ( is_array( $diag['pickup'] ) && isset( $diag['pickup']['pinned'] ) && ! $diag['pickup']['pinned'] ) {
							echo '<br><span class="description" style="color:#996800">Zone centre, not a pin &mdash;'
								. ' the rider searches for the shop by name.</span>';
						}
						?>
					</td>
					<td>
						<?php Pokbon_Delivery_Admin::form_open( 'save_vendor_pickup' ); ?>
							<input type="hidden" name="vendor_id" value="<?php echo (int) $vendor_id; ?>">
							<p style="margin:0 0 .4em">
								<label style="display:inline-block;width:5em">Zone</label>
								<select name="zone_code">
									<option value="">&mdash; work it out from the pin &mdash;</option>
									<?php
									$chosen_zone = strtoupper( (string) ( $row['zoneCode'] ?? '' ) );
									foreach ( Pokbon_Delivery_Settings::active_zones() as $zone ) :
										?>
										<option value="<?php echo esc_attr( $zone['code'] ); ?>"
											<?php selected( $chosen_zone, strtoupper( (string) $zone['code'] ) ); ?>>
											<?php echo esc_html( $zone['name'] . ' (' . $zone['code'] . ')' ); ?>
										</option>
									<?php endforeach; ?>
								</select>
							</p>
							<p style="margin:0 0 .4em">
								<label style="display:inline-block;width:5em">Pin</label>
								<input type="text" name="pin" class="regular-text" style="width:22em"
									placeholder="5.6689, -0.1651 or a Google Maps link — optional"
									value="<?php echo $hasPin ? esc_attr( $row['lat'] . ', ' . $row['lng'] ) : ''; ?>">
							</p>
							<p style="margin:0 0 .4em">
								<label style="display:inline-block;width:5em">Address</label>
								<input type="text" name="address" class="regular-text" style="width:22em"
									placeholder="What the rider should read"
									value="<?php echo esc_attr( (string) ( $row['address'] ?? '' ) ); ?>">
							</p>
							<p style="margin:0 0 .4em">
								<label style="display:inline-block;width:5em">Phone</label>
								<input type="text" name="contact_phone" class="regular-text" style="width:12em"
									placeholder="Phone at the shop"
									value="<?php echo esc_attr( (string) ( $row['contactPhone'] ?? '' ) ); ?>">
							</p>
							<p style="margin:0">
								<button type="submit" class="button button-primary">Save</button>
								<?php if ( is_array( $row ) && $row !== [] ) : ?>
									<button type="submit" name="clear" value="1" class="button">Clear my overrides</button>
								<?php endif; ?>
							</p>
						</form>
						<p class="description" style="margin:.4em 0 0">
							<?php if ( $hint !== '' ) : ?>
								Blank means &ldquo;use theirs&rdquo;. They registered:
								<em><?php echo esc_html( $hint ); ?></em>
							<?php else : ?>
								Blank means &ldquo;use theirs&rdquo;.
							<?php endif; ?>
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
