<?php
/**
 * WP-CLI commands.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\CLI;

use WOW\Mail\Dns;
use WOW\Mail\Log;
use WOW\Mail\Mailer;
use WOW\Mail\Options;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Says how the site sends mail and whether anybody will believe it.
 *
 * ## EXAMPLES
 *
 *     wp wow-mail status
 */
function status(): void {
	$options = Options\all_options();
	$dns     = Dns\report();

	\WP_CLI::log( 'Sending through: ' . ( Options\ready() ? sprintf( '%s:%d (%s)', $options['host'], $options['port'], $options['encryption'] ) : "the web server's own mail() — unauthenticated" ) );
	\WP_CLI::log( 'Sending as:      ' . Options\from() . ' (' . Options\from_name() . ')' );
	\WP_CLI::log( 'Password kept:   ' . Options\password_lives() );
	\WP_CLI::log( '' );
	\WP_CLI::log( 'Domain:          ' . $dns['domain'] );

	if ( ! $dns['checked'] ) {
		\WP_CLI::warning( 'This server does not allow DNS lookups, so SPF and DMARC cannot be checked here.' );
	} else {
		\WP_CLI::log( 'SPF:             ' . ( '' !== $dns['spf'] ? $dns['spf'] : 'MISSING' ) );
		\WP_CLI::log( 'DMARC:           ' . ( '' !== $dns['dmarc'] ? $dns['dmarc'] : 'MISSING' ) );
		\WP_CLI::log( 'MX records:      ' . $dns['mx'] );
	}

	\WP_CLI::log( '' );
	\WP_CLI::log( 'DKIM is set up at the mail provider under a name it chooses and cannot be looked for from here.' );

	$log = Log\entries();

	\WP_CLI::log( '' );
	\WP_CLI::log( sprintf( 'Messages in the last fortnight: %d', count( $log ) ) );

	foreach ( array_slice( $log, 0, 10 ) as $entry ) {
		\WP_CLI::log(
			sprintf(
				'  %s  %-32s %-40s %s',
				gmdate( 'Y-m-d H:i', (int) $entry['time'] ),
				(string) $entry['to'],
				mb_substr( (string) $entry['subject'], 0, 40 ),
				$entry['ok'] ? 'sent' : (string) $entry['reason']
			)
		);
	}
}
\WP_CLI::add_command( 'wow-mail status', __NAMESPACE__ . '\\status' );

/**
 * Sends a test message and prints the exchange with the mail server.
 *
 * ## OPTIONS
 *
 * <address>
 * : Where to send it.
 *
 * ## EXAMPLES
 *
 *     wp wow-mail test somebody@example.com
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function test( array $args, array $assoc_args ): void {
	$to = (string) ( $args[0] ?? '' );

	if ( ! is_email( $to ) ) {
		\WP_CLI::error( 'That is not an address.' );
	}

	$result = Mailer\test( $to );

	if ( '' !== $result['conversation'] ) {
		\WP_CLI::log( $result['conversation'] );
		\WP_CLI::log( '' );
	}

	if ( ! $result['sent'] ) {
		\WP_CLI::error( '' === $result['reason'] ? 'It did not go out.' : $result['reason'] );
	}

	\WP_CLI::success( 'The mail server accepted it. Whether it lands in the inbox or the spam folder is decided by SPF, DKIM and DMARC — see `wp wow-mail status`.' );
}
\WP_CLI::add_command( 'wow-mail test', __NAMESPACE__ . '\\test' );
