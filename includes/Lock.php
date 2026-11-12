<?php
/**
 * Atomic locks in the options table.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * A lock is a row in the options table with the value "{expiry}|{token}".
 *
 * The function add_option() is not suitable: it checks whether the option
 * exists and then runs an upsert, so two requests can both "add" it. INSERT
 * IGNORE on the unique option_name is atomic. An expired lock (its holder died
 * or ran too long) is taken over; the random token makes sure that the
 * previous holder can then neither extend nor release the new holder's lock.
 * The object cache is kept consistent, so get_option()/delete_option() see the
 * rows as usual.
 */
final class Lock {

	/**
	 * Acquires a lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Lifetime in seconds (extend with refresh()).
	 * @param float  $wait Seconds to wait for a lock held by somebody else.
	 * @return string Token of the acquired lock, '' if it is held by somebody else.
	 */
	public static function acquire( $name, $ttl, $wait = 0.0 ) {
		global $wpdb;
		$until = microtime( true ) + (float) $wait;
		$token = wp_generate_password( 20, false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		while ( true ) {
			$now = time();
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) <= %d", $name, $now ) );
			$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, ( $now + (int) $ttl ) . '|' . $token ) );
			self::flush_cache( $name );
			if ( 1 === $inserted ) {
				return $token;
			}
			if ( microtime( true ) >= $until ) {
				return '';
			}
			usleep( 100000 );
		}
		// phpcs:enable
	}

	/**
	 * Extends a lock if it is still held with this token.
	 *
	 * @param string $name  Option name.
	 * @param string $token Token returned by acquire().
	 * @param int    $ttl   Lifetime in seconds from now.
	 * @return bool False if the lock was lost (expired and taken over, or released).
	 */
	public static function refresh( $name, $token, $ttl ) {
		global $wpdb;
		if ( '' === (string) $token ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s", ( time() + (int) $ttl ) . '|' . $token, $name, '%|' . $wpdb->esc_like( $token ) ) );
		self::flush_cache( $name );
		return self::holds( $name, $token );
	}

	/**
	 * Releases a lock if it is still held with this token.
	 *
	 * @param string $name  Option name.
	 * @param string $token Token returned by acquire().
	 * @return void
	 */
	public static function release( $name, $token ) {
		global $wpdb;
		if ( '' === (string) $token ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", $name, '%|' . $wpdb->esc_like( $token ) ) );
		self::flush_cache( $name );
	}

	/**
	 * Whether a lock is held (by anybody) and not expired.
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_locked( $name ) {
		$value = self::value( $name );
		return null !== $value && (int) strtok( $value, '|' ) > time();
	}

	/**
	 * Whether the lock is held with this token and not expired.
	 *
	 * @param string $name  Option name.
	 * @param string $token Token.
	 * @return bool
	 */
	public static function holds( $name, $token ) {
		$value = self::value( $name );
		if ( null === $value || '' === (string) $token ) {
			return false;
		}
		$parts = explode( '|', $value, 2 );
		return 2 === count( $parts ) && hash_equals( $parts[1], (string) $token ) && (int) $parts[0] > time();
	}

	/**
	 * Raw value of a lock row.
	 *
	 * @param string $name Option name.
	 * @return string|null
	 */
	private static function value( $name ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Drops cached values of the option (including the "does not exist" cache),
	 * so that the next get_option() reads the database.
	 *
	 * @param string $name Option name.
	 * @return void
	 */
	public static function flush_cache( $name ) {
		wp_cache_delete( $name, 'options' );
		$notoptions = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $notoptions ) && isset( $notoptions[ $name ] ) ) {
			unset( $notoptions[ $name ] );
			wp_cache_set( 'notoptions', $notoptions, 'options' );
		}
	}
}
