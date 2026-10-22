<?php
/**
 * The settings screen.
 *
 * Laid out in the order the questions actually arrive: can this site send at
 * all, does what it sends get believed, and what has it been sending.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Admin;

use WOW\Mail\Dns;
use WOW\Mail\Log;
use WOW\Mail\Mailer;
use WOW\Mail\Options;

defined( 'ABSPATH' ) || exit;

/** Where the settings live. */
const PAGE = 'wow-mail';

/** Where the result of a test is kept until the page is drawn again. */
const RESULT = 'wow_mail_test_result';

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
		__( 'Mail', 'wow-mail' ),
		__( 'Mail', 'wow-mail' ),
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
			sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'options-general.php?page=' . PAGE ) ), esc_html__( 'Settings', 'wow-mail' ) )
		);
	}

	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WOW_MAIL_FILE ), __NAMESPACE__ . '\\action_links' );

/**
 * Sends a test message, when somebody asks.
 */
function handle_test(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-mail' ) );
	}

	check_admin_referer( 'wow-mail-test' );

	$to = isset( $_POST['to'] ) ? sanitize_email( wp_unslash( (string) $_POST['to'] ) ) : '';

	if ( ! is_email( $to ) ) {
		set_transient( RESULT, array( 'sent' => false, 'reason' => __( 'That is not an address.', 'wow-mail' ), 'conversation' => '' ), MINUTE_IN_SECONDS );
	} else {
		set_transient( RESULT, Mailer\test( $to ), 5 * MINUTE_IN_SECONDS );
	}

	wp_safe_redirect( admin_url( 'options-general.php?page=' . PAGE ) );
	exit;
}
add_action( 'admin_post_wow_mail_test', __NAMESPACE__ . '\\handle_test' );

/**
 * Empties the record, when somebody asks.
 */
function handle_clear(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do that.', 'wow-mail' ) );
	}

	check_admin_referer( 'wow-mail-clear' );
	Log\clear();

	wp_safe_redirect( admin_url( 'options-general.php?page=' . PAGE ) );
	exit;
}
add_action( 'admin_post_wow_mail_clear', __NAMESPACE__ . '\\handle_clear' );

/**
 * A paragraph of explanation.
 *
 * @param string $text What to say.
 */
function note( string $text ): void {
	printf( '<p class="description" style="max-width:46em">%s</p>', esc_html( $text ) );
}

/**
 * Draws the page.
 */
function page(): void {
	$options = Options\all_options();
	$name    = Options\OPTION;
	$dns     = Dns\report();
	$result  = get_transient( RESULT );

	if ( is_array( $result ) ) {
		delete_transient( RESULT );
	}
	?>
	<div class="wrap wow-mail">
		<h1><?php esc_html_e( 'Mail', 'wow-mail' ); ?></h1>

		<?php if ( is_array( $result ) ) : ?>
			<div class="notice <?php echo $result['sent'] ? 'notice-success' : 'notice-error'; ?>">
				<p>
					<strong><?php echo $result['sent'] ? esc_html__( 'The test message was accepted by the mail server.', 'wow-mail' ) : esc_html__( 'The test message did not go out.', 'wow-mail' ); ?></strong>
					<?php echo '' === $result['reason'] ? '' : esc_html( ' ' . $result['reason'] ); ?>
				</p>
				<?php if ( $result['sent'] ) : ?>
					<p><?php esc_html_e( 'Accepted is not the same as delivered. Look in the inbox, and then in the spam folder — which of the two it landed in is what the records below decide.', 'wow-mail' ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== $result['conversation'] ) : ?>
					<p><?php esc_html_e( 'The exchange with the mail server:', 'wow-mail' ); ?></p>
					<pre style="max-height:18em;overflow:auto;background:#f6f7f7;padding:.8em;font-size:12px"><?php echo esc_html( $result['conversation'] ); ?></pre>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2 class="title"><?php esc_html_e( 'Whether this will land in the inbox', 'wow-mail' ); ?></h2>

		<table class="widefat striped" style="max-width:52em">
			<tbody>
				<tr>
					<td style="width:16em"><strong><?php esc_html_e( 'Sending through', 'wow-mail' ); ?></strong></td>
					<td>
						<?php if ( Options\ready() ) : ?>
							<?php printf( '%s <code>%s:%d</code>', esc_html__( 'a mail server —', 'wow-mail' ), esc_html( (string) $options['host'] ), (int) $options['port'] ); ?>
						<?php else : ?>
							<span style="color:#b32d2e"><?php esc_html_e( 'the web server’s own mail() — unauthenticated, and the usual reason mail is refused or filed as spam', 'wow-mail' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Sending as', 'wow-mail' ); ?></strong></td>
					<td><code><?php echo esc_html( Options\from() ); ?></code> <?php echo esc_html( Options\from_name() ); ?></td>
				</tr>
				<tr>
					<td><strong>SPF</strong></td>
					<td>
						<?php if ( ! $dns['checked'] ) : ?>
							<?php esc_html_e( 'cannot be checked — this server does not allow DNS lookups', 'wow-mail' ); ?>
						<?php elseif ( '' !== $dns['spf'] ) : ?>
							<span style="color:#007017">&#10003;</span> <code><?php echo esc_html( $dns['spf'] ); ?></code>
							<?php if ( ! empty( $dns['strict'] ) ) : ?>
								<p class="description"><?php esc_html_e( 'It ends in -all, which asks receiving servers to refuse anything sent from elsewhere rather than merely doubt it. That is the right setting, and it means the mail server used here has to be one the record allows.', 'wow-mail' ); ?></p>
							<?php endif; ?>
						<?php else : ?>
							<span style="color:#b32d2e">&#10007; <?php esc_html_e( 'missing — anybody may send as this domain, and receiving servers know it', 'wow-mail' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>DMARC</strong></td>
					<td>
						<?php if ( ! $dns['checked'] ) : ?>
							&mdash;
						<?php elseif ( '' !== $dns['dmarc'] ) : ?>
							<span style="color:#007017">&#10003;</span> <code><?php echo esc_html( $dns['dmarc'] ); ?></code>
						<?php else : ?>
							<span style="color:#b32d2e">&#10007; <?php esc_html_e( 'missing', 'wow-mail' ); ?></span>
							<p class="description">
								<?php esc_html_e( 'Worth adding, and harmless to start with. A first record asks for reports without changing how anything is delivered:', 'wow-mail' ); ?>
								<br />
								<code>_dmarc.<?php echo esc_html( $dns['domain'] ); ?> &nbsp; TXT &nbsp; "v=DMARC1; p=none; rua=mailto:<?php echo esc_html( Options\from() ); ?>"</code>
							</p>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td><strong>DKIM</strong></td>
					<td><?php esc_html_e( 'set up at the mail provider, under a name it chooses — there is no way to look for it from here. Ask the provider whether it signs outgoing mail.', 'wow-mail' ); ?></td>
				</tr>
				<tr>
					<td><strong><?php esc_html_e( 'Checking for certain', 'wow-mail' ); ?></strong></td>
					<td>
						<?php esc_html_e( 'Send a test to a Gmail address, open it there and choose “Show original”. The first lines give the receiving end’s own verdict — spf: PASS, dkim: PASS, dmarc: PASS — and that is the only answer that counts. Nothing checked from this side can tell you, because SPF is judged on the machine that delivers the message, not on the one this site hands it to.', 'wow-mail' ); ?>
					</td>
				</tr>
			</tbody>
		</table>

		<?php
		note(
			sprintf(
				/* translators: %s: a domain name. */
				__( 'These records belong to the domain %s and live with whoever holds it, not in WordPress. Sending through a mail server settles who is sending; these decide whether the receiving end believes it. Without them a perfectly configured site still lands in the spam folder.', 'wow-mail' ),
				$dns['domain']
			)
		);
		?>

		<form method="post" action="options.php">
			<?php settings_fields( Options\OPTION ); ?>

			<h2 class="title"><?php esc_html_e( 'The mail server', 'wow-mail' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Use it', 'wow-mail' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( ! empty( $options['enabled'] ) ); ?> /> <?php esc_html_e( 'Send through SMTP instead of the web server', 'wow-mail' ); ?></label></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wow-mail-host"><?php esc_html_e( 'Server and port', 'wow-mail' ); ?></label></th>
					<td>
						<input type="text" id="wow-mail-host" class="regular-text" name="<?php echo esc_attr( $name ); ?>[host]" value="<?php echo esc_attr( (string) $options['host'] ); ?>" placeholder="smtp.example.com" />
						<input type="number" min="1" max="65535" class="small-text" name="<?php echo esc_attr( $name ); ?>[port]" value="<?php echo esc_attr( (string) $options['port'] ); ?>" />
						<select name="<?php echo esc_attr( $name ); ?>[encryption]">
							<option value="tls" <?php selected( 'tls', $options['encryption'] ); ?>><?php esc_html_e( 'STARTTLS (usually port 587)', 'wow-mail' ); ?></option>
							<option value="ssl" <?php selected( 'ssl', $options['encryption'] ); ?>><?php esc_html_e( 'TLS from the start (usually port 465)', 'wow-mail' ); ?></option>
							<option value="none" <?php selected( 'none', $options['encryption'] ); ?>><?php esc_html_e( 'No encryption', 'wow-mail' ); ?></option>
						</select>
						<?php note( __( 'The host gives these out with the mailbox. Without encryption the password crosses the network in the clear, so only choose that for a server on the same machine.', 'wow-mail' ) ); ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Signing in', 'wow-mail' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[auth]" value="1" <?php checked( ! empty( $options['auth'] ) ); ?> /> <?php esc_html_e( 'The server asks for a user name and password', 'wow-mail' ); ?></label></p>
						<p>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[username]" value="<?php echo esc_attr( (string) $options['username'] ); ?>" placeholder="<?php esc_attr_e( 'user name', 'wow-mail' ); ?>" autocomplete="off" />
						</p>
						<p>
							<input type="password" class="regular-text" name="<?php echo esc_attr( $name ); ?>[password]" value="" placeholder="<?php echo 'nowhere' === Options\password_lives() ? esc_attr__( 'password', 'wow-mail' ) : esc_attr__( 'leave empty to keep the one saved', 'wow-mail' ); ?>" autocomplete="new-password" />
						</p>

						<?php if ( 'config' === Options\password_lives() ) : ?>
							<p class="description"><strong><?php esc_html_e( 'The password is set as a constant in a file rather than in the database, which is where it is best kept. Anything typed here is ignored.', 'wow-mail' ); ?></strong></p>
						<?php elseif ( 'database' === Options\password_lives() ) : ?>
							<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[forget_password]" value="1" /> <?php esc_html_e( 'Forget the saved password', 'wow-mail' ); ?></label></p>
							<?php
							note( __( 'It is kept in the database, encrypted with a key made from this site’s salts — which live in wp-config.php and not in the database. That protects a database dump, which is how these usually get out; it protects nothing against somebody already on the server.', 'wow-mail' ) );
							note( __( 'Better still is a constant in a file the database knows nothing about — wp-config.php, or a must-use plugin: define( \'WOW_MAIL_PASSWORD\', \'…\' );', 'wow-mail' ) );
							?>
						<?php else : ?>
							<?php note( __( 'Better than typing it here is a constant in a file, which keeps it out of the database and out of every backup of it — in wp-config.php, or in a must-use plugin: define( \'WOW_MAIL_PASSWORD\', \'…\' );', 'wow-mail' ) ); ?>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<h2 class="title"><?php esc_html_e( 'Who the mail is from', 'wow-mail' ); ?></h2>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="wow-mail-from"><?php esc_html_e( 'Address and name', 'wow-mail' ); ?></label></th>
					<td>
						<input type="email" id="wow-mail-from" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from]" value="<?php echo esc_attr( (string) $options['from'] ); ?>" placeholder="contact@example.com" />
						<input type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[from_name]" value="<?php echo esc_attr( (string) $options['from_name'] ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" />
						<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[force_from]" value="1" <?php checked( ! empty( $options['force_from'] ) ); ?> /> <?php esc_html_e( 'Use it for everything the site sends', 'wow-mail' ); ?></label></p>
						<?php
						note( __( 'It has to be a mailbox that exists, and at the same domain the mail server signs us in as. WordPress otherwise sends as wordpress@ the site’s domain, which is usually nothing at all — and a From address that bounces is read by a spam filter as exactly what it looks like.', 'wow-mail' ) );
						note( __( 'A message sent through one provider’s server claiming to come from another’s domain has the shape of a forgery and is treated as one, however well everything else is set up.', 'wow-mail' ) );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Record', 'wow-mail' ); ?></th>
					<td>
						<p><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[keep_log]" value="1" <?php checked( ! empty( $options['keep_log'] ) ); ?> /> <?php esc_html_e( 'Keep a short record of what was sent', 'wow-mail' ); ?></label></p>
						<?php note( __( 'Who it was for and what it was about, for a fortnight. Never the message itself.', 'wow-mail' ) ); ?>
					</td>
				</tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Send a test', 'wow-mail' ); ?></h2>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'wow-mail-test' ); ?>
			<input type="hidden" name="action" value="wow_mail_test" />
			<input type="email" class="regular-text" name="to" value="<?php echo esc_attr( (string) wp_get_current_user()->user_email ); ?>" required />
			<?php submit_button( __( 'Send', 'wow-mail' ), 'secondary', 'submit', false ); ?>
			<?php note( __( 'If it fails, the whole exchange with the mail server is shown — which greeting failed, which password was refused, which certificate was not trusted. That is nearly always where the answer is.', 'wow-mail' ) ); ?>
		</form>

		<h2 class="title"><?php esc_html_e( 'Recently sent', 'wow-mail' ); ?></h2>

		<?php
		$log = Log\entries();

		if ( empty( $log ) ) {
			printf( '<p>%s</p>', esc_html__( 'Nothing has been sent lately.', 'wow-mail' ) );
		} else {
			echo '<table class="widefat striped"><thead><tr>';
			printf(
				'<th>%s</th><th>%s</th><th>%s</th><th>%s</th>',
				esc_html__( 'When', 'wow-mail' ),
				esc_html__( 'To', 'wow-mail' ),
				esc_html__( 'About', 'wow-mail' ),
				esc_html__( 'What happened', 'wow-mail' )
			);
			echo '</tr></thead><tbody>';

			foreach ( $log as $entry ) {
				printf(
					'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
					esc_html( sprintf( /* translators: %s: a length of time. */ __( '%s ago', 'wow-mail' ), human_time_diff( (int) $entry['time'] ) ) ),
					esc_html( (string) $entry['to'] ),
					esc_html( (string) $entry['subject'] ),
					$entry['ok']
						? '<span style="color:#007017">' . esc_html__( 'sent', 'wow-mail' ) . '</span>'
						: '<span style="color:#b32d2e">' . esc_html( (string) $entry['reason'] ) . '</span>'
				);
			}

			echo '</tbody></table>';
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wow-mail-clear' ); ?>
				<input type="hidden" name="action" value="wow_mail_clear" />
				<?php submit_button( __( 'Empty the record', 'wow-mail' ), 'secondary' ); ?>
			</form>
			<?php
		}
		?>
	</div>
	<?php
}
