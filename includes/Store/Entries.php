<?php
/**
 * Lists of entries stored as single option rows.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Store;

defined( 'ABSPATH' ) || exit;

/**
 * A list (retries, pending handovers) whose entries are separate rows in the
 * options table, named prefix + key. Adding, removing and changing an entry
 * is a single SQL statement, so requests that add entries and the cron that
 * works them off cannot overwrite each other's changes – no lock needed, and
 * nothing to lose when a lock could not be acquired.
 *
 * The rows are never read through get_option() (not autoloaded, not cached).
 */
final class Entries {

	/**
	 * Option name prefix of the entries.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param string $prefix Option name prefix, e.g. 'bms3o_retry_'.
	 */
	public function __construct( $prefix ) {
		$this->prefix = (string) $prefix;
	}

	/**
	 * Adds an entry; an existing entry keeps its value.
	 *
	 * @param string $key   Key (letters, digits, '_' and '-'; the option name is limited to 191 characters).
	 * @param mixed  $value Value.
	 * @return bool Whether the entry exists now.
	 */
	public function add( $key, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $this->prefix . $key, maybe_serialize( $value ) ) );
		return false !== $result;
	}

	/**
	 * Changes the value of an entry that (still) exists.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 * @return void
	 */
	public function update( $key, $value ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s", maybe_serialize( $value ), $this->prefix . $key ) );
	}

	/**
	 * Removes an entry.
	 *
	 * @param string $key Key.
	 * @return void
	 */
	public function remove( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $this->prefix . $key ) );
	}

	/**
	 * Whether an entry exists.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public function has( $key ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null !== $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $this->prefix . $key ) );
	}

	/**
	 * Entries, oldest first.
	 *
	 * @param int    $limit      Maximum number.
	 * @param string $key_prefix Only keys starting with this prefix.
	 * @return array<string,mixed> Values by key.
	 */
	public function all( $limit = 1000, $key_prefix = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id ASC LIMIT %d", $wpdb->esc_like( $this->prefix . $key_prefix ) . '%', max( 1, (int) $limit ) ), ARRAY_A );
		$entries = array();
		$length  = strlen( $this->prefix );
		foreach ( (array) $rows as $row ) {
			$entries[ substr( $row['option_name'], $length ) ] = maybe_unserialize( $row['option_value'] );
		}
		return $entries;
	}

	/**
	 * Number of entries.
	 *
	 * @return int
	 */
	public function count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $this->prefix ) . '%' ) );
	}

	/**
	 * Removes all entries (uninstall).
	 *
	 * @return void
	 */
	public function clear() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $this->prefix ) . '%' ) );
	}
}
