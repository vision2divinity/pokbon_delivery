<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/**
 * @var array $flash  ['errors' => [...], 'old' => [...]]
 */
$old             = $flash['old'] ?? array();
$errors          = $flash['errors'] ?? array();
$cart            = WC()->cart;
$subtotal        = (float) $cart->get_subtotal();
$currency        = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : '₵';
$intro_text      = (string) get_option( 'pokbon_checkout_intro_text', '' );
$terms_text      = (string) get_option( 'pokbon_checkout_terms_text', '' );
$pickup_address  = (string) get_option( 'pokbon_checkout_pickup_address', '' );
$pickup_label    = (string) get_option( 'pokbon_checkout_pickup_label', 'Store Pickup (Free)' );
$home_label      = (string) get_option( 'pokbon_checkout_home_delivery_label', 'Home Delivery' );
$payment_methods = (array) get_option( 'pokbon_checkout_payment_methods', array( 'paystack' => 1, 'cod' => 1 ) );
$ussd_config     = get_option( 'pokbon_app_ussd_payment', array() );
$ussd_code       = is_array( $ussd_config ) ? (string) ( $ussd_config['code'] ?? '' ) : '';
$ussd_merchant   = is_array( $ussd_config ) ? (string) ( $ussd_config['merchantName'] ?? '' ) : '';
$ussd_available  = '' !== $ussd_code;
$regions         = Pokbon_Shipping::regions_for_select();
$selected_type   = $old['delivery_type'] ?? 'home';
$selected_region = $old['shipping_state'] ?? Pokbon_Form::old( 'billing_state', 'AA', $old );
$selected_pay    = $old['payment_method'] ?? ( ! empty( $payment_methods['paystack'] ) ? 'paystack' : 'cod' );
$action_url      = esc_url( admin_url( 'admin-post.php' ) );
$is_logged_in    = is_user_logged_in();
$account_url     = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '';
?>
<div class="pokbon-checkout" data-subtotal="<?php echo esc_attr( $subtotal ); ?>">

	<ol class="pokbon-progress" aria-label="<?php esc_attr_e( 'Checkout progress', 'pokbon-checkout' ); ?>">
		<li class="is-done"><span class="pkb-step">✓</span><?php esc_html_e( 'Cart', 'pokbon-checkout' ); ?></li>
		<li class="is-current"><span class="pkb-step">2</span><?php esc_html_e( 'Shipping & Payment', 'pokbon-checkout' ); ?></li>
		<li><span class="pkb-step">3</span><?php esc_html_e( 'Confirmation', 'pokbon-checkout' ); ?></li>
	</ol>

	<?php if ( ! $is_logged_in && $account_url ) : ?>
		<div class="pokbon-topbar">
			<span><?php esc_html_e( 'Returning customer?', 'pokbon-checkout' ); ?></span>
			<a href="<?php echo esc_url( $account_url ); ?>"><?php esc_html_e( 'Click here to log in', 'pokbon-checkout' ); ?></a>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $intro_text ) ) : ?>
		<div class="pokbon-checkout__intro"><?php echo wp_kses_post( $intro_text ); ?></div>
	<?php endif; ?>

	<?php if ( ! empty( $errors ) ) : ?>
		<div class="pokbon-checkout__errors" role="alert" aria-live="assertive">
			<strong><?php esc_html_e( 'Please fix the following:', 'pokbon-checkout' ); ?></strong>
			<ul>
				<?php foreach ( $errors as $err ) : ?>
					<li><?php echo esc_html( $err ); ?></li>
				<?php endforeach; ?>
			</ul>
		</div>
	<?php endif; ?>

	<form method="post" action="<?php echo $action_url; ?>" class="pokbon-checkout__form" id="pokbon-checkout-form" autocomplete="on" novalidate>
		<input type="hidden" name="action" value="pokbon_checkout_place_order" />
		<?php wp_nonce_field( Pokbon_Handler::ACTION, Pokbon_Form::NONCE_NAME ); ?>
		<div style="position:absolute;left:-10000px;top:auto;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
			<label for="<?php echo esc_attr( Pokbon_Form::HONEYPOT ); ?>">Leave this blank</label>
			<input tabindex="-1" autocomplete="off" type="text" name="<?php echo esc_attr( Pokbon_Form::HONEYPOT ); ?>" id="<?php echo esc_attr( Pokbon_Form::HONEYPOT ); ?>" value="" />
		</div>

		<div class="pokbon-checkout__grid">
			<div class="pokbon-checkout__main">

				<section class="pokbon-section">
					<h2><?php esc_html_e( 'Contact', 'pokbon-checkout' ); ?></h2>
					<div class="pokbon-field">
						<label for="billing_email"><?php esc_html_e( 'Email address', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
						<input type="email" id="billing_email" name="billing_email" required autocomplete="email"
							value="<?php echo esc_attr( Pokbon_Form::old( 'billing_email', wp_get_current_user()->user_email ?? '', $old ) ); ?>" />
					</div>
				</section>

				<section class="pokbon-section">
					<h2><?php esc_html_e( 'Delivery method', 'pokbon-checkout' ); ?></h2>
					<div class="pokbon-radio-group">
						<label class="pokbon-radio-card">
							<input type="radio" name="delivery_type" value="home" <?php checked( $selected_type, 'home' ); ?> />
							<span class="pokbon-radio-card__title"><?php echo esc_html( $home_label ); ?></span>
							<span class="pokbon-radio-card__desc"><?php esc_html_e( 'Delivered to your address. Rate depends on your region.', 'pokbon-checkout' ); ?></span>
						</label>
						<label class="pokbon-radio-card">
							<input type="radio" name="delivery_type" value="pickup" <?php checked( $selected_type, 'pickup' ); ?> />
							<span class="pokbon-radio-card__title"><?php echo esc_html( $pickup_label ); ?></span>
							<span class="pokbon-radio-card__desc"><?php esc_html_e( 'Pick your order up from our store. No delivery fee.', 'pokbon-checkout' ); ?></span>
						</label>
					</div>
				</section>

				<section class="pokbon-section pokbon-pickup-info" id="pokbon-pickup-info" hidden>
					<h2><?php esc_html_e( 'Pickup location', 'pokbon-checkout' ); ?></h2>
					<div class="pokbon-pickup-address"><?php echo nl2br( esc_html( $pickup_address ) ); ?></div>
				</section>

				<section class="pokbon-section pokbon-shipping-address" id="pokbon-shipping-address">
					<h2><?php esc_html_e( 'Shipping details', 'pokbon-checkout' ); ?></h2>

					<div class="pokbon-row">
						<div class="pokbon-field">
							<label for="shipping_first_name"><?php esc_html_e( 'First name', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
							<input type="text" id="shipping_first_name" name="shipping_first_name" required autocomplete="given-name"
								value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_first_name', Pokbon_Form::old( 'billing_first_name', '', $old ), $old ) ); ?>" />
						</div>
						<div class="pokbon-field">
							<label for="shipping_last_name"><?php esc_html_e( 'Last name', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
							<input type="text" id="shipping_last_name" name="shipping_last_name" required autocomplete="family-name"
								value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_last_name', Pokbon_Form::old( 'billing_last_name', '', $old ), $old ) ); ?>" />
						</div>
					</div>

					<div class="pokbon-field">
						<label for="shipping_phone"><?php esc_html_e( 'Mobile phone', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
						<input type="tel" id="shipping_phone" name="shipping_phone" required autocomplete="tel" inputmode="tel"
							placeholder="+233 5X XXX XXXX"
							value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_phone', Pokbon_Form::old( 'billing_phone', '', $old ), $old ) ); ?>" />
					</div>

					<div class="pokbon-field">
						<label for="shipping_address_1"><?php esc_html_e( 'Street address', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
						<input type="text" id="shipping_address_1" name="shipping_address_1" required autocomplete="address-line1"
							placeholder="<?php esc_attr_e( 'House number and street name', 'pokbon-checkout' ); ?>"
							value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_address_1', Pokbon_Form::old( 'billing_address_1', '', $old ), $old ) ); ?>" />
					</div>

					<div class="pokbon-field">
						<label for="shipping_address_2"><?php esc_html_e( 'Apartment or suite (optional)', 'pokbon-checkout' ); ?></label>
						<input type="text" id="shipping_address_2" name="shipping_address_2" autocomplete="address-line2"
							value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_address_2', Pokbon_Form::old( 'billing_address_2', '', $old ), $old ) ); ?>" />
					</div>

					<?php
					/*
					 * The landmark, asked for on its own.
					 *
					 * It used to share a box with "apartment, suite" — three
					 * questions in one field — so people put a suburb in it, or
					 * nothing, and whatever they typed was swallowed into the
					 * address line. Asked plainly, it is the single most useful
					 * thing on a Ghanaian delivery: the rider's app searches for
					 * it BEFORE the address when an order has no map pin,
					 * because "opposite Melcom, Sowutuom" is a place a maps app
					 * knows and "Planet Close 44" frequently is not.
					 *
					 * Optional on purpose. A required field here would be one
					 * more thing between a buyer and a completed order, and the
					 * ones who know their area will fill it in.
					 */
					?>
					<div class="pokbon-field">
						<label for="pokbon_landmark"><?php esc_html_e( 'Nearest landmark (optional)', 'pokbon-checkout' ); ?></label>
						<input type="text" id="pokbon_landmark" name="pokbon_landmark" maxlength="160"
							placeholder="<?php esc_attr_e( 'e.g. opposite Melcom, behind the Shell filling station', 'pokbon-checkout' ); ?>"
							value="<?php echo esc_attr( Pokbon_Form::old( 'pokbon_landmark', '', $old ) ); ?>" />
						<p class="pokbon-hint">
							<?php esc_html_e( 'What would you tell a stranger to look for? This is what the rider searches for.', 'pokbon-checkout' ); ?>
						</p>
					</div>

					<div class="pokbon-row">
						<div class="pokbon-field">
							<label for="shipping_city"><?php esc_html_e( 'City / town', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
							<input type="text" id="shipping_city" name="shipping_city" required autocomplete="address-level2"
								value="<?php echo esc_attr( Pokbon_Form::old( 'shipping_city', Pokbon_Form::old( 'billing_city', '', $old ), $old ) ); ?>" />
						</div>
						<div class="pokbon-field">
							<label for="shipping_state"><?php esc_html_e( 'Region', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
							<select id="shipping_state" name="shipping_state" required autocomplete="address-level1">
								<?php foreach ( $regions as $code => $name ) : ?>
									<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected_region, $code ); ?>><?php echo esc_html( $name ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php
						/*
						 * The areas, for EVERY region, handed to the browser.
						 *
						 * This used to render one region's areas — the region the
						 * page happened to load with — and nothing re-ran when the
						 * buyer changed region. So choosing Ho still showed Accra's
						 * environs and their Accra prices, on a form that looked
						 * entirely normal. The mobile app was right the whole time
						 * because it holds every area and filters on the device;
						 * this now does the same.
						 *
						 * One filter call prices the basket once for all regions.
						 * Labels are built here so currency formatting stays in PHP
						 * rather than being reimplemented in JavaScript.
						 */
						$pokbon_regions   = array_keys( $regions );
						$pokbon_by_region = apply_filters( 'pokbon_delivery_zone_areas_by_region', array(), $pokbon_regions, null );
						$pokbon_gate      = (bool) apply_filters( 'pokbon_delivery_coverage_required', false );
						$chosen_zone      = strtoupper( (string) Pokbon_Form::old( 'delivery_zone', '', $old ) );

						$pokbon_payload = array();
						foreach ( $pokbon_by_region as $pokbon_code => $pokbon_areas ) {
							$pokbon_rows = array();
							foreach ( (array) $pokbon_areas as $pokbon_area ) {
								$pokbon_rows[] = array(
									'code'   => (string) $pokbon_area['code'],
									'amount' => (float) $pokbon_area['amount'],
									// wc_price() returns markup, and markup inside an
									// <option> renders as literal text. Stripped rather
									// than escaped so the buyer sees "GH₵25.00".
									'label'  => sprintf(
										'%s — %s',
										$pokbon_area['name'],
										html_entity_decode( wp_strip_all_tags( wc_price( $pokbon_area['amount'] ) ) )
									),
								);
							}
							$pokbon_payload[ (string) $pokbon_code ] = $pokbon_rows;
						}

						$pokbon_has_any = false;
						foreach ( $pokbon_payload as $pokbon_rows ) {
							if ( ! empty( $pokbon_rows ) ) { $pokbon_has_any = true; break; }
						}
						?>
						<?php if ( $pokbon_has_any ) : ?>
							<script type="application/json" id="pokbon-delivery-areas"><?php
								echo wp_json_encode( array(
									'areas'   => $pokbon_payload,
									'gate'    => $pokbon_gate,
									'chosen'  => $chosen_zone,
									/* translators: shown when POKBON has no rider coverage in the chosen region. */
									'noCover' => __( 'We do not deliver to this area yet. Please choose another location.', 'pokbon-checkout' ),
								) );
							?></script>
							<div class="pokbon-field" data-pokbon-zone-field hidden>
								<label for="delivery_zone"><?php esc_html_e( 'Which area?', 'pokbon-checkout' ); ?> <span class="req">*</span></label>
								<select id="delivery_zone" name="delivery_zone">
									<option value=""><?php esc_html_e( 'Choose your area…', 'pokbon-checkout' ); ?></option>
								</select>
								<p class="pokbon-hint">
									<?php esc_html_e( 'Delivery is priced by area, so you only pay for the distance we actually ride.', 'pokbon-checkout' ); ?>
								</p>
							</div>
							<p class="pokbon-error" data-pokbon-no-coverage hidden role="alert"></p>
						<?php endif; ?>
					</div>
				</section>

				<section class="pokbon-section">
					<h2><?php esc_html_e( 'Order notes (optional)', 'pokbon-checkout' ); ?></h2>
					<div class="pokbon-field">
						<textarea id="order_notes" name="order_notes" rows="3" maxlength="1000"
							placeholder="<?php esc_attr_e( 'Special delivery instructions, landmarks, preferred time…', 'pokbon-checkout' ); ?>"><?php echo esc_textarea( Pokbon_Form::old( 'order_notes', '', $old ) ); ?></textarea>
					</div>
				</section>

				<section class="pokbon-section">
					<h2><?php esc_html_e( 'Payment', 'pokbon-checkout' ); ?></h2>
					<div class="pokbon-radio-group">
						<?php if ( ! empty( $payment_methods['paystack'] ) ) : ?>
							<label class="pokbon-radio-card">
								<input type="radio" name="payment_method" value="paystack" <?php checked( $selected_pay, 'paystack' ); ?> />
								<span class="pokbon-radio-card__title">
									<?php esc_html_e( 'Pay Online', 'pokbon-checkout' ); ?>
									<?php $logo = POKBON_CHECKOUT_URL; // base url ?>
									<img class="pokbon-pay-logo" src="<?php echo esc_url( WP_PLUGIN_URL . '/woo-paystack/assets/images/paystack-gh.png' ); ?>" alt="Paystack" loading="lazy" onerror="this.style.display='none'" />
								</span>
								<span class="pokbon-radio-card__desc"><?php esc_html_e( 'Card • Mobile Money • Bank. Secure redirect to Paystack.', 'pokbon-checkout' ); ?></span>
							</label>
						<?php endif; ?>
						<?php if ( ! empty( $payment_methods['cod'] ) ) : ?>
							<label class="pokbon-radio-card">
								<input type="radio" name="payment_method" value="cod" <?php checked( $selected_pay, 'cod' ); ?> />
								<span class="pokbon-radio-card__title"><?php esc_html_e( 'Cash on Delivery', 'pokbon-checkout' ); ?></span>
								<span class="pokbon-radio-card__desc"><?php esc_html_e( 'Pay in cash when your order arrives. Inspect before paying.', 'pokbon-checkout' ); ?></span>
							</label>
						<?php endif; ?>
						<?php if ( ! empty( $payment_methods['ussd'] ) && $ussd_available ) : ?>
							<label class="pokbon-radio-card">
								<input type="radio" name="payment_method" value="ussd" <?php checked( $selected_pay, 'ussd' ); ?> />
								<span class="pokbon-radio-card__title"><?php esc_html_e( 'Pay by USSD', 'pokbon-checkout' ); ?></span>
								<span class="pokbon-radio-card__desc">
									<?php esc_html_e( 'Dial a USSD code and pay directly from your mobile money wallet.', 'pokbon-checkout' ); ?>
									<button type="button" class="pokbon-ussd-link" id="pokbon-ussd-view-link" aria-haspopup="dialog" aria-controls="pokbon-ussd-modal"><?php esc_html_e( 'View USSD payment instructions', 'pokbon-checkout' ); ?></button>
								</span>
							</label>
						<?php endif; ?>
					</div>

					<?php if ( ! empty( $payment_methods['ussd'] ) && $ussd_available ) :
						$ussd_instructions_html = Pokbon_Payment::get_ussd_instructions_html();
						$ussd_merchant_display  = $ussd_merchant ? $ussd_merchant : __( 'POKBON', 'pokbon-checkout' );
						?>
						<!-- Graceful degradation: with JS disabled the modal below never opens, so
						     the same instructions are duplicated here inside <noscript>, where they
						     render inline instead. -->
						<noscript>
							<div class="pokbon-ussd-info" id="pokbon-ussd-info">
								<p>
									<strong><?php esc_html_e( 'USSD dial code:', 'pokbon-checkout' ); ?></strong> <?php echo esc_html( $ussd_code ); ?>
									&nbsp;·&nbsp;
									<strong><?php esc_html_e( 'Merchant:', 'pokbon-checkout' ); ?></strong> <?php echo esc_html( $ussd_merchant_display ); ?>
								</p>
								<div><?php echo $ussd_instructions_html; ?></div>
							</div>
						</noscript>

						<div class="pokbon-modal-overlay" id="pokbon-ussd-modal" hidden>
							<div class="pokbon-modal" role="dialog" aria-modal="true" aria-labelledby="pokbon-ussd-modal-title">
								<button type="button" class="pokbon-modal__close" id="pokbon-ussd-modal-close" aria-label="<?php esc_attr_e( 'Close', 'pokbon-checkout' ); ?>">&times;</button>
								<div class="pokbon-modal__icon" aria-hidden="true">📱</div>
								<h2 id="pokbon-ussd-modal-title"><?php esc_html_e( 'Pay by USSD', 'pokbon-checkout' ); ?></h2>
								<div class="pokbon-modal__code"><?php echo esc_html( $ussd_code ); ?></div>
								<div class="pokbon-modal__merchant"><?php esc_html_e( 'Merchant', 'pokbon-checkout' ); ?>: <strong><?php echo esc_html( $ussd_merchant_display ); ?></strong></div>
								<div class="pokbon-modal__instructions"><?php echo $ussd_instructions_html; ?></div>
								<div class="pokbon-modal__actions">
									<button type="button" class="pokbon-button pokbon-button--ghost" id="pokbon-ussd-modal-close-btn"><?php esc_html_e( 'Close', 'pokbon-checkout' ); ?></button>
									<button type="button" class="pokbon-button pokbon-button--primary" id="pokbon-ussd-modal-gotit"><?php esc_html_e( 'Got it', 'pokbon-checkout' ); ?></button>
								</div>
							</div>
						</div>
					<?php endif; ?>
				</section>

				<?php if ( ! empty( $terms_text ) ) : ?>
					<div class="pokbon-checkout__terms"><?php echo wp_kses_post( $terms_text ); ?></div>
				<?php endif; ?>

				<div class="pokbon-checkout__actions">
					<a class="pokbon-back-link" href="<?php echo esc_url( wc_get_cart_url() ); ?>">← <?php esc_html_e( 'Edit cart', 'pokbon-checkout' ); ?></a>
					<button type="submit" class="pokbon-button pokbon-button--primary" id="pokbon-place-order">
						<span class="pkb-lock" aria-hidden="true">🔒</span>
						<?php esc_html_e( 'Place Order', 'pokbon-checkout' ); ?>
						<span class="pokbon-button__total" id="pokbon-button-total"></span>
					</button>
				</div>

				<div class="pokbon-trust" aria-label="<?php esc_attr_e( 'Trust signals', 'pokbon-checkout' ); ?>">
					<div class="pokbon-trust__item">
						<span class="pokbon-trust__icon" aria-hidden="true">🔒</span>
						<strong><?php esc_html_e( 'Secure Checkout', 'pokbon-checkout' ); ?></strong>
						<?php esc_html_e( 'SSL encrypted payments', 'pokbon-checkout' ); ?>
					</div>
					<div class="pokbon-trust__item">
						<span class="pokbon-trust__icon" aria-hidden="true">🔄</span>
						<strong><?php esc_html_e( '14-Day Returns', 'pokbon-checkout' ); ?></strong>
						<?php esc_html_e( 'Easy refunds policy', 'pokbon-checkout' ); ?>
					</div>
					<div class="pokbon-trust__item">
						<span class="pokbon-trust__icon" aria-hidden="true">📞</span>
						<strong><?php esc_html_e( '24/7 Support', 'pokbon-checkout' ); ?></strong>
						<?php esc_html_e( 'We are here to help', 'pokbon-checkout' ); ?>
					</div>
				</div>
			</div>

			<aside class="pokbon-checkout__summary" aria-label="<?php esc_attr_e( 'Order summary', 'pokbon-checkout' ); ?>">
				<h2><?php esc_html_e( 'Your order', 'pokbon-checkout' ); ?></h2>
				<ul class="pokbon-summary-items">
					<?php foreach ( $cart->get_cart() as $cart_item ) :
						$_product = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, '' );
						if ( ! $_product instanceof WC_Product || $cart_item['quantity'] <= 0 ) {
							continue;
						}
						$thumbnail   = wp_get_attachment_image_url( $_product->get_image_id(), 'thumbnail' );
						$line_total  = $cart_item['line_total'] + $cart_item['line_tax'];
						$item_name   = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, '' );
						?>
						<li class="pokbon-summary-item">
							<?php if ( $thumbnail ) : ?>
								<img class="pokbon-summary-item__img" src="<?php echo esc_url( $thumbnail ); ?>" alt="" />
							<?php endif; ?>
							<div class="pokbon-summary-item__body">
								<div class="pokbon-summary-item__name"><?php echo wp_kses_post( $item_name ); ?> × <?php echo (int) $cart_item['quantity']; ?></div>
								<?php echo wc_get_formatted_cart_item_data( $cart_item ); ?>
							</div>
							<div class="pokbon-summary-item__price"><?php echo wp_kses_post( wc_price( $line_total ) ); ?></div>
						</li>
					<?php endforeach; ?>
				</ul>

				<dl class="pokbon-totals">
					<dt><?php esc_html_e( 'Subtotal', 'pokbon-checkout' ); ?></dt>
					<dd id="pokbon-subtotal"><?php echo wp_kses_post( wc_price( $subtotal ) ); ?></dd>

					<dt><?php esc_html_e( 'Shipping', 'pokbon-checkout' ); ?></dt>
					<dd id="pokbon-shipping-line">—</dd>

					<dt class="pokbon-totals__grand"><?php esc_html_e( 'Total', 'pokbon-checkout' ); ?></dt>
					<dd class="pokbon-totals__grand" id="pokbon-grand-total"><?php echo wp_kses_post( wc_price( $subtotal ) ); ?></dd>
				</dl>

				<div class="pokbon-help">
					<h3><?php esc_html_e( 'Need help?', 'pokbon-checkout' ); ?></h3>
					<div><?php esc_html_e( 'WhatsApp:', 'pokbon-checkout' ); ?> <a href="https://wa.me/233574482260" rel="noopener">+233 57 448 2260</a></div>
					<div><?php esc_html_e( 'Phone:', 'pokbon-checkout' ); ?> <a href="tel:+233556780200">+233 55 678 0200</a></div>
					<div><?php esc_html_e( 'Email:', 'pokbon-checkout' ); ?> <a href="mailto:info@pokbongroup.com">info@pokbongroup.com</a></div>
				</div>
			</aside>
		</div>
	</form>
</div>
