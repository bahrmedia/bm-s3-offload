<?php
/**
 * Counters for large tables: index for the "pending" counters, existence
 * checks with LIMIT 1 and the counter cache of the tools tab.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.DB

use BahrMedia\S3Offload\Admin\SettingsPage;
use BahrMedia\S3Offload\Bulk;
use BahrMedia\S3Offload\Store\Counts;
use BahrMedia\S3Offload\Store\Items;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

global $wpdb;

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$bulk      = $plugin->bulk;
$items     = $offloader->items();

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
	)
);
wp_set_current_user( 0 );

/**
 * Lets the counter cache drop its values on the next change again (a process
 * drops it at most once within a few seconds).
 *
 * @return void
 */
function bms3o_t_reset_flush() {
	$flushed = new ReflectionProperty( Counts::class, 'flushed' );
	$flushed->setAccessible( true );
	$flushed->setValue( null, 0.0 );
}

bms3o_t_section( 'Database schema' );

$table   = Items::table();
$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'source_local'", 4 );
bms3o_t( array( 'source_type', 'local_removed', 'source_id' ) === $indexes, 'Index (source_type, local_removed, source_id) exists', $indexes );
bms3o_t( Items::DB_VERSION === get_option( 'bms3o_db_version' ), 'Schema version is current' );

$wpdb->query( "ALTER TABLE {$table} DROP INDEX source_local" );
update_option( 'bms3o_db_version', '3' );
Items::install();
$indexes = $wpdb->get_col( "SHOW INDEX FROM {$table} WHERE Key_name = 'source_local'", 4 );
bms3o_t( 3 === count( $indexes ) && '4' === get_option( 'bms3o_db_version' ), 'The upgrade from schema 3 adds the index', $indexes );

$plan = $wpdb->get_results( $wpdb->prepare( "EXPLAIN SELECT COUNT(DISTINCT source_id) FROM {$table} FORCE INDEX (source_local) WHERE source_type = %s AND local_removed = %d", Items::SOURCE_POST, 1 ), ARRAY_A );
bms3o_t( 'source_local' === $plan[0]['key'] && false !== stripos( (string) $plan[0]['Extra'], 'index' ), 'The counter of local copies is answered from the index alone', $plan );

bms3o_t_section( 'Existence checks with LIMIT 1' );

$id   = bms3o_t_import( bms3o_t_image( 120, 90, 'counters.png' ) );
$file = get_post_meta( $id, '_wp_attached_file', true );
bms3o_t( $items->has_any() && $items->count() > 0, 'has_any() finds items' );

$queries = array();
$log     = static function ( $query ) use ( &$queries ) {
	$queries[] = $query;
	return $query;
};
add_filter( 'query', $log );
$items->has_any();
$offloader->orphan_store()->has_any();
remove_filter( 'query', $log );
bms3o_t( 2 === count( $queries ) && 2 === count( preg_grep( '/LIMIT 1$/', $queries ) ) && ! preg_grep( '/COUNT/i', $queries ), 'Both checks stop at the first row', $queries );

$offloader->orphan_store()->clear();
bms3o_t( ! $offloader->orphan_store()->has_any(), 'An empty queue is detected' );
$offloader->queue_orphans( array( array( 'bucket', 'counters/test.bin', 'counters/test.bin' ) ) );
bms3o_t( $offloader->orphan_store()->has_any(), 'A queued object is detected' );
$offloader->orphan_store()->clear();

foreach ( Bulk::OPERATIONS as $operation ) {
	if ( 'bb_sync' === $operation ) {
		continue;
	}
	$remaining = $bulk->remaining( $operation );
	bms3o_t( ( $remaining > 0 ) === $bulk->has_pending( $operation ), "has_pending() matches remaining() for {$operation}", $remaining );
}

bms3o_t_section( 'Counter cache' );

delete_transient( Counts::TRANSIENT );
$calls   = 0;
$compute = static function () use ( &$calls ) {
	return ++$calls;
};
bms3o_t( 1 === Counts::get( 'test', $compute ) && 1 === Counts::get( 'test', $compute ) && 1 === $calls, 'A counter is computed once and then cached' );

$items->save( $items->get( $file ) );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'Saving an item drops the cache' );

Counts::get( 'test', $compute );
$items->set_local_removed( array( $file ), false );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'Changing the local copy flag drops the cache' );

Counts::get( 'test', $compute );
bms3o_t_settings( array( 'copy_on_upload' => false ) );
$plain = bms3o_t_import( bms3o_t_image( 80, 60, 'counters-plain.png' ) );
bms3o_t_settings( array( 'copy_on_upload' => true ) );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'A new attachment that is not offloaded drops the cache' );

Counts::get( 'test', $compute );
$plugin->settings->save( array_map( 'strval', array_diff_key( $plugin->settings->all(), array( 'secret_key' => true ) ) ) );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'Saving the settings drops the cache' );

Counts::get( 'test', $compute );
wp_delete_attachment( $plain, true );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'Deleting an attachment drops the cache' );

Counts::get( 'test', $compute );
$items->delete( array( 'counters/does-not-exist.png' ) );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'Deleting items drops the cache' );

$items->save( $items->get( $file ) );
set_transient( Counts::TRANSIENT, array( 'test' => 1 ), 60 );
for ( $i = 0; $i < 5; $i++ ) {
	$items->save( $items->get( $file ) );
}
bms3o_t( false !== get_transient( Counts::TRANSIENT ), 'Repeated writes within seconds drop the cache only once' );

add_filter( 'bms3o_count_cache_ttl', '__return_zero' );
bms3o_t_reset_flush();
delete_transient( Counts::TRANSIENT );
Counts::get( 'test', $compute );
bms3o_t( false === get_transient( Counts::TRANSIENT ), 'A lifetime of 0 disables the cache' );
remove_filter( 'bms3o_count_cache_ttl', '__return_zero' );

bms3o_t_section( 'Tools tab uses the cache' );

wp_set_current_user( 1 );
$_GET['tab'] = 'tools';
$page        = new SettingsPage( $plugin );
delete_transient( Counts::TRANSIENT );
ob_start();
$page->render();
ob_end_clean();
$cached = get_transient( Counts::TRANSIENT );
bms3o_t( is_array( $cached ) && isset( $cached['stats'], $cached['remaining_offload'], $cached['remaining_download'] ), 'Rendering fills the cache', is_array( $cached ) ? array_keys( $cached ) : $cached );

$cached['stats']['files']      = 987654;
$cached['remaining_unoffload'] = 123456;
set_transient( Counts::TRANSIENT, $cached, 60 );
ob_start();
$page->render();
$html = ob_get_clean();
bms3o_t( false !== strpos( $html, number_format_i18n( 987654 ) ) && false !== strpos( $html, number_format_i18n( 123456 ) ), 'Statistics and pending counters come from the cache' );
unset( $_GET['tab'] );
wp_set_current_user( 0 );
delete_transient( Counts::TRANSIENT );

wp_delete_attachment( $id, true );

bms3o_t_done();
