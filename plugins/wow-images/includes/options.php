<?php
/**
 * What the plugin has been told to do.
 *
 * All of it lives in a single option, read through get() so that a setting
 * added later has a value on a site that was configured before it existed.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Options;

use function WOW\Images\Formats\all;
use function WOW\Images\Formats\default_quality;
use function WOW\Images\Formats\known;

defined( 'ABSPATH' ) || exit;

/** The option everything is kept in. */
const OPTION = 'wow_images';

/** How the generated file is named beside the picture it came from. */
const NAMING_APPEND  = 'append';
const NAMING_REPLACE = 'replace';

/**
 * Every setting, with the value a site starts with.
 *
 * Both formats are on from the outset. A format the server cannot write is
 * skipped at the moment of writing rather than switched off here, so that
 * moving the site to a host that can write it needs no second thought.
 *
 * @return array<string,mixed>
 */
function defaults(): array {
	$formats   = array();
	$qualities = array();
	$retina    = array();

	foreach ( all() as $format ) {
		$formats[ $format ]   = true;
		$qualities[ $format ] = default_quality( $format );
		$retina[ $format ]    = retina_default( $format );
	}

	return array(
		'store'          => 'webp',
		'master_quality' => 82,
		'formats'        => $formats,
		'quality'        => $qualities,
		'quality_2x'     => $retina,
		'naming'         => NAMING_APPEND,
		'on_upload'      => true,
		'output'         => true,
		'min_gain'       => 5,
	);
}

/**
 * The format uploaded pictures are kept in, or an empty string for "as sent".
 *
 * Storing them as WebP is not an optimisation with a safety net: the JPEG is
 * gone afterwards, and every crop WordPress makes later comes out of the
 * WebP. It is here because on a site that is mostly photographs the saving is
 * the difference between buying more disk and not.
 */
function store(): string {
	$format = (string) ( all_options()['store'] ?? '' );

	return \WOW\Images\Formats\storable( $format ) && \WOW\Images\Formats\supported( $format ) ? $format : '';
}

/**
 * The quality the stored picture itself is encoded at.
 *
 * A setting of its own because this file is not only something a visitor
 * looks at: it is the only thing left to cut future crops out of, so what it
 * throws away is thrown away for good. The obvious move is to set it higher
 * than the crops, and on this site's photographs that turned out to be a
 * mistake — a WebP written at 86 came out *larger* than the JPEG it was made
 * from on 22% of them, which means those stay JPEG and gain nothing at all.
 * At 82 it is 4%. Raise it only after measuring, not on principle.
 */
function master_quality(): int {
	$quality = (int) ( all_options()['master_quality'] ?? 82 );

	return min( 100, max( 1, $quality ) );
}

/**
 * Every setting as it stands.
 *
 * @return array<string,mixed>
 */
function all_options(): array {
	$saved    = get_option( OPTION );
	$defaults = defaults();

	if ( ! is_array( $saved ) ) {
		return $defaults;
	}

	// Not wp_parse_args(): formats and quality are arrays in their own right,
	// and a shallow merge would let a half-written one hide a format added
	// to the plugin after this site was configured.
	$merged = array_merge( $defaults, $saved );

	foreach ( array( 'formats', 'quality', 'quality_2x' ) as $key ) {
		$merged[ $key ] = array_merge( $defaults[ $key ], is_array( $saved[ $key ] ?? null ) ? $saved[ $key ] : array() );
	}

	return $merged;
}

/**
 * One setting.
 *
 * @param string $key Setting name.
 * @return mixed The value, or null when there is no such setting.
 */
function get( string $key ) {
	return all_options()[ $key ] ?? null;
}

/**
 * Whether a format is switched on and this server can actually write it.
 */
function wanted( string $format ): bool {
	if ( ! known( $format ) ) {
		return false;
	}

	return ! empty( all_options()['formats'][ $format ] ) && \WOW\Images\Formats\supported( $format );
}

/**
 * The formats to generate copies in, best first.
 *
 * The stored format is never among them. A picture is going to be written in
 * that format anyway — it is what the file itself will be — and a copy of it
 * beside itself would be the same bytes twice.
 *
 * @return string[]
 */
function active(): array {
	$store = store();

	return array_values(
		array_filter(
			all(),
			static fn ( string $format ): bool => $format !== $store && wanted( $format )
		)
	);
}

/**
 * The quality a format is encoded at.
 */
function quality( string $format ): int {
	$quality = (int) ( all_options()['quality'][ $format ] ?? default_quality( $format ) );

	return min( 100, max( 1, $quality ) );
}

/**
 * Where the quality for a double-density size starts from.
 *
 * Lower than the ordinary one, and not by guesswork. A 2x file is only ever
 * drawn at half its pixel size, so the browser averages four of its pixels
 * into every one the eye receives — and an encoder's artefacts, which live in
 * the finest detail, are exactly what that averaging destroys. Spending bytes
 * on detail that is thrown away before anybody sees it is the one saving in
 * image compression that costs nothing at all.
 *
 * Measured on this site's own 2x files: AVIF at 45 comes to about two thirds
 * of what 55 costs, WebP at 70 to about three quarters of 82.
 */
function retina_default( string $format ): int {
	return 'avif' === $format ? 45 : 70;
}

/**
 * The quality a double-density size is encoded at.
 */
function retina_quality( string $format ): int {
	$quality = (int) ( all_options()['quality_2x'][ $format ] ?? retina_default( $format ) );

	return min( 100, max( 1, $quality ) );
}

/**
 * How much smaller a copy has to be before it is worth keeping, as a fraction.
 *
 * A WebP that saves two percent is not worth a second file on disk, a second
 * entry in the backup, and a second thing to go wrong. The threshold is what
 * separates a copy that earns its place from one that merely exists.
 */
function min_gain(): float {
	return min( 90, max( 0, (int) get( 'min_gain' ) ) ) / 100;
}

/**
 * Brings a submitted form into the shape the rest of the plugin expects.
 *
 * Everything is read out by name and cast; nothing is carried across from the
 * submission as it arrived.
 *
 * @param mixed $input Raw form values.
 * @return array<string,mixed>
 */
function sanitize( $input ): array {
	$input  = is_array( $input ) ? $input : array();
	$clean  = defaults();
	$chosen = is_array( $input['formats'] ?? null ) ? $input['formats'] : array();

	foreach ( all() as $format ) {
		$clean['formats'][ $format ]    = ! empty( $chosen[ $format ] );
		$clean['quality'][ $format ]    = min( 100, max( 1, (int) ( $input['quality'][ $format ] ?? default_quality( $format ) ) ) );
		$clean['quality_2x'][ $format ] = min( 100, max( 1, (int) ( $input['quality_2x'][ $format ] ?? retina_default( $format ) ) ) );
	}

	$store = (string) ( $input['store'] ?? '' );

	$clean['store']          = \WOW\Images\Formats\storable( $store ) ? $store : '';
	$clean['master_quality'] = min( 100, max( 1, (int) ( $input['master_quality'] ?? 82 ) ) );
	$clean['naming']         = NAMING_REPLACE === ( $input['naming'] ?? '' ) ? NAMING_REPLACE : NAMING_APPEND;
	$clean['on_upload']      = ! empty( $input['on_upload'] );
	$clean['output']         = ! empty( $input['output'] );
	$clean['min_gain']       = min( 90, max( 0, (int) ( $input['min_gain'] ?? 5 ) ) );

	return $clean;
}
