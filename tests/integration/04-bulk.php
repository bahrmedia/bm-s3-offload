<?php
/**
 * Bulk operations, background runner, verify, sidecar files.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

use BahrMedia\S3Offload\Background;

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$bulk      = $plugin->bulk;

bms3o_t_section( 'Bulk offload of existing media' );

bms3o_t_settings( array( 'copy_on_upload' => false ) );
$ids = array();
for ( $i = 0; $i < 3; $i++ ) {
	$ids[] = bms3o_t_import( bms3o_t_image( 500, 400, "bulk-{$i}.png" ) );
}
bms3o_t_settings( array( 'copy_on_upload' => true ) );

bms3o_t( $bulk->remaining( 'offload' ) >= 3, 'New media are pending' );

$cursor = 0;
do {
	$batch  = $bulk->run( 'offload', $cursor, 2, array( 'remove_local' => true ) );
	$cursor = $batch['cursor'];
	$offloader->process_removal_queue();
} while ( ! $batch['done'] );
$ok = true;
foreach ( $ids as $id ) {
	$item = $media->main_item( $id );
	$ok   = $ok && $item && $item['local_removed'];
}
bms3o_t( $ok && 0 === $bulk->remaining( 'offload' ), 'Batches offload everything and remove local copies' );

bms3o_t_section( 'Restore and remove in bulk' );

do {
	$batch = $bulk->run( 'download', 0, 50 );
} while ( ! $batch['done'] );
bms3o_t( 0 === $bulk->remaining( 'download' ) && file_exists( get_attached_file( $ids[0] ) ), 'All local copies restored' );

do {
	$batch = $bulk->run( 'remove_local', 0, 50 );
} while ( ! $batch['done'] );
bms3o_t( ! file_exists( get_attached_file( $ids[0] ) ), 'Local copies removed in bulk (verified)' );

bms3o_t_section( 'Background runner' );

$background = $plugin->background;
$background->stop();
delete_option( Background::OPTION );
bms3o_t( $background->start( 'download' ), 'Background operation started' );
bms3o_t( ! $background->start( 'offload' ), 'A second operation is refused while one is running' );
for ( $i = 0; $i < 10 && $background->is_running(); $i++ ) {
	delete_transient( Background::LOCK );
	do_action( Background::HOOK );
}
$state = $background->state();
bms3o_t( 'done' === $state['status'] && $state['processed'] >= 3, 'Background operation finished', $state );
bms3o_t( file_exists( get_attached_file( $ids[1] ) ), 'Background operation did the work' );

// Idempotent operation: spawn_cron() may already run it in a parallel request.
bms3o_t( $background->start( 'download' ), 'Second background operation can start after the first one' );
$background->stop();
// A parallel cron request (spawn_cron) may have finished the fast operation
// already, or may even re-add a stale cron event. What matters: nothing runs.
$before = $background->state();
bms3o_t( ! $background->is_running() && in_array( $before['status'], array( 'stopped', 'done' ), true ), 'Background operation can be stopped', $before );
delete_transient( Background::LOCK );
do_action( Background::HOOK );
bms3o_t( $background->state() === $before, 'A stale cron event after stopping does nothing' );

bms3o_t_section( 'Verify' );

$file = get_post_meta( $ids[2], '_wp_attached_file', true );
$item = $offloader->items()->get( $file );
$plugin->settings->client()->delete( $item['object_key'] );
bms3o_t( 'missing' === $offloader->verify( $item, false ), 'Missing object detected' );
bms3o_t( 'fixed' === $offloader->verify( $item, true ) && bms3o_t_object_exists( $item['object_key'] ), 'Missing object uploaded again from the local copy' );
bms3o_t( 'ok' === $offloader->verify( $item ), 'Object present afterwards' );

bms3o_t_section( 'WebP/AVIF copies of image optimisers' );

$sidecar_id = bms3o_t_import( bms3o_t_image( 300, 200, 'sidecar.png' ) );
foreach ( $media->paths( $sidecar_id ) as $path ) {
	copy( $offloader->absolute( $path ), $offloader->absolute( $path ) . '.webp' );
}
$result = $media->offload( $sidecar_id, null, false, true );
$offloader->process_removal_queue();
$main = get_post_meta( $sidecar_id, '_wp_attached_file', true );
bms3o_t( null !== $offloader->items()->get( $main . '.webp' ), 'photo.png.webp is offloaded with the attachment', $result );
bms3o_t( ! file_exists( $offloader->absolute( $main . '.webp' ) ), 'Its local copy is removed as well' );
$html = apply_filters( 'the_content', '<picture><source srcset="' . $offloader->uploads()['baseurl'] . '/' . $main . '.webp"></picture>' );
bms3o_t( false === strpos( $html, $offloader->uploads()['baseurl'] ), 'WebP URLs in content are rewritten' );

foreach ( array_merge( $ids, array( $sidecar_id ) ) as $id ) {
	wp_delete_attachment( $id, true );
}
bms3o_t( null === $offloader->items()->get( $main . '.webp' ), 'Sidecar objects are deleted with the attachment' );

bms3o_t_done();
