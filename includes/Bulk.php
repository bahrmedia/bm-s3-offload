<?php
/**
 * Batch operations shared by the admin tools and WP-CLI.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\Integrations\BuddyBoss;
use BahrMedia\S3Offload\Media\Attachments;
use BahrMedia\S3Offload\Store\Items;

defined( 'ABSPATH' ) || exit;

/**
 * Every operation works with an ascending cursor (the last processed ID), so
 * failing items never block a run and runs can be resumed.
 */
final class Bulk {

	const OPERATIONS = array( 'offload', 'remove_local', 'download', 'unoffload', 'bb_sync', 'sync_privacy' );

	/**
	 * Attachments module.
	 *
	 * @var Attachments
	 */
	private $attachments;

	/**
	 * BuddyBoss integration (null if inactive).
	 *
	 * @var BuddyBoss|null
	 */
	private $buddyboss;

	/**
	 * Constructor.
	 *
	 * @param Attachments    $attachments Attachments module.
	 * @param BuddyBoss|null $buddyboss   BuddyBoss integration.
	 */
	public function __construct( Attachments $attachments, $buddyboss = null ) {
		$this->attachments = $attachments;
		$this->buddyboss   = $buddyboss;
	}

	/**
	 * Number of objects an operation still has to process.
	 *
	 * @param string $operation Operation.
	 * @return int -1 if unknown without an expensive file system scan.
	 */
	public function remaining( $operation ) {
		if ( 'bb_sync' === $operation ) {
			if ( ! $this->buddyboss ) {
				return 0;
			}
			$cached = get_transient( BuddyBoss::DIRS_CACHE );
			return is_array( $cached ) ? count( $cached ) : -1;
		}
		return $this->count( $operation );
	}

	/**
	 * Processes one batch.
	 *
	 * @param string $operation Operation.
	 * @param int    $cursor    Last processed ID (or offset for bb_sync).
	 * @param int    $limit     Batch size.
	 * @param array  $options   Options: 'remove_local' (bool) removes local copies after offloading, 'force' (bool) uploads unchanged files again.
	 * @return array{cursor:int,processed:int,done:bool,errors:array<int,string>,total?:int}
	 */
	public function run( $operation, $cursor, $limit, array $options = array() ) {
		$limit  = max( 1, (int) $limit );
		$errors = array();

		if ( 'bb_sync' === $operation ) {
			// Scan the file system once per run (first batch), then use the cached list.
			$all  = $this->buddyboss ? $this->buddyboss->all_dirs( 0 === (int) $cursor ) : array();
			$dirs = array_slice( $all, (int) $cursor, $limit );
			foreach ( $dirs as $dir ) {
				$result = $this->buddyboss->sync_dir( $dir['dir'], $dir['type'], $dir['id'] );
				if ( $result['errors'] ) {
					/* translators: %s: directory */
					$errors[ $dir['id'] ] = sprintf( __( 'Errors while synchronising %s', 'bm-s3-offload' ), $dir['dir'] );
				}
			}
			return array(
				'cursor'    => (int) $cursor + count( $dirs ),
				'processed' => count( $dirs ),
				'done'      => (int) $cursor + count( $dirs ) >= count( $all ),
				'errors'    => $errors,
				'total'     => count( $all ),
			);
		}

		$ids = $this->ids( $operation, (int) $cursor, $limit );
		foreach ( $ids as $id ) {
			$message = $this->process( $operation, $id, $options );
			if ( '' !== $message ) {
				$errors[ $id ] = $message;
			}
		}

		return array(
			'cursor'    => empty( $ids ) ? (int) $cursor : (int) end( $ids ),
			'processed' => count( $ids ),
			'done'      => count( $ids ) < $limit,
			'errors'    => $errors,
		);
	}

	/**
	 * Processes a single attachment.
	 *
	 * @param string $operation     Operation.
	 * @param int    $attachment_id Attachment ID.
	 * @param array  $options       Options.
	 * @return string Error message ('' on success).
	 */
	public function process( $operation, $attachment_id, array $options = array() ) {
		switch ( $operation ) {
			case 'offload':
				$remove = isset( $options['remove_local'] ) ? (bool) $options['remove_local'] : null;
				$result = $this->attachments->offload( $attachment_id, null, ! empty( $options['force'] ), $remove );
				return self::first_error( $result['errors'] );

			case 'remove_local':
				$this->attachments->remove_local( $attachment_id );
				return '';

			case 'download':
				$result = $this->attachments->restore_local( $attachment_id );
				return self::first_error( $result['errors'] );

			case 'unoffload':
				return self::first_error( $this->attachments->unoffload( $attachment_id ) );

			case 'sync_privacy':
				return self::first_error( $this->attachments->sync_privacy( $attachment_id ) );

			case 'make_private':
			case 'make_public':
				return self::first_error( $this->attachments->set_privacy( $attachment_id, 'make_private' === $operation ) );
		}
		return '';
	}

	/**
	 * Attachment IDs for an operation, ascending after the cursor.
	 *
	 * @param string $operation Operation.
	 * @param int    $cursor    Last processed ID.
	 * @param int    $limit     Maximum number of IDs.
	 * @return int[]
	 */
	private function ids( $operation, $cursor, $limit ) {
		global $wpdb;
		$items = Items::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names only.
		switch ( $operation ) {
			case 'offload':
				$sql = $wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.ID > %d
					AND NOT EXISTS ( SELECT 1 FROM {$items} i WHERE i.source_type = %s AND i.source_id = p.ID )
					ORDER BY p.ID ASC LIMIT %d",
					$cursor,
					Items::SOURCE_POST,
					$limit
				);
				break;

			case 'remove_local':
			case 'download':
				$sql = $wpdb->prepare(
					"SELECT DISTINCT source_id FROM {$items} WHERE source_type = %s AND local_removed = %d AND source_id > %d ORDER BY source_id ASC LIMIT %d",
					Items::SOURCE_POST,
					'download' === $operation ? 1 : 0,
					$cursor,
					$limit
				);
				break;

			case 'unoffload':
				$sql = $wpdb->prepare(
					"SELECT DISTINCT source_id FROM {$items} WHERE source_type = %s AND source_id > %d ORDER BY source_id ASC LIMIT %d",
					Items::SOURCE_POST,
					$cursor,
					$limit
				);
				break;

			case 'sync_privacy':
				// BuddyBoss media whose storage does not match the "private media" setting.
				$sql = $wpdb->prepare(
					"SELECT DISTINCT source_id FROM {$items} WHERE source_type = %s AND ( path LIKE %s OR path LIKE %s OR path LIKE %s ) AND is_private = %d
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = source_id AND m.meta_key = %s AND m.meta_value = '1' )
					AND source_id > %d ORDER BY source_id ASC LIMIT %d",
					Items::SOURCE_POST,
					'bb\\_medias/%',
					'bb\\_documents/%',
					'bb\\_videos/%',
					$this->attachments->buddyboss_private() ? 0 : 1,
					Attachments::META_PRIVATE,
					$cursor,
					$limit
				);
				break;

			default:
				return array();
		}

		$ids = $wpdb->get_col( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		// phpcs:enable

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of attachments an operation applies to.
	 *
	 * @param string $operation Operation.
	 * @return int
	 */
	private function count( $operation ) {
		global $wpdb;
		$items = Items::table();

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table names only.
		switch ( $operation ) {
			case 'offload':
				$sql = $wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_type = 'attachment'
					AND NOT EXISTS ( SELECT 1 FROM {$items} i WHERE i.source_type = %s AND i.source_id = p.ID )",
					Items::SOURCE_POST
				);
				break;

			case 'remove_local':
			case 'download':
				$sql = $wpdb->prepare(
					"SELECT COUNT(DISTINCT source_id) FROM {$items} WHERE source_type = %s AND local_removed = %d",
					Items::SOURCE_POST,
					'download' === $operation ? 1 : 0
				);
				break;

			case 'unoffload':
				$sql = $wpdb->prepare( "SELECT COUNT(DISTINCT source_id) FROM {$items} WHERE source_type = %s", Items::SOURCE_POST );
				break;

			case 'sync_privacy':
				$sql = $wpdb->prepare(
					"SELECT COUNT(DISTINCT source_id) FROM {$items} WHERE source_type = %s AND ( path LIKE %s OR path LIKE %s OR path LIKE %s ) AND is_private = %d
					AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = source_id AND m.meta_key = %s AND m.meta_value = '1' )",
					Items::SOURCE_POST,
					'bb\\_medias/%',
					'bb\\_documents/%',
					'bb\\_videos/%',
					$this->attachments->buddyboss_private() ? 0 : 1,
					Attachments::META_PRIVATE
				);
				break;

			default:
				return 0;
		}

		$count = $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared above.
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * First error message of a result.
	 *
	 * @param array $errors Errors by path.
	 * @return string
	 */
	private static function first_error( array $errors ) {
		if ( empty( $errors ) ) {
			return '';
		}
		$path = (string) key( $errors );
		return $path . ': ' . reset( $errors );
	}
}
