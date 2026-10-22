<?php
/**
 * The screens.
 *
 * A settings page under Settings, a column in the media library saying how
 * much each picture gained, and an entry in the media library's bulk actions
 * that hands a selection to the conversion screen in Tools.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Admin;

use WOW\Images\Formats;
use WOW\Images\Options;
use WOW\Images\Queue;
use WOW\Images\Record;

defined( 'ABSPATH' ) || exit;

/** Where the settings live. */
const PAGE = 'wow-images';

/**
 * Registers the option so that options.php will save it.
 */
function register(): void {
	register_setting(
		Options\OPTION,
		Options\OPTION,
		array(
			'type'              => 'array',
			'sanitize_callback' => __NAMESPACE__ . '\\sanitize',
			'default'           => Options\defaults(),
		)
	);
}
add_action( 'admin_init', __NAMESPACE__ . '\\register' );

/**
 * Cleans a submitted form.
 *
 * @param mixed $input Raw form values.
 * @return array<string,mixed>
 */
function sanitize( $input ): array {
	return Options\sanitize( $input );
}

/**
 * Puts the settings page in the menu.
 */
function add_page(): void {
	add_options_page(
		__( 'Image formats', 'wow-images' ),
		__( 'Image formats', 'wow-images' ),
		'manage_options',
		PAGE,
		__NAMESPACE__ . '\\settings_page'
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\\add_page' );

/**
 * Adds a settings link beside the plugin in the plugin list.
 *
 * @param string[] $links Existing links.
 * @return string[]
 */
function action_links( array $links ): array {
	if ( ! current_user_can( 'manage_options' ) ) {
		return $links;
	}

	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=' . PAGE ) ),
			esc_html__( 'Settings', 'wow-images' )
		)
	);

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WOW_IMAGES_FILE ), __NAMESPACE__ . '\\action_links' );

/**
 * A size in bytes, written the way a person reads it.
 *
 * @param int $bytes Size.
 */
function weight( int $bytes ): string {
	return size_format( max( 0, $bytes ), $bytes >= MB_IN_BYTES ? 1 : 0 );
}

/**
 * Draws the settings page.
 */
function settings_page(): void {
	$options = Options\all_options();
	$summary = Queue\summary();
	$engine  = Formats\engine();
	?>
	<div class="wrap wow-images">
		<h1><?php esc_html_e( 'Image formats', 'wow-images' ); ?></h1>

		<p class="description" style="max-width:46em">
			<?php esc_html_e( 'Beside every picture on the site this keeps a copy in one or more newer formats. The page offers all of them at once and each browser takes the best it understands, so nobody is sent a format they cannot open and nothing has to be chosen by hand.', 'wow-images' ); ?>
		</p>

		<h2 class="title"><?php esc_html_e( 'This server', 'wow-images' ); ?></h2>

		<table class="widefat striped wow-images-support">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Image library', 'wow-images' ); ?></th>
					<td><?php echo '' === $engine ? esc_html__( 'None — no picture can be converted.', 'wow-images' ) : esc_html( $engine ); ?></td>
				</tr>
				<?php foreach ( Formats\all() as $format ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( Formats\label( $format ) ); ?></th>
						<td>
							<?php if ( Formats\supported( $format ) ) : ?>
								<span class="wow-images-yes">&#10003; <?php esc_html_e( 'can be written here', 'wow-images' ); ?></span>
							<?php else : ?>
								<span class="wow-images-no">&#10007; <?php esc_html_e( 'cannot be written here', 'wow-images' ); ?></span>
								<p class="description">
									<?php esc_html_e( 'Files already made elsewhere are still offered to visitors. Only new pictures uploaded on this server will go without.', 'wow-images' ); ?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Pictures', 'wow-images' ); ?></th>
					<td>
						<?php
						printf(
							/* translators: 1: number of pictures, 2: number still waiting. */
							esc_html__( '%1$s on the site, %2$s still to convert.', 'wow-images' ),
							'<strong>' . esc_html( number_format_i18n( $summary['total'] ) ) . '</strong>',
							'<strong>' . esc_html( number_format_i18n( $summary['waiting'] ) ) . '</strong>'
						);
						?>
						<?php if ( $summary['saved'] > 0 ) : ?>
							<br>
							<?php
							printf(
								/* translators: 1: weight saved, 2: percentage. */
								esc_html__( 'A visitor whose browser takes the best format on offer loads %1$s less — %2$s of what the originals weigh.', 'wow-images' ),
								'<strong>' . esc_html( weight( $summary['saved'] ) ) . '</strong>',
								'<strong>' . esc_html( $summary['source'] > 0 ? round( $summary['saved'] / $summary['source'] * 100 ) . '%' : '—' ) . '</strong>'
							);
							?>
						<?php endif; ?>
						<p>
							<a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=' . \WOW\Images\Bulk\PAGE ) ); ?>">
								<?php esc_html_e( 'Convert the whole library', 'wow-images' ); ?>
							</a>
						</p>
					</td>
				</tr>
			</tbody>
		</table>

		<form method="post" action="options.php">
			<?php settings_fields( Options\OPTION ); ?>

			<h2 class="title"><?php esc_html_e( 'Settings', 'wow-images' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'How pictures are stored', 'wow-images' ); ?></th>
					<td>
						<fieldset>
							<p>
								<label>
									<input type="radio" name="<?php echo esc_attr( Options\OPTION ); ?>[store]" value="" <?php checked( '', (string) $options['store'] ); ?> />
									<?php esc_html_e( 'Keep each picture in the format it was uploaded in', 'wow-images' ); ?>
								</label>
							</p>
							<?php foreach ( Formats\all() as $format ) : ?>
								<?php if ( Formats\storable( $format ) ) : ?>
									<p>
										<label>
											<input type="radio" name="<?php echo esc_attr( Options\OPTION ); ?>[store]" value="<?php echo esc_attr( $format ); ?>" <?php checked( $format, (string) $options['store'] ); ?> <?php disabled( ! Formats\supported( $format ) ); ?> />
											<?php
											printf(
												/* translators: %s: format name, e.g. WebP. */
												esc_html__( 'Store every picture as %s', 'wow-images' ),
												esc_html( Formats\label( $format ) )
											);
											?>
										</label>
										<label style="margin-left:1.5em">
											<?php esc_html_e( 'quality', 'wow-images' ); ?>
											<input type="number" min="1" max="100" step="1" class="small-text" name="<?php echo esc_attr( Options\OPTION ); ?>[master_quality]" value="<?php echo esc_attr( (string) $options['master_quality'] ); ?>" />
										</label>
									</p>
								<?php endif; ?>
							<?php endforeach; ?>

							<p class="description" style="max-width:46em">
								<strong><?php esc_html_e( 'Storing means replacing.', 'wow-images' ); ?></strong>
								<?php esc_html_e( 'An uploaded JPEG is written out in the new format and the JPEG itself is not kept, so every crop made afterwards — today’s and any made years from now when a size changes — comes out of the converted file rather than out of the camera’s. That is why its quality is set separately here. Setting it higher than the crops is tempting and usually wrong: a copy that comes out larger than the picture it was made from is thrown away, and that picture then gains nothing. On this site’s photographs that happens to 4% of them at 82 and to 22% at 86.', 'wow-images' ); ?>
							</p>
							<p class="description" style="max-width:46em">
								<?php esc_html_e( 'AVIF is not offered as the stored format on purpose. A handful of browsers still cannot read one, and a picture kept only as AVIF would show them nothing at all.', 'wow-images' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Formats', 'wow-images' ); ?></th>
					<td>
						<fieldset>
							<?php foreach ( Formats\all() as $format ) : ?>
								<p>
									<label>
										<input type="checkbox" name="<?php echo esc_attr( Options\OPTION ); ?>[formats][<?php echo esc_attr( $format ); ?>]" value="1" <?php checked( ! empty( $options['formats'][ $format ] ) ); ?> />
										<?php echo esc_html( Formats\label( $format ) ); ?>
									</label>
									<label style="margin-left:1.5em">
										<?php esc_html_e( 'quality', 'wow-images' ); ?>
										<input type="number" min="1" max="100" step="1" class="small-text" name="<?php echo esc_attr( Options\OPTION ); ?>[quality][<?php echo esc_attr( $format ); ?>]" value="<?php echo esc_attr( (string) ( $options['quality'][ $format ] ?? Formats\default_quality( $format ) ) ); ?>" />
									</label>
									<label style="margin-left:1.5em">
										<?php esc_html_e( 'quality at 2×', 'wow-images' ); ?>
										<input type="number" min="1" max="100" step="1" class="small-text" name="<?php echo esc_attr( Options\OPTION ); ?>[quality_2x][<?php echo esc_attr( $format ); ?>]" value="<?php echo esc_attr( (string) ( $options['quality_2x'][ $format ] ?? Options\retina_default( $format ) ) ); ?>" />
									</label>
								</p>
							<?php endforeach; ?>

							<p class="description" style="max-width:46em">
								<?php esc_html_e( 'The second number is for the double-density sizes — the ones a phone or a Retina screen takes. Those are never drawn at their own size: four of their pixels are averaged into every pixel the eye receives, and an encoder’s artefacts live precisely in the detail that averaging destroys. Compressing them harder is the one saving in this whole business that costs nothing visible. On this site’s photographs it halves the weight of what most visitors actually download.', 'wow-images' ); ?>
							</p>

							<p class="description" style="max-width:46em">
								<?php esc_html_e( 'The two quality scales are not comparable — they belong to different encoders. The numbers offered here were measured on this site’s own photographs: AVIF at 55 lands around half the weight of the JPEG it came from, WebP at 82 around three quarters. Raising a number makes the copy larger and closer to the original; lowering it does the opposite. Changing either only affects pictures converted afterwards.', 'wow-images' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Smallest worthwhile saving', 'wow-images' ); ?></th>
					<td>
						<label>
							<input type="number" min="0" max="90" step="1" class="small-text" name="<?php echo esc_attr( Options\OPTION ); ?>[min_gain]" value="<?php echo esc_attr( (string) $options['min_gain'] ); ?>" />
							<?php esc_html_e( '%', 'wow-images' ); ?>
						</label>
						<p class="description" style="max-width:46em">
							<?php esc_html_e( 'A copy that does not come out at least this much smaller than the original is thrown away and not made again. Not every picture compresses: a flat drawing can leave a photographic encoder larger than it went in.', 'wow-images' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'File names', 'wow-images' ); ?></th>
					<td>
						<fieldset>
							<p>
								<label>
									<input type="radio" name="<?php echo esc_attr( Options\OPTION ); ?>[naming]" value="<?php echo esc_attr( Options\NAMING_APPEND ); ?>" <?php checked( Options\NAMING_APPEND, $options['naming'] ); ?> />
									<?php esc_html_e( 'Add the extension: picture.jpg.webp', 'wow-images' ); ?>
								</label>
							</p>
							<p>
								<label>
									<input type="radio" name="<?php echo esc_attr( Options\OPTION ); ?>[naming]" value="<?php echo esc_attr( Options\NAMING_REPLACE ); ?>" <?php checked( Options\NAMING_REPLACE, $options['naming'] ); ?> />
									<?php esc_html_e( 'Replace the extension: picture.webp', 'wow-images' ); ?>
								</label>
							</p>
							<p class="description" style="max-width:46em">
								<?php esc_html_e( 'Adding is the safe one: the name it is built from was already unique, so the copy cannot collide with anything. Replacing reads better and is what a server-side rewrite rule expects to find, but the day somebody uploads both picture.jpg and picture.png the two copies want the same name. Nothing here needs the rewrite rule — the page tells the browser about the copies itself.', 'wow-images' ); ?>
							</p>
						</fieldset>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'When to convert', 'wow-images' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Options\OPTION ); ?>[on_upload]" value="1" <?php checked( ! empty( $options['on_upload'] ) ); ?> />
							<?php esc_html_e( 'Convert a picture as it is uploaded', 'wow-images' ); ?>
						</label>
						<p class="description" style="max-width:46em">
							<?php esc_html_e( 'Switch this off on a server where encoding is slow enough to make an upload time out, and convert from the screen in Tools instead.', 'wow-images' ); ?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'On the site', 'wow-images' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="<?php echo esc_attr( Options\OPTION ); ?>[output]" value="1" <?php checked( ! empty( $options['output'] ) ); ?> />
							<?php esc_html_e( 'Offer the copies to visitors', 'wow-images' ); ?>
						</label>
						<p class="description" style="max-width:46em">
							<?php esc_html_e( 'Switched off, the copies go on being made and kept but every visitor is served the original. Useful for finding out whether something on the page is upset by the change, without undoing an afternoon of encoding.', 'wow-images' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Adds the column to the media library.
 *
 * @param string[] $columns Existing columns.
 * @return string[]
 */
function media_columns( array $columns ): array {
	$columns['wow_images'] = __( 'Formats', 'wow-images' );

	return $columns;
}
add_filter( 'manage_media_columns', __NAMESPACE__ . '\\media_columns' );

/**
 * Fills the column.
 *
 * @param string $column Column name.
 * @param int    $id     Attachment ID.
 */
function media_column( $column, $id ): void {
	if ( 'wow_images' !== $column ) {
		return;
	}

	$record = Record\read( (array) wp_get_attachment_metadata( (int) $id ) );
	$totals = Record\totals( $record );

	if ( empty( $totals ) ) {
		echo '<span class="wow-images-none">&mdash;</span>';

		return;
	}

	$saving = Record\saving( $record );
	$labels = array();

	foreach ( $totals as $format => $total ) {
		$labels[] = sprintf(
			'%s %s',
			esc_html( Formats\label( $format ) ),
			esc_html( weight( (int) $total['copy'] ) )
		);
	}

	printf(
		'<span class="wow-images-saving">&minus;%d%%</span><br><span class="wow-images-detail">%s</span>',
		(int) $saving,
		implode( '<br>', $labels ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
	);
}
add_action( 'manage_media_custom_column', __NAMESPACE__ . '\\media_column', 10, 2 );

/**
 * Offers converting a selection straight from the media library.
 *
 * @param array<string,string> $actions Existing bulk actions.
 * @return array<string,string>
 */
function bulk_action( array $actions ): array {
	if ( current_user_can( 'upload_files' ) ) {
		$actions['wow_images_convert'] = __( 'Convert to WebP and AVIF', 'wow-images' );
	}

	return $actions;
}
add_filter( 'bulk_actions-upload', __NAMESPACE__ . '\\bulk_action' );

/**
 * Hands the selection to the conversion screen.
 *
 * The work is not done here: a selection can be hundreds of pictures, and a
 * single request has neither the time nor anywhere to show progress. The
 * screen in Tools has both.
 *
 * @param string $redirect Where WordPress means to go next.
 * @param string $action   Which bulk action was chosen.
 * @param int[]  $ids      The selected attachments.
 * @return string Where to go instead.
 */
function handle_bulk_action( $redirect, $action, $ids ): string {
	if ( 'wow_images_convert' !== $action || ! current_user_can( 'upload_files' ) ) {
		return $redirect;
	}

	return add_query_arg(
		array(
			'page' => \WOW\Images\Bulk\PAGE,
			'ids'  => implode( ',', array_map( 'intval', (array) $ids ) ),
		),
		admin_url( 'tools.php' )
	);
}
add_filter( 'handle_bulk_actions-upload', __NAMESPACE__ . '\\handle_bulk_action', 10, 3 );

/**
 * Loads the admin stylesheet where it is used.
 *
 * @param string $hook Current admin page.
 */
function assets( string $hook ): void {
	$ours = array( 'upload.php', 'settings_page_' . PAGE, 'tools_page_' . \WOW\Images\Bulk\PAGE );

	if ( ! in_array( $hook, $ours, true ) ) {
		return;
	}

	wp_enqueue_style( 'wow-images-admin', WOW_IMAGES_URL . 'assets/admin.css', array(), WOW_IMAGES_VERSION );
}
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\\assets' );
