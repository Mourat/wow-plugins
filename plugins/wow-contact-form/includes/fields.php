<?php
/**
 * The fields of the form, described once.
 *
 * Everything else — the markup, the validation, the stored record and the
 * email — reads this list instead of naming fields of its own. Adding a field
 * is then one entry here rather than five edits spread over the plugin.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Fields;

defined( 'ABSPATH' ) || exit;

/**
 * The fields, in the order the visitor meets them.
 *
 * The labels carry a translation context. The same words head columns in the
 * admin, and the translator may well want to address the visitor ("Votre
 * Email") without that wording turning up over a table.
 *
 * The limits are not decoration. Without them a single request could push
 * megabytes of text into the database, and a long line in an email header is
 * a way of smuggling a second header in.
 *
 * @return array<string,array<string,mixed>> Field key => description.
 */
function fields(): array {
	$fields = array(
		'first_name' => array(
			'label'        => _x( 'First name', 'form field', 'wow-contact-form' ),
			'type'         => 'text',
			'required'     => true,
			'autocomplete' => 'given-name',
			'max'          => 100,
		),
		'last_name'  => array(
			'label'        => _x( 'Last name', 'form field', 'wow-contact-form' ),
			'type'         => 'text',
			'required'     => true,
			'autocomplete' => 'family-name',
			'max'          => 100,
		),
		'company'    => array(
			'label'        => _x( 'Company', 'form field', 'wow-contact-form' ),
			'type'         => 'text',
			'required'     => false,
			'autocomplete' => 'organization',
			'max'          => 150,
		),
		'email'      => array(
			'label'        => _x( 'Email', 'form field', 'wow-contact-form' ),
			'type'         => 'email',
			'required'     => true,
			'autocomplete' => 'email',
			'max'          => 200,
		),
		'message'    => array(
			'label'        => _x( 'Message', 'form field', 'wow-contact-form' ),
			'type'         => 'textarea',
			'required'     => true,
			'autocomplete' => 'off',
			'max'          => 5000,
		),
	);

	/**
	 * Filters the fields of the contact form.
	 *
	 * @param array<string,array<string,mixed>> $fields Field key => description.
	 */
	return (array) apply_filters( 'wow_contact_form_fields', $fields );
}

/**
 * The field keys alone.
 *
 * @return array<int,string> Keys in display order.
 */
function keys(): array {
	return array_keys( fields() );
}

/**
 * Reduces one submitted value to plain text.
 *
 * Plain text is the point. The value is on its way to post_content, where
 * kses would otherwise rewrite anything that looks like markup — a visitor
 * writing "if a < b" would find their sentence quietly truncated. Nothing
 * reaches storage with a tag left in it, so kses finds nothing to rewrite.
 *
 * @param string $key   Field key.
 * @param string $value Raw value as it arrived.
 * @return string The value, cleaned and cut to the field's limit.
 */
function sanitize( string $key, string $value ): string {
	$field = fields()[ $key ] ?? array();
	$clean = 'textarea' === ( $field['type'] ?? 'text' )
		? sanitize_textarea_field( $value )
		: sanitize_text_field( $value );

	$max = (int) ( $field['max'] ?? 200 );

	// mb_substr counts characters, so a limit cannot split a multi-byte one.
	return mb_substr( $clean, 0, $max );
}

/**
 * Cleans a whole submission.
 *
 * Fields that were not sent come back as empty strings rather than going
 * missing, so every later step can count on the same shape.
 *
 * @param array<string,mixed> $raw Raw request data.
 * @return array<string,string> Field key => clean value.
 */
function clean( array $raw ): array {
	$values = array();

	foreach ( keys() as $key ) {
		$sent           = isset( $raw[ $key ] ) ? (string) wp_unslash( $raw[ $key ] ) : '';
		$values[ $key ] = sanitize( $key, $sent );
	}

	return $values;
}

/**
 * Checks a cleaned submission.
 *
 * Length is not checked here: sanitize() has already cut anything overlong,
 * and telling a visitor their message was too long after silently shortening
 * it would be a lie either way.
 *
 * @param array<string,string> $values Cleaned values.
 * @return array<string,string> Field key => message, empty when all is well.
 */
function validate( array $values ): array {
	$errors = array();

	foreach ( fields() as $key => $field ) {
		$value = $values[ $key ] ?? '';

		if ( ! empty( $field['required'] ) && '' === trim( $value ) ) {
			$errors[ $key ] = sprintf(
				/* translators: %s: field label. */
				__( '%s is required.', 'wow-contact-form' ),
				$field['label']
			);

			continue;
		}

		if ( 'email' === $field['type'] && '' !== $value && ! is_email( $value ) ) {
			$errors[ $key ] = __( 'That does not look like an email address.', 'wow-contact-form' );
		}
	}

	return $errors;
}
