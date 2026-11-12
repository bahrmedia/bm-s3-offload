<?php
/**
 * Atomic locks in the options table.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * A lock is a row in the options table whose value is its expiry time.
 *
 * The function add_option() is not suitable: it checks whether the option exists and then
 * runs an upsert, so two requests can both "add" it. INSERT IGNORE on the
 * unique option_name is atomic. An expired lock (its holder died) is taken
 * over. The object cache is kept consistent, so get_option()/delete_option()
 * see the rows as usual.
 */
final class Lock {

	/**
	 * Acquires a lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Lifetime in seconds (renew with refresh()).
	 * @param float  $wait Seconds to wait for a lock held by somebody else.
	 * @return bool Whether the lock was acquired.
	 */
	public static function acquire( $name, $ttl, $wait = 0.0 ) {
		global $wpdb;
		$until = microtime( true ) + (float) $wait;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		while ( true ) {
			$now = time();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(option_value AS UNSIGNED) <= %d", $name, $now ) );
			$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) ( $now + (int) $ttl ) ) );
			self::flush_cache( $name );
			if ( 1 === $inserted ) {
				return true;
			}
			if ( microtime( true ) >= $until ) {
				return false;
			}
			usleep( 100000 );
		}
		// phpcs:enable
	}

	/**
	 * Extends a held lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Lifetime in seconds from now.
	 * @return void
	 */
	public static function refresh( $name, $ttl ) {
		global $wpdb;
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) ( time() + (int) $ttl ) ), array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_cache( $name );
	}

	/**
	 * Releases a lock.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function release( $name ) {
		global $wpdb;
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::flush_cache( $name );
	}

	/**
	 * Whether a lock is held and not expired.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_locked( $name ) {
		global $wpdb;
		$expires = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $expires && (int) $expires > time();
	}

	/**
	 * Drops cached values of the option (including the "does not exist" cache).
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	private static function flush_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
}
