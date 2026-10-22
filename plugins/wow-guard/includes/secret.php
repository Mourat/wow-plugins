<?php
/**
 * Keeping a second factor's secret out of a database backup.
 *
 * A TOTP secret is not a password but it is worth exactly as much: anybody
 * holding it can produce the codes for ever. Stored plainly, a database that
 * leaks hands over everybody's second factor along with their first.
 *
 * So it is encrypted with a key made from the site's own salts, which live in
 * wp-config.php and not in the database. The distinction is narrow and worth
 * stating exactly: this protects a database that leaks on its own, which is
 * how these things usually get out. It protects nothing against somebody
 * already on the server who can read wp-config.php.
 *
 * (The mail plugin has the same few functions. They are copied rather than
 * shared because a security plugin that stops working when another plugin is
 * switched off is not a security plugin.)
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * The key, derived from the salts WordPress keeps in wp-config.php.
 */
function key(): string {
	return substr( hash( 'sha256', wp_salt( 'secure_auth' ) . '|wow-guard', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Locks a secret away.
 *
 * @param string $plain The secret.
 */
function seal( string $plain ): string {
	if ( '' === $plain ) {
		return '';
	}

	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storing bytes in a text column.
}

/**
 * Gets it back, or nothing if it cannot.
 *
 * Returning nothing rather than guessing matters here: salts that have
 * changed — a site moved without its wp-config.php — would otherwise produce
 * rubbish, and rubbish compared against a typed code locks somebody out of
 * their own site with no explanation. An empty secret is recognised and said
 * out loud instead.
 *
 * @param string $sealed What was stored.
 */
function open( string $sealed ): string {
	if ( '' === $sealed ) {
		return '';
	}

	$raw = base64_decode( $sealed, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- reading back what seal() wrote.

	if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
		return '';
	}

	$nonce  = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$cipher = substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	try {
		$plain = sodium_crypto_secretbox_open( $cipher, $nonce, key() );
	} catch ( \Throwable $e ) {
		return '';
	}

	return false === $plain ? '' : $plain;
}
