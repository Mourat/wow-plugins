<?php
/**
 * What the guard has been told to do.
 *
 * Everything defaults to on except the two settings that can lock somebody
 * out of their own site or out of their own visitors: the renamed login
 * address, and the header to read a visitor's address from. Those are
 * deliberate choices, not defaults.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Options;

defined( 'ABSPATH' ) || exit;

/** The option everything is kept in. */
const OPTION = 'wow_guard';

/**
 * Every setting, with the value a site starts with.
 *
 * @return array<string,mixed>
 */
function defaults(): array {
	return array(
		// Password guessing.
		'attempts'      => 5,
		'lockout'       => 20,   // minutes.
		'window'        => 60,   // minutes a count of failures survives.
		'same_error'    => true,

		// Who may be named in public.
		'hide_names'    => true,

		// Doors nobody here uses.
		'xmlrpc'        => false,
		'app_passwords' => false,
		'file_editor'   => false,
		'hide_versions' => true,

		// What the browser is told.
		'headers'       => true,
		'hsts'          => false,

		// The uploads folder.
		'no_php_uploads' => true,

		// Keeping up to date. 'minor' is what WordPress does on its own:
		// security and maintenance releases install themselves, a new major
		// version waits to be asked for.
		'auto_core'     => 'minor',
		'auto_themes'   => true,
		'auto_plugins'  => true,

		// The login address. Empty means wp-login.php, as WordPress has it.
		'login_slug'    => '',

		// Reading the visitor's address.
		'behind_proxy'  => false,
		'proxy_header'  => 'HTTP_X_FORWARDED_FOR',
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
 * The login address, which a constant in wp-config.php may override.
 *
 * The constant is the way back in. A slug saved in the database and then
 * forgotten is a locked door with the key inside; a line in wp-config.php can
 * be changed over FTP by somebody who cannot log in at all.
 */
function login_slug(): string {
	if ( defined( 'WOW_GUARD_LOGIN_SLUG' ) ) {
		return sanitize_title( (string) \WOW_GUARD_LOGIN_SLUG );
	}

	return sanitize_title( (string) get( 'login_slug' ) );
}

/**
 * Brings a submitted form into the shape the rest of the plugin expects.
 *
 * @param mixed $input Raw form values.
 * @return array<string,mixed>
 */
function sanitize( $input ): array {
	$input = is_array( $input ) ? $input : array();
	$clean = defaults();

	$clean['attempts'] = min( 50, max( 1, (int) ( $input['attempts'] ?? 5 ) ) );
	$clean['lockout']  = min( 1440, max( 1, (int) ( $input['lockout'] ?? 20 ) ) );
	$clean['window']   = min( 1440, max( 5, (int) ( $input['window'] ?? 60 ) ) );

	foreach ( array( 'same_error', 'hide_names', 'xmlrpc', 'app_passwords', 'file_editor', 'hide_versions', 'headers', 'hsts', 'no_php_uploads', 'behind_proxy', 'auto_themes', 'auto_plugins' ) as $flag ) {
		$clean[ $flag ] = ! empty( $input[ $flag ] );
	}

	$core = (string) ( $input['auto_core'] ?? 'minor' );

	$clean['auto_core'] = in_array( $core, array( 'off', 'minor', 'all' ), true ) ? $core : 'minor';

	$slug = sanitize_title( (string) ( $input['login_slug'] ?? '' ) );

	// Names WordPress itself owns would make the site unreachable.
	$clean['login_slug'] = in_array( $slug, array( 'wp-admin', 'wp-login', 'wp-content', 'wp-includes', 'admin', 'login' ), true ) ? '' : $slug;

	$header = strtoupper( preg_replace( '/[^A-Za-z0-9_]/', '', (string) ( $input['proxy_header'] ?? '' ) ) ?? '' );

	$clean['proxy_header'] = '' === $header ? 'HTTP_X_FORWARDED_FOR' : $header;

	return $clean;
}
