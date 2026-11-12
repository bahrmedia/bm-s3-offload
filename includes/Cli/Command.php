<?php
/**
 * WP-CLI commands.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Cli;

use BahrMedia\S3Offload\Plugin;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Manages offloaded media.
 *
 * ## EXAMPLES
 *
 *     wp bm-s3 status
 *     wp bm-s3 test
 *     wp bm-s3 offload --all --remove-local
 *     wp bm-s3 offload 123 456
 *     wp bm-s3 download --all
 *     wp bm-s3 bb-sync
 */
final class Command {

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Shows configuration and statistics.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$settings = $this->plugin->settings;
		$stats    = $this->plugin->offloader->items()->stats();

		$values = array(
			'configured'      => $settings->is_configured() ? 'yes' : 'no',
			'endpoint'        => $settings->get( 'endpoint' ),
			'bucket'          => $settings->get( 'bucket' ),
			'region'          => $settings->get( 'region' ),
			'remove_local'    => $settings->get( 'remove_local' ) ? 'yes' : 'no',
			'buddyboss'       => $this->plugin->buddyboss ? 'active' : 'inactive',
			'files'           => $stats['files'],
			'bytes'           => size_format( $stats['bytes'], 1 ),
			'local_removed'   => $stats['local_removed'],
			'private'         => $stats['private'],
			'pending_offload' => $this->plugin->bulk->remaining( 'offload' ),
			'pending_retries' => count( $this->plugin->attachments->pending_retries() ),
			'pending_cleanup' => $this->plugin->offloader->orphan_store()->count(),
			'failing_cleanup' => $this->plugin->offloader->failing_orphans(),
			'storage'         => '' === $settings->storage_error() ? 'ok' : 'changed – see "wp bm-s3 storage"',
		);

		$rows = array();
		foreach ( $values as $key => $value ) {
			$rows[] = array(
				'key'   => $key,
				'value' => $value,
			);
		}

		\WP_CLI\Utils\format_items( isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table', $rows, array( 'key', 'value' ) );
	}

	/**
	 * Runs the connection test.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function test( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$failed = false;
		foreach ( $this->plugin->offloader->test_connection() as $step ) {
			$line = ( $step['ok'] ? '✔ ' : '✘ ' ) . $step['step'] . ( '' !== $step['message'] ? ' – ' . $step['message'] : '' );
			if ( $step['ok'] ) {
				WP_CLI::log( $line );
			} else {
				$failed = true;
				WP_CLI::warning( $line );
			}
		}
		if ( $failed ) {
			WP_CLI::error( 'Connection test failed.' );
		}
		WP_CLI::success( 'Connection test passed.' );
	}

	/**
	 * Offloads media to the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All media that are not offloaded yet.
	 *
	 * [--remove-local]
	 * : Remove local copies afterwards (overrides the setting).
	 *
	 * [--force]
	 * : Upload again even if unchanged (only with IDs).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function offload( $args, $assoc_args ) {
		$options = array( 'force' => ! empty( $assoc_args['force'] ) );
		if ( isset( $assoc_args['remove-local'] ) ) {
			$options['remove_local'] = (bool) $assoc_args['remove-local'];
		}
		$this->execute( 'offload', $args, $assoc_args, $options );
	}

	/**
	 * Removes local copies of offloaded media (each object is verified first).
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All offloaded media.
	 *
	 * @subcommand remove-local
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function remove_local( $args, $assoc_args ) {
		$this->execute( 'remove_local', $args, $assoc_args );
	}

	/**
	 * Restores local copies from the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All media without local copy.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function download( $args, $assoc_args ) {
		$this->execute( 'download', $args, $assoc_args );
	}

	/**
	 * Restores local copies and deletes the objects from the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Attachment IDs.
	 *
	 * [--all]
	 * : All offloaded media.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function unoffload( $args, $assoc_args ) {
		if ( ! empty( $assoc_args['all'] ) ) {
			WP_CLI::confirm( 'Restore all files locally and delete them from the bucket?', $assoc_args );
		}
		$this->execute( 'unoffload', $args, $assoc_args );
	}

	/**
	 * Checks that every offloaded file exists in the bucket.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : Upload missing objects again from the local copy.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- WP-CLI signature.
		if ( ! $this->plugin->settings->is_configured() ) {
			WP_CLI::error( 'The bucket is not configured.' );
		}

		$items    = $this->plugin->offloader->items();
		$progress = \WP_CLI\Utils\make_progress_bar( 'Verifying', $items->count() );
		$counts   = array(
			'ok'      => 0,
			'fixed'   => 0,
			'missing' => 0,
			'error'   => 0,
		);
		$after    = 0;
		$per_page = 200;

		do {
			$page = $items->page( $after, $per_page );
			foreach ( $page as $item ) {
				$after  = $item['id'];
				$result = $this->plugin->offloader->verify( $item, ! empty( $assoc_args['fix'] ) );
				if ( isset( $counts[ $result ] ) ) {
					++$counts[ $result ];
				} else {
					++$counts['error'];
				}
				if ( 'ok' !== $result ) {
					WP_CLI::warning( sprintf( '%s: %s', $item['path'], $result ) );
				}
				$progress->tick();
			}
			$fetched = count( $page );
		} while ( $fetched === $per_page );
		$progress->finish();
		// Every item has been checked; files that are still missing were listed above.
		$this->plugin->offloader->clear_cleanup_conflicts();

		$summary = sprintf( '%d ok, %d fixed, %d missing, %d errors.', $counts['ok'], $counts['fixed'], $counts['missing'], $counts['error'] );
		if ( $counts['missing'] || $counts['error'] ) {
			WP_CLI::error( $summary );
		}
		WP_CLI::success( $summary );
	}

	/**
	 * Controls bulk operations running in the background (WP-Cron).
	 *
	 * ## OPTIONS
	 *
	 * <command>
	 * : start, stop or status.
	 *
	 * [<operation>]
	 * : For start: offload, remove_local, download, bb_sync or unoffload.
	 *
	 * [--remove-local]
	 * : For start offload: remove local copies afterwards.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bm-s3 background start offload --remove-local
	 *     wp bm-s3 background status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function background( $args, $assoc_args ) {
		$background = $this->plugin->background;
		$command    = $args[0];

		if ( 'start' === $command ) {
			$operation = isset( $args[1] ) ? $args[1] : '';
			if ( ! $background->start( $operation, array( 'remove_local' => ! empty( $assoc_args['remove-local'] ) ) ) ) {
				WP_CLI::error( 'Invalid operation or another background operation is running.' );
			}
			WP_CLI::success( sprintf( 'Started %s in the background.', $operation ) );
			return;
		}

		if ( 'stop' === $command ) {
			$background->stop();
			WP_CLI::success( 'Stopped.' );
			return;
		}

		$state = $background->state();
		if ( ! $state ) {
			WP_CLI::log( 'No background operation.' );
			return;
		}
		WP_CLI::log( sprintf( '%s: %s, %d/%d processed, %d errors', $state['operation'], $state['status'], $state['processed'], $state['total'], count( $state['errors'] ) ) );
		foreach ( $state['errors'] as $id => $message ) {
			WP_CLI::warning( sprintf( '#%d: %s', $id, $message ) );
		}
	}

	/**
	 * Makes media private (expiring signed URLs) or public.
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Attachment IDs.
	 *
	 * [--private]
	 * : Make private.
	 *
	 * [--public]
	 * : Make public.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bm-s3 privacy 123 456 --private
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function privacy( $args, $assoc_args ) {
		if ( empty( $assoc_args['private'] ) === empty( $assoc_args['public'] ) ) {
			WP_CLI::error( 'Pass either --private or --public.' );
		}
		$this->execute( empty( $assoc_args['private'] ) ? 'make_public' : 'make_private', $args, array() );
	}

	/**
	 * Shows or confirms the storage the files are offloaded to.
	 *
	 * The plugin pauses all bucket operations when the endpoint differs from
	 * the storage the files were uploaded to. Copy all objects to the new
	 * storage first (e.g. `rclone sync old:bucket new:bucket`), check them with
	 * `wp bm-s3 verify` and confirm the change.
	 *
	 * ## OPTIONS
	 *
	 * [<command>]
	 * : status (default) or confirm.
	 *
	 * ## EXAMPLES
	 *
	 *     wp bm-s3 storage
	 *     wp bm-s3 storage confirm
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function storage( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		$settings = $this->plugin->settings;
		$command  = isset( $args[0] ) ? $args[0] : 'status';

		if ( 'confirm' === $command ) {
			$settings->confirm_storage();
			WP_CLI::success( sprintf( 'Storage confirmed: %s. Run "wp bm-s3 verify" to check the objects.', get_option( $settings::STORAGE_OPTION, '' ) ) );
			return;
		}
		if ( 'status' !== $command ) {
			WP_CLI::error( 'Use "status" or "confirm".' );
		}

		$error = $settings->storage_error();
		if ( '' !== $error ) {
			WP_CLI::warning( $error );
			return;
		}
		WP_CLI::success( sprintf( 'Files are offloaded to %s.', get_option( $settings::STORAGE_OPTION, '' ) ) );
	}

	/**
	 * Mirrors all BuddyBoss avatars and cover images to the bucket.
	 *
	 * @subcommand bb-sync
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 * @return void
	 */
	public function bb_sync( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI signature.
		if ( ! $this->plugin->buddyboss ) {
			WP_CLI::error( 'BuddyBoss integration is not active.' );
		}
		$this->execute( 'bb_sync', array(), array( 'all' => true ) );
	}

	/**
	 * Runs an operation for IDs or in batches for everything.
	 *
	 * @param string $operation  Operation.
	 * @param array  $ids        Attachment IDs.
	 * @param array  $assoc_args Named arguments.
	 * @param array  $options    Options.
	 * @return void
	 */
	private function execute( $operation, array $ids, array $assoc_args, array $options = array() ) {
		if ( ! $this->plugin->settings->is_configured() ) {
			WP_CLI::error( 'The bucket is not configured.' );
		}
		if ( empty( $ids ) && empty( $assoc_args['all'] ) ) {
			WP_CLI::error( 'Pass attachment IDs or --all.' );
		}

		$failed = 0;
		$done   = 0;

		if ( ! empty( $ids ) ) {
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$error = $this->plugin->bulk->process( $operation, $id, $options );
				$this->report( $id, $error, $done, $failed );
				$this->plugin->offloader->process_removal_queue();
			}
		} else {
			$total = $this->plugin->bulk->remaining( $operation );
			if ( $total < 0 && $this->plugin->buddyboss ) {
				$total = count( $this->plugin->buddyboss->all_dirs( true ) );
			}
			$progress = \WP_CLI\Utils\make_progress_bar( sprintf( '%s (%d)', $operation, $total ), $total );
			$cursor   = 0;
			do {
				$batch  = $this->plugin->bulk->run( $operation, $cursor, 25, $options );
				$cursor = $batch['cursor'];
				$done  += $batch['processed'] - count( $batch['errors'] );
				foreach ( $batch['errors'] as $id => $message ) {
					++$failed;
					WP_CLI::warning( sprintf( '#%d: %s', $id, $message ) );
				}
				$this->plugin->offloader->process_removal_queue();
				$progress->tick( $batch['processed'] );
				// Keep memory flat on large libraries.
				wp_cache_flush_runtime();
			} while ( ! $batch['done'] );
			$progress->finish();
		}

		if ( $failed ) {
			WP_CLI::error( sprintf( '%d done, %d failed.', $done, $failed ) );
		}
		WP_CLI::success( sprintf( '%d done.', $done ) );
	}

	/**
	 * Logs the result of a single item.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $error  Error message.
	 * @param int    $done   Counter of successful items.
	 * @param int    $failed Counter of failed items.
	 * @return void
	 */
	private function report( $id, $error, &$done, &$failed ) {
		if ( '' === $error ) {
			++$done;
			WP_CLI::log( sprintf( '#%d ok', $id ) );
			return;
		}
		++$failed;
		WP_CLI::warning( sprintf( '#%d: %s', $id, $error ) );
	}
}
