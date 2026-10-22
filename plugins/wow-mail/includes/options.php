<?php
/**
 * What the mail settings are.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Options;

use WOW\Mail\Secret;

defined( 'ABSPATH' ) || exit;

/** The option everything is kept in. */
const OPTION = 'wow_mail';

/**
 * Every setting, with the value a site starts with.
 *
 * @return array<string,mixed>
 */
function defaults(): array {
	return array(
		'enabled'    => false,
		'host'       => '',
		'port'       => 587,
		'encryption' => 'tls',
		'auth'       => true,
		'username'   => '',
		'password'   => '',
		'from'       => '',
		'from_name'  => '',
		'force_from' => true,
		'keep_log'   => true,
	);
}

/**
 * Every setting as it stands.
 *
 * @return array<string,mixed>
 */
function all_options(): array {
	$saved = get_option( OPTION );

	return is_array( $saved ) ? array_merge( defaults(), $saved ) : defaults();
}

/**
 * One setting.
 *
 * @param string $key Setting name.
 * @return mixed
 */
function get( string $key ) {
	return all_options()[ $key ] ?? null;
}

/**
 * The password, from wherever it is kept.
 *
 * A line in wp-config.php wins, because it is the one place this plugin would
 * rather it were.
 */
function password(): string {
	if ( defined( 'WOW_MAIL_PASSWORD' ) ) {
		return (string) \WOW_MAIL_PASSWORD;
	}

	return Secret\open( (string) get( 'password' ) );
}

/**
 * Where the password is kept, for saying so on screen.
 *
 * @return string 'config', 'database' or 'nowhere'.
 */
function password_lives(): string {
	if ( defined( 'WOW_MAIL_PASSWORD' ) ) {
		return 'config';
	}

	return '' === (string) get( 'password' ) ? 'nowhere' : 'database';
}

/**
 * Whether there is enough here to send anything.
 */
function ready(): bool {
	if ( ! get( 'enabled' ) || '' === trim( (string) get( 'host' ) ) ) {
		return false;
	}

	return ! get( 'auth' ) || ( '' !== trim( (string) get( 'username' ) ) && '' !== password() );
}

/**
 * The address mail should come from.
 *
 * Falling back to the site's own domain rather than to WordPress's
 * wordpress@… , which is almost never a mailbox that exists and is read by
 * spam filters as exactly the sort of thing it is.
 */
function from(): string {
	$set = trim( (string) get( 'from' ) );

	if ( is_email( $set ) ) {
		return $set;
	}

	return (string) get_option( 'admin_email' );
}

/**
 * The name mail should come from.
 */
function from_name(): string {
	$set = trim( (string) get( 'from_name' ) );

	return '' === $set ? (string) get_bloginfo( 'name' ) : $set;
}

/**
 * Brings a submitted form into the shape the rest of the plugin expects.
 *
 * @param mixed $input Raw form values.
 * @return array<string,mixed>
 */
function sanitize( $input ): array {
	$input   = is_array( $input ) ? $input : array();
	$clean   = defaults();
	$current = all_options();

	$clean['enabled']    = ! empty( $input['enabled'] );
	$clean['auth']       = ! empty( $input['auth'] );
	$clean['force_from'] = ! empty( $input['force_from'] );
	$clean['keep_log']   = ! empty( $input['keep_log'] );

	$clean['host']      = sanitize_text_field( (string) ( $input['host'] ?? '' ) );
	$clean['port']      = min( 65535, max( 1, (int) ( $input['port'] ?? 587 ) ) );
	$clean['username']  = sanitize_text_field( (string) ( $input['username'] ?? '' ) );
	$clean['from']      = sanitize_email( (string) ( $input['from'] ?? '' ) );
	$clean['from_name'] = sanitize_text_field( (string) ( $input['from_name'] ?? '' ) );

	$encryption = (string) ( $input['encryption'] ?? 'tls' );

	$clean['encryption'] = in_array( $encryption, array( 'none', 'ssl', 'tls' ), true ) ? $encryption : 'tls';

	/*
	 * The password field is never filled in with what is already stored, so
	 * an empty one means "leave it alone" rather than "clear it". Clearing is
	 * asked for separately, or there would be no way to save any other
	 * setting without retyping it.
	 */
	$typed = (string) ( $input['password'] ?? '' );

	if ( ! empty( $input['forget_password'] ) ) {
		$clean['password'] = '';
	} elseif ( '' !== $typed ) {
		$clean['password'] = Secret\seal( $typed );
	} else {
		$clean['password'] = (string) ( $current['password'] ?? '' );
	}

	return $clean;
}
