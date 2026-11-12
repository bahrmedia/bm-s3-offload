<?php
/**
 * Repository for offloaded files.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Store;

defined( 'ABSPATH' ) || exit;

/**
 * One row per offloaded file. The local path (relative to the uploads base
 * directory) is the unique identity of an item, which makes URL rewriting a
 * simple, indexed lookup.
 *
 * Columns:
 *  - source_type   'attachment', 'bb_avatar', 'bb_cover' …
 *  - source_id     Attachment ID, user ID or group ID.
 *  - path          Relative local path, e.g. "2026/11/photo-300x200.jpg".
 *  - bucket        Bucket the object lives in.
 *  - object_key    Object key in the bucket.
 *  - is_private    1 = served with pre-signed URLs only.
 *  - local_removed 1 = the local copy has been deleted.
 *  - filesize      Size in bytes at upload time.
 *  - local_mtime   Modification time of the local file at upload time.
 */
final class Items {

	const DB_VERSION  = '3';
	const CACHE_GROUP = 'bms3o_items';
	const SOURCE_POST = 'attachment';
	const NOT_FOUND   = 0;

	/**
	 * Request cache per site: blog ID => path => row|0.
	 *
	 * @var array<int,array<string,array|int>>
	 */
	private $cache = array();

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'bms3o_items';
	}

	/**
	 * Creates or updates the table.
	 *
	 * @return void
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  source_type varchar(32) NOT NULL DEFAULT '',
  source_id bigint(20) unsigned NOT NULL DEFAULT 0,
  path varchar(1024) NOT NULL DEFAULT '',
  path_hash char(32) NOT NULL DEFAULT '',
  bucket varchar(63) NOT NULL DEFAULT '',
  object_key varchar(1024) NOT NULL DEFAULT '',
  is_private tinyint(1) unsigned NOT NULL DEFAULT 0,
  local_removed tinyint(1) unsigned NOT NULL DEFAULT 0,
  filesize bigint(20) unsigned NOT NULL DEFAULT 0,
  local_mtime int(10) unsigned NOT NULL DEFAULT 0,
  created_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY path_hash (path_hash),
  KEY path (path(191)),
  KEY source (source_type,source_id)
) {$collate};"
		);

		Orphans::install();

		update_option( 'bms3o_db_version', self::DB_VERSION, true );
	}

	/**
	 * Drops the table (uninstall).
	 *
	 * @return void
	 */
	public static function uninstall() {
		global $wpdb;
		$table = self::table();
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		delete_option( 'bms3o_db_version' );
	}

	/**
	 * Item for a relative path.
	 *
	 * @param string $path Relative path.
	 * @return array|null
	 */
	public function get( $path ) {
		$rows = $this->get_many( array( $path ) );
		return isset( $rows[ $path ] ) ? $rows[ $path ] : null;
	}

	/**
	 * Items for several paths with a single query. Misses are cached too.
	 *
	 * @param string[] $paths Relative paths.
	 * @return array<string,array> Map path => row (only found items).
	 */
	public function get_many( array $paths ) {
		global $wpdb;

		$found   = array();
		$missing = array();

		foreach ( array_unique( array_map( 'strval', $paths ) ) as $path ) {
			if ( '' === $path ) {
				continue;
			}
			if ( ! $this->remembered( $path ) ) {
				$cached = wp_cache_get( self::cache_key( $path ), self::CACHE_GROUP );
				if ( false !== $cached ) {
					$this->cache[ get_current_blog_id() ][ $path ] = $cached;
				}
			}
			if ( $this->remembered( $path ) ) {
				if ( is_array( $this->cache[ get_current_blog_id() ][ $path ] ) ) {
					$found[ $path ] = $this->cache[ get_current_blog_id() ][ $path ];
				}
				continue;
			}
			$missing[] = $path;
		}

		foreach ( array_chunk( $missing, 200 ) as $chunk ) {
			$table        = self::table();
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE path_hash IN ({$placeholders})", array_map( 'md5', $chunk ) ), ARRAY_A );

			$by_path = array();
			foreach ( (array) $rows as $row ) {
				$by_path[ $row['path'] ] = self::normalize( $row );
			}

			foreach ( $chunk as $path ) {
				$value = isset( $by_path[ $path ] ) ? $by_path[ $path ] : self::NOT_FOUND;
				$this->cache[ get_current_blog_id() ][ $path ] = $value;
				wp_cache_set( self::cache_key( $path ), $value, self::CACHE_GROUP, DAY_IN_SECONDS );
				if ( is_array( $value ) ) {
					$found[ $path ] = $value;
				}
			}
		}

		return $found;
	}

	/**
	 * All items of a source.
	 *
	 * @param string $type Source type.
	 * @param int    $id   Source ID.
	 * @return array<string,array> Map path => row.
	 */
	public function get_by_source( $type, $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type = %s AND source_id = %d", $type, $id ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$row                   = self::normalize( $row );
			$items[ $row['path'] ] = $row;
			$this->cache[ get_current_blog_id() ][ $row['path'] ] = $row;
		}
		return $items;
	}

	/**
	 * Loads the items of many sources with one query into the request cache.
	 *
	 * Paths listed in $expected that are not found are cached as misses, so
	 * later lookups for them do not hit the database.
	 *
	 * @param string   $type     Source type.
	 * @param int[]    $ids      Source IDs.
	 * @param string[] $expected Paths that are expected to belong to these sources.
	 * @return void
	 */
	public function prime( $type, array $ids, array $expected = array() ) {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return;
		}

		$table = self::table();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE source_type = %s AND source_id IN ({$placeholders})", array_merge( array( $type ), $chunk ) ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$row = self::normalize( $row );
				$this->cache[ get_current_blog_id() ][ $row['path'] ] = $row;
			}
		}

		foreach ( $expected as $path ) {
			if ( '' !== $path && ! $this->remembered( $path ) ) {
				$this->cache[ get_current_blog_id() ][ $path ] = self::NOT_FOUND;
			}
		}
	}

	/**
	 * Items in a directory (recursively).
	 *
	 * @param string $dir Relative directory without trailing slash.
	 * @return array<string,array> Map path => row.
	 */
	public function get_in_dir( $dir ) {
		return $this->get_with_prefix( trailingslashit( $dir ) );
	}

	/**
	 * Items whose path starts with a prefix.
	 *
	 * @param string $prefix Path prefix.
	 * @param int    $limit  Maximum number of rows (0 = no limit).
	 * @return array<string,array> Map path => row.
	 */
	public function get_with_prefix( $prefix, $limit = 0 ) {
		global $wpdb;
		$table = self::table();
		$like  = $wpdb->esc_like( $prefix ) . '%';
		$sql   = $limit > 0
			? $wpdb->prepare( "SELECT * FROM {$table} WHERE path LIKE %s LIMIT %d", $like, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM {$table} WHERE path LIKE %s", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $sql, ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$row                   = self::normalize( $row );
			$items[ $row['path'] ] = $row;
		}
		return $items;
	}

	/**
	 * Number of files below a path prefix that exist only in the bucket.
	 *
	 * @param string $prefix Path prefix.
	 * @return int
	 */
	public function count_bucket_only( $prefix ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE path LIKE %s AND local_removed = 1", $wpdb->esc_like( $prefix ) . '%' ) );
	}

	/**
	 * Items ordered by ID, for iterating over the whole table.
	 *
	 * @param int $after_id Return items with a larger ID.
	 * @param int $limit    Maximum number of items.
	 * @return array[] Rows.
	 */
	public function page( $after_id, $limit ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE id > %d ORDER BY id ASC LIMIT %d", $after_id, $limit ), ARRAY_A );
		return array_map( array( __CLASS__, 'normalize' ), (array) $rows );
	}

	/**
	 * Number of items.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Inserts or updates an item (identified by its path).
	 *
	 * @param array $item Item data.
	 * @return bool Whether the row was written.
	 */
	public function save( array $item ) {
		global $wpdb;
		$table = self::table();

		$row = array(
			'source_type'   => (string) $item['source_type'],
			'source_id'     => (int) $item['source_id'],
			'path'          => (string) $item['path'],
			'path_hash'     => md5( (string) $item['path'] ),
			'bucket'        => (string) $item['bucket'],
			'object_key'    => (string) $item['object_key'],
			'is_private'    => empty( $item['is_private'] ) ? 0 : 1,
			'local_removed' => empty( $item['local_removed'] ) ? 0 : 1,
			'filesize'      => isset( $item['filesize'] ) ? (int) $item['filesize'] : 0,
			'local_mtime'   => isset( $item['local_mtime'] ) ? (int) $item['local_mtime'] : 0,
			'created_at'    => current_time( 'mysql', true ),
		);

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery -- Table name only.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (source_type, source_id, path, path_hash, bucket, object_key, is_private, local_removed, filesize, local_mtime, created_at)
				VALUES (%s, %d, %s, %s, %s, %s, %d, %d, %d, %d, %s)
				ON DUPLICATE KEY UPDATE source_type = VALUES(source_type), source_id = VALUES(source_id), bucket = VALUES(bucket),
					object_key = VALUES(object_key), is_private = VALUES(is_private), local_removed = VALUES(local_removed), filesize = VALUES(filesize), local_mtime = VALUES(local_mtime)",
				$row['source_type'],
				$row['source_id'],
				$row['path'],
				$row['path_hash'],
				$row['bucket'],
				$row['object_key'],
				$row['is_private'],
				$row['local_removed'],
				$row['filesize'],
				$row['local_mtime'],
				$row['created_at']
			)
		);
		// phpcs:enable

		$this->forget( array( $row['path'] ) );
		return false !== $written;
	}

	/**
	 * Sets the local_removed flag for paths.
	 *
	 * @param string[] $paths   Relative paths.
	 * @param bool     $removed Flag value.
	 * @return void
	 */
	public function set_local_removed( array $paths, $removed ) {
		global $wpdb;
		$paths = array_values( array_filter( array_map( 'strval', $paths ), 'strlen' ) );
		if ( empty( $paths ) ) {
			return;
		}
		$table = self::table();
		foreach ( array_chunk( $paths, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET local_removed = %d WHERE path_hash IN ({$placeholders})", array_merge( array( $removed ? 1 : 0 ), array_map( 'md5', $chunk ) ) ) );
		}
		$this->forget( $paths );
	}

	/**
	 * Deletes items by path.
	 *
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	public function delete( array $paths ) {
		global $wpdb;
		$paths = array_values( array_filter( array_map( 'strval', $paths ), 'strlen' ) );
		if ( empty( $paths ) ) {
			return;
		}
		$table = self::table();
		foreach ( array_chunk( $paths, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE path_hash IN ({$placeholders})", array_map( 'md5', $chunk ) ) );
		}
		$this->forget( $paths );
	}

	/**
	 * Aggregated statistics.
	 *
	 * @return array{files:int,bytes:int,local_removed:int,private:int}
	 */
	public function stats() {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( "SELECT COUNT(*) AS files, COALESCE(SUM(filesize),0) AS bytes, COALESCE(SUM(local_removed),0) AS local_removed, COALESCE(SUM(is_private),0) AS private FROM {$table}", ARRAY_A );

		return array(
			'files'         => isset( $row['files'] ) ? (int) $row['files'] : 0,
			'bytes'         => isset( $row['bytes'] ) ? (int) $row['bytes'] : 0,
			'local_removed' => isset( $row['local_removed'] ) ? (int) $row['local_removed'] : 0,
			'private'       => isset( $row['private'] ) ? (int) $row['private'] : 0,
		);
	}

	/**
	 * Removes paths from all caches.
	 *
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	public function forget( array $paths ) {
		foreach ( $paths as $path ) {
			unset( $this->cache[ get_current_blog_id() ][ $path ] );
			wp_cache_delete( self::cache_key( $path ), self::CACHE_GROUP );
		}
	}

	/**
	 * Whether a path (found or not) is in the request cache of the current site.
	 *
	 * @param string $path Relative path.
	 * @return bool
	 */
	private function remembered( $path ) {
		$blog_id = get_current_blog_id();
		return isset( $this->cache[ $blog_id ] ) && array_key_exists( $path, $this->cache[ $blog_id ] );
	}

	/**
	 * Cache key for a path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function cache_key( $path ) {
		// Versioned: rows cached before a schema change are never used.
		return self::DB_VERSION . ':' . md5( $path );
	}

	/**
	 * Casts DB values.
	 *
	 * @param array $row DB row.
	 * @return array
	 */
	private static function normalize( array $row ) {
		$row['id']            = (int) $row['id'];
		$row['source_id']     = (int) $row['source_id'];
		$row['is_private']    = (bool) $row['is_private'];
		$row['local_removed'] = (bool) $row['local_removed'];
		$row['filesize']      = (int) $row['filesize'];
		$row['local_mtime']   = isset( $row['local_mtime'] ) ? (int) $row['local_mtime'] : 0;
		unset( $row['path_hash'] );
		return $row;
	}
}
