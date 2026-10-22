<?php
/**
 * The half of this that is not in WordPress at all.
 *
 * Sending through an authenticated mail server settles who is sending.
 * Whether the receiving end believes it is decided by three records in the
 * domain's DNS, and no plugin can put them there — they belong to whoever
 * holds the domain.
 *
 * What a plugin can do is stop the owner guessing. These checks say, in
 * plain terms, which of the three exist right now.
 *
 *   SPF    a list of the servers allowed to send as this domain. Without it,
 *          anybody may, and the receiving end knows that.
 *   DKIM   a signature on each message, so the body cannot be altered in
 *          transit and the sender can be checked. Set up at the mail
 *          provider, which publishes the key under a name it chooses, so
 *          there is no way to look for it without being told the name.
 *   DMARC  what a receiving server should do when the first two do not match
 *          — and an address to send reports to.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Dns;

defined( 'ABSPATH' ) || exit;

/**
 * The domain the site sends as.
 */
function domain(): string {
	$from = \WOW\Mail\Options\from();

	if ( is_email( $from ) && str_contains( $from, '@' ) ) {
		return strtolower( substr( strrchr( $from, '@' ), 1 ) );
	}

	$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	return strtolower( (string) preg_replace( '/^www\./', '', $host ) );
}

/**
 * Whether this server is allowed to ask DNS anything.
 *
 * Shared hosting sometimes switches these functions off, and a check that
 * cannot run must say so rather than report everything as missing.
 */
function possible(): bool {
	return function_exists( 'dns_get_record' );
}

/**
 * The TXT records of a name.
 *
 * @param string $name What to ask about.
 * @return string[]
 */
function txt( string $name ): array {
	if ( ! possible() ) {
		return array();
	}

	// Errors are not exceptional here: a name that does not exist is the
	// ordinary answer and is not worth a warning in the log.
	$records = @dns_get_record( $name, DNS_TXT ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( ! is_array( $records ) ) {
		return array();
	}

	$found = array();

	foreach ( $records as $record ) {
		// A long record arrives split into pieces that have to be rejoined.
		$value = isset( $record['entries'] ) && is_array( $record['entries'] )
			? implode( '', $record['entries'] )
			: (string) ( $record['txt'] ?? '' );

		if ( '' !== $value ) {
			$found[] = $value;
		}
	}

	return $found;
}

/**
 * What the domain says about itself.
 *
 * @return array{domain:string,checked:bool,spf:string,dmarc:string,mx:int}
 */
function report(): array {
	$domain = domain();
	$spf    = '';
	$dmarc  = '';
	$mx     = 0;

	if ( possible() && '' !== $domain ) {
		foreach ( txt( $domain ) as $value ) {
			if ( 0 === stripos( $value, 'v=spf1' ) ) {
				$spf = $value;
			}
		}

		foreach ( txt( '_dmarc.' . $domain ) as $value ) {
			if ( 0 === stripos( $value, 'v=DMARC1' ) ) {
				$dmarc = $value;
			}
		}

		$records = @dns_get_record( $domain, DNS_MX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$mx      = is_array( $records ) ? count( $records ) : 0;
	}

	return array(
		'domain'  => $domain,
		'checked' => possible(),
		'spf'     => $spf,
		'strict'  => '' !== $spf && str_contains( strtolower( $spf ), '-all' ),
		'dmarc'   => $dmarc,
		'mx'      => $mx,
	);
}

/*
 * There is deliberately no check here of whether the SPF record covers the
 * mail server in the settings, tempting as it looks.
 *
 * SPF is checked against the address of the machine that delivers a message
 * to its recipient, and that is not the machine WordPress hands it to. A
 * provider takes submissions on one host and sends out from a fleet of
 * others, and only the latter are in its published record. Comparing the
 * submission host against the record therefore reports a perfectly working
 * setup as broken — which this plugin did, once, before the owner pointed out
 * that the mail had been arriving for years.
 *
 * The only honest test is at the receiving end, and the screen says how to
 * run it.
 */
