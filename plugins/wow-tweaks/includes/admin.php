<?php
/**
 * The settings screen.
 *
 * One section per tweak. There is one tweak so far; the shape is here so the
 * second one is an addition rather than a rewrite.
 *
 * @package WOW\Tweaks
 */

namespace WOW\Tweaks\Admin;

use WOW\Tweaks\Options;
use WOW\Tweaks\Revisions;

defined( 'ABSPATH' ) || exit;

/** Where the settings live. */
const PAGE = 'wow-tweaks';

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
 * Puts the page in the menu.
 */
function add_page(): void {
	add_options_page(
		__( 'Site tweaks', 'wow-tweaks' ),
		__( 'Site tweaks', 'wow-tweaks' ),
		'manage_options',
		PAGE,
		__NAMESPACE__ . '\\page'
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
	if ( current_user_can( 'manage_options' ) ) {
		array_unshift(
			$links,
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'options-general.php?page=' . PAGE ) ),
				esc_html__( 'Settings', 'wow-tweaks' )
			)
		);
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WOW_TWEAKS_FILE ), __NAMESPACE__ . '\\action_links' );

/**
 * Says what the last save threw away.
 */
function notices(): void {
	$pruned = get_transient( 'wow_tweaks_pruned' );

	if ( ! is_array( $pruned ) ) {
		return;
	}

	delete_transient( 'wow_tweaks_pruned' );

	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: number of revisions, 2: number of posts, 3: size freed. */
				__( '%1$d old revisions of %2$d pages were deleted, freeing %3$s.', 'wow-tweaks' ),
				(int) $pruned['deleted'],
				(int) $pruned['posts'],
				size_format( (int) $pruned['bytes'], 1 )
			)
		)
	);
}
add_action( 'admin_notices', __NAMESPACE__ . '\\notices' );

/**
 * Draws the page.
 */
function page(): void {
	$options = Options\all_options();
	$keep    = Options\revisions();
	$waiting = Options\UNLIMITED === $keep ? array( 'deleted' => 0 ) : Revisions\prune( true );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Site tweaks', 'wow-tweaks' ); ?></h1>

		<form method="post" action="options.php">
			<?php settings_fields( Options\OPTION ); ?>

			<h2 class="title"><?php esc_html_e( 'Revisions', 'wow-tweaks' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Versions kept per page', 'wow-tweaks' ); ?></th>
					<td>
						<input type="number" min="0" max="1000" step="1" class="small-text"
							name="<?php echo esc_attr( Options\OPTION ); ?>[revisions]"
							value="<?php echo esc_attr( Options\UNLIMITED === $keep ? '' : (string) $keep ); ?>"
							placeholder="<?php esc_attr_e( 'all', 'wow-tweaks' ); ?>" />

						<p class="description" style="max-width:46em">
							<?php esc_html_e( 'Leave empty to keep every version, as WordPress does by default. Zero switches versions off altogether.', 'wow-tweaks' ); ?>
						</p>

						<p class="description" style="max-width:46em">
							<?php esc_html_e( 'On a gallery site a version is not a few paragraphs of text: it is the whole arrangement of every picture on the page, written out again each time anything is nudged. They also name the pictures under the names those pictures had at the time, so old versions go on referring to files that have since been renamed or converted.', 'wow-tweaks' ); ?>
						</p>

						<?php if ( ! empty( $waiting['deleted'] ) ) : ?>
							<p class="description" style="max-width:46em">
								<strong>
									<?php
									printf(
										/* translators: 1: number of revisions, 2: number of pages, 3: size. */
										esc_html__( 'Saving this page now would delete %1$d old versions across %2$d pages and free %3$s. This cannot be undone.', 'wow-tweaks' ),
										(int) $waiting['deleted'],
										(int) $waiting['posts'],
										esc_html( size_format( (int) $waiting['bytes'], 1 ) )
									);
									?>
								</strong>
							</p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}
