<?php
/**
 * Brand and app control. The backend-first screen.
 *
 * Everything the rider app renders — colours, every word, which features
 * exist, what the home screen shows — is edited here and picked up on the next
 * launch. No release, no store review, no waiting for riders to update.
 *
 * The form is generated from `Pokbon_Delivery_App_Config::defaults()` rather
 * than hand-written, which matters more than it looks: a new setting added to
 * that file appears here automatically, so the control surface can never drift
 * behind what the app actually reads.
 *
 * Only differences from the default are stored. An owner who changes one
 * button keeps every later improvement to everything else.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$defaults  = Pokbon_Delivery_App_Config::defaults();
$effective = Pokbon_Delivery_App_Config::build();
$overrides = get_option( Pokbon_Delivery_App_Config::OPT_OVERRIDES, [] );
$overrides = is_array( $overrides ) ? $overrides : [];

/** Human labels for the top-level groups, in the order they should appear. */
$sections = [
	'brand'     => [ 'Brand', 'Names and the numbers a rider is told to call.' ],
	'theme'     => [ 'Theme', 'One palette, shared with the marketplace app. The contrast notes in the code are from that app\'s accessibility audit — <code>primary</code> is a fill colour and fails as text, which is why <code>primaryText</code> is darker. Changing a token here changes the rider app without a release.' ],
	'copy'      => [ 'Words riders read', 'Every label, hint and warning. The payment and code wording is the difference between a rider handing goods over correctly and handing them over too early, so it is worth reading aloud before you save.' ],
	'features'  => [ 'Features', 'Whole screens and behaviours, on or off. A feature that confuses riders can be withdrawn the same day.' ],
	'riderHome' => [ 'Rider home screen', 'Rendered in this order. The app ignores anything it does not recognise, so a card added here is safe on older builds.' ],
];

/** Render one leaf as the right kind of input. */
function pokbon_delivery_field( string $name, $value, $default ): void {
	$is_colour = is_string( $default ) && preg_match( '/^#[0-9a-fA-F]{6}$/', $default );
	$is_bool   = is_bool( $default );
	$is_long   = is_string( $default ) && strlen( $default ) > 60;
	$changed   = $value !== $default;

	if ( $is_bool ) {
		printf(
			'<label><input type="hidden" name="%1$s" value="0"><input type="checkbox" name="%1$s" value="1" %2$s> on</label>',
			esc_attr( $name ),
			checked( (bool) $value, true, false )
		);
		return;
	}

	if ( $is_colour ) {
		printf(
			'<input type="color" name="%1$s" value="%2$s" style="width:3.5em;height:2em;vertical-align:middle">'
			. '<input type="text" value="%2$s" readonly style="width:8em;margin-left:.5em" '
			. 'onfocus="this.previousElementSibling.click()">',
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	} elseif ( $is_long ) {
		printf(
			'<textarea name="%s" rows="3" style="width:100%%;max-width:46em">%s</textarea>',
			esc_attr( $name ),
			esc_textarea( (string) $value )
		);
	} else {
		printf(
			'<input type="text" name="%s" value="%s" class="regular-text" style="max-width:46em">',
			esc_attr( $name ),
			esc_attr( (string) $value )
		);
	}

	if ( $changed ) {
		echo ' <span class="description" style="color:#996800">changed</span>';
	}
}

/** Walk a branch, emitting a row per leaf. */
function pokbon_delivery_branch( array $values, array $defaults, array $path ): void {
	foreach ( $defaults as $key => $default ) {
		$value = $values[ $key ] ?? $default;
		$next  = array_merge( $path, [ $key ] );
		$name  = 'cfg';
		foreach ( $next as $segment ) {
			$name .= '[' . $segment . ']';
		}

		// A list of cards (riderHome) is edited as JSON: it is structure, not
		// a value, and pretending otherwise with thirty little boxes would be
		// harder to get right than reading the shape directly.
		if ( is_array( $default ) && Pokbon_Delivery_App_Config::is_list( $default ) ) {
			printf(
				'<tr><th scope="row">%s</th><td><textarea name="%s" rows="8" style="width:100%%;max-width:46em;font-family:monospace">%s</textarea>'
				. '<p class="description">A list, edited as JSON. Leave it alone unless you know the shape.</p></td></tr>',
				esc_html( $key ),
				esc_attr( $name . '[__json]' ),
				esc_textarea( wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
			);
			continue;
		}

		if ( is_array( $default ) ) {
			printf(
				'<tr><th colspan="2" style="padding-top:1.5em"><h4 style="margin:0">%s</h4></th></tr>',
				esc_html( ucfirst( str_replace( '_', ' ', (string) $key ) ) )
			);
			pokbon_delivery_branch( is_array( $value ) ? $value : [], $default, $next );
			continue;
		}

		echo '<tr><th scope="row" style="font-weight:400">' . esc_html( $key ) . '</th><td>';
		pokbon_delivery_field( $name, $value, $default );
		echo '</td></tr>';
	}
}
?>
<div class="wrap">
	<h1>Brand &amp; app</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<p class="description" style="max-width:56em">
		Everything on this page is read by the rider app when it launches. Change a colour or a
		sentence here and riders see it on their next open — no app release, no store review, no
		waiting for people to update. Only what you change is stored, so later improvements to
		everything else still reach you.
	</p>

	<p>
		<strong>The app reads:</strong>
		<code><?php echo esc_html( rest_url( POKBON_DELIVERY_REST_NAMESPACE . '/delivery/app-config' ) ); ?></code>
		&nbsp;·&nbsp; version <?php echo (int) ( $effective['version'] ?? 1 ); ?>
		<?php if ( ! empty( $overrides ) ) : ?>
			&nbsp;·&nbsp; <?php echo count( $overrides, COUNT_RECURSIVE ); ?> value(s) overridden
		<?php endif; ?>
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_app_config' ); ?>

	<?php foreach ( $sections as $key => [ $title, $blurb ] ) : ?>
		<h2><?php echo esc_html( $title ); ?></h2>
		<p class="description" style="max-width:56em"><?php echo wp_kses_post( $blurb ); ?></p>
		<table class="form-table" role="presentation">
			<?php
			if ( isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) && Pokbon_Delivery_App_Config::is_list( $defaults[ $key ] ) ) {
				printf(
					'<tr><th scope="row">%s</th><td><textarea name="cfg[%s][__json]" rows="10" style="width:100%%;max-width:46em;font-family:monospace">%s</textarea></td></tr>',
					esc_html( $title ),
					esc_attr( $key ),
					esc_textarea( wp_json_encode( $effective[ $key ] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) )
				);
			} else {
				pokbon_delivery_branch(
					is_array( $effective[ $key ] ?? null ) ? $effective[ $key ] : [],
					is_array( $defaults[ $key ] ?? null ) ? $defaults[ $key ] : [],
					[ $key ]
				);
			}
			?>
		</table>
		<hr>
	<?php endforeach; ?>

	<p>
		<button type="submit" class="button button-primary">Save and publish to the app</button>
		<span class="description" style="margin-left:1em">
			Riders pick this up on their next launch, within five minutes at most.
		</span>
	</p>
	</form>

	<h2>Start again</h2>
	<p class="description" style="max-width:56em">
		Clears every override and returns the app to the shipped defaults. Nothing else is touched:
		zones, prices, riders and jobs are untouched by this page.
	</p>
	<?php echo Pokbon_Delivery_Admin::button( 'reset_app_config', 'Reset every value to default' ); ?>
</div>
