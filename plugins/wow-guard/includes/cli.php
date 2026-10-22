<?php
/**
 * WP-CLI commands.
 *
 * The way back in. Every one of these works without logging in, which is the
 * point: the settings they change are the settings that can stop somebody
 * logging in.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\CLI;

use WOW\Guard\Attempts;
use WOW\Guard\Log;
use WOW\Guard\LoginUrl;
use WOW\Guard\Options;
use WOW\Guard\Visitor;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Says where the login page is, or moves it.
 *
 * ## OPTIONS
 *
 * [--set=<slug>]
 * : Move it to this address.
 *
 * [--off]
 * : Put it back at wp-login.php.
 *
 * ## EXAMPLES
 *
 *     wp wow-guard login-url
 *     wp wow-guard login-url --set=entree-de-service
 *     wp wow-guard login-url --off
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function login_url( array $args, array $assoc_args ): void {
	if ( isset( $assoc_args['off'] ) || isset( $assoc_args['set'] ) ) {
		$options = Options\all_options();
		$wanted  = isset( $assoc_args['off'] ) ? '' : (string) $assoc_args['set'];

		$options['login_slug'] = '';
		$options               = Options\sanitize( array_merge( $options, array( 'login_slug' => $wanted ) ) );

		update_option( Options\OPTION, $options );

		if ( '' !== $wanted && '' === $options['login_slug'] ) {
			\WP_CLI::error( 'That address is one WordPress owns and cannot be used.' );
		}
	}

	if ( defined( 'WOW_GUARD_LOGIN_SLUG' ) ) {
		\WP_CLI::warning( 'A line in wp-config.php is setting this and wins over whatever is in the database.' );
	}

	\WP_CLI::success( LoginUrl\moved() ? LoginUrl\address() : wp_login_url() );
}
\WP_CLI::add_command( 'wow-guard login-url', __NAMESPACE__ . '\\login_url' );

/**
 * Lifts a lockout.
 *
 * ## OPTIONS
 *
 * [<ip>]
 * : The address to let back in. All of them when none is given.
 *
 * ## EXAMPLES
 *
 *     wp wow-guard unlock
 *     wp wow-guard unlock 203.0.113.9
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function unlock( array $args, array $assoc_args ): void {
	if ( empty( $args ) ) {
		Attempts\forget_all();

		\WP_CLI::success( 'Every lockout has been lifted.' );

		return;
	}

	$ip = (string) $args[0];

	if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
		\WP_CLI::error( 'That is not an address.' );
	}

	Attempts\forget( $ip );

	\WP_CLI::success( sprintf( '%s may try again.', $ip ) );
}
\WP_CLI::add_command( 'wow-guard unlock', __NAMESPACE__ . '\\unlock' );

/**
 * Says what the guard is doing and what it has seen.
 *
 * ## EXAMPLES
 *
 *     wp wow-guard status
 */
function status(): void {
	$options = Options\all_options();

	\WP_CLI::log( 'Login page:        ' . ( LoginUrl\moved() ? LoginUrl\address() : 'wp-login.php (not moved)' ) );
	\WP_CLI::log( sprintf( 'Attempts allowed:  %d, then locked out for %d minutes', $options['attempts'], $options['lockout'] ) );
	\WP_CLI::log( 'Same refusal:      ' . ( $options['same_error'] ? 'yes' : 'no' ) );
	\WP_CLI::log( 'Names hidden:      ' . ( $options['hide_names'] ? 'yes' : 'no' ) );
	\WP_CLI::log( 'XML-RPC:           ' . ( $options['xmlrpc'] ? 'open' : 'shut' ) );
	\WP_CLI::log( 'App passwords:     ' . ( $options['app_passwords'] ? 'open' : 'shut' ) );
	\WP_CLI::log( 'File editor:       ' . ( $options['file_editor'] ? 'open' : 'shut' ) );
	\WP_CLI::log( 'Headers:           ' . ( $options['headers'] ? 'sent' : 'not sent' ) . ( $options['hsts'] ? ', with HSTS' : '' ) );
	\WP_CLI::log( 'Uploads:           ' . \WOW\Guard\Uploads\state() );
	\WP_CLI::log( 'WordPress updates: ' . $options['auto_core'] );
	\WP_CLI::log( 'Behind a proxy:    ' . ( $options['behind_proxy'] ? 'yes, reading ' . $options['proxy_header'] : 'no' ) );

	$log = Log\entries();

	\WP_CLI::log( '' );
	\WP_CLI::log( sprintf( 'Failed attempts in the last week: %d', count( $log ) ) );

	foreach ( array_slice( $log, 0, 10 ) as $entry ) {
		\WP_CLI::log(
			sprintf(
				'  %s  %-39s %-20s %s',
				gmdate( 'Y-m-d H:i', (int) $entry['time'] ),
				(string) $entry['ip'],
				(string) $entry['user'],
				'locked' === $entry['event'] ? 'locked out' : 'wrong details'
			)
		);
	}
}
\WP_CLI::add_command( 'wow-guard status', __NAMESPACE__ . '\\status' );

/**
 * Says who has a second factor, and takes one away.
 *
 * The taking away is the point. A second factor is meant to be hard to get
 * past, and the day somebody loses both their phone and the piece of paper
 * with the recovery codes on it, this is the only way back — which is why it
 * lives here, where it needs a shell on the server rather than a password.
 *
 * ## OPTIONS
 *
 * <command>
 * : list, or disable.
 *
 * [<user>]
 * : Whose, for disable. A login name, an email address or an ID.
 *
 * ## EXAMPLES
 *
 *     wp wow-guard 2fa list
 *     wp wow-guard 2fa disable mourat
 *
 * @param array<int,string>    $args       Positional arguments.
 * @param array<string,string> $assoc_args Options.
 */
function two_factor( array $args, array $assoc_args ): void {
	$command = (string) ( $args[0] ?? 'list' );

	if ( 'list' === $command ) {
		$rows = array();

		foreach ( get_users( array( 'fields' => array( 'ID', 'user_login' ) ) ) as $user ) {
			$how = \WOW\Guard\TwoFactor\method( (int) $user->ID );

			$rows[] = array(
				'user'     => $user->user_login,
				'method'   => '' === $how ? 'none' : $how,
				'recovery' => '' === $how ? '' : \WOW\Guard\TwoFactor\recovery_left( (int) $user->ID ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'user', 'method', 'recovery' ) );

		return;
	}

	if ( 'disable' !== $command ) {
		\WP_CLI::error( 'Say either list or disable.' );
	}

	$who  = (string) ( $args[1] ?? '' );
	$user = is_numeric( $who ) ? get_user_by( 'id', (int) $who ) : ( is_email( $who ) ? get_user_by( 'email', $who ) : get_user_by( 'login', $who ) );

	if ( ! $user ) {
		\WP_CLI::error( 'No such person.' );
	}

	if ( ! \WOW\Guard\TwoFactor\enabled( (int) $user->ID ) ) {
		\WP_CLI::success( sprintf( '%s had none to begin with.', $user->user_login ) );

		return;
	}

	\WOW\Guard\TwoFactor\disable( (int) $user->ID );

	\WP_CLI::success( sprintf( 'Two-step login is off for %s. Only the password stands between anybody and that account now, so set it up again.', $user->user_login ) );
}
\WP_CLI::add_command( 'wow-guard 2fa', __NAMESPACE__ . '\\two_factor' );
