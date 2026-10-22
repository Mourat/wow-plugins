<?php
/**
 * The six digits that change every thirty seconds.
 *
 * TOTP, from RFC 6238, which is what every authenticator application speaks
 * — Google Authenticator, 1Password, Authy, Bitwarden and the rest. It is a
 * small enough algorithm to write out rather than depend on: a shared secret,
 * the current half-minute, HMAC-SHA1 over the two, and six digits taken from
 * the result.
 *
 * The one piece with no PHP function behind it is base32, which is how the
 * secret is written down for a person to type. Base64 would have done the job
 * better, but the applications all expect base32, so base32 it is.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Totp;

defined( 'ABSPATH' ) || exit;

/** The alphabet base32 is written in. */
const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

/** How long one code lasts, in seconds. */
const STEP = 30;

/** How many digits a code has. */
const DIGITS = 6;

/**
 * A fresh secret, as a person would copy it.
 *
 * Twenty bytes, which is what RFC 4226 asks for and what every application
 * expects. random_bytes() and not anything else: this is the whole of the
 * second factor, and a predictable one is no factor at all.
 */
function secret(): string {
	return encode( random_bytes( 20 ) );
}

/**
 * Bytes to base32.
 *
 * @param string $bytes Raw bytes.
 */
function encode( string $bytes ): string {
	$bits = '';

	foreach ( str_split( $bytes ) as $byte ) {
		$bits .= str_pad( decbin( ord( $byte ) ), 8, '0', STR_PAD_LEFT );
	}

	$out = '';

	foreach ( str_split( $bits, 5 ) as $chunk ) {
		$out .= ALPHABET[ bindec( str_pad( $chunk, 5, '0', STR_PAD_RIGHT ) ) ];
	}

	return $out;
}

/**
 * Base32 back to bytes.
 *
 * Forgiving about what it is given: people type these by hand, in groups of
 * four, in whichever case the screen showed them. Anything that is not part
 * of the alphabet is simply not part of the secret.
 *
 * @param string $base32 The secret as written.
 */
function decode( string $base32 ): string {
	$clean = preg_replace( '/[^A-Z2-7]/', '', strtoupper( $base32 ) );

	if ( ! is_string( $clean ) || '' === $clean ) {
		return '';
	}

	$bits = '';

	foreach ( str_split( $clean ) as $letter ) {
		$bits .= str_pad( decbin( (int) strpos( ALPHABET, $letter ) ), 5, '0', STR_PAD_LEFT );
	}

	$bytes = '';

	foreach ( str_split( $bits, 8 ) as $chunk ) {
		if ( 8 === strlen( $chunk ) ) {
			$bytes .= chr( bindec( $chunk ) );
		}
	}

	return $bytes;
}

/**
 * Which half-minute it is.
 *
 * @param int|null $at A moment; now when not given.
 */
function timestep( ?int $at = null ): int {
	return (int) floor( ( $at ?? time() ) / STEP );
}

/**
 * The code for a secret at a given half-minute.
 *
 * @param string $secret The secret, in base32.
 * @param int    $step   Which half-minute.
 */
function code( string $secret, int $step ): string {
	$key = decode( $secret );

	if ( '' === $key ) {
		return '';
	}

	// The counter goes in as eight bytes, most significant first.
	$counter = pack( 'J', $step );
	$hash    = hash_hmac( 'sha1', $counter, $key, true );

	// The last four bits say where in the hash the number starts — the
	// "dynamic truncation" that stops the code being the same slice every
	// time.
	$offset = ord( $hash[19] ) & 0x0F;

	$number = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
		| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
		| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
		| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

	return str_pad( (string) ( $number % ( 10 ** DIGITS ) ), DIGITS, '0', STR_PAD_LEFT );
}

/**
 * Whether a code is right, and if so for which half-minute.
 *
 * A window either side, because the phone's clock and the server's are never
 * quite the same and because thirty seconds is not long to type six digits.
 * One step each way is the usual allowance; more would widen the guessing.
 *
 * The half-minute is returned rather than a yes, so that the caller can write
 * down which one was used and refuse it a second time. Without that, a code
 * overheard or looked over a shoulder is good for the rest of its life.
 *
 * hash_equals() rather than ===, so that a wrong guess takes as long to
 * reject as a nearly-right one.
 *
 * @param string $secret The secret, in base32.
 * @param string $given  What was typed.
 * @param int    $window How many half-minutes either side to allow.
 * @return int|false The half-minute it matched, or false.
 */
function verify( string $secret, string $given, int $window = 1 ) {
	$given = preg_replace( '/\D/', '', $given );

	if ( ! is_string( $given ) || DIGITS !== strlen( $given ) ) {
		return false;
	}

	$now = timestep();

	for ( $offset = -$window; $offset <= $window; $offset++ ) {
		$step = $now + $offset;

		if ( hash_equals( code( $secret, $step ), $given ) ) {
			return $step;
		}
	}

	return false;
}

/**
 * The address an authenticator application is given.
 *
 * otpauth:// is the convention every one of them understands, and is what a
 * QR code on the enrolment screen contains. The issuer appears twice because
 * the older applications read it from the label and the newer ones from the
 * parameter, and disagreeing with yourself there produces two entries.
 *
 * @param string $secret  The secret, in base32.
 * @param string $account Whose it is — an email address, usually.
 * @param string $issuer  Which site it is for.
 */
function uri( string $secret, string $account, string $issuer ): string {
	$label = rawurlencode( $issuer ) . ':' . rawurlencode( $account );

	return sprintf(
		'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
		$label,
		$secret,
		rawurlencode( $issuer ),
		DIGITS,
		STEP
	);
}
