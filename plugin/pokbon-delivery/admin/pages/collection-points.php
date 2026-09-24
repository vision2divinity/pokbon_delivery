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
		Where a rider goes to collect each vendor&rsquo;s parcels. Paste a pin from Google Maps &mdash;
		either the <code>5.6689, -0.1651</code> that appears when you long-press a spot, or the whole
		link from the address bar. Both work.
	</p>
	<p class="description" style="max-width:62em">
		<strong>A vendor with no pin anywhere is collected from your default collection point</strong>,
		which means a rider is sent to your shop for their goods. That still happens rather than
		blocking the order, and the order gets a note saying so &mdash; but the rider has already set
		off by then, so it is worth settling here first.
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
						?>
					</td>
					<td>
						<?php Pokbon_Delivery_Admin::form_open( 'save_vendor_pickup' ); ?>
							<input type="hidden" name="vendor_id" value="<?php echo (int) $vendor_id; ?>">
							<p style="margin:0 0 .4em">
								<input type="text" name="pin" class="regular-text" style="width:24em"
									placeholder="5.6689, -0.1651 or a Google Maps link"
									value="<?php echo $hasPin ? esc_attr( $row['lat'] . ', ' . $row['lng'] ) : ''; ?>">
							</p>
							<p style="margin:0 0 .4em">
								<input type="text" name="address" class="regular-text" style="width:24em"
									placeholder="What the rider should read"
									value="<?php echo esc_attr( (string) ( $row['address'] ?? $hint ) ); ?>">
							</p>
							<p style="margin:0 0 .4em">
								<input type="text" name="contact_phone" class="regular-text" style="width:12em"
									placeholder="Phone at the shop"
									value="<?php echo esc_attr( (string) ( $row['contactPhone'] ?? get_user_meta( $vendor_id, 'billing_phone', true ) ) ); ?>">
								<button type="submit" class="button button-primary">Save</button>
								<?php if ( $hasPin ) : ?>
									<button type="submit" name="clear" value="1" class="button">Clear</button>
								<?php endif; ?>
							</p>
						</form>
						<p class="description" style="margin:0">
							A phone is required &mdash; a collection point a rider cannot ring is one they
							cannot use when the shutter is down.
						</p>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

	<?php endif; ?>
</div>
