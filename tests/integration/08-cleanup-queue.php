<?php
/**
 * Cleanup queue (objects that still have to be deleted): concurrent changes,
 * storage identity, storage change guard, rotation of failing entries and
 * reporting of leftover public copies by re-uploads.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB

use BahrMedia\S3Offload\Settings;
use BahrMedia\S3Offload\Store\Items;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

$plugin    = bms3o_t_plugin();
$settings  = $plugin->settings;
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$client    = $settings->client();
$bucket    = $client->get_bucket();
$storage   = Settings::storage_id( $settings->get( 'endpoint' ) );

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'delete_remote'  => true,
	)
);
delete_option( $offloader::ORPHANS_OPTION );
wp_set_current_user( 0 );

/**
 * Creates an object and returns its key.
 *
 * @param string $name Name.
 * @return string
 */
function bms3o_t_queue_object( $name ) {
	$key = bms3o_t_plugin()->offloader->key_prefix( false ) . 'bms3o-test/queue-' . $name . '.txt';
	bms3o_t_plugin()->settings->client()->put_string( $key, $name );
	return $key;
}

/**
 * Keys in the queue.
 *
 * @return string[]
 */
function bms3o_t_queued() {
	return array_values( wp_list_pluck( bms3o_t_plugin()->offloader->orphans( true ), 'key' ) );
}

bms3o_t_section( 'Entries added while the cleanup runs are kept' );

$a = bms3o_t_queue_object( 'a' );
$b = bms3o_t_queue_object( 'b' );
$offloader->queue_orphans( array( array( $bucket, $a, 'bms3o-test/queue-a.txt' ) ) );

$concurrent = static function ( $pre, $args, $url ) use ( $offloader, $bucket, $b ) {
	static $done = false;
	if ( false === $pre && ! $done && 'POST' === $args['method'] && false !== strpos( $url, '?delete' ) ) {
		$done = true;
		// Another request queues an object while this one talks to the bucket.
		$offloader->queue_orphans( array( array( $bucket, $b, 'bms3o-test/queue-b.txt' ) ) );
	}
	return $pre;
};
add_filter( 'pre_http_request', $concurrent, 10, 3 );
$offloader->process_orphans();
remove_filter( 'pre_http_request', $concurrent, 10 );

bms3o_t( ! bms3o_t_object_exists( $a ), 'The processed object is deleted' );
bms3o_t( array( $b ) === bms3o_t_queued(), 'The entry added meanwhile is still queued', bms3o_t_queued() );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $b ) && array() === bms3o_t_queued(), 'It is deleted by the next run' );

bms3o_t_section( 'Entries are only executed on the storage they belong to' );

$c       = bms3o_t_queue_object( 'c' );
$other   = 'https://other-storage.invalid';
$orphans = array(
	md5( $bucket . '|' . $c ) => array(
		'bucket'   => $bucket,
		'key'      => $c,
		'path'     => 'bms3o-test/queue-c.txt',
		'storage'  => $other,
		'attempts' => 0,
	),
);
update_option( $offloader::ORPHANS_OPTION, $orphans, false );
$offloader->process_orphans();
bms3o_t( bms3o_t_object_exists( $c ) && array( $c ) === bms3o_t_queued(), 'An entry of another storage is neither executed nor dropped' );
do_action( 'bms3o_storage_changed', $other, $storage );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $c ) && array() === bms3o_t_queued(), 'A confirmed change of storage takes the entries along' );

bms3o_t_section( 'Queued deletions block a change of storage' );

// Simulate a site whose last offloaded file was deleted: no items, only queued objects.
$no_items = static function ( $query ) {
	return 'SELECT COUNT(*) FROM ' . Items::table() === trim( $query ) ? 'SELECT 0' : $query;
};
$endpoint = $settings->get( 'endpoint' );
$input    = array( 'endpoint' => 'http://storage.invalid:9000' ) + array_map( 'strval', $settings->all() );
unset( $input['secret_key'] );

$d = bms3o_t_queue_object( 'd' );
$offloader->queue_orphans( array( array( $bucket, $d, 'bms3o-test/queue-d.txt' ) ) );
add_filter( 'query', $no_items );
$errors = $settings->save( $input );
bms3o_t( ! empty( $errors ) && $settings->get( 'endpoint' ) === $endpoint, 'A new endpoint is rejected while deletions are queued', $errors );
$offloader->process_orphans();
$errors = $settings->save( $input );
$moved  = $settings->get( 'endpoint' );
$settings->save( array_merge( $input, array( 'endpoint' => $endpoint ) ) );
remove_filter( 'query', $no_items );
bms3o_t( empty( $errors ) && 'http://storage.invalid:9000' === $moved, 'Without files and queued deletions the endpoint can be changed', $errors );
bms3o_t( $settings->get( 'endpoint' ) === $endpoint && '' === $settings->storage_error(), 'Changed back' );

bms3o_t_section( 'Failing entries do not block the others' );

$e    = bms3o_t_queue_object( 'e' );
$f    = bms3o_t_queue_object( 'f' );
$deny = static function ( $pre, $args, $url ) use ( $e ) {
	$body = is_string( $args['body'] ) ? $args['body'] : '';
	if ( false !== $pre || ( false === strpos( $url, rawurlencode( basename( $e ) ) ) && false === strpos( $body, basename( $e ) ) ) ) {
		return $pre;
	}
	return array(
		'headers'  => array(),
		'body'     => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>AccessDenied</Code><Message>Simulated</Message></Error>',
		'response' => array(
			'code'    => 403,
			'message' => 'Forbidden',
		),
		'cookies'  => array(),
		'filename' => null,
	);
};
$offloader->queue_orphans(
	array(
		array( $bucket, $e, 'bms3o-test/queue-e.txt' ),
		array( $bucket, $f, 'bms3o-test/queue-f.txt' ),
	)
);
add_filter( 'pre_http_request', $deny, 10, 3 );
$offloader->process_orphans( 1 );
bms3o_t( array( $f, $e ) === bms3o_t_queued(), 'A failed entry moves to the end of the queue', bms3o_t_queued() );
$offloader->process_orphans( 1 );
bms3o_t( ! bms3o_t_object_exists( $f ) && array( $e ) === bms3o_t_queued(), 'The next entry is processed' );
$offloader->process_orphans( 1 );
$offloader->process_orphans( 1 );
bms3o_t( 1 === $offloader->failing_orphans(), 'Repeated failures are reported' );
remove_filter( 'pre_http_request', $deny, 10 );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $e ) && 0 === $offloader->failing_orphans() && array() === bms3o_t_queued(), 'Deleted once the deletion works again' );

bms3o_t_section( 'A re-upload reports a public copy that is still reachable' );

$id     = bms3o_t_import( bms3o_t_image( 200, 150, 'queue-private.png' ) );
$file   = get_post_meta( $id, '_wp_attached_file', true );
$public = $offloader->items()->get( $file )['object_key'];
update_post_meta( $id, '_bms3o_private', '1' );
$fail = static function ( $pre, $args, $url ) {
	if ( false === $pre && ( 'DELETE' === $args['method'] || 'POST' === $args['method'] ) && false === strpos( $url, '/private/' ) ) {
		return array(
			'headers'  => array(),
			'body'     => '',
			'response' => array(
				'code'    => 403,
				'message' => 'Forbidden',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
	return $pre;
};
add_filter( 'pre_http_request', $fail, 10, 3 );
$result = $media->offload( $id, null, true );
remove_filter( 'pre_http_request', $fail, 10 );
bms3o_t( isset( $result['errors'][ $file ] ) && $offloader->items()->get( $file )['is_private'], 'The incomplete change is reported, the file is private', $result['errors'] );
bms3o_t( in_array( $public, bms3o_t_queued(), true ) && bms3o_t_object_exists( $public ), 'The public copy is queued for cleanup' );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $public ), 'The cleanup removes it' );
wp_delete_attachment( $id, true );
delete_option( $offloader::ORPHANS_OPTION );

bms3o_t_done();
