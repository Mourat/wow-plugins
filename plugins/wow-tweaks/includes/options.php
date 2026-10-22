<?php
/**
 * What the tweaks have been told to do.
 *
 * One option holding all of them, so a tweak added later still has a value on
 * a site configured before it existed.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Options;

defined( 'ABSPATH' ) || exit;

/** The option everything is kept in. */
const OPTION = 'wow_tweaks';

/** What "keep every revision there has ever been" is written as. */
const UNLIMITED = -1;

/**
 * Every setting, with the value a site starts with.
 *
 * @return array<string,mixed>
 */
function defaults(): array {
	return array(
		'revisions' => 3,
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
 * How many revisions a post may keep.
 *
 * Zero switches revisions off entirely, which WordPress understands; -1 means
 * keep them all, which is what WordPress does when nobody says otherwise.
 */
function revisions(): int {
	$keep = (int) get( 'revisions' );

	return $keep < 0 ? UNLIMITED : min( 1000, $keep );
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

	$keep = $input['revisions'] ?? 3;

	$clean['revisions'] = '' === $keep || ! is_numeric( $keep ) || (int) $keep < 0
		? UNLIMITED
		: min( 1000, (int) $keep );

	return $clean;
}
