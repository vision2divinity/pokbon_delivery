<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class Pokbon_Settings {
	private static $instance = null;
	const MENU_SLUG = 'pokbon-checkout';
	const CAP       = 'manage_woocommerce';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	public function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'POKBON Checkout', 'pokbon-checkout' ),
			__( 'POKBON Checkout', 'pokbon-checkout' ),
			self::CAP,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_region_rates', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_region_rates' ),
			'default'           => Pokbon_Installer::default_region_rates(),
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_pickup_address', array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_multiline' ),
			'default'           => '',
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_pickup_label', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Store Pickup (Free)',
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_home_delivery_label', array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Home Delivery',
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_payment_methods', array(
			'type'              => 'array',
			'sanitize_callback' => array( $this, 'sanitize_payment_methods' ),
			'default'           => array( 'paystack' => 1, 'cod' => 1 ),
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_free_threshold', array(
			'type'              => 'number',
			'sanitize_callback' => array( $this, 'sanitize_amount' ),
			'default'           => 0,
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_intro_text', array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => '',
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_terms_text', array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => '',
		) );
		register_setting( 'pokbon_checkout_settings', 'pokbon_checkout_ussd_instructions', array(
			'type'              => 'string',
			'sanitize_callback' => 'wp_kses_post',
			'default'           => '',
		) );
	}

	public function sanitize_region_rates( $input ) {
		$default = Pokbon_Installer::default_region_rates();
		if ( ! is_array( $input ) ) {
			return $default;
		}
		$out = array();
		foreach ( $default as $code => $row ) {
			$rate = isset( $input[ $code ]['rate'] ) ? (float) $input[ $code ]['rate'] : (float) $row['rate'];
			if ( $rate < 0 ) {
				$rate = 0;
			}
			$out[ $code ] = array(
				'name' => $row['name'],
				'rate' => round( $rate, 2 ),
			);
		}
		return $out;
	}

	public function sanitize_multiline( $value ) {
		$value = (string) $value;
		$value = wp_check_invalid_utf8( $value );
		$value = wp_kses( $value, array( 'br' => array() ) );
		return trim( $value );
	}

	public function sanitize_payment_methods( $input ) {
		return array(
			'paystack' => ! empty( $input['paystack'] ) ? 1 : 0,
			'cod'      => ! empty( $input['cod'] ) ? 1 : 0,
			'ussd'     => ! empty( $input['ussd'] ) ? 1 : 0,
		);
	}

	public function sanitize_amount( $value ) {
		$value = (float) $value;
		return $value < 0 ? 0 : round( $value, 2 );
	}

	public function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rates              = get_option( 'pokbon_checkout_region_rates', Pokbon_Installer::default_region_rates() );
		$pickup_address     = get_option( 'pokbon_checkout_pickup_address', '' );
		$pickup_label       = get_option( 'pokbon_checkout_pickup_label', 'Store Pickup (Free)' );
		$home_label         = get_option( 'pokbon_checkout_home_delivery_label', 'Home Delivery' );
		$payment_methods    = get_option( 'pokbon_checkout_payment_methods', array( 'paystack' => 1, 'cod' => 1 ) );
		$ussd_config        = get_option( 'pokbon_app_ussd_payment', array() );
		$ussd_code          = is_array( $ussd_config ) ? (string) ( $ussd_config['code'] ?? '' ) : '';
		$ussd_merchant      = is_array( $ussd_config ) ? (string) ( $ussd_config['merchantName'] ?? '' ) : '';
		$free_threshold     = get_option( 'pokbon_checkout_free_threshold', 0 );
		$intro_text         = get_option( 'pokbon_checkout_intro_text', '' );
		$terms_text         = get_option( 'pokbon_checkout_terms_text', '' );
		$ussd_instructions  = get_option( 'pokbon_checkout_ussd_instructions', '' );
		$shortcode          = '[pokbon_checkout]';

		?>
		<div class="wrap pokbon-settings">
			<h1><?php esc_html_e( 'POKBON Checkout Settings', 'pokbon-checkout' ); ?></h1>
			<p><?php esc_html_e( 'A custom server-rendered checkout that bypasses the WooCommerce Store API. Place this shortcode on your checkout page:', 'pokbon-checkout' ); ?></p>
			<p><code style="font-size:14px;background:#f0f0f1;padding:6px 10px;border-radius:4px;"><?php echo esc_html( $shortcode ); ?></code></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'pokbon_checkout_settings' ); ?>

				<h2 class="title"><?php esc_html_e( 'Pickup Location', 'pokbon-checkout' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pickup_label"><?php esc_html_e( 'Pickup option label', 'pokbon-checkout' ); ?></label></th>
						<td><input name="pokbon_checkout_pickup_label" id="pickup_label" type="text" value="<?php echo esc_attr( $pickup_label ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="pickup_address"><?php esc_html_e( 'Pickup address & instructions', 'pokbon-checkout' ); ?></label></th>
						<td>
							<textarea name="pokbon_checkout_pickup_address" id="pickup_address" rows="5" class="large-text"><?php echo esc_textarea( $pickup_address ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown to customers who choose Pickup. Plain text. Line breaks are preserved.', 'pokbon-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Home Delivery Rates by Region (GHS)', 'pokbon-checkout' ); ?></h2>
				<p class="description"><?php esc_html_e( 'These rates apply when the customer chooses Home Delivery. Edit any rate below.', 'pokbon-checkout' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="home_label"><?php esc_html_e( 'Home Delivery option label', 'pokbon-checkout' ); ?></label></th>
						<td><input name="pokbon_checkout_home_delivery_label" id="home_label" type="text" value="<?php echo esc_attr( $home_label ); ?>" class="regular-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="free_threshold"><?php esc_html_e( 'Free home delivery when subtotal ≥', 'pokbon-checkout' ); ?></label></th>
						<td>
							<input name="pokbon_checkout_free_threshold" id="free_threshold" type="number" min="0" step="0.01" value="<?php echo esc_attr( $free_threshold ); ?>" class="small-text" /> GHS
							<p class="description"><?php esc_html_e( 'Set to 0 to disable.', 'pokbon-checkout' ); ?></p>
						</td>
					</tr>
				</table>
				<table class="widefat striped" style="max-width:560px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Region', 'pokbon-checkout' ); ?></th>
							<th><?php esc_html_e( 'Rate (GHS)', 'pokbon-checkout' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( Pokbon_Installer::default_region_rates() as $code => $row ) :
							$value = isset( $rates[ $code ]['rate'] ) ? $rates[ $code ]['rate'] : $row['rate']; ?>
							<tr>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td>
									<input type="number" min="0" step="0.01"
										name="pokbon_checkout_region_rates[<?php echo esc_attr( $code ); ?>][rate]"
										value="<?php echo esc_attr( $value ); ?>"
										class="small-text" />
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2 class="title" style="margin-top:30px;"><?php esc_html_e( 'Payment Methods', 'pokbon-checkout' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Available payment methods', 'pokbon-checkout' ); ?></th>
						<td>
							<label><input type="checkbox" name="pokbon_checkout_payment_methods[paystack]" value="1" <?php checked( ! empty( $payment_methods['paystack'] ) ); ?> /> <?php esc_html_e( 'Paystack (cards, mobile money, bank)', 'pokbon-checkout' ); ?></label><br />
							<label><input type="checkbox" name="pokbon_checkout_payment_methods[cod]" value="1" <?php checked( ! empty( $payment_methods['cod'] ) ); ?> /> <?php esc_html_e( 'Cash on Delivery', 'pokbon-checkout' ); ?></label><br />
							<label><input type="checkbox" name="pokbon_checkout_payment_methods[ussd]" value="1" <?php checked( ! empty( $payment_methods['ussd'] ) ); ?> <?php disabled( '' === $ussd_code ); ?> /> <?php esc_html_e( 'Pay by USSD', 'pokbon-checkout' ); ?></label>
							<p class="description"><?php esc_html_e( 'Paystack must also be configured at WooCommerce → Settings → Payments.', 'pokbon-checkout' ); ?></p>
							<p class="description">
								<?php if ( '' !== $ussd_code ) : ?>
									<?php printf(
										/* translators: 1: USSD dial code, 2: merchant name */
										esc_html__( 'USSD dial code and merchant name are shared with the POKBON mobile app: %1$s → %2$s. Configure them in the mobile app\'s admin settings — there is no separate web setting.', 'pokbon-checkout' ),
										'<code>' . esc_html( $ussd_code ) . '</code>',
										'<strong>' . esc_html( $ussd_merchant ?: __( '(merchant name not set)', 'pokbon-checkout' ) ) . '</strong>'
									); ?>
								<?php else : ?>
									<?php esc_html_e( 'USSD is unavailable until the mobile app\'s "pokbon_app_ussd_payment" option has a dial code configured. The checkbox above is disabled until then.', 'pokbon-checkout' ); ?>
								<?php endif; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ussd_instructions"><?php esc_html_e( 'USSD payment instructions (optional)', 'pokbon-checkout' ); ?></label></th>
						<td>
							<textarea name="pokbon_checkout_ussd_instructions" id="ussd_instructions" rows="4" class="large-text"><?php echo esc_textarea( $ussd_instructions ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Shown in a popup when the customer selects Pay by USSD. Leave blank for the default. You can use the placeholders {code} and {merchant}.', 'pokbon-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Page Copy', 'pokbon-checkout' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="intro_text"><?php esc_html_e( 'Top of page intro (optional)', 'pokbon-checkout' ); ?></label></th>
						<td>
							<?php wp_editor( $intro_text, 'intro_text', array(
								'textarea_name' => 'pokbon_checkout_intro_text',
								'media_buttons' => false,
								'textarea_rows' => 4,
								'teeny'         => true,
							) ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="terms_text"><?php esc_html_e( 'Terms agreement notice', 'pokbon-checkout' ); ?></label></th>
						<td>
							<?php wp_editor( $terms_text, 'terms_text', array(
								'textarea_name' => 'pokbon_checkout_terms_text',
								'media_buttons' => false,
								'textarea_rows' => 3,
								'teeny'         => true,
							) ); ?>
							<p class="description"><?php esc_html_e( 'Shown above the Place Order button. Use HTML to insert links.', 'pokbon-checkout' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Audit Log', 'pokbon-checkout' ); ?></h2>
				<p>
					<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=pokbon-checkout-log' ) ); ?>"><?php esc_html_e( 'View checkout audit log', 'pokbon-checkout' ); ?></a>
				</p>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
