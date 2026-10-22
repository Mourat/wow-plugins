<?php
/**
 * The settings screen.
 *
 * Written to be read by somebody deciding, not by somebody who already knows:
 * each setting says what it actually buys, and the two that do not buy
 * security say so outright rather than letting a checkbox imply it.
 *
 * @package WOW\Guard
 */

namespace WOW\Guard\Admin;

use WOW\Guard\Attempts;
use WOW\Guard\Log;
use WOW\Guard\LoginUrl;
use WOW\Guard\Options;
use WOW\Guard\Updates;
use WOW\Guard\Uploads;

defined( 'ABSPATH' ) || exit;

/** Where the settings live. */
const PAGE = 'wow-guard';

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
		__( 'Security', 'wow-guard' ),
		__( 'Security', 'wow-guard' ),
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
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . PAGE ) ), esc_html__( 'Settings', 'wow-guard' ) )
		);
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WOW_GUARD_FILE ), __NAMESPACE__ . '\\action_links' );

/**
 * Lifts every lockout, when somebody asks.
 */
function handle_unlock(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-guard' ) );
	}

	check_admin_referer( 'wow-guard-unlock' );

	Attempts\forget_all();
	Log\clear();

	wp_safe_redirect( admin_url( 'options-general.php?page=' . PAGE . '&unlocked=1' ) );
	exit;
}
add_action( 'admin_post_wow_guard_unlock', __NAMESPACE__ . '\\handle_unlock' );

/**
 * One checkbox.
 *
 * @param array<string,mixed> $options The settings.
 * @param string              $key     Which one.
 * @param string              $label   What it says.
 */
function checkbox( array $options, string $key, string $label ): void {
	printf(
		'<p><label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label></p>',
		esc_attr( Options\OPTION ),
		esc_attr( $key ),
		checked( ! empty( $options[ $key ] ), true, false ),
		esc_html( $label )
	);
}

/**
 * A paragraph of explanation.
 *
 * @param string $text What to say.
 */
function note( string $text ): void {
	printf( '<p class="description" style="max-width:46em">%s</p>', esc_html( $text ) );
}

/**
 * Whether anything on this page is actually happening.
 *
 * Everything below installs itself through WP-Cron, and WP-Cron fails
 * quietly: the request that starts a run is sent without waiting for an
 * answer, so a site that cannot reach itself looks exactly like a site with
 * nothing to do. Switches set here then mean nothing, and the first anybody
 * hears of it is a version that was never updated.
 */
function scheduler(): void {
	$behind = Updates\behind();

	if ( $behind < Updates\LATE ) {
		printf(
			'<p><strong>%s</strong> %s</p>',
			esc_html__( 'Running.', 'wow-guard' ),
			esc_html__( 'Nothing scheduled is overdue, so what is set below is being acted on.', 'wow-guard' )
		);

		return;
	}

	printf(
		'<div class="notice notice-error inline" style="margin:0 0 1em"><p><strong>%s</strong></p><p>%s</p></div>',
		sprintf(
			/* translators: %s: a length of time, such as "2 days". */
			esc_html__( 'Nothing scheduled has run for %s.', 'wow-guard' ),
			esc_html( human_time_diff( time() - $behind ) )
		),
		esc_html__( 'Until that is put right, nothing on this page installs itself, however it is set. WordPress runs what it has scheduled when somebody asks the site for a page, by quietly asking itself for one in turn.', 'wow-guard' )
	);

	$why = Updates\loopback();

	if ( '' === $why ) {
		note( __( 'The site can reach itself, so the fault is elsewhere — a visitor has to arrive for anything to run, and nothing has arrived. A real cron on the server is the cure for a site nobody visits.', 'wow-guard' ) );

		return;
	}

	printf(
		'<p>%s</p>',
		sprintf(
			/* translators: 1: a URL, 2: an error message. */
			esc_html__( 'The site cannot reach itself at %1$s: %2$s', 'wow-guard' ),
			'<code>' . esc_html( Updates\loopback_url() ) . '</code>',
			'<em>' . esc_html( $why ) . '</em>'
		)
	);

	note( __( 'That address is the one in Settings, and the server has to be able to open it from where it is standing. Inside a container it often cannot: the port the site is published on belongs to the machine outside.', 'wow-guard' ) );
}

/**
 * Draws the page.
 */
function page(): void {
	$options = Options\all_options();
	$name    = Options\OPTION;
	?>
	<div class="wrap wow-guard">
		<h1><?php esc_html_e( 'Security', 'wow-guard' ); ?></h1>

		<?php if ( isset( $_GET['unlocked'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Every lockout has been lifted and the record emptied.', 'wow-guard' ); ?></p></div>
		<?php endif; ?>

		<?php if ( LoginUrl\moved() ) : ?>
			<div class="notice notice-info">
				<p>
					<strong><?php esc_html_e( 'The login page is at:', 'wow-guard' ); ?></strong>
					<code><?php echo esc_html( LoginUrl\address() ); ?></code>
				</p>
				<p><?php esc_html_e( 'Write it down somewhere that is not this site. If it is ever lost, put a line in wp-config.php: define( \'WOW_GUARD_LOGIN_SLUG\', \'something\' ); — or run wp wow-guard login-url --off.', 'wow-guard' ); ?></p>
			</div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( Options\OPTION ); ?>

			<h2 class="title"><?php esc_html_e( 'The address of the login page', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wow-guard-slug"><?php esc_html_e( 'Secret address', 'wow-guard' ); ?></label></th>
					<td>
						<p>
							<code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code>
							<input type="text" id="wow-guard-slug" class="regular-text" name="<?php echo esc_attr( $name ); ?>[login_slug]"
								value="<?php echo esc_attr( (string) $options['login_slug'] ); ?>"
								placeholder="<?php esc_attr_e( 'leave empty for wp-login.php', 'wow-guard' ); ?>" />
							<code>/</code>
						</p>

						<?php
						note( __( 'This is not security, and it is worth being clear about that: anybody who has decided to break into this site in particular will find the page. What it is good for is everything else — the machines that work through lists of addresses trying wp-login.php on each one. They stop finding anything, the server stops answering them, and the record of failed attempts stops being noise.', 'wow-guard' ) );
						note( __( 'Once this is set, wp-login.php answers "not found", and so does wp-admin for anybody who is not already logged in — otherwise WordPress would send them to the new address and give it away.', 'wow-guard' ) );
						?>

						<?php if ( defined( 'WOW_GUARD_LOGIN_SLUG' ) ) : ?>
							<p class="description"><strong><?php esc_html_e( 'A line in wp-config.php is setting this and wins over the field above.', 'wow-guard' ); ?></strong></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Password guessing', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Attempts allowed', 'wow-guard' ); ?></th>
					<td>
						<p>
							<input type="number" min="1" max="50" class="small-text" name="<?php echo esc_attr( $name ); ?>[attempts]" value="<?php echo esc_attr( (string) $options['attempts'] ); ?>" />
							<?php esc_html_e( 'tries, then locked out for', 'wow-guard' ); ?>
							<input type="number" min="1" max="1440" class="small-text" name="<?php echo esc_attr( $name ); ?>[lockout]" value="<?php echo esc_attr( (string) $options['lockout'] ); ?>" />
							<?php esc_html_e( 'minutes. A count of failures is forgotten after', 'wow-guard' ); ?>
							<input type="number" min="5" max="1440" class="small-text" name="<?php echo esc_attr( $name ); ?>[window]" value="<?php echo esc_attr( (string) $options['window'] ); ?>" />
							<?php esc_html_e( 'minutes.', 'wow-guard' ); ?>
						</p>
						<?php note( __( 'This is the one thing here that stops an attack rather than making it quieter. It counts attempts wherever they come from, not only on the login form — XML-RPC authenticates through the same place, and that is the route a guarded login page normally gets walked around.', 'wow-guard' ) ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'The refusal message', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'same_error', __( 'Say the same thing whichever half was wrong', 'wow-guard' ) );
						note( __( 'WordPress is helpful about it: an unknown name and a wrong password get different messages. To somebody working through a list of names that is the answer to the only question worth asking.', 'wow-guard' ) );
						?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Visitors’ addresses', 'wow-guard' ); ?></th>
					<td>
						<?php checkbox( $options, 'behind_proxy', __( 'Something sits in front of this site — read the address from a header', 'wow-guard' ) ); ?>
						<p>
							<input type="text" class="regular-text code" name="<?php echo esc_attr( $name ); ?>[proxy_header]" value="<?php echo esc_attr( (string) $options['proxy_header'] ); ?>" />
						</p>
						<?php
						note( __( 'Leave this off unless a proxy or a CDN really is in front of the site. Turned on without one, anybody can send whatever address they like and never be locked out; left off when there is one, every visitor looks like the proxy and the first mistyped password locks out the world.', 'wow-guard' ) );
						printf(
							'<p class="description">%s <code>%s</code></p>',
							esc_html__( 'This request appears to come from:', 'wow-guard' ),
							esc_html( \WOW\Guard\Visitor\address() ?: __( 'nowhere — no address was given', 'wow-guard' ) )
						);
						?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Who may be named in public', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'User names', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'hide_names', __( 'Do not let the site say who its users are', 'wow-guard' ) );
						note( __( 'Guessing a password needs two things, and WordPress gives one away: ask for /?author=1 and it answers with the name. With the name known, the guessing has only one thing left to find. This closes that, the same question asked of the REST API, and the author archive.', 'wow-guard' ) );
						?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Doors this site does not use', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Leave open', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'xmlrpc', __( 'XML-RPC', 'wow-guard' ) );
						note( __( 'For desktop blogging clients and the WordPress mobile app. Its system.multicall carries any number of password attempts in one request, which is why it is the usual way round a guarded login page.', 'wow-guard' ) );

						checkbox( $options, 'app_passwords', __( 'Application passwords', 'wow-guard' ) );
						note( __( 'A second kind of credential, for programs talking to the site. Nothing here uses one.', 'wow-guard' ) );

						checkbox( $options, 'file_editor', __( 'The theme and plugin file editor in the admin', 'wow-guard' ) );
						note( __( 'Without it, somebody who gets hold of an administrator session still has to find a way to run code. With it, the admin has a text editor for exactly that and a Save button.', 'wow-guard' ) );

						checkbox( $options, 'hide_versions', __( 'Keep the WordPress and PHP versions to ourselves', 'wow-guard' ) );
						note( __( 'Worth little against anybody paying attention, but it is what the indiscriminate scanners read, and they are most of the traffic.', 'wow-guard' ) );
						?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Keeping up to date', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'The scheduler', 'wow-guard' ); ?></th>
					<td><?php scheduler(); ?></td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'WordPress itself', 'wow-guard' ); ?></th>
					<td>
						<select name="<?php echo esc_attr( $name ); ?>[auto_core]">
							<option value="minor" <?php selected( 'minor', $options['auto_core'] ); ?>><?php esc_html_e( 'Install security and maintenance releases by itself (recommended)', 'wow-guard' ); ?></option>
							<option value="all" <?php selected( 'all', $options['auto_core'] ); ?>><?php esc_html_e( 'Install everything by itself, new major versions included', 'wow-guard' ); ?></option>
							<option value="off" <?php selected( 'off', $options['auto_core'] ); ?>><?php esc_html_e( 'Install nothing by itself', 'wow-guard' ); ?></option>
						</select>
						<?php note( __( 'Most sites that are broken into are running a version with a hole that was published and fixed months earlier. A security release only fixes; a new major version can change the editor this site is built on, which is better taken deliberately with somebody looking at the result.', 'wow-guard' ) ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'Themes and plugins', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'auto_themes', __( 'Update themes by themselves', 'wow-guard' ) );
						checkbox( $options, 'auto_plugins', __( 'Update plugins by themselves', 'wow-guard' ) );
						note( __( 'Only things with somewhere to update from are affected. The plugins and the theme written for this site have no such place and are untouched either way — but this site’s theme is a child of a WordPress one, and a child theme is no safer than the parent it stands on.', 'wow-guard' ) );
						?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'The rest', 'wow-guard' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'What the browser is told', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'headers', __( 'Send the usual protective headers', 'wow-guard' ) );
						note( __( 'nosniff, so a browser cannot decide a file is something other than what it was sent as; SAMEORIGIN, so another site cannot put this one in a frame and collect clicks meant for it; a referrer policy; and a refusal of camera, microphone and location to anything embedded in a page.', 'wow-guard' ) );

						checkbox( $options, 'hsts', __( 'Insist on HTTPS for a year (HSTS)', 'wow-guard' ) );
						note( __( 'Only sent over HTTPS. A browser remembers it for as long as it is told to, so a site that sends this and later loses its certificate becomes unreachable rather than insecure. Switch it on once the site is live and the certificate is settled.', 'wow-guard' ) );
						?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php esc_html_e( 'The uploads folder', 'wow-guard' ); ?></th>
					<td>
						<?php
						checkbox( $options, 'no_php_uploads', __( 'Do not let anything in it be executed', 'wow-guard' ) );
						note( __( 'The uploads folder is the one place the outside world can put files. Everything that belongs there is a picture; if anything else ever lands there, this is the difference between a nuisance and a disaster.', 'wow-guard' ) );

						$state = Uploads\state();

						if ( 'ours' === $state ) {
							printf( '<p class="description"><strong>%s</strong></p>', esc_html__( 'The file is in place.', 'wow-guard' ) );
						} elseif ( 'theirs' === $state ) {
							printf( '<p class="description"><strong>%s</strong></p>', esc_html__( 'There is already an .htaccess there that this plugin did not write, so it has been left alone.', 'wow-guard' ) );
						}

						note( __( 'This is an Apache instruction. On nginx the same thing has to be said in the server configuration instead, and this setting will do nothing.', 'wow-guard' ) );
						?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Recent failed attempts', 'wow-guard' ); ?></h2>

		<?php
		$log = Log\entries();

		if ( empty( $log ) ) {
			printf( '<p>%s</p>', esc_html__( 'Nobody has failed to log in lately.', 'wow-guard' ) );
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			printf( '<th>%s</th><th>%s</th><th>%s</th><th>%s</th>', esc_html__( 'When', 'wow-guard' ), esc_html__( 'From', 'wow-guard' ), esc_html__( 'Name tried', 'wow-guard' ), esc_html__( 'What happened', 'wow-guard' ) );
			echo '</tr></thead><tbody>';

			foreach ( $log as $entry ) {
				printf(
					'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
					esc_html( sprintf( /* translators: %s: a length of time. */ __( '%s ago', 'wow-guard' ), human_time_diff( (int) $entry['time'] ) ) ),
					esc_html( (string) $entry['ip'] ),
					esc_html( (string) $entry['user'] ),
					'locked' === $entry['event'] ? esc_html__( 'locked out', 'wow-guard' ) : esc_html__( 'wrong details', 'wow-guard' )
				);
			}

			echo '</tbody></table>';
		}

		note( __( 'The last fifty, kept for a week and no longer. An address is personal information, and a security plugin that quietly accumulates a year of them has solved one problem by making another.', 'wow-guard' ) );
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wow-guard-unlock' ); ?>
			<input type="hidden" name="action" value="wow_guard_unlock" />
			<?php submit_button( __( 'Lift every lockout and empty the record', 'wow-guard' ), 'secondary' ); ?>
		</form>
	</div>
	<?php
}
