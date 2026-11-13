<?php
/**
 * Retry lists (failed uploads, failed handovers of shared files): the hourly
 * retry skips files another attachment with the same main file uploaded;
 * every entry is a row of its own, so entries added or removed by other
 * requests while the retry runs are not lost – without any lock. The lists
 * of 1.0.10/1.0.11 (one option each) are migrated.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

use BahrMedia\S3Offload\Lock;
use BahrMedia\S3Offload\Media\Attachments;
use BahrMedia\S3Offload\Store\Entries;
use BahrMedia\S3Offload\Store\Items;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$retries   = new Entries( Attachments::RETRY_PREFIX );
$handovers = new Entries( Attachments::HANDOVER_PREFIX );

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'delete_remote'  => true,
		'sign_all'       => false,
	)
);
bms3o_t_clear_lists();

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
$retries->add( (string) $copy, 0 );
$media->retry_failed();
$offloader->items()->forget( $media->paths( $original ) );
bms3o_t( ! in_array( $copy, $media->pending_retries(), true ), 'The retry of an attachment sharing the file succeeds', $retries->all() );
bms3o_t( $original === (int) $offloader->items()->get( $file )['source_id'], 'The files still belong to the attachment that uploaded them' );
wp_delete_attachment( $copy, true );
wp_delete_attachment( $original, true );

bms3o_t_section( 'Concurrent changes of the retry list' );

bms3o_t_settings( array( 'copy_on_upload' => false ) );
$id = bms3o_t_import( bms3o_t_image( 300, 200, 'retry-concurrent.png' ) );
bms3o_t_settings( array( 'copy_on_upload' => true ) );
bms3o_t( null === $media->main_item( $id ), 'The attachment is not offloaded yet' );

$retries->add( (string) $id, 0 );
$other  = 999999;
$inject = static function () use ( $retries, $other ) {
	$retries->add( (string) $other, 2 ); // Another request adds an entry while the retry uploads.
};
add_action( 'bms3o_uploaded', $inject );
$media->retry_failed();
remove_action( 'bms3o_uploaded', $inject );
$retry = $retries->all();
bms3o_t( null !== $media->main_item( $id ) && ! isset( $retry[ $id ] ), 'The retried attachment is offloaded and leaves the list', $retry );
bms3o_t( isset( $retry[ $other ] ) && 2 === (int) $retry[ $other ], 'An entry added by another request meanwhile is kept', $retry );
bms3o_t_clear_lists();
wp_delete_attachment( $id, true );

bms3o_t_section( 'No lock involved' );

// A lock of 1.0.11 (held by a request still running the old code) neither blocks nor loses the change.
$token = Lock::acquire( 'bms3o_retry_lock', 30 );
$retries->add( (string) $other, 1 );
$id    = bms3o_t_import( bms3o_t_image( 300, 200, 'retry-lock.png' ) );
$start = microtime( true );
$add   = new ReflectionMethod( $media, 'add_retry' );
$add->setAccessible( true );
$add->invoke( $media, $id );
$waited = microtime( true ) - $start;
$retry  = $retries->all();
bms3o_t( $waited < 1.0 && isset( $retry[ $id ], $retry[ $other ] ), 'Adding an entry does not wait and keeps the other entries', array( round( $waited, 2 ), $retry ) );
Lock::release( 'bms3o_retry_lock', $token );
bms3o_t_clear_lists();
wp_delete_attachment( $id, true );

bms3o_t_section( 'Concurrent changes of the handover list' );

$handovers->add( md5( 'gone/handover-a.png' ) . '_' . md5( 'gone/handover-a.png' ), array( 'gone/handover-a.png', 'gone/handover-a.png' ) );
$inject = static function ( $query ) use ( $handovers ) {
	static $done = false;
	if ( ! $done && false !== strpos( $query, 'bms3o_items' ) ) {
		$done = true; // Another request adds a handover while the retry reads the items.
		$handovers->add( md5( 'gone/handover-b.png' ) . '_' . md5( 'gone/handover-b.png' ), array( 'gone/handover-b.png', 'gone/handover-b.png' ) );
	}
	return $query;
};
add_filter( 'query', $inject );
$media->retry_handovers();
remove_filter( 'query', $inject );
$pending = wp_list_pluck( $handovers->all(), 0 );
bms3o_t( array( 'gone/handover-b.png' ) === array_values( $pending ), 'The finished entry leaves the list, the new one is kept', $pending );
bms3o_t_clear_lists();

bms3o_t_section( 'Migration of the lists of 1.0.10/1.0.11' );

update_option( 'bms3o_retry', array( $other => 3 ), false );
update_option( 'bms3o_handover', array( 'gone/legacy.png' => 'gone/legacy-main.png' ), false );
update_option( 'bms3o_retry_lock', ( time() + 30 ) . '|token', false );
update_option( 'bms3o_db_version', '5' );
Items::install();
$retry   = $retries->all();
$pending = array_values( $handovers->all() );
bms3o_t( isset( $retry[ $other ] ) && 3 === (int) $retry[ $other ], 'Retry entries are migrated with their attempts', $retry );
bms3o_t( array( array( 'gone/legacy.png', 'gone/legacy-main.png' ) ) === $pending, 'Pending handovers are migrated', $pending );
bms3o_t( null === get_option( 'bms3o_retry', null ) && null === get_option( 'bms3o_handover', null ) && null === get_option( 'bms3o_retry_lock', null ), 'The old options and locks are removed' );
bms3o_t( Items::DB_VERSION === get_option( 'bms3o_db_version' ), 'The schema version is current' );
bms3o_t_clear_lists();

bms3o_t_done();
