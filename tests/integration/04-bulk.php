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
$slices = 0;
while ( $slices < 10 && $background->is_running() ) {
	delete_option( Background::LOCK );
	do_action( Background::HOOK );
	++$slices;
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
delete_option( Background::LOCK );
do_action( Background::HOOK );
bms3o_t( $background->state() === $before, 'A stale cron event after stopping does nothing' );

// Stop written by another request (directly in the database, not via this
// request's option cache) must win over a running slice.
$background->stop();
bms3o_t( $background->start( 'download' ), 'Start for the cache test' );
get_option( Background::OPTION ); // Warm this request's cache with "running".
$stopped           = $background->state();
$stopped['status'] = 'stopped';
$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->options, array( 'option_value' => maybe_serialize( $stopped ) ), array( 'option_name' => Background::OPTION ) );
delete_option( Background::LOCK );
do_action( Background::HOOK );
bms3o_t( 'stopped' === $background->state()['status'], 'A stop from another request is not overwritten by a running slice' );

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

// "owner.png.webp" uploaded as an attachment of its own must not be claimed by "owner.png".
$owner      = bms3o_t_import( bms3o_t_image( 200, 200, 'owner.png' ) );
$owner_file = get_post_meta( $owner, '_wp_attached_file', true );
$webp       = $offloader->absolute( $owner_file ) . '.webp';
copy( $offloader->absolute( $owner_file ), $webp );
$other = wp_insert_attachment(
	array(
		'post_title'     => 'separate webp',
		'post_mime_type' => 'image/webp',
		'post_status'    => 'inherit',
	),
	$webp
);
bms3o_t( ! in_array( $owner_file . '.webp', $media->paths( $owner ), true ), 'A sidecar-like file that is its own attachment is not claimed' );
wp_update_attachment_metadata( $other, array() );
$media->offload( $owner, null, true, true );
$offloader->process_removal_queue();
bms3o_t( file_exists( $webp ) || $offloader->items()->get( $owner_file . '.webp' )['source_id'] === $other, 'The other attachment keeps its file', $offloader->items()->get( $owner_file . '.webp' ) );
wp_delete_attachment( $owner, true );
bms3o_t( null !== $offloader->items()->get( $owner_file . '.webp' ), 'Deleting the first attachment keeps the other one\'s object' );
wp_delete_attachment( $other, true );

bms3o_t_section( 'Browser requests with a time budget' );

bms3o_t_settings( array( 'copy_on_upload' => false ) );
$budget_ids = array();
for ( $i = 0; $i < 3; $i++ ) {
	$budget_ids[] = bms3o_t_import( bms3o_t_image( 200, 150, "budget-{$i}.png" ) );
}
bms3o_t_settings( array( 'copy_on_upload' => true ) );
$page  = new BahrMedia\S3Offload\Admin\SettingsPage( $plugin );
$start = min( $budget_ids ) - 1;

// Uploading the first attachment takes longer than the budget of one second.
$slow = static function ( $path, $key, $source_type, $source_id ) use ( $budget_ids ) {
	static $seen = array();
	if ( $source_id === $budget_ids[0] && ! isset( $seen[ $source_id ] ) ) {
		$seen[ $source_id ] = true;
		usleep( 1200000 );
	}
};
add_action( 'bms3o_uploaded', $slow, 10, 4 );
$result = $page->run_bulk( 'offload', $start, array(), 10, 1 ); // The budget is checked after every item, not per batch of 10.
remove_action( 'bms3o_uploaded', $slow, 10 );
bms3o_t( 1 === $result['processed'] && ! $result['done'] && $result['cursor'] === $budget_ids[0], 'The request ends after the budget, the next one continues at the cursor', $result );

$result = $page->run_bulk( 'offload', $result['cursor'], array(), 1, 30 );
bms3o_t( 2 === $result['processed'] && $result['done'], 'Within the budget, several batches are processed in one request', $result );
$ok = true;
foreach ( $budget_ids as $id ) {
	$ok = $ok && null !== $media->main_item( $id );
}
bms3o_t( $ok, 'All attachments are offloaded' );

foreach ( array_merge( $ids, $budget_ids, array( $sidecar_id ) ) as $id ) {
	wp_delete_attachment( $id, true );
}
bms3o_t( null === $offloader->items()->get( $main . '.webp' ), 'Sidecar objects are deleted with the attachment' );

bms3o_t_done();
