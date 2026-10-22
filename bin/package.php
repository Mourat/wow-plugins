<?php
/**
 * Writes the list of what a release contains.
 *
 * Run by the workflow once the zips are made. Everything it needs is read
 * out of the plugins themselves, so adding a plugin to the repository is all
 * it takes to add it to a release.
 *
 * The zipping is left to the zip command in the workflow rather than done
 * here: ext-zip is not on every PHP, and a release that fails because of a
 * missing extension is a bad way to find that out.
 *
 * Usage: php bin/package.php <owner/repo> <tag>
 *
 * @package WOW\Updates
 */

declare( strict_types = 1 );

$repository = $argv[1] ?? '';
$tag        = $argv[2] ?? '';

if ( '' === $repository || '' === $tag ) {
	fwrite( STDERR, "usage: php bin/package.php <owner/repo> <tag>\n" );
	exit( 1 );
}

$root = dirname( __DIR__ );
$dist = $root . '/dist';

if ( ! is_dir( $dist ) && ! mkdir( $dist, 0777, true ) ) {
	fwrite( STDERR, "could not make $dist\n" );
	exit( 1 );
}

/**
 * The headers WordPress reads, out of a plugin's main file.
 *
 * @param string $file Path to the file.
 * @return array<string,string>
 */
function headers( string $file ): array {
	$top   = (string) file_get_contents( $file, false, null, 0, 8192 );
	$wants = array(
		'Plugin Name'       => 'name',
		'Version'           => 'version',
		'Requires at least' => 'requires',
		'Requires PHP'      => 'requires_php',
		'Tested up to'      => 'tested',
		'Update URI'        => 'uri',
	);

	$found = array();

	foreach ( $wants as $label => $key ) {
		$found[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $top, $m )
			? trim( (string) $m[1] )
			: '';
	}

	return $found;
}

$manifest = array(
	'repository' => $repository,
	'tag'        => $tag,
	'made'       => gmdate( 'c' ),
	'plugins'    => array(),
);

foreach ( glob( $root . '/plugins/*', GLOB_ONLYDIR ) ?: array() as $folder ) {
	$slug = basename( $folder );
	$main = $folder . '/' . $slug . '.php';

	if ( ! is_file( $main ) ) {
		fwrite( STDERR, "skipping $slug: no $slug.php\n" );

		continue;
	}

	$about = headers( $main );

	if ( '' === $about['version'] ) {
		fwrite( STDERR, "skipping $slug: no Version header\n" );

		continue;
	}

	$zip = $dist . '/' . $slug . '.zip';

	if ( ! is_file( $zip ) ) {
		fwrite( STDERR, "skipping $slug: $zip was not built\n" );

		continue;
	}

	$manifest['plugins'][ $slug ] = array(
		'name'         => $about['name'],
		'version'      => $about['version'],
		'requires'     => $about['requires'],
		'requires_php' => $about['requires_php'],
		'tested'       => $about['tested'],
		'package'      => sprintf( 'https://github.com/%s/releases/download/%s/%s.zip', $repository, rawurlencode( $tag ), $slug ),
	);

	printf( "%-20s %-8s %7d bytes\n", $slug, $about['version'], (int) filesize( $zip ) );
}

if ( array() === $manifest['plugins'] ) {
	fwrite( STDERR, "nothing to release\n" );
	exit( 1 );
}

file_put_contents( $dist . '/plugins.json', json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );

printf( "\n%d plugins, manifest written\n", count( $manifest['plugins'] ) );
