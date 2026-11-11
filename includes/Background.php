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
 * its progress and schedules the next slice. A lock prevents parallel runs.
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
	}

	/**
	 * Current state.
	 *
	 * @return array{operation:string,status:string,cursor:int,processed:int,total:int,errors:array,started:int,updated:int,options:array}|null
	 */
	public function state() {
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

		$total = $this->bulk->remaining( $operation );
		update_option(
			self::OPTION,
			array(
				'operation' => $operation,
				'status'    => 'running',
				'cursor'    => 0,
				'processed' => 0,
				'total'     => max( 0, $total ),
				'errors'    => array(),
				'started'   => time(),
				'updated'   => time(),
				'options'   => $options,
			),
			false
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
			update_option( self::OPTION, $state, false );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Makes sure a running operation keeps going (called while the tools screen polls).
	 *
	 * @return void
	 */
	public function nudge() {
		if ( ! $this->is_running() ) {
			return;
		}
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			$this->schedule( 0 );
		}
		spawn_cron();
	}

	/**
	 * Cron: processes one time slice.
	 *
	 * @return void
	 */
	public function run() {
		if ( ! $this->is_running() || get_transient( self::LOCK ) ) {
			return;
		}
		set_transient( self::LOCK, 1, 5 * MINUTE_IN_SECONDS );

		$deadline = time() + self::SLICE;
		$state    = $this->state();

		do {
			$batch = $this->bulk->run( $state['operation'], $state['cursor'], 10, $state['options'] );
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
				break;
			}

			// Stop requested meanwhile (another request changed the option).
			$current = get_option( self::OPTION );
			if ( ! is_array( $current ) || 'running' !== $current['status'] ) {
				$state['status'] = is_array( $current ) ? $current['status'] : 'stopped';
				break;
			}

			update_option( self::OPTION, $state, false );
		} while ( time() < $deadline );

		update_option( self::OPTION, $state, false );
		delete_transient( self::LOCK );

		if ( 'running' === $state['status'] ) {
			$this->schedule( 5 );
		}
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
