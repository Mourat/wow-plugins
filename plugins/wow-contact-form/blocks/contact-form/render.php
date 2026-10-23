<?php
/**
 * The form.
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 *
 * @package WOW\ContactForm
 */

use WOW\ContactForm\Block;
use WOW\ContactForm\Fields;
use WOW\ContactForm\Security;
use WOW\ContactForm\Submission;

defined( 'ABSPATH' ) || exit;

$wow_instance = Block\next_instance();
$wow_id       = 1 === $wow_instance ? 'wow-contact-form' : 'wow-contact-form-' . $wow_instance;

/*
 * postId comes from the block's context, which core also supplies to the
 * editor preview. It is what the hidden field carries back, and what the
 * handler turns into the address to return to.
 */
$wow_post = (int) ( $block->context['postId'] ?? get_the_ID() );

/*
 * An empty action posts the form to the address of the page showing it, which
 * is what we want and what the permalink would spell out anyway. It is only
 * written out when there is a post to ask, so a preview with no post behind
 * it still renders.
 */
$wow_action = $wow_post > 0 ? (string) get_permalink( $wow_post ) : '';

$wow_state  = Submission\state();
$wow_values = $wow_state['values'];
$wow_errors = $wow_state['errors'];
$wow_notice = $wow_state['notice'];

$wow_wrapper = get_block_wrapper_attributes(
	array(
		'id'    => $wow_id,
		'class' => 'wow-contact-form',
	)
);
?>
<div <?php echo $wow_wrapper; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by get_block_wrapper_attributes(). ?>>
<?php if ( Submission\was_sent() ) : ?>
	<p class="wow-contact-form__sent" role="status">
		<?php esc_html_e( 'Thank you — your message has reached us. We will get back to you shortly.', 'wow-contact-form' ); ?>
	</p>
<?php else : ?>
	<?php if ( '' !== $wow_notice ) : ?>
		<p class="wow-contact-form__notice" role="alert"><?php echo esc_html( $wow_notice ); ?></p>
	<?php endif; ?>

	<form class="wow-contact-form__form" method="post" action="<?php echo esc_url( $wow_action ); ?>">
		<?php foreach ( Fields\fields() as $wow_key => $wow_field ) : ?>
			<?php
			$wow_field_id = $wow_id . '-' . str_replace( '_', '-', $wow_key );
			$wow_error    = $wow_errors[ $wow_key ] ?? '';
			$wow_value    = $wow_values[ $wow_key ] ?? '';
			?>
			<p class="wow-contact-form__field wow-contact-form__field--<?php echo esc_attr( $wow_key ); ?>">
				<label class="wow-contact-form__label" for="<?php echo esc_attr( $wow_field_id ); ?>">
					<?php echo esc_html( $wow_field['label'] ); ?>
					<?php if ( ! empty( $wow_field['required'] ) ) : ?>
						<?php // The asterisk repeats what the required attribute already tells a screen reader. ?>
						<span class="wow-contact-form__required" aria-hidden="true">*</span>
					<?php endif; ?>
				</label>

				<?php if ( 'textarea' === $wow_field['type'] ) : ?>
					<textarea
						class="wow-contact-form__input wow-contact-form__input--textarea"
						id="<?php echo esc_attr( $wow_field_id ); ?>"
						name="<?php echo esc_attr( $wow_key ); ?>"
						rows="8"
						maxlength="<?php echo esc_attr( (string) $wow_field['max'] ); ?>"
						autocomplete="<?php echo esc_attr( $wow_field['autocomplete'] ); ?>"
						<?php echo empty( $wow_field['required'] ) ? '' : 'required'; ?>
						<?php echo '' === $wow_error ? '' : 'aria-invalid="true" aria-describedby="' . esc_attr( $wow_field_id . '-error' ) . '"'; ?>
					><?php echo esc_textarea( $wow_value ); ?></textarea>
				<?php else : ?>
					<input
						class="wow-contact-form__input"
						type="<?php echo esc_attr( $wow_field['type'] ); ?>"
						id="<?php echo esc_attr( $wow_field_id ); ?>"
						name="<?php echo esc_attr( $wow_key ); ?>"
						value="<?php echo esc_attr( $wow_value ); ?>"
						maxlength="<?php echo esc_attr( (string) $wow_field['max'] ); ?>"
						autocomplete="<?php echo esc_attr( $wow_field['autocomplete'] ); ?>"
						<?php echo empty( $wow_field['required'] ) ? '' : 'required'; ?>
						<?php echo '' === $wow_error ? '' : 'aria-invalid="true" aria-describedby="' . esc_attr( $wow_field_id . '-error' ) . '"'; ?>
					>
				<?php endif; ?>

				<?php if ( '' !== $wow_error ) : ?>
					<span class="wow-contact-form__error" id="<?php echo esc_attr( $wow_field_id . '-error' ); ?>">
						<?php echo esc_html( $wow_error ); ?>
					</span>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>

		<?php
		/*
		 * The field nobody sees. Moved off-screen rather than hidden with
		 * display:none, which some robots know to skip, and taken out of the
		 * tab order so a keyboard never lands in it by accident.
		 */
		?>
		<?php
		/*
		 * And the one that is meant to be seen.
		 *
		 * Every class, and the asterisk, are the ones the loop above writes:
		 * a field that looked different in the markup would be a field a
		 * robot could learn to leave alone. The asterisk is the only part
		 * that is a pretence — there is no required attribute, because that
		 * would stop a person sending the form. It is there because a robot
		 * filling in what looks mandatory is exactly what is being asked of
		 * it.
		 */
		?>
		<p class="wow-contact-form__field wow-contact-form__field--confirm">
			<label class="wow-contact-form__label" for="<?php echo esc_attr( $wow_id . '-confirm' ); ?>">
				<?php esc_html_e( 'Leave this field empty', 'wow-contact-form' ); ?>
				<span class="wow-contact-form__required" aria-hidden="true">*</span>
			</label>
			<input
				class="wow-contact-form__input"
				type="text"
				id="<?php echo esc_attr( $wow_id . '-confirm' ); ?>"
				name="<?php echo esc_attr( Security\SEEN_TRAP ); ?>"
				value=""
				maxlength="100"
				autocomplete="off"
				spellcheck="false"
			>
		</p>

		<div class="wow-contact-form__trap" aria-hidden="true">
			<label for="<?php echo esc_attr( $wow_id . '-trap' ); ?>"><?php esc_html_e( 'Leave this field empty', 'wow-contact-form' ); ?></label>
			<input
				type="text"
				id="<?php echo esc_attr( $wow_id . '-trap' ); ?>"
				name="<?php echo esc_attr( Security\HONEYPOT ); ?>"
				value=""
				tabindex="-1"
				autocomplete="off"
			>
		</div>

		<input type="hidden" name="<?php echo esc_attr( Submission\FIELD_ACTION ); ?>" value="send">
		<input type="hidden" name="<?php echo esc_attr( Submission\FIELD_POST ); ?>" value="<?php echo esc_attr( (string) $wow_post ); ?>">
		<input type="hidden" name="<?php echo esc_attr( Security\STAMP ); ?>" value="<?php echo esc_attr( Security\stamp() ); ?>">
		<?php
		/*
		 * Written out rather than left to wp_nonce_field(), which would add a
		 * referer field the handler never reads and an id that a second copy
		 * of the form on the same page would repeat.
		 */
		?>
		<input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( Security\NONCE ) ); ?>">

		<p class="wow-contact-form__actions">
			<button type="submit" class="wow-contact-form__submit">
				<?php esc_html_e( 'Send', 'wow-contact-form' ); ?>
			</button>
		</p>
	</form>
<?php endif; ?>
</div>
