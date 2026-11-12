<?php
/**
 * Retry lists (failed uploads, failed handovers of shared files): the hourly
 * retry skips files another attachment with the same main file uploaded, and
 * entries added by other requests while the retry runs are not lost.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'delete_remote'  => true,
		'sign_all'       => false,
	)
);
delete_option( 'bms3o_retry' );
delete_option( 'bms3o_handover' );

/**
 * Writes a list option directly to the database, as another request would
 * (without touching this request's object cache).
 *
 * @param string $option Option name.
 * @param array  $value  Value.
 * @return void
 */
function bms3o_t_write_elsewhere( $option, array $value ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $option, maybe_serialize( $value ) ) );
}

/**
 * Reads a list option from the database.
 *
 * @param string $option Option name.
 * @return array
 */
function bms3o_t_read_db( $option ) {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$value = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option ) ) );
	return is_array( $value ) ? $value : array();
}

bms3o_t_section( 'Retry of a translation' );

$original = bms3o_t_import( bms3o_t_image( 300, 200, 'retry-shared.png' ) );
$file     = get_post_meta( $original, '_wp_attached_file', true );
$copy     = wp_insert_attachment(
	array(
		'post_title'     => 'retry-shared (copy)',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	$file
);
wp_update_attachment_metadata( $copy, wp_get_attachment_metadata( $original ) );
update_option( 'bms3o_retry', array( $copy => 0 ), false );
$media->retry_failed();
$offloader->items()->forget( $media->paths( $original ) );
bms3o_t( ! in_array( $copy, $media->pending_retries(), true ), 'The retry of an attachment sharing the file succeeds', get_option( 'bms3o_retry' ) );
bms3o_t( $original === (int) $offloader->items()->get( $file )['source_id'], 'The files still belong to the attachment that uploaded them' );
wp_delete_attachment( $copy, true );
wp_delete_attachment( $original, true );

bms3o_t_section( 'Concurrent changes of the retry list' );

bms3o_t_settings( array( 'copy_on_upload' => false ) );
$id = bms3o_t_import( bms3o_t_image( 300, 200, 'retry-concurrent.png' ) );
bms3o_t_settings( array( 'copy_on_upload' => true ) );
bms3o_t( null === $media->main_item( $id ), 'The attachment is not offloaded yet' );

update_option( 'bms3o_retry', array( $id => 0 ), false );
$other  = 999999;
$inject = static function () use ( $id, $other ) {
	// Another request adds an entry while the retry uploads.
	bms3o_t_write_elsewhere( 'bms3o_retry', array( $id => 0, $other => 2 ) );
};
add_action( 'bms3o_uploaded', $inject );
$media->retry_failed();
remove_action( 'bms3o_uploaded', $inject );
$retry = bms3o_t_read_db( 'bms3o_retry' );
bms3o_t( null !== $media->main_item( $id ) && ! isset( $retry[ $id ] ), 'The retried attachment is offloaded and leaves the list', $retry );
bms3o_t( isset( $retry[ $other ] ) && 2 === (int) $retry[ $other ], 'An entry added by another request meanwhile is kept', $retry );
delete_option( 'bms3o_retry' );
wp_delete_attachment( $id, true );

bms3o_t_section( 'Lock of the lists' );

$token = \BahrMedia\S3Offload\Lock::acquire( 'bms3o_retry_lock', 2 );
bms3o_t_write_elsewhere( 'bms3o_retry', array( $other => 1 ) );
$id    = bms3o_t_import( bms3o_t_image( 300, 200, 'retry-lock.png' ) );
$start = microtime( true );
// Adding to the list waits for the lock held by another request, then reads the current list.
$add = new ReflectionMethod( $media, 'add_retry' );
$add->setAccessible( true );
$add->invoke( $media, $id );
$waited = microtime( true ) - $start;
$retry = bms3o_t_read_db( 'bms3o_retry' );
bms3o_t( $waited >= 1.0 && isset( $retry[ $id ], $retry[ $other ] ), 'Adding an entry waits for the lock and keeps the other entries', array( round( $waited, 2 ), $retry ) );
\BahrMedia\S3Offload\Lock::release( 'bms3o_retry_lock', $token );
bms3o_t( ! \BahrMedia\S3Offload\Lock::is_locked( 'bms3o_retry_lock' ), 'The lock is released afterwards' );
delete_option( 'bms3o_retry' );
wp_delete_attachment( $id, true );

bms3o_t_section( 'Concurrent changes of the handover list' );

update_option( 'bms3o_handover', array( 'gone/handover-a.png' => 'gone/handover-a.png' ), false );
$inject = static function ( $query ) {
	static $done = false;
	if ( ! $done && false !== strpos( $query, 'bms3o_items' ) ) {
		$done = true; // Another request adds a handover while the retry reads the items.
		bms3o_t_write_elsewhere(
			'bms3o_handover',
			array(
				'gone/handover-a.png' => 'gone/handover-a.png',
				'gone/handover-b.png' => 'gone/handover-b.png',
			)
		);
	}
	return $query;
};
add_filter( 'query', $inject );
$media->retry_handovers();
remove_filter( 'query', $inject );
$pending = bms3o_t_read_db( 'bms3o_handover' );
bms3o_t( ! isset( $pending['gone/handover-a.png'] ) && isset( $pending['gone/handover-b.png'] ), 'The finished entry leaves the list, the new one is kept', $pending );
delete_option( 'bms3o_handover' );

bms3o_t_done();
