<?php
/**
 * Failure handling: failed deletions and database writes, size checks,
 * storage changes, retries, multipart copy and uninstall.
 *
 * Failures of the S3 server are simulated with `pre_http_request`, database
 * failures with the `query` filter.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.DB

use BahrMedia\S3Offload\S3\Client;
use BahrMedia\S3Offload\Store\Items;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

$plugin    = bms3o_t_plugin();
$settings  = $plugin->settings;
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
delete_option( $offloader::ORPHANS_OPTION );
wp_set_current_user( 0 );

/**
 * Lets S3 requests fail.
 *
 * @param callable $matches Receives method and URL, returns true for requests that fail.
 * @param int      $status  HTTP status of the failure.
 * @return callable The filter (pass it to remove_filter()).
 */
function bms3o_t_fail_requests( callable $matches, $status = 403 ) {
	$filter = static function ( $pre, $args, $url ) use ( $matches, $status ) {
		if ( false !== $pre || ! $matches( $args['method'], $url ) ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>Simulated</Code><Message>Simulated failure</Message></Error>',
			'response' => array(
				'code'    => $status,
				'message' => 'Simulated',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	return $filter;
}

bms3o_t_section( 'Failed deletion of the public copy when a file becomes private' );

$id     = bms3o_t_import( bms3o_t_image( 320, 240, 'robust-privacy.png' ) );
$file   = get_post_meta( $id, '_wp_attached_file', true );
$public = $offloader->items()->get( $file )['object_key'];

$filter = bms3o_t_fail_requests(
	static function ( $method, $url ) {
		return ( 'DELETE' === $method || 'POST' === $method ) && false === strpos( $url, '/private/' );
	}
);
$errors = $media->set_privacy( $id, true );
remove_filter( 'pre_http_request', $filter, 10 );

$item = $offloader->items()->get( $file );
bms3o_t( ! empty( $errors ), 'The incomplete change is reported', $errors );
bms3o_t( $item['is_private'] && '1' === get_post_meta( $id, '_bms3o_private', true ), 'The file is private nevertheless (item and attachment)' );
bms3o_t( bms3o_t_object_exists( $public ), 'The public copy is still there (simulated failure)' );
$orphans = wp_list_pluck( $offloader->orphans(), 'key' );
bms3o_t( in_array( $public, $orphans, true ), 'The public copy is queued for cleanup', $orphans );

$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $public ), 'The cleanup removes the public copy' );
bms3o_t( empty( $offloader->orphans() ), 'The cleanup queue is empty afterwards' );

bms3o_t_section( 'The cleanup never deletes objects that are in use again' );

$errors = $media->set_privacy( $id, false );
$item   = $offloader->items()->get( $file );
bms3o_t( empty( $errors ) && $item['object_key'] === $public, 'Made public again', $errors );
$offloader->queue_orphans( array( array( $item['bucket'], $public, $file ) ) );
$offloader->process_orphans();
bms3o_t( bms3o_t_object_exists( $public ) && empty( $offloader->orphans() ), 'A queued key that is referenced again is kept and forgotten' );

bms3o_t_section( 'Failed database writes' );

$break = static function ( $query ) {
	return 0 === strpos( ltrim( $query ), 'INSERT INTO ' . Items::table() ) ? 'INSERT INTO bms3o_simulated_failure VALUES (1)' : $query;
};
$GLOBALS['wpdb']->suppress_errors( true );
add_filter( 'query', $break );
$errors = $offloader->set_privacy( array( $file ), true );
remove_filter( 'query', $break );
$GLOBALS['wpdb']->suppress_errors( false );

$item = $offloader->items()->get( $file );
bms3o_t( ! empty( $errors ), 'set_privacy() reports the failed write', $errors );
bms3o_t( ! $item['is_private'] && $item['object_key'] === $public, 'The item still points to the public object' );
bms3o_t( bms3o_t_object_exists( $public ), 'The public object was not deleted' );
bms3o_t( ! bms3o_t_object_exists( $offloader->object_key( $file, true ) ), 'The unrecorded private copy was removed again' );

bms3o_t_section( 'Size check before local copies are removed' );

$local = $offloader->absolute( $file );
$settings->client()->put_string( $public, 'x' );
$removed = $offloader->remove_local( array( $file ), true );
bms3o_t( empty( $removed ) && is_file( $local ), 'A truncated object does not count as copy', $removed );
$result = $offloader->verify( $offloader->items()->get( $file ) );
bms3o_t( 0 === strpos( $result, 'size mismatch' ), 'verify reports the size mismatch', $result );
$result = $offloader->verify( $offloader->items()->get( $file ), true );
$head   = $settings->client()->head( $public );
bms3o_t( 'fixed' === $result && (int) $head['content-length'] === filesize( $local ), 'verify --fix uploads the file again', $result );
$removed = $offloader->remove_local( array( $file ), true );
bms3o_t( array( $file ) === $removed, 'Afterwards the local copy can be removed' );
$offloader->download( array( $file ) );

bms3o_t_section( 'Change of storage' );

$endpoint = $settings->get( 'endpoint' );
$other    = 'http://storage.invalid:9000';
$input    = array( 'endpoint' => $other ) + array_map( 'strval', $settings->all() );
unset( $input['secret_key'] );

$errors = $settings->save( $input );
bms3o_t( ! empty( $errors ) && $settings->get( 'endpoint' ) === $endpoint, 'An unconfirmed new endpoint is rejected', $errors );

$moved = static function ( $values ) use ( $other ) {
	$values['endpoint'] = $other;
	return $values;
};
add_filter( 'bms3o_settings', $moved );
$settings->flush();
$paths = $media->paths( $id );
bms3o_t( '' !== $settings->storage_error(), 'An endpoint changed by constant is detected' );
$upload = $offloader->upload( Items::SOURCE_POST, $id, $paths, false, true );
bms3o_t( empty( $upload['uploaded'] ) && count( $upload['errors'] ) === count( $paths ), 'Uploads are paused' );
bms3o_t( array() === $offloader->remove_local( $paths, true ) && is_file( $local ), 'Local copies are kept' );
bms3o_t( count( $offloader->delete_remote( $paths ) ) === count( $paths ) && null !== $offloader->items()->get( $file ), 'Deletions are paused' );
bms3o_t( ! empty( $offloader->set_privacy( $paths, true ) ), 'Privacy changes are paused' );
remove_filter( 'bms3o_settings', $moved );
$settings->flush();
bms3o_t( '' === $settings->storage_error(), 'Back to normal with the original endpoint' );

$errors = $settings->save( array_merge( $input, array( 'storage_moved' => '1' ) ) );
bms3o_t( empty( $errors ) && $settings->get( 'endpoint' ) === $other && '' === $settings->storage_error(), 'A confirmed change is accepted', $errors );
$settings->save( array_merge( $input, array( 'endpoint' => $endpoint, 'storage_moved' => '1' ) ) );
bms3o_t( $settings->get( 'endpoint' ) === $endpoint && '' === $settings->storage_error(), 'Changed back (confirmed)' );

bms3o_t_section( 'Failed deletion of a deleted attachment' );

$keys   = wp_list_pluck( $offloader->items()->get_by_source( Items::SOURCE_POST, $id ), 'object_key' );
$filter = bms3o_t_fail_requests(
	static function ( $method ) {
		return 'DELETE' === $method || 'POST' === $method;
	}
);
wp_delete_attachment( $id, true );
remove_filter( 'pre_http_request', $filter, 10 );
$orphans = wp_list_pluck( $offloader->orphans(), 'key' );
bms3o_t( ! array_diff( $keys, $orphans ) && bms3o_t_object_exists( $public ), 'The objects are queued for cleanup', $orphans );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $public ) && empty( $offloader->orphans() ), 'The cleanup deletes them' );

bms3o_t_section( 'S3 client: retries and multipart copy' );

$config   = array_intersect_key( $settings->all(), array_flip( array( 'endpoint', 'region', 'bucket', 'access_key', 'secret_key', 'path_style' ) ) );
$client   = new Client( $config );
$key      = $offloader->key_prefix( false ) . 'bms3o-test/retry.txt';
$attempts = 0;
$flaky    = static function ( $pre, $args ) use ( &$attempts ) {
	if ( false !== $pre || 'PUT' !== $args['method'] ) {
		return $pre;
	}
	return 1 === ++$attempts ? array(
		'headers'  => array(),
		'body'     => '',
		'response' => array(
			'code'    => 503,
			'message' => 'Slow Down',
		),
		'cookies'  => array(),
		'filename' => null,
	) : $pre;
};
add_filter( 'pre_http_request', $flaky, 10, 2 );
$client->put_string( $key, 'retry' );
remove_filter( 'pre_http_request', $flaky, 10 );
bms3o_t( 2 === $attempts && 'retry' === $client->get_string( $key ), 'A 503 response is retried', $attempts );

$attempts = 0;
$denied   = bms3o_t_fail_requests(
	static function ( $method ) use ( &$attempts ) {
		if ( 'PUT' === $method ) {
			++$attempts;
			return true;
		}
		return false;
	}
);
try {
	$client->put_string( $key, 'denied' );
	$thrown = false;
} catch ( Exception $e ) {
	$thrown = true;
}
remove_filter( 'pre_http_request', $denied, 10 );
bms3o_t( $thrown && 1 === $attempts, 'A 403 response is not retried', $attempts );
$client->delete( $key );

$large  = new Client(
	$config + array(
		'copy_threshold' => 5242880,
		'copy_part_size' => 5242880,
	)
);
$source = $offloader->key_prefix( false ) . 'bms3o-test/large-source.bin';
$target = $offloader->key_prefix( true ) . 'bms3o-test/large-target.bin';
$body   = str_repeat( wp_generate_password( 64, true, true ), 196608 ); // 12 MiB.
$large->put_string( $source, $body );
$large->copy( $source, $target, array( 'Content-Type' => 'application/octet-stream' ), strlen( $body ) );
$head = $large->head( $target );
bms3o_t( (int) $head['content-length'] === strlen( $body ) && md5( $large->get_string( $target ) ) === md5( $body ), 'Multipart copy of a large object (3 parts)' );
$large->delete_many( array( $source, $target ) );

bms3o_t_section( 'Uninstall keeps the mapping of files that exist only in the bucket' );

$id   = bms3o_t_import( bms3o_t_image( 200, 200, 'robust-uninstall.png' ) );
$file = get_post_meta( $id, '_wp_attached_file', true );
$offloader->remove_local( $media->paths( $id ), true );
bms3o_t( $offloader->items()->get( $file )['local_removed'], 'The local copy is removed' );

bms3o_t_settings( array( 'remove_data' => true ) );
define( 'WP_UNINSTALL_PLUGIN', 'bm-s3-offload/bm-s3-offload.php' );
require dirname( getenv( 'BMS3O_TEST_DIR' ), 2 ) . '/uninstall.php';
$table = Items::table();
bms3o_t( $table === $GLOBALS['wpdb']->get_var( "SHOW TABLES LIKE '{$table}'" ) && false !== get_option( 'bms3o_settings', false ), 'Table and settings are kept' );

bms3o_t_settings( array( 'remove_data' => false ) );
$offloader->download( $media->paths( $id ) );
wp_delete_attachment( $id, true );

bms3o_t_done();
