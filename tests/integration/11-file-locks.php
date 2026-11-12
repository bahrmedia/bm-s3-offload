<?php
/**
 * Concurrent changes of the same file: every operation that changes a file's
 * item or objects holds the file lock, so interleaved operations can never
 * delete each other's objects. A second Offloader instance plays the part of
 * another request (file locks are re-entrant only within one instance).
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions

use BahrMedia\S3Offload\Offloader;
use BahrMedia\S3Offload\Store\Items;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

global $wpdb;

$plugin    = bms3o_t_plugin();
$settings  = $plugin->settings;
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$other     = new Offloader( $settings, new Items() ); // Another request.

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'delete_remote'  => true,
		'sign_all'       => false,
	)
);
$offloader->orphan_store()->clear();
wp_set_current_user( 0 );

$short_wait = static function () {
	return 0.5;
};
add_filter( 'bms3o_file_lock_wait', $short_wait );

/**
 * Runs a callback once, right before the first request that matches.
 *
 * @param callable $matches  Receives method and URL.
 * @param callable $callback Runs before the request is sent.
 * @return callable The filter (pass it to remove_filter()).
 */
function bms3o_t_before( callable $matches, callable $callback ) {
	$done   = false;
	$filter = static function ( $pre, $args, $url ) use ( $matches, $callback, &$done ) {
		if ( false === $pre && ! $done && $matches( $args['method'], rawurldecode( $url ), $args ) ) {
			$done = true;
			$callback();
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	return $filter;
}

/**
 * Whether the object the item of a path points to exists.
 *
 * @param string $path Relative path.
 * @return bool
 */
function bms3o_t_item_object_exists( $path ) {
	$items = bms3o_t_plugin()->offloader->items();
	$items->forget( array( $path ) );
	$item = $items->get( $path );
	return $item && bms3o_t_object_exists( $item['object_key'] );
}

bms3o_t_section( 'Opposite privacy changes of the same file' );

$id     = bms3o_t_import( bms3o_t_image( 200, 150, 'locks-privacy.png' ) );
$file   = get_post_meta( $id, '_wp_attached_file', true );
$public = $offloader->items()->get( $file )['object_key'];
$offloader->remove_local( array( $file ), true );

// A makes the file private; before A deletes the public copy, B makes it public again.
$second = null;
$filter = bms3o_t_before(
	static function ( $method, $url ) use ( $public ) {
		return 'DELETE' === $method && false !== strpos( $url, $public );
	},
	static function () use ( $other, $file, &$second ) {
		$second = $other->set_privacy( array( $file ), false );
	}
);
$first  = $offloader->set_privacy( array( $file ), true );
remove_filter( 'pre_http_request', $filter, 10 );

bms3o_t( bms3o_t_item_object_exists( $file ), 'The object the file points to exists', array( $first, $second ) );
bms3o_t( empty( $first ) && ! empty( $second ), 'The first change succeeds, the concurrent one waits and is reported', array( $first, $second ) );
$errors = $other->set_privacy( array( $file ), false );
bms3o_t( empty( $errors ) && bms3o_t_item_object_exists( $file ) && $offloader->items()->get( $file )['object_key'] === $public, 'Repeated afterwards, the second change succeeds', $errors );
$offloader->download( array( $file ) );
wp_delete_attachment( $id, true );

bms3o_t_section( 'Upload with a privacy change against a privacy change' );

$id     = bms3o_t_import( bms3o_t_image( 200, 150, 'locks-upload.png' ) );
$file   = get_post_meta( $id, '_wp_attached_file', true );
$public = $offloader->items()->get( $file )['object_key'];

// A uploads the file as private; before A deletes the public copy, B makes it public again.
$second = null;
$filter = bms3o_t_before(
	static function ( $method, $url ) use ( $public ) {
		return 'DELETE' === $method && false !== strpos( $url, $public );
	},
	static function () use ( $other, $file, &$second ) {
		$second = $other->set_privacy( array( $file ), false );
	}
);
$upload = $offloader->upload( Items::SOURCE_POST, $id, array( $file ), true, true );
remove_filter( 'pre_http_request', $filter, 10 );
$offloader->remove_local( array( $file ), true );
bms3o_t( bms3o_t_item_object_exists( $file ), 'The object the file points to exists', array( $upload, $second ) );
bms3o_t( array( $file ) === $upload['uploaded'] && ! empty( $second ), 'The upload succeeds, the concurrent change is reported', array( $upload, $second ) );
$offloader->download( array( $file ) );
wp_delete_attachment( $id, true );

bms3o_t_section( 'Leaving the bucket against removing local copies' );

$id    = bms3o_t_import( bms3o_t_image( 200, 150, 'locks-leave.png' ) );
$file  = get_post_meta( $id, '_wp_attached_file', true );
$paths = $media->paths( $id );
$offloader->remove_local( $paths, true );
$local = $offloader->absolute( $file );
bms3o_t( ! is_file( $local ), 'The local copy is removed' );

// A leaves the bucket (restore, then delete); before A deletes the objects, B removes the local copies.
$removed = null;
$filter  = bms3o_t_before(
	static function ( $method, $url ) {
		return 'POST' === $method && false !== strpos( $url, '?delete' );
	},
	static function () use ( $other, $paths, &$removed ) {
		$removed = $other->remove_local( $paths, true );
	}
);
$errors  = $media->unoffload( $id );
remove_filter( 'pre_http_request', $filter, 10 );
bms3o_t( is_file( $local ) || bms3o_t_item_object_exists( $file ), 'The file still exists locally or in the bucket', array( $errors, $removed ) );
bms3o_t( empty( $errors ) && array() === $removed && is_file( $local ), 'The concurrent removal waits and keeps the local copy', array( $errors, $removed ) );

if ( method_exists( Offloader::class, 'delete_remote' ) && ( new ReflectionMethod( Offloader::class, 'delete_remote' ) )->getNumberOfParameters() > 1 ) {
	$offloader->upload( Items::SOURCE_POST, $id, $paths, false, true );
	wp_delete_file( $local );
	$errors = $offloader->delete_remote( $paths, true );
	bms3o_t( isset( $errors[ $file ] ) && bms3o_t_item_object_exists( $file ), 'With a missing local copy, "Leave the bucket" keeps the object', $errors );
	$offloader->download( $paths );
}
wp_delete_attachment( $id, true );

bms3o_t_section( 'The file lock is extended before every request and cannot run out' );

$dir  = $offloader->uploads()['basedir'] . '/bms3o-test';
$path = 'bms3o-test/locks-large.bin';
wp_mkdir_p( $dir );
$fp = fopen( $dir . '/locks-large.bin', 'wb' );
for ( $i = 0; $i < 18; $i++ ) {
	fwrite( $fp, random_bytes( 1048576 ) ); // 18 MiB: multipart upload with 3 parts.
}
fclose( $fp );
$lock_name = 'bms3o_path_lock_' . md5( $path );

$seen  = array();
$probe = static function ( $pre, $args, $url ) use ( &$seen, $lock_name ) {
	if ( false === $pre && 'PUT' === $args['method'] && false !== strpos( $url, 'partNumber=' ) ) {
		$value  = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $lock_name ) );
		$seen[] = (int) strtok( $value, '|' ) - ( time() + (float) $args['timeout'] );
	}
	return $pre;
};
add_filter( 'pre_http_request', $probe, 10, 3 );
$upload = $offloader->upload( 'bms3o_test', 1, array( $path ), false, true );
remove_filter( 'pre_http_request', $probe, 10 );
bms3o_t( array( $path ) === $upload['uploaded'] && 3 === count( $seen ) && min( $seen ) >= 30, 'Every part ends at least 30 seconds before the file lock expires', array( $upload['errors'], $seen ) );

// The lock is lost during the upload (e.g. taken over): no further request is sent.
$parts  = 0;
$lose   = static function ( $pre, $args, $url ) use ( &$parts, $lock_name ) {
	if ( false === $pre && 'PUT' === $args['method'] && false !== strpos( $url, 'partNumber=' ) && 1 === ++$parts ) {
		$GLOBALS['wpdb']->query( $GLOBALS['wpdb']->prepare( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $lock_name ) );
	}
	return $pre;
};
$before = $offloader->items()->get( $path );
add_filter( 'pre_http_request', $lose, 10, 3 );
$upload = $offloader->upload( 'bms3o_test', 1, array( $path ), true, true );
remove_filter( 'pre_http_request', $lose, 10 );
$offloader->items()->forget( array( $path ) );
$after = $offloader->items()->get( $path );
bms3o_t( 1 === $parts && isset( $upload['errors'][ $path ] ), 'After the lock was lost, the upload stops before the next part', array( $parts, $upload['errors'] ) );
bms3o_t( $after['object_key'] === $before['object_key'] && bms3o_t_item_object_exists( $path ), 'The item still points to the previous, existing object' );

$offloader->delete_remote( array( $path ) );
wp_delete_file( $dir . '/locks-large.bin' );

bms3o_t_section( 'Locks are released' );

$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'bms3o\\_path\\_lock\\_%'" );
bms3o_t( 0 === $left, 'No file lock is left behind', $left );

remove_filter( 'bms3o_file_lock_wait', $short_wait );
bms3o_t_done();
