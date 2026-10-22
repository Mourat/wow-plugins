<?php
/**
 * Sending through a real mail server.
 *
 * WordPress already carries PHPMailer and already sends everything through
 * it; by default it tells it to use PHP's mail(), which hands the message to
 * whatever the web server has, unauthenticated, from an address nobody
 * vouches for. This points the same object at a mail server that will
 * authenticate us instead — three lines of configuration and a password.
 *
 * It is worth being clear that this alone does not keep mail out of the spam
 * folder. It settles who is sending; whether the receiving end believes it is
 * decided in DNS, by the records dns.php reports on.
 *
 * @package WOW\Mail
 */

namespace WOW\Mail\Mailer;

use WOW\Mail\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Points PHPMailer at the mail server.
 *
 * @param mixed $mail The PHPMailer instance WordPress is about to use.
 */
function configure( $mail ): void {
	if ( ! Options\ready() || ! is_object( $mail ) || ! method_exists( $mail, 'isSMTP' ) ) {
		return;
	}

	$mail->isSMTP();

	$mail->Host    = (string) Options\get( 'host' );
	$mail->Port    = (int) Options\get( 'port' );
	$mail->Timeout = 20;

	$encryption = (string) Options\get( 'encryption' );

	// PHPMailer calls STARTTLS "tls" and a connection that is encrypted from
	// the first byte "ssl"; which one a server wants usually follows from the
	// port, 587 for the first and 465 for the second.
	$mail->SMTPSecure  = 'none' === $encryption ? '' : $encryption;
	$mail->SMTPAutoTLS = 'none' !== $encryption;

	$mail->SMTPAuth = (bool) Options\get( 'auth' );

	if ( $mail->SMTPAuth ) {
		$mail->Username = (string) Options\get( 'username' );
		$mail->Password = Options\password();
	}

	/**
	 * Filters the mailer after this plugin has configured it.
	 *
	 * The way to say anything this plugin has no setting for.
	 *
	 * @param object $mail The PHPMailer instance.
	 */
	do_action( 'wow_mail_configured', $mail );
}
add_action( 'phpmailer_init', __NAMESPACE__ . '\\configure', 20 );

/**
 * Sends a message and reports the conversation that produced it.
 *
 * The ordinary failure from wp_mail() is one sentence, and the sentence is
 * rarely the reason. What is needed when mail will not go is the exchange
 * itself — which greeting failed, which password was refused, which
 * certificate was not trusted — so a test send turns PHPMailer's own
 * reporting on and keeps it.
 *
 * @param string $to Where to send it.
 * @return array{sent:bool,reason:string,conversation:string}
 */
function test( string $to ): array {
	$conversation = '';

	$listen = static function ( $mail ) use ( &$conversation ): void {
		if ( ! is_object( $mail ) ) {
			return;
		}

		$mail->SMTPDebug = 2; // The dialogue, without the handshake internals.

		$mail->Debugoutput = static function ( $line, $level ) use ( &$conversation ): void {
			$conversation .= rtrim( (string) $line ) . "\n";
		};
	};

	$error = null;

	$capture = static function ( $wp_error ) use ( &$error ): void {
		$error = $wp_error;
	};

	add_action( 'phpmailer_init', $listen, 100 );
	add_action( 'wp_mail_failed', $capture );

	$sent = wp_mail(
		$to,
		sprintf(
			/* translators: %s: the name of the site. */
			__( 'Test message from %s', 'wow-mail' ),
			get_bloginfo( 'name' )
		),
		sprintf(
			/* translators: 1: the site address, 2: the date and time. */
			__( "This is a test message from %1\$s, sent at %2\$s.\n\nIf it arrived, the site can send mail. Whether it arrived in the inbox or in the spam folder is a different question, and the settings screen says what decides it.", 'wow-mail' ),
			home_url(),
			wp_date( 'Y-m-d H:i' )
		)
	);

	remove_action( 'phpmailer_init', $listen, 100 );
	remove_action( 'wp_mail_failed', $capture );

	return array(
		'sent'         => (bool) $sent,
		'reason'       => $error instanceof \WP_Error ? $error->get_error_message() : '',
		'conversation' => trim( $conversation ),
	);
}
