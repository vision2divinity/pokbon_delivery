<?php
/**
 * Every message this system sends a person, in one place.
 *
 * These lived in code until 2026-09-22 — the rider's sign-in text inside the
 * delivery service itself, the rest scattered through the PHP — so changing a
 * word meant a release. That is exactly what the backend-first rule exists to
 * avoid, and it was the first thing Francis went looking for and could not
 * find.
 *
 * The delivery service reads these through the settings sync, so a change here
 * reaches the rider's sign-in code as well as the plugin's own messages.
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( POKBON_DELIVERY_CAP ) ) {
	wp_die( 'Forbidden' );
}

$settings = Pokbon_Delivery_Settings::all();
$messages = isset( $settings['messages'] ) && is_array( $settings['messages'] ) ? $settings['messages'] : [];
$defaults = Pokbon_Delivery_Settings::defaults()['messages'];

/**
 * What each message is for, and what it may say.
 *
 * The variables are listed rather than discovered so that a typo shows up as a
 * missing word here instead of as `{rdier}` on somebody's phone.
 */
$catalogue = [
	'rider_otp' => [
		'title' => 'Rider sign-in code',
		'to'    => 'the rider',
		'when'  => 'They enter their number in the app.',
		'vars'  => [ 'code' => 'the six digits', 'minutes' => 'how long it lasts' ],
		'note'  => 'Sent by the delivery service, not from here. Keep "never share it" in it.',
	],
	'assigned' => [
		'title' => 'A rider is on the way',
		'to'    => 'the customer',
		'when'  => 'A rider accepts the job.',
		'vars'  => [ 'rider' => "the rider's name", 'amount' => 'the amount due, when there is one' ],
		'note'  => '',
	],
	'delivery_code' => [
		'title' => 'Delivery code',
		'to'    => 'the customer',
		'when'  => 'The rider says they have arrived.',
		'vars'  => [ 'code' => 'the six digits', 'amount' => 'the amount due, when there is one' ],
		'note'  => 'The rider never sees this code. Do not remove "read it to the rider only".',
	],
	'payment_prompt' => [
		'title' => 'Approve the payment',
		'to'    => 'the customer',
		'when'  => 'The code matches and payment is requested.',
		'vars'  => [ 'amount' => 'the amount due', 'order' => 'the order number' ],
		'note'  => "Paystack's own instruction and a payment link are added after this line when they apply.",
	],
	'pay_by_link' => [
		'title' => 'Payment link',
		'to'    => 'the customer',
		'when'  => 'A rider or dispatcher sends a link instead of a prompt.',
		'vars'  => [ 'amount' => 'the amount due', 'order' => 'the order number', 'link' => 'the checkout link' ],
		'note'  => 'Keep {link} in it, or the message is useless.',
	],
];
?>
<div class="wrap">
	<h1>Messages</h1>
	<?php Pokbon_Delivery_Admin::notices(); ?>

	<p class="description" style="max-width:60em">
		Everything this service says to a rider or a customer. Changes take effect on the next message —
		no app release, and no release of this plugin. Text in <code>{braces}</code> is filled in when the
		message is sent; a name that is not on the list for that message is removed rather than shown, so a
		typo costs you a word rather than putting <code>{rdier}</code> on somebody's phone.
	</p>
	<p class="description" style="max-width:60em">
		Messages are folded to what a plain SMS can carry before they are sent, so the cedi sign, curly
		quotes and dashes are replaced with their plain equivalents. Write <strong>GHS</strong> rather than
		the symbol. Keep them short: about 160 characters is one message, and longer costs more to send.
	</p>

	<?php Pokbon_Delivery_Admin::form_open( 'save_messages' ); ?>

	<?php foreach ( $catalogue as $key => $about ) : ?>
		<?php
		$current = isset( $messages[ $key ] ) && is_array( $messages[ $key ] ) ? $messages[ $key ] : [];
		$text    = (string) ( $current['text'] ?? $defaults[ $key ]['text'] ?? '' );
		$on      = ! isset( $current['enabled'] ) || ! empty( $current['enabled'] );
		?>
		<h2 style="margin-top:2em"><?php echo esc_html( $about['title'] ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Sent to</th>
				<td>
					<?php echo esc_html( ucfirst( $about['to'] ) ); ?>.
					<?php echo esc_html( $about['when'] ); ?>
					<?php if ( $about['note'] !== '' ) : ?>
						<br><span class="description"><?php echo esc_html( $about['note'] ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="pkbd-msg-<?php echo esc_attr( $key ); ?>">Message</label></th>
				<td>
					<textarea id="pkbd-msg-<?php echo esc_attr( $key ); ?>"
						name="message_text[<?php echo esc_attr( $key ); ?>]"
						rows="3" class="large-text code"><?php echo esc_textarea( $text ); ?></textarea>
					<p class="description">
						You can use:
						<?php foreach ( $about['vars'] as $name => $means ) : ?>
							<code>{<?php echo esc_html( $name ); ?>}</code>
							<?php echo esc_html( $means ); ?><?php echo $name === array_key_last( $about['vars'] ) ? '' : ' &middot; '; ?>
						<?php endforeach; ?>
					</p>
					<p class="description">
						<strong><?php echo esc_html( mb_strlen( $text ) ); ?></strong> characters as saved.
						<?php if ( mb_strlen( $text ) > 160 ) : ?>
							<span style="color:#b32d2e">Over 160 — this will be billed as more than one message.</span>
						<?php endif; ?>
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row">Send it</th>
				<td>
					<label>
						<input type="checkbox" name="message_on[<?php echo esc_attr( $key ); ?>]" value="1"
							<?php checked( $on ); ?>>
						Send this message
					</label>
					<?php if ( $key === 'delivery_code' || $key === 'rider_otp' ) : ?>
						<p class="description" style="color:#b32d2e">
							Switching this off stops
							<?php echo $key === 'rider_otp' ? 'riders signing in' : 'deliveries being confirmed'; ?>.
							There is no other route for it.
						</p>
					<?php endif; ?>
				</td>
			</tr>
		</table>
	<?php endforeach; ?>

	<p><button type="submit" class="button button-primary">Save messages</button></p>
	</form>
</div>
