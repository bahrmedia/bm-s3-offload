<?php
/**
 * Queue of objects that still have to be deleted from the bucket.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One row per object. Every change is a single atomic SQL statement, so
 * concurrent requests never overwrite each other's entries (the queue was a
 * single option in 1.0.1 – 1.0.3 and needed read-modify-write cycles).
 */
final class Orphans {

	/**
	 * Option of the queue in 1.0.1 – 1.0.3 (migrated on upgrade).
	 */
	const LEGACY_OPTION = 'bms3o_orphans';

	/**
	 * Maximum number of entries.
	 */
	const MAX = 5000;

	/**
	 * Seconds before a failed entry is due again; others are processed first.
	 */
	const RETRY_DELAY = 300;

	/**
	 * Seconds after which a claim of a process that died while writing to the
	 * key expires (the cleanup then checks the entry again).
	 */
	const CLAIM_TTL = DAY_IN_SECONDS;

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bms3o_orphans';
	}

	/**
	 * Creates the table and migrates the legacy option. The option is only
	 * deleted when every entry has been written to the table.
	 *
	 * @return bool Whether the table exists and the migration is complete.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  object_hash char(32) NOT NULL DEFAULT '',
  bucket varchar(63) NOT NULL DEFAULT '',
  object_key varchar(1024) NOT NULL DEFAULT '',
  path varchar(1024) NOT NULL DEFAULT '',
  storage varchar(255) NOT NULL DEFAULT '',
  attempts int(10) unsigned NOT NULL DEFAULT 0,
  next_attempt int(10) unsigned NOT NULL DEFAULT 0,
  claims int(10) unsigned NOT NULL DEFAULT 0,
  claimed_until int(10) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY object_hash (object_hash),
  KEY next_attempt (next_attempt)
) {$collate};"
		);

		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return false;
		}

		$legacy = get_option( self::LEGACY_OPTION, null );
		if ( is_array( $legacy ) ) {
			$store  = new self();
			$failed = array();
			foreach ( $legacy as $entry ) {
				if ( isset( $entry['bucket'], $entry['key'], $entry['path'] ) && $store->add( array( array( $entry['bucket'], $entry['key'], $entry['path'] ) ), isset( $entry['storage'] ) ? (string) $entry['storage'] : '' ) ) {
					$failed[] = $entry;
				}
			}
			if ( ! empty( $failed ) ) {
				update_option( self::LEGACY_OPTION, $failed, false ); // Retried on the next upgrade check.
				return false;
			}
		}
		if ( null !== $legacy ) {
			delete_option( self::LEGACY_OPTION );
		}
		return true;
	}

	/**
	 * Hash identifying an object.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return string
	 */
	public static function hash( $bucket, $key ) {
		return md5( $bucket . '|' . $key );
	}

	/**
	 * Adds objects (an existing entry keeps its attempts).
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $objects Bucket, key and path of each object.
	 * @param string                                       $storage Storage identity.
	 * @return array<int,array{0:string,1:string,2:string}> Objects that could not be queued (queue full or database error).
	 */
	public function add( array $objects, $storage ) {
		global $wpdb;
		$table  = self::table();
		$failed = array();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		foreach ( $objects as $object ) {
			if ( $this->count() >= self::MAX && ! $this->has( $object[0], $object[1] ) ) {
				$failed[] = $object;
				continue;
			}
			$written = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO {$table} (object_hash, bucket, object_key, path, storage) VALUES (%s, %s, %s, %s, %s)
					ON DUPLICATE KEY UPDATE path = VALUES(path), storage = VALUES(storage)",
					self::hash( (string) $object[0], (string) $object[1] ),
					(string) $object[0],
					(string) $object[1],
					(string) $object[2],
					(string) $storage
				)
			);
			if ( false === $written ) {
				$failed[] = $object;
			}
		}
		// phpcs:enable
		return $failed;
	}

	/**
	 * Whether an object is queued.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return bool
	 */
	public function has( $bucket, $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE object_hash = %s", self::hash( $bucket, $key ) ) );
	}

	/**
	 * Whether the queue has entries (independent of its size).
	 *
	 * @return bool
	 */
	public function has_any() {
		global $wpdb;
		$table = self::table();
		return null !== $wpdb->get_var( "SELECT id FROM {$table} LIMIT 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Entry of an object.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return array|null Row.
	 */
	public function get( $bucket, $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE object_hash = %s", self::hash( $bucket, $key ) ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Whether an entry is claimed by an operation that writes to its key.
	 *
	 * @param array $row Row.
	 * @return bool
	 */
	public static function is_claimed( array $row ) {
		return (int) $row['claims'] > 0 && (int) $row['claimed_until'] > time();
	}

	/**
	 * Claims a queued object for an operation that writes to its key: the
	 * cleanup leaves it alone until the claim is released (or expires).
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return bool False if the claim could not be recorded.
	 */
	public function claim( $bucket, $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET claims = claims + 1, claimed_until = %d WHERE object_hash = %s", time() + self::CLAIM_TTL, self::hash( $bucket, $key ) ) );
		return false !== $updated;
	}

	/**
	 * Releases a claim (the write failed; the entry stays queued).
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return void
	 */
	public function unclaim( $bucket, $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET claims = IF( claims > 0, claims - 1, 0 ) WHERE object_hash = %s", self::hash( $bucket, $key ) ) );
	}

	/**
	 * Entries that are due on a storage (entries without storage belong to any),
	 * except entries claimed by an operation that writes to their key.
	 *
	 * @param string $storage Storage identity.
	 * @param int    $limit   Maximum number of entries.
	 * @return array[] Rows, failed entries last.
	 */
	public function due( $storage, $limit ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE ( storage = %s OR storage = '' ) AND next_attempt <= %d AND ( claims = 0 OR claimed_until <= %d ) ORDER BY next_attempt ASC, id ASC LIMIT %d", $storage, time(), time(), max( 1, (int) $limit ) ), ARRAY_A );
	}

	/**
	 * All entries in processing order.
	 *
	 * @return array[] Rows.
	 */
	public function all() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( "SELECT * FROM {$table} ORDER BY next_attempt ASC, id ASC", ARRAY_A );
	}

	/**
	 * Removes an object from the queue.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return void
	 */
	public function remove( $bucket, $key ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'object_hash' => self::hash( $bucket, $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Records a failed deletion: the entry is due again after the retry delay.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return void
	 */
	public function failed( $bucket, $key ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET attempts = attempts + 1, next_attempt = %d WHERE object_hash = %s", time() + self::RETRY_DELAY, self::hash( $bucket, $key ) ) );
	}

	/**
	 * Moves entries to another storage (confirmed change of storage).
	 *
	 * @param string $from Previous storage identity.
	 * @param string $to   New storage identity.
	 * @return void
	 */
	public function move( $from, $to ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET storage = %s WHERE storage = %s OR storage = ''", $to, $from ) );
	}

	/**
	 * Number of entries.
	 *
	 * @param int $min_attempts Only entries that failed at least this often.
	 * @return int
	 */
	public function count( $min_attempts = 0 ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE attempts >= %d", (int) $min_attempts ) );
	}

	/**
	 * Removes all entries.
	 *
	 * @return void
	 */
	public function clear() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}
}
