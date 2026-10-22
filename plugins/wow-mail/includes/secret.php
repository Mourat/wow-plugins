<?php
/**
 * Keeping the mail server's password out of a database backup.
 *
 * It has to be somewhere. The honest options are two, and they are not equal.
 *
 * A line in wp-config.php is the better one, and this plugin prefers it: the
 * password is then in a file, outside the database, and it is not in any of
 * the dumps that get taken nightly, emailed to people and left in folders.
 *
 * Failing that it goes in the options table — but encrypted, with a key made
 * from the site's own salts, which live in wp-config.php and not in the
 * database. That is a real distinction and worth being precise about: it
 * protects a database that leaks on its own, which is how passwords usually
 * get out. It protects nothing at all against somebody who is already on the
 * server and can read wp-config.php — at that point they have the database
 * password too, and this one is the least of it.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Secret;

defined( 'ABSPATH' ) || exit;

/**
 * The key, derived from the salts WordPress keeps in wp-config.php.
 */
function key(): string {
	return substr( hash( 'sha256', wp_salt( 'secure_auth' ) . '|wow-mail', true ), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
}

/**
 * Locks a password away.
 *
 * @param string $plain The password.
 * @return string Something safe to put in an option, or '' for an empty one.
 */
function seal( string $plain ): string {
	if ( '' === $plain ) {
		return '';
	}

	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );

	return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storing bytes in a text column.
}

/**
 * Gets it back.
 *
 * Returns nothing rather than guessing when it cannot: the salts having
 * changed — a site moved without its wp-config.php, most likely — makes the
 * stored password unreadable, and a wrong password sent to a mail server
 * looks like somebody trying to break into it.
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
