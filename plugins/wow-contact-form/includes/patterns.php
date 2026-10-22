<?php
/**
 * The ready-made contact page.
 *
 * A pattern rather than a page created on activation: a plugin that puts
 * pages into somebody's site without being asked is a nuisance, and a pattern
 * leaves the choice, the wording and the moment to the person building the
 * site.
 *
 * @package WOW\ContactForm
 */

namespace WOW\ContactForm\Patterns;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the pattern.
 *
 * The block markup lives in its own file and is pulled in through filePath,
 * supported since WordPress 6.5. The alternative is a heredoc inside this
 * file, where the markup cannot be read at a glance and every quotation mark
 * is a question.
 *
 * On init, and not earlier or later: the pattern registry records anything
 * registered outside init as a mistake.
 */
function register(): void {
	register_block_pattern(
		'wow-contact-form/contact-page',
		array(
			'title'       => __( 'Contact page', 'wow-contact-form' ),
			'description' => __( 'A short introduction and the contact form beneath it.', 'wow-contact-form' ),
			'categories'  => array( 'contact' ),
			'blockTypes'  => array( 'wow/contact-form' ),
			'filePath'    => WOW_CONTACT_FORM_DIR . 'patterns/contact-page.php',
		)
	);
}
add_action( 'init', __NAMESPACE__ . '\\register', 20 );
