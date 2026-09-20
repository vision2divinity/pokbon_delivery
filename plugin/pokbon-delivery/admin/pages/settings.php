<?php
/**
 * Settings. PRD § 12b — nothing on this page may be a constant in code.
 *
 * Three groups: the connection to the Delivery API, the operating numbers, and
 * the rider commission schedule. The schedule is the one the owner cares most
 * about: riders pay nothing for about six months, then it phases in, and that
 * must happen by adding a dated row here rather than by shipping a release.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$settings   = Pokbon_Delivery_Settings::all();
$api        = Pokbon_Delivery_Settings::api();
$has_secret = Pokbon_Delivery_Settings::shared_secret() !== '';
$schedule   = (array) $settings['rider_commission_schedule'];
$zones      = Pokbon_Delivery_Settings::active_zones();
$last_sync  = Pokbon_Delivery_Settings::last_sync();
?>
<div class="wrap">
	<h1>Delivery settings</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<h2>Connection</h2>
	<p class="description" style="max-width:52em">
		The Delivery API holds riders, jobs and the delivery code. This plugin holds the money,
		the messages and everything on this page. They authenticate to each other with one
		shared secret, so treat it like a password.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_api' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="pkbd-base">API address</label></th>
			<td>
				<input id="pkbd-base" name="base_url" type="url" class="regular-text code"
					value="<?php echo esc_attr( $api['base_url'] ?? '' ); ?>"
					placeholder="https://delivery.pokbongroup.com">
				<p class="description">No trailing slash. Must be https once it is off your own machine.</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-secret">Shared secret</label></th>
			<td>
				<input id="pkbd-secret" name="secret" type="password" class="regular-text code"
					autocomplete="new-password"
					placeholder="<?php echo $has_secret ? 'Set — leave blank to keep it' : 'Not set'; ?>">
				<p class="description">
					At least 32 characters, and identical to <code>PLUGIN_SHARED_SECRET</code> in the API's
					environment. Leave blank to keep the current one. Saving a new value keeps the old one
					working for a short window so nothing breaks mid-delivery.
				</p>
			</td>
		</tr>
	</table>
	<p>
		<button type="submit" class="button button-primary">Save connection</button>
		<?php echo Pokbon_Delivery_Admin::button( 'test_api', 'Test the connection' ); ?>
		<?php echo Pokbon_Delivery_Admin::button( 'push_sync', 'Push zones, prices and settings' ); ?>
	</p>
	</form>

	<?php if ( ! empty( $last_sync['synced_at'] ) ) : ?>
		<p class="description">
			Last successful push: <?php echo esc_html( $last_sync['synced_at'] ); ?>
			(version <?php echo (int) Pokbon_Delivery_Settings::sync_version(); ?>).
		</p>
	<?php endif; ?>

	<hr>

	<h2>Rider commission</h2>
	<p class="description" style="max-width:52em">
		<strong>Empty means riders pay nothing</strong>, which is the launch position. Add a row with the
		date it starts to phase commission in — the delivery service reads the schedule, so no release is
		needed. Each job records the rate in force when it was created, and riders are shown this schedule
		when they enrol, so a later increase is something they agreed to rather than a surprise.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_commission' ); ?>
	<table class="widefat striped" style="max-width:40em">
		<thead><tr><th>Effective from</th><th>Percentage of the rider fee</th></tr></thead>
		<tbody>
		<?php
		$rows = $schedule;
		$rows[] = [ 'effectiveFrom' => '', 'rateBps' => 0 ];
		$rows[] = [ 'effectiveFrom' => '', 'rateBps' => 0 ];
		foreach ( $rows as $row ) :
			?>
			<tr>
				<td><input type="date" name="commission_from[]" value="<?php echo esc_attr( $row['effectiveFrom'] ); ?>"></td>
				<td>
					<input type="number" step="0.01" min="0" max="100" style="width:8em"
						name="commission_pct[]"
						value="<?php echo esc_attr( $row['rateBps'] ? number_format( $row['rateBps'] / 100, 2, '.', '' ) : '' ); ?>"> %
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<p>
		<button type="submit" class="button">Save the schedule</button>
		<span class="description" style="margin-left:1em">
			Clearing every row returns riders to paying nothing.
		</span>
	</p>
	</form>

	<hr>

	<h2>Operating numbers</h2>

	<?php Pokbon_Delivery_Admin::form_open( 'save_settings' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">Automatic dispatch</th>
			<td>
				<label>
					<input name="auto_create_jobs" type="checkbox" value="1"
						<?php checked( ! empty( $settings['auto_create_jobs'] ) ); ?>>
					Create a delivery job when an order reaches processing
				</label>
				<p class="description">
					Leave this off until the zones and the matrix are set. While it is off you can still
					dispatch any order by hand from the job board, which is how phase 0 is meant to run.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Wording at checkout</th>
			<td>
				<label>
					<input name="rename_cod_label" type="checkbox" value="1"
						<?php checked( ! empty( $settings['rename_cod_label'] ) ); ?>>
					Call it &ldquo;Pay on delivery&rdquo; instead of &ldquo;Cash on delivery&rdquo;
				</label>
				<p class="description">
					<strong>Turn this on the day the first rider goes out, not before.</strong>
					Until riders exist there really is cash, and this wording would be a lie to every buyer.
					After it, a buyer who chose &ldquo;cash&rdquo; and is asked for a mobile-money PIN at their own
					front door is the most likely argument this product will cause.
				</p>
				<p class="description">
					This covers the website checkout, every order email and the admin.
					<strong>The mobile app carries its own copy and needs a release:</strong>
					<?php echo esc_html( implode( ' and ', Pokbon_Delivery_Labels::app_files() ) ); ?>.
					Ship that in the same week.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-pickup-zone">Default pickup</label></th>
			<td>
				<select id="pkbd-pickup-zone" name="default_pickup_zone">
					<option value="">— none —</option>
					<?php foreach ( $zones as $zone ) : ?>
						<option value="<?php echo esc_attr( $zone['code'] ); ?>"
							<?php selected( $settings['default_pickup_zone'], $zone['code'] ); ?>>
							<?php echo esc_html( $zone['name'] ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p style="margin-top:.5em">
					<input name="default_pickup_address" type="text" class="regular-text"
						placeholder="Collection address"
						value="<?php echo esc_attr( $settings['default_pickup_address'] ); ?>">
				</p>
				<p>
					<input name="default_pickup_contact" type="text" placeholder="Contact name"
						value="<?php echo esc_attr( $settings['default_pickup_contact'] ); ?>">
					<input name="default_pickup_phone" type="text" placeholder="0244 000 000"
						value="<?php echo esc_attr( $settings['default_pickup_phone'] ); ?>">
				</p>
				<p class="description">
					Where a rider collects when the vendor has no location of its own. The rider is shown
					this address and phone number.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Offers</th>
			<td>
				<label>Seconds to answer
					<input name="offer_timeout_seconds" type="number" min="10" style="width:7em"
						value="<?php echo esc_attr( $settings['offer_timeout_seconds'] ); ?>"></label>
				<label style="margin-left:1em">Riders tried
					<input name="offer_cascade_depth" type="number" min="1" style="width:6em"
						value="<?php echo esc_attr( $settings['offer_cascade_depth'] ); ?>"></label>
				<label style="margin-left:1em">Within
					<input name="offer_radius_metres" type="number" min="500" step="500" style="width:8em"
						value="<?php echo esc_attr( $settings['offer_radius_metres'] ); ?>">m of pickup</label>
			</td>
		</tr>
		<tr>
			<th scope="row">The delivery code</th>
			<td>
				<label>Valid for
					<input name="code_expiry_minutes" type="number" min="5" style="width:7em"
						value="<?php echo esc_attr( $settings['code_expiry_minutes'] ); ?>"> minutes</label>
				<label style="margin-left:1em">Attempts
					<input name="code_max_attempts" type="number" min="1" style="width:6em"
						value="<?php echo esc_attr( $settings['code_max_attempts'] ); ?>"></label>
				<label style="margin-left:1em">Sends per job
					<input name="code_max_sends" type="number" min="1" style="width:6em"
						value="<?php echo esc_attr( $settings['code_max_sends'] ); ?>"></label>
				<p class="description">
					The code goes to the buyer by SMS. <strong>No rider ever sees it</strong>, here or anywhere.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Payment at the door</th>
			<td>
				<label>Wait up to
					<input name="payment_wait_minutes" type="number" min="1" style="width:6em"
						value="<?php echo esc_attr( $settings['payment_wait_minutes'] ); ?>"> minutes</label>
				<label style="margin-left:1em">Prompts per job
					<input name="payment_max_prompts" type="number" min="1" style="width:6em"
						value="<?php echo esc_attr( $settings['payment_max_prompts'] ); ?>"></label>
				<p class="description">
					A rider waiting unpaid is losing money, so the wait is bounded. The buyer can never be
					charged twice: every retry checks the previous attempt with Paystack first.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Failed trip uplift</th>
			<td>
				<input name="failed_trip_uplift_pct" type="number" step="0.01" min="0" style="width:8em"
					value="<?php echo esc_attr( number_format( ( $settings['failed_trip_uplift']['rateBps'] ?? 0 ) / 100, 2, '.', '' ) ); ?>"> %
				<p class="description">
					A rider who delivers, is refused, and rides back has earned nothing. This much extra is
					added to their next completed delivery, and they are shown why.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">Default markup</th>
			<td>
				<input name="default_markup_pct" type="number" step="0.01" min="0" style="width:8em"
					value="<?php echo esc_attr( number_format( ( $settings['default_markup']['rateBps'] ?? 0 ) / 100, 2, '.', '' ) ); ?>"> %
				<p class="description">
					Fills the buyer price on the matrix when you enter only a rider fee. 33.33% turns
					GH₵30 into GH₵40. Any cell can be overridden by hand.
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="pkbd-payout">Rider payouts</label></th>
			<td>
				<select id="pkbd-payout" name="payout_cycle">
					<?php foreach ( [ 'daily' => 'Daily', 'weekly' => 'Weekly', 'fortnightly' => 'Fortnightly' ] as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>"
							<?php selected( $settings['payout_cycle'], $value ); ?>>
							<?php echo esc_html( $label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<p class="description">Fast, predictable payout is the strongest reason a rider stays.</p>
			</td>
		</tr>
	</table>
	<p><button type="submit" class="button button-primary">Save settings</button></p>
	</form>
</div>
