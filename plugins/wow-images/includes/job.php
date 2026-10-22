<?php
/**
 * Converting the library without anybody watching.
 *
 * The screen in Tools used to be the work: a browser handed the server one
 * picture at a time and the job existed only as long as the tab did. That is
 * fine for six pictures and wrong for a thousand — a closed laptop, a lost
 * connection or a sleeping phone stopped it.
 *
 * So the job lives on the server instead. Starting it writes down what is to
 * be done; a worker takes pictures off that list until it is close to
 * whatever time limit PHP is under, writes down where it got to, and asks the
 * server to start another worker. The screen only reads the tally. Closing it
 * changes nothing at all.
 *
 * Three things can set a worker going, which is deliberate, because any one
 * of them can be unavailable on somebody's hosting:
 *
 *   - the worker itself, calling the site back when it stops;
 *   - WP-Cron, as a watchdog, when a job has gone quiet with work left;
 *   - the screen, which does a short turn of the work itself if it notices
 *     that neither of the above has happened.
 *
 * The last is the reason this needs no configuring. A host that blocks
 * requests from a site to itself — the containers this site is developed in
 * do, since the site's address exists only outside them — simply falls back
 * to the browser doing the driving, exactly as before. Nobody has to know.
 *
 * @package WOW\Images
 */

namespace WOW\Images\Job;

use WOW\Images\Convert;
use WOW\Images\Queue;
use WOW\Images\References;
use WOW\Images\Retina;
use WOW\Images\Store;

defined( 'ABSPATH' ) || exit;

/** Where the job is written down. */
const OPTION = 'wow_images_job';

/** The shared secret a worker must present. */
const SECRET = 'wow_images_job_key';

/** Held while a worker is running, so that two never are. */
const LOCK = 'wow_images_job_lock';

/** What a worker does, over admin-ajax. */
const WORK = 'wow_images_work';

/** What the screen asks for. */
const PROGRESS = 'wow_images_job';

/** The cron hook that restarts a job nobody else has. */
const WATCH = 'wow_images_job_watch';

/** A worker that has not been heard from for this long is presumed gone. */
const SILENCE = 90;

/**
 * The job as it stands, or null when there is none.
 *
 * @return array<string,mixed>|null
 */
function state(): ?array {
	$job = get_option( OPTION );

	return is_array( $job ) && isset( $job['id'] ) ? $job : null;
}

/**
 * Writes the job down.
 *
 * Not autoloaded: it holds a list of every picture left to do and has no
 * business being read into memory on every page of the site.
 *
 * @param array<string,mixed> $job The job.
 */
function save( array $job ): void {
	update_option( OPTION, $job, false );
}

/**
 * Forgets the job.
 */
function clear(): void {
	delete_option( OPTION );
	release();
}

/**
 * The secret a worker presents to prove it was sent by this site.
 *
 * A worker arrives without a session — the request that starts it carries no
 * cookies — so a nonce would have nothing to check. This is checked instead,
 * and with hash_equals() rather than ===, so that a wrong guess takes the
 * same time to reject as a right one.
 */
function secret(): string {
	$key = get_option( SECRET );

	if ( ! is_string( $key ) || 32 > strlen( $key ) ) {
		$key = wp_generate_password( 48, false );
		update_option( SECRET, $key, false );
	}

	return $key;
}

/**
 * Takes the lock, or says somebody else has it.
 *
 * add_option() is an INSERT that fails when the row is already there, which
 * makes it the one thing in the options table that two processes cannot both
 * succeed at. A lock older than the silence we allow is assumed to belong to
 * a worker that died and is taken anyway.
 */
function claim(): bool {
	if ( add_option( LOCK, time(), '', false ) ) {
		return true;
	}

	$held = (int) get_option( LOCK );

	if ( $held > 0 && time() - $held < SILENCE ) {
		return false;
	}

	update_option( LOCK, time(), false );

	return true;
}

/**
 * Lets go of the lock.
 */
function release(): void {
	delete_option( LOCK );
}

/**
 * Whether a worker is at work right now.
 */
function working(): bool {
	$held = (int) get_option( LOCK );

	return $held > 0 && time() - $held < SILENCE;
}

/**
 * Begins a job.
 *
 * @param int[]|null $ids Attachments to work on; everything outstanding when null.
 * @return array<string,mixed> The job.
 */
function start( ?array $ids = null ): array {
	$ids = null === $ids
		? array_column( Queue\pending(), 'id' )
		: array_values( array_filter( array_map( 'intval', $ids ) ) );

	$job = array(
		'id'        => wp_generate_password( 12, false ),
		'ids'       => $ids,
		'total'     => count( $ids ),
		'done'      => 0,
		'stored'    => 0,
		'copies'    => 0,
		'failed'    => 0,
		'errors'    => array(),
		'started'   => time(),
		'heartbeat' => 0,
		'finished'  => 0,
		'names'     => 0,
		'posts'     => 0,
	);

	save( $job );
	release();
	dispatch();

	return $job;
}

/**
 * Asks the site to start a worker, without waiting for it to answer.
 *
 * Nothing can be learnt from the result: a request nobody waits for reports
 * success whether or not anything received it. Whether a worker actually
 * started is told by the heartbeat, a little later.
 */
function dispatch(): void {
	wp_remote_post(
		admin_url( 'admin-ajax.php' ),
		array(
			'timeout'   => 0.01,
			'blocking'  => false,
			'sslverify' => false,
			'cookies'   => array(),
			'body'      => array(
				'action' => WORK,
				'key'    => secret(),
			),
		)
	);
}

/**
 * How long a worker may run before writing down where it got to.
 *
 * Comfortably inside whatever PHP allows, and never long enough that the
 * screen looks frozen. With no limit at all it still stops after twenty
 * seconds, so that progress is something a person can watch.
 */
function budget(): float {
	$limit = (int) ini_get( 'max_execution_time' );

	return $limit > 0 ? max( 5.0, min( 20.0, $limit - 10 ) ) : 20.0;
}

/**
 * Whether there is enough memory left to open another photograph.
 */
function room(): bool {
	$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );

	return $limit <= 0 || memory_get_usage( true ) < $limit * 0.8;
}

/**
 * Everything that has to happen to one picture.
 *
 * The one place that says what converting a picture means, so that the
 * screen, the commands and the worker cannot drift apart about it.
 *
 * @param int $id Attachment ID.
 * @return array{stored:bool,copies:int,error:string}
 */
function one( int $id ): array {
	$done = array(
		'stored' => false,
		'copies' => 0,
		'error'  => '',
	);

	if ( Queue\needs_store( $id ) ) {
		$moved = Store\migrate( $id );

		if ( is_wp_error( $moved ) ) {
			// Being no smaller in the new format is an answer, not a failure,
			// and migrate() has already written it down so that nothing asks
			// again.
			if ( 'wow_images_no_gain' !== $moved->get_error_code() ) {
				$done['error'] = $moved->get_error_message();

				return $done;
			}
		} else {
			$done['stored'] = true;
		}
	}

	Retina\recut( $id );

	$copies = Convert\attachment( $id );

	if ( is_wp_error( $copies ) ) {
		if ( 'wow_images_no_formats' !== $copies->get_error_code() ) {
			$done['error'] = $copies->get_error_message();
		}

		return $done;
	}

	$done['copies'] = (int) $copies['made'];

	if ( ! empty( $copies['errors'] ) ) {
		$done['error'] = implode( '; ', $copies['errors'] );
	}

	return $done;
}

/**
 * Works through the list until the budget runs out.
 *
 * The caller holds the lock. Every picture is written down as it is finished,
 * so a worker killed mid-stride costs at most the one it was holding.
 *
 * @param float $seconds How long to keep going.
 * @return array<string,mixed>|null The job as it now stands.
 */
function step( float $seconds ): ?array {
	$job = state();

	if ( null === $job || ! empty( $job['finished'] ) ) {
		return $job;
	}

	$until = microtime( true ) + $seconds;

	wp_raise_memory_limit( 'image' );

	while ( ! empty( $job['ids'] ) && microtime( true ) < $until && room() ) {
		$id   = (int) array_shift( $job['ids'] );
		$done = one( $id );

		++$job['done'];
		$job['copies'] += $done['copies'];

		if ( $done['stored'] ) {
			++$job['stored'];
		}

		if ( '' !== $done['error'] ) {
			++$job['failed'];

			// Only the last few: a job that goes wrong everywhere must not
			// grow an option row until it cannot be saved.
			$job['errors'][] = sprintf( '#%d: %s', $id, $done['error'] );
			$job['errors']   = array_slice( $job['errors'], -10 );
		}

		$job['heartbeat'] = time();

		save( $job );
		update_option( LOCK, time(), false );
	}

	if ( empty( $job['ids'] ) ) {
		$job = finish( $job );
	}

	return $job;
}

/**
 * Closes a job, once the list is empty.
 *
 * The pages are pointed at the pictures here, in a single pass over the whole
 * site, rather than after each picture. See references.php for why.
 *
 * @param array<string,mixed> $job The job.
 * @return array<string,mixed>
 */
function finish( array $job ): array {
	$pointed = References\repoint_all();

	$job['names']     = (int) $pointed['names'];
	$job['posts']     = (int) $pointed['posts'];
	$job['finished']  = time();
	$job['heartbeat'] = time();
	$job['ids']       = array();

	save( $job );

	return $job;
}

/**
 * A worker.
 *
 * Reached over admin-ajax by a request this site made to itself, so there is
 * no user and no session — only the secret.
 */
function work(): void {
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['key'] ) ) : '';

	if ( ! hash_equals( secret(), $key ) ) {
		wp_die( '', '', array( 'response' => 403 ) );
	}

	$job = state();

	if ( null === $job || ! empty( $job['finished'] ) || ! claim() ) {
		wp_die( '', '', array( 'response' => 200 ) );
	}

	// The request that sent this worker is already gone and is not waiting.
	ignore_user_abort( true );

	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- forbidden on some hosts.
	}

	$job = step( budget() );

	release();

	// More to do: hand over to the next worker rather than running on, so
	// that no single request has to outlive whatever the host allows.
	if ( null !== $job && empty( $job['finished'] ) ) {
		dispatch();
	}

	wp_die( '', '', array( 'response' => 200 ) );
}
add_action( 'wp_ajax_' . WORK, __NAMESPACE__ . '\\work' );
add_action( 'wp_ajax_nopriv_' . WORK, __NAMESPACE__ . '\\work' );

/**
 * Restarts a job that has gone quiet.
 *
 * On WP-Cron, which on most sites runs when somebody visits a page. It is the
 * safety net for the case where the site cannot call itself: as long as
 * anybody at all is looking at the site, the job goes on.
 */
function watch(): void {
	$job = state();

	if ( null === $job || ! empty( $job['finished'] ) || working() ) {
		return;
	}

	if ( time() - (int) $job['heartbeat'] < SILENCE && (int) $job['heartbeat'] > 0 ) {
		return;
	}

	dispatch();
}
add_action( WATCH, __NAMESPACE__ . '\\watch' );

/**
 * Keeps the watchdog scheduled.
 */
function schedule(): void {
	if ( ! wp_next_scheduled( WATCH ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', WATCH );
	}
}
add_action( 'admin_init', __NAMESPACE__ . '\\schedule' );
