<?php
/**
 * Alternative text for pictures that have none.
 *
 * Sixteen hundred photographs on this site and not one of them had any: a
 * screen reader announced them as "image", and a search engine read a page
 * of pictures as a page of nothing. Writing sixteen hundred descriptions by
 * hand is not going to happen, so they are built from what the site already
 * knows.
 *
 * What it knows is the album a picture sits in and the name of its file.
 * The album is the better half: "Murs pierres sèches", "Monuments
 * historiques" — these are the words somebody would search for. The file
 * name is the lesser half and comes in three sorts:
 *
 *     appareillage-de-mur151   the album's own name with a number
 *     moulin-du-bonheur23      something the album does not say
 *     WhatsApp-Image-2026…     nothing at all
 *
 * Only the middle sort is worth keeping, so the name is used when it adds a
 * word the album has not already said, and dropped otherwise. The result is
 * honest but structural: it says where the picture belongs, not what is in
 * the frame. That is better than silence and worse than a sentence written
 * by somebody who looked at it, which is a thing this can never replace.
 *
 * Nothing already written is touched. A picture with alt text has been
 * described by a person, and a person beats this.
 *
 * @package WOW\Blocks
 */

namespace WOW\Blocks\AltText;

use function WOW\Blocks\Albums\image_ids;

defined( 'ABSPATH' ) || exit;

/** Names a camera or a messenger gives a file, which say nothing. */
const NOISE = array(
	'whatsapp',
	'image',
	'img',
	'dsc',
	'dscn',
	'photo',
	'screenshot',
	'capture',
	'resized',
	'scaled',
	'copy',
	'final',
	'new',
);

/**
 * The words in a file name, with everything WordPress and the camera added.
 *
 * Strips the editor's suffixes (-e1792689014363), the size suffixes
 * (-754x330), the copy numbers (-1) and the bare digits that number a
 * series, then turns what is left into words.
 *
 * @param string $file Path or file name.
 * @return string[] The words, lower case, accents as they were typed.
 */
function words( string $file ): array {
	$stem = pathinfo( $file, PATHINFO_FILENAME );

	$stem = preg_replace( '/-e\d{10,}$/', '', $stem );
	$stem = preg_replace( '/-\d+x\d+(-\d+)*$/', '', (string) $stem );
	$stem = preg_replace( '/[_.]+/', ' ', (string) $stem );
	$stem = preg_replace( '/[-]+/', ' ', (string) $stem );
	$stem = preg_replace( '/\d+/', ' ', (string) $stem );

	$found = preg_split( '/\s+/u', mb_strtolower( trim( (string) $stem ) ) ) ?: array();

	return array_values(
		array_filter(
			$found,
			static fn ( string $word ): bool =>
				'' !== $word && ! in_array( $word, NOISE, true )
		)
	);
}

/**
 * The same words, flattened for comparing.
 *
 * Accents off and plurals ignored at the last letter, so that "murs" and
 * "mur" or "pierres" and "pierre" count as said already.
 *
 * @param string[] $some Words.
 * @return string[] Comparable forms.
 */
function flatten( array $some ): array {
	return array_map(
		static function ( string $word ): string {
			$plain = remove_accents( $word );

			return rtrim( mb_strtolower( $plain ), 's' );
		},
		$some
	);
}

/**
 * Whether a word has already been said by the album's own words.
 *
 * Not only word for word. File names here are full of abbreviations of the
 * album they belong to — charp-couv in Charpente couverture — and an
 * abbreviation adds nothing but noise, so a word that begins another, or is
 * begun by one, from three letters on, counts as said.
 *
 * @param string   $word One word from the file name, flattened.
 * @param string[] $said The album's words, flattened.
 * @return bool True when the album has said it already.
 */
function said_already( string $word, array $said ): bool {
	foreach ( $said as $known ) {
		if ( $word === $known ) {
			return true;
		}

		$short = min( mb_strlen( $word ), mb_strlen( $known ) );

		if ( $short >= 3 && 0 === mb_strpos( $known, mb_substr( $word, 0, $short ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * What a picture's alternative text should say.
 *
 * @param int    $id    Attachment id.
 * @param string $album The album's title.
 * @return string The text, or an empty string when there is nothing to say.
 */
function suggest( int $id, string $album ): string {
	$album = trim( wp_strip_all_tags( $album ) );
	$file  = (string) get_attached_file( $id );

	if ( '' === $album ) {
		return '';
	}

	$said  = flatten( preg_split( '/[^\p{L}]+/u', $album ) ?: array() );
	$named = words( $file );
	$extra = array();

	foreach ( $named as $word ) {
		if ( ! said_already( flatten( array( $word ) )[0], $said ) ) {
			$extra[] = $word;
		}
	}

	/*
	 * A single stray word is as likely to be a slip in the file name as a
	 * subject; two or more look like somebody naming the place or the job.
	 */
	if ( count( $extra ) < 2 ) {
		return $album;
	}

	/*
	 * The whole of the name, not only the words the album had not said:
	 * "moulin du bonheur" reads as French, "moulin bonheur" reads as a
	 * database.
	 */
	return $album . ' — ' . ucfirst( implode( ' ', $named ) );
}

/**
 * Every picture in every album, with the album it belongs to.
 *
 * A picture used in two albums takes the first it was found in: the second
 * says no more than the first, and the point is to have something true
 * rather than something exhaustive.
 *
 * @return array<int,string> Attachment id to album title.
 */
function all_pictures(): array {
	$where = array();

	foreach (
		get_posts(
			array(
				'post_type'   => 'wow_album',
				'numberposts' => -1,
				'post_status' => array( 'publish', 'draft', 'private' ),
			)
		) as $album
	) {
		foreach ( image_ids( $album->ID, true ) as $id ) {
			if ( ! isset( $where[ $id ] ) ) {
				$where[ $id ] = $album->post_title;
			}
		}
	}

	return $where;
}

/**
 * Writes the text where there is none.
 *
 * @param bool $apply False to work out what would be written and write
 *                    nothing.
 * @param int  $limit How many to write before stopping, 0 for all of them.
 *                    A screen filling sixteen hundred in one request has
 *                    nothing to show while it works, so it asks for a few
 *                    hundred at a time and draws a bar between the asks.
 * @return array{written: int, skipped: int, left: int, samples: array<string,string>}
 *         How many were written (or would be), how many were left alone
 *         because somebody had already described them, how many are still
 *         waiting beyond the limit, and one example from each album.
 */
function fill( bool $apply = false, int $limit = 0 ): array {
	$written = 0;
	$skipped = 0;
	$left    = 0;
	$samples = array();
	$seen    = array();

	foreach ( all_pictures() as $id => $album ) {
		// Asked for a batch and the batch is full: the rest are counted so
		// that whoever is watching a bar knows how far there is to go.
		if ( $limit > 0 && $written >= $limit ) {
			++$left;

			continue;
		}

		if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			++$skipped;

			continue;
		}

		$text = suggest( (int) $id, $album );

		if ( '' === $text ) {
			++$skipped;

			continue;
		}

		// One from each album rather than the first dozen of the first
		// album: the point of looking is to see the variety.
		if ( ! isset( $seen[ $album ] ) ) {
			$seen[ $album ]  = true;
			$samples[ wp_basename( (string) get_attached_file( $id ) ) ] = $text;
		}

		if ( $apply ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $text ) );
		}

		++$written;
	}

	return array(
		'written' => $written,
		'skipped' => $skipped,
		'left'    => $left,
		'samples' => $samples,
	);
}

/**
 * Fills in the pictures of one album as it is saved.
 *
 * So that a picture added next spring is described like the sixteen hundred
 * described today, without anybody remembering to run anything.
 *
 * @param int $post_id Album id.
 */
function on_album_saved( $post_id ): void {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	$album = get_post( $post_id );

	if ( ! $album || 'wow_album' !== $album->post_type ) {
		return;
	}

	foreach ( image_ids( (int) $post_id, true ) as $id ) {
		if ( '' !== trim( (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) ) ) {
			continue;
		}

		$text = suggest( (int) $id, $album->post_title );

		if ( '' !== $text ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $text ) );
		}
	}
}
add_action( 'save_post_wow_album', __NAMESPACE__ . '\\on_album_saved', 20 );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command(
		'wow-blocks alt',
		static function ( array $args, array $flags ): void {
			$apply = ! empty( $flags['apply'] );
			$done  = fill( $apply );

			foreach ( $done['samples'] as $file => $text ) {
				\WP_CLI::line( sprintf( '  %-44s %s', $file, $text ) );
			}

			\WP_CLI::line( '' );
			\WP_CLI::success(
				sprintf(
					$apply ? '%d written, %d left alone.' : '%d would be written, %d left alone. Add --apply to write them.',
					$done['written'],
					$done['skipped']
				)
			);
		},
		array( 'shortdesc' => 'Fills in alternative text for album pictures that have none.' )
	);
}
