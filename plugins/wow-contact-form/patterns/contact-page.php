<?php
/**
 * Block markup for the contact page pattern.
 *
 * Included by the pattern registry, which captures whatever this file prints.
 * No heading: the page template already prints the page title as the heading
 * of the page, and a second one directly under it would be a duplicate.
 *
 * @package WOW\ContactForm
 */

defined( 'ABSPATH' ) || exit;

?>
<!-- wp:paragraph -->
<p><?php echo esc_html__( 'Write to us using the form below and we will answer as soon as we can.', 'wow-contact-form' ); ?></p>
<!-- /wp:paragraph -->

<!-- wp:wow/contact-form /-->
