<?php
/**
 * Bulk operations in the background (WP-Cron).
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a bulk operation in time slices via WP-Cron, so the browser does not
 * have to stay open. Every slice processes batches for a few seconds, saves
 * its progress and schedules the next slice.
 *
 * Safety:
 *  - The state is always read fresh from the database (never from the
 *    request cache), so "stop" takes effect after the current batch.
 *  - Every run has a token; a slice only writes while its token is current,
 *    so an old slice can never overwrite a newer run.
 *  - The lock is atomic (add_option) and expires; a watchdog in the hourly
 *    plugin cron resumes runs whose slice died (e.g. fatal error, timeout).
 *
 * Progress continues on every page view (WP-Cron), immediately while the
 * tools screen is open (it pings WP-Cron), and independently of traffic when
 * a real system cron calls wp-cron.php.
 */
final class Background {

	const OPTION = 'bms3o_background';
	const HOOK   = 'bms3o_background_run';
	const LOCK   = 'bms3o_background_lock';

	/**
	 * Seconds of work per slice.
	 */
	const SLICE = 20;

	/**
	 * Seconds after which a lock (or a silent run) is considered dead.
	 */
	const STALE = 600;

	/**
	 * Bulk operations.
	 *
	 * @var Bulk
	 */
	private $bulk;

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	private $offloader;

	/**
	 * Whether this request holds the lock.
	 *
	 * @var bool
	 */
	private $holds_lock = false;

	/**
	 * Constructor.
	 *
	 * @param Bulk      $bulk      Bulk operations.
	 * @param Offloader $offloader Offloader.
	 */
	public function __construct( Bulk $bulk, Offloader $offloader ) {
		$this->bulk      = $bulk;
		$this->offloader = $offloader;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( Plugin::CRON_HOOK, array( $this, 'watchdog' ) );
	}

	/**
	 * Current state, read from the database.
	 *
	 * @return array|null
	 */
	public function state() {
		wp_cache_delete( self::OPTION, 'options' );
		$state = get_option( self::OPTION );
		return is_array( $state ) ? $state : null;
	}

	/**
	 * Whether an operation is running.
	 *
	 * @return bool
	 */
	public function is_running() {
		$state = $this->state();
		return $state && 'running' === $state['status'];
	}

	/**
	 * Starts an operation.
	 *
	 * @param string $operation Operation (see Bulk::OPERATIONS).
	 * @param array  $options   Options passed to Bulk::run().
	 * @return bool False if another operation is running.
	 */
	public function start( $operation, array $options = array() ) {
		if ( $this->is_running() || ! in_array( $operation, Bulk::OPERATIONS, true ) ) {
			return false;
		}

		$this->write(
			array(
				'token'     => wp_generate_password( 16, false ),
				'operation' => $operation,
				'status'    => 'running',
				'cursor'    => 0,
				'processed' => 0,
				'total'     => max( 0, $this->bulk->remaining( $operation ) ),
				'errors'    => array(),
				'started'   => time(),
				'updated'   => time(),
				'options'   => $options,
			)
		);

		$this->schedule( 0 );
		return true;
	}

	/**
	 * Stops the running operation after the current batch.
	 *
	 * @return void
	 */
	public function stop() {
		$state = $this->state();
		if ( $state && 'running' === $state['status'] ) {
			$state['status']  = 'stopped';
			$state['updated'] = time();
			$this->write( $state );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Makes sure a running operation keeps going (called while the tools screen polls).
	 *
	 * @return void
	 */
	public function nudge() {
		if ( $this->is_running() && ! wp_next_scheduled( self::HOOK ) && ! $this->is_locked() ) {
			$this->schedule( 0 );
		}
		if ( $this->is_running() ) {
			spawn_cron();
		}
	}

	/**
	 * Hourly: resumes runs whose slice died without rescheduling.
	 *
	 * @return void
	 */
	public function watchdog() {
		if ( $this->is_running() && ! wp_next_scheduled( self::HOOK ) && ! $this->is_locked() ) {
			$this->schedule( 0 );
		}
	}

	/**
	 * Cron: processes one time slice.
	 *
	 * @return void
	 */
	public function run() {
		$state = $this->state();
		if ( ! $state || 'running' !== $state['status'] || ! $this->lock() ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( self::SLICE + 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- Uploads of large files in a cron request.
		}

		$token    = isset( $state['token'] ) ? $state['token'] : '';
		$deadline = time() + self::SLICE;
		$current  = true;

		do {
			$batch = $this->bulk->run( $state['operation'], $state['cursor'], 10, $state['options'], (float) $deadline );
			$this->offloader->process_removal_queue();

			$state['cursor']     = $batch['cursor'];
			$state['processed'] += $batch['processed'];
			if ( isset( $batch['total'] ) ) {
				$state['total'] = $batch['total'];
			}
			foreach ( $batch['errors'] as $id => $message ) {
				$state['errors'][ $id ] = $message;
			}
			// Keep the latest 50 errors only.
			$state['errors']  = array_slice( $state['errors'], -50, null, true );
			$state['updated'] = time();
			if ( $batch['done'] ) {
				$state['status'] = 'done';
			}

			// Stopped or replaced meanwhile? Then this slice must not write anymore.
			$fresh = $this->state();
			if ( ! $fresh || ( isset( $fresh['token'] ) ? $fresh['token'] : '' ) !== $token || 'running' !== $fresh['status'] ) {
				$current = false;
				break;
			}

			$this->write( $state );
			$this->lock(); // Heartbeat.
		} while ( 'running' === $state['status'] && time() < $deadline );

		$this->unlock();

		if ( $current && 'running' === $state['status'] ) {
			$this->schedule( 5 );
		}
	}

	/**
	 * Saves the state without autoloading.
	 *
	 * @param array $state State.
	 * @return void
	 */
	private function write( array $state ) {
		update_option( self::OPTION, $state, false );
	}

	/**
	 * Acquires the lock, or refreshes it when this request holds it (heartbeat).
	 *
	 * The lock is atomic (see Lock): only one slice can win, an expired lock of a
	 * slice that died is taken over.
	 *
	 * @return bool
	 */
	private function lock() {
		if ( $this->holds_lock ) {
			Lock::refresh( self::LOCK, self::STALE );
			return true;
		}
		if ( ! Lock::acquire( self::LOCK, self::STALE ) ) {
			return false; // Another slice is alive.
		}
		$this->holds_lock = true;
		return true;
	}

	/**
	 * Releases the lock.
	 *
	 * @return void
	 */
	private function unlock() {
		Lock::release( self::LOCK );
		$this->holds_lock = false;
	}

	/**
	 * Whether a slice currently holds a valid lock.
	 *
	 * @return bool
	 */
	private function is_locked() {
		return Lock::is_locked( self::LOCK );
	}

	/**
	 * Schedules the next slice.
	 *
	 * @param int $delay Seconds from now.
	 * @return void
	 */
	private function schedule( $delay ) {
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_single_event( time() + (int) $delay, self::HOOK );
		spawn_cron();
	}
}
