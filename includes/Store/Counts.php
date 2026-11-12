<?php
/**
 * Cache for the counters shown in the admin.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Store;

defined( 'ABSPATH' ) || exit;

/**
 * The statistics and "pending" counters of the tools tab aggregate the whole
 * items table, which takes noticeable time with large tables. They are cached
 * in a transient that is dropped whenever the plugin changes items, settings
 * or attachments. The lifetime only matters for changes made outside the
 * plugin (e.g. directly in the database).
 */
final class Counts {

	/**
	 * Transient that holds all cached counters.
	 */
	const TRANSIENT = 'bms3o_counts';

	/**
	 * Within this many seconds a process drops the cache only once
	 * (a bulk run writes many items).
	 */
	const FLUSH_INTERVAL = 10;

	/**
	 * Time of the last flush in this process.
	 *
	 * @var float
	 */
	private static $flushed = 0.0;

	/**
	 * Returns a cached counter or computes and caches it.
	 *
	 * @param string   $key     Counter name.
	 * @param callable $compute Computes the value.
	 * @return mixed
	 */
	public static function get( $key, callable $compute ) {
		$cached = get_transient( self::TRANSIENT );
		$cached = is_array( $cached ) ? $cached : array();
		if ( array_key_exists( $key, $cached ) ) {
			return $cached[ $key ];
		}

		$cached[ $key ] = $compute();

		/**
		 * Filters how long the counters of the tools tab are cached, in seconds
		 * (0 disables the cache). Changes made by the plugin drop the cache anyway.
		 *
		 * @param int $seconds Lifetime.
		 */
		$ttl = (int) apply_filters( 'bms3o_count_cache_ttl', 5 * MINUTE_IN_SECONDS );
		if ( $ttl > 0 ) {
			set_transient( self::TRANSIENT, $cached, $ttl );
			self::$flushed = 0.0; // The next change has to drop the new values.
		}
		return $cached[ $key ];
	}

	/**
	 * Drops the cached counters.
	 *
	 * @return void
	 */
	public static function flush() {
		$now = microtime( true );
		if ( $now - self::$flushed < self::FLUSH_INTERVAL ) {
			return;
		}
		self::$flushed = $now;
		delete_transient( self::TRANSIENT );
	}
}
