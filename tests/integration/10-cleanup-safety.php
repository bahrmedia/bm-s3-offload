<?php
/**
 * Safety of the cleanup queue under slow storage and failures: locks outlast
 * delete requests, a lost lock does not lose a reused object, failed writes
 * keep queued deletions, database errors while queuing are reported and the
 * migration of the legacy queue can be repeated.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.DB, WordPress.WP.AlternativeFunctions

use BahrMedia\S3Offload\S3\Client;
use BahrMedia\S3Offload\Store\Items;
use BahrMedia\S3Offload\Store\Orphans;

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

global $wpdb;

$plugin    = bms3o_t_plugin();
$settings  = $plugin->settings;
$offloader = $plugin->offloader;
$client    = $settings->client();
$bucket    = $client->get_bucket();

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

/**
 * Lets S3 requests fail.
 *
 * @param callable $matches Receives method and URL, returns true for requests that fail.
 * @return callable The filter (pass it to remove_filter()).
 */
function bms3o_t_fail( callable $matches ) {
	$filter = static function ( $pre, $args, $url ) use ( $matches ) {
		if ( false !== $pre || ! $matches( $args['method'], $url, $args ) ) {
			return $pre;
		}
		return array(
			'headers'  => array(),
			'body'     => '<?xml version="1.0" encoding="UTF-8"?><Error><Code>Simulated</Code><Message>Simulated failure</Message></Error>',
			'response' => array(
				'code'    => 403,
				'message' => 'Simulated',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	};
	add_filter( 'pre_http_request', $filter, 10, 3 );
	return $filter;
}

/**
 * Keys in the queue.
 *
 * @return string[]
 */
function bms3o_t_queued_keys() {
	return array_values( wp_list_pluck( bms3o_t_plugin()->offloader->orphans(), 'key' ) );
}

/**
 * Lets queued entries be due now.
 *
 * @return void
 */
function bms3o_t_due_now() {
	$GLOBALS['wpdb']->query( 'UPDATE ' . Orphans::table() . ' SET next_attempt = 0' );
}

/**
 * Imports an image and makes its main file private while the deletion of the
 * public object fails, so the public object waits in the cleanup queue.
 *
 * @param string $name File name.
 * @return array{0:int,1:string,2:string} Attachment ID, path and public key.
 */
function bms3o_t_leftover( $name ) {
	$offloader = bms3o_t_plugin()->offloader;
	$id        = bms3o_t_import( bms3o_t_image( 160, 120, $name ) );
	$file      = get_post_meta( $id, '_wp_attached_file', true );
	$public    = $offloader->items()->get( $file )['object_key'];
	$filter    = bms3o_t_fail(
		static function ( $method ) {
			return 'DELETE' === $method || 'POST' === $method;
		}
	);
	$offloader->set_privacy( array( $file ), true );
	remove_filter( 'pre_http_request', $filter, 10 );
	return array( $id, $file, $public );
}

bms3o_t_section( 'Object locks of the cleanup outlast its delete requests' );

list( $id, $file, $public ) = bms3o_t_leftover( 'safety-deadline.png' );
bms3o_t( in_array( $public, bms3o_t_queued_keys(), true ), 'The public copy waits in the queue' );

$seen  = array();
$probe = static function ( $pre, $args, $url ) use ( &$seen, $bucket, $public ) {
	if ( false === $pre && 'POST' === $args['method'] && false !== strpos( $url, '?delete' ) ) {
		$lock   = 'bms3o_object_lock_' . Orphans::hash( $bucket, $public );
		$value  = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT option_value FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s", $lock ) );
		$seen[] = array(
			'request_ends' => time() + (float) $args['timeout'],
			'lock_expires' => (int) strtok( $value, '|' ),
		);
	}
	return $pre;
};
add_filter( 'bms3o_http_args', $long = static function ( $args ) { // phpcs:ignore Squiz.PHP.DisallowMultipleAssignments.Found
	$args['timeout'] = 900; // A filter that raises the timeout must not break the bound.
	return $args;
} );
add_filter( 'pre_http_request', $probe, 10, 3 );
$offloader->process_orphans();
remove_filter( 'pre_http_request', $probe, 10 );
remove_filter( 'bms3o_http_args', $long );
bms3o_t( 1 === count( $seen ) && $seen[0]['lock_expires'] - $seen[0]['request_ends'] >= 30, 'The delete request ends at least 30 seconds before the lock expires', $seen );
bms3o_t( ! bms3o_t_object_exists( $public ) && ! in_array( $public, bms3o_t_queued_keys(), true ), 'The public copy is deleted' );

if ( method_exists( Client::class, 'with_deadline' ) ) {
	$requests = 0;
	$count    = static function ( $pre ) use ( &$requests ) {
		++$requests;
		return $pre;
	};
	add_filter( 'pre_http_request', $count );
	try {
		$client->with_deadline( microtime( true ) - 1 )->head( $public );
		$thrown = false;
	} catch ( Exception $e ) {
		$thrown = true;
	}
	remove_filter( 'pre_http_request', $count );
	bms3o_t( $thrown && 0 === $requests, 'No request starts after the deadline' );
}

bms3o_t_section( 'A cleanup that lost its lock does not lose a reused object' );

$reuse = static function ( $local ) use ( $offloader, $bucket ) {
	list( $id, $file, $public ) = bms3o_t_leftover( $local ? 'safety-reuse.png' : 'safety-reuse-remote.png' );
	if ( ! $local ) {
		$offloader->remove_local( array( $file ), true );
	}
	bms3o_t_due_now();
	$takeover = static function ( $pre, $args, $url ) use ( $offloader, $file ) {
		static $done = false;
		if ( false === $pre && ! $done && 'POST' === $args['method'] && false !== strpos( $url, '?delete' ) ) {
			$done = true;
			// The delete request is slow: the lock expires and another request takes the
			// key over and makes the file public again before the request arrives.
			$GLOBALS['wpdb']->query( "DELETE FROM {$GLOBALS['wpdb']->options} WHERE option_name LIKE 'bms3o\\_object\\_lock\\_%'" );
			$errors = $offloader->set_privacy( array( $file ), false );
			bms3o_t( empty( $errors ), 'The file is made public again meanwhile', $errors );
		}
		return $pre;
	};
	add_filter( 'pre_http_request', $takeover, 10, 3 );
	$offloader->process_orphans();
	remove_filter( 'pre_http_request', $takeover, 10 );
	$offloader->items()->forget( array( $file ) );
	$item = $offloader->items()->get( $file );
	return array( $id, $file, $public, $item );
};

list( $id, $file, $public, $item ) = $reuse( true );
bms3o_t( $item && ! $item['is_private'] && $item['object_key'] === $public, 'The item points to the public key' );
bms3o_t( bms3o_t_object_exists( $public ), 'The reused object exists (restored from the local copy)' );
wp_delete_attachment( $id, true );

if ( method_exists( $offloader, 'cleanup_conflicts' ) ) {
	list( $id, $file, $public, $item ) = $reuse( false );
	bms3o_t( in_array( $file, $offloader->cleanup_conflicts(), true ), 'Without a local copy the file is reported', $offloader->cleanup_conflicts() );
	$offloader->clear_cleanup_conflicts();
	$offloader->download( array( $file ) ); // Fails (object gone); the attachment is removed below.
	wp_delete_attachment( $id, true );
}

bms3o_t_section( 'A failed write keeps the queued deletion' );

list( $id, $file, $public ) = bms3o_t_leftover( 'safety-copy.png' );
bms3o_t_due_now();
$filter = bms3o_t_fail(
	static function ( $method, $url ) use ( $public ) {
		return 'PUT' === $method && false !== strpos( rawurldecode( $url ), $public );
	}
);
$errors = $offloader->set_privacy( array( $file ), false );
remove_filter( 'pre_http_request', $filter, 10 );
$item = $offloader->items()->get( $file );
bms3o_t( ! empty( $errors ) && $item['is_private'], 'The failed copy is reported, the file stays private', $errors );
bms3o_t( bms3o_t_object_exists( $public ) && in_array( $public, bms3o_t_queued_keys(), true ), 'The public leftover is still queued', bms3o_t_queued_keys() );
$offloader->process_orphans();
bms3o_t( ! bms3o_t_object_exists( $public ) && ! in_array( $public, bms3o_t_queued_keys(), true ), 'The cleanup deletes it afterwards' );

// Same for an upload to a queued key (the file is private, the upload makes it public).
$offloader->queue_orphans( array( array( $bucket, $public, $file ) ) );
$filter = bms3o_t_fail(
	static function ( $method, $url ) use ( $public ) {
		return 'PUT' === $method && false !== strpos( rawurldecode( $url ), $public );
	}
);
$upload = $offloader->upload( Items::SOURCE_POST, $id, array( $file ), false, true );
remove_filter( 'pre_http_request', $filter, 10 );
bms3o_t( ! empty( $upload['errors'] ) && in_array( $public, bms3o_t_queued_keys(), true ), 'A failed upload to a queued key keeps the entry', $upload['errors'] );
$offloader->orphan_store()->clear();
wp_delete_attachment( $id, true );

if ( method_exists( Orphans::class, 'is_claimed' ) ) {
	bms3o_t_section( 'Claims of operations that write to a queued key' );

	$key = $offloader->key_prefix( false ) . 'bms3o-test/safety-claim.txt';
	$client->put_string( $key, 'claimed' );
	$offloader->queue_orphans( array( array( $bucket, $key, 'bms3o-test/safety-claim.txt' ) ) );
	$offloader->orphan_store()->claim( $bucket, $key );
	$offloader->process_orphans();
	bms3o_t( bms3o_t_object_exists( $key ) && in_array( $key, bms3o_t_queued_keys(), true ), 'The cleanup leaves a claimed object alone' );

	// The claiming process died: the claim expires.
	$wpdb->query( $wpdb->prepare( 'UPDATE ' . Orphans::table() . ' SET claimed_until = %d WHERE object_hash = %s', time() - 1, Orphans::hash( $bucket, $key ) ) );
	$offloader->process_orphans();
	bms3o_t( ! bms3o_t_object_exists( $key ) && ! in_array( $key, bms3o_t_queued_keys(), true ), 'After the claim expired, the cleanup checks and deletes it' );
}

bms3o_t_section( 'Database errors while queuing' );

$break = static function ( $query ) {
	return 0 === strpos( ltrim( $query ), 'INSERT INTO ' . Orphans::table() ) ? 'INSERT INTO bms3o_simulated_failure VALUES (1)' : $query;
};
$wpdb->suppress_errors( true );
add_filter( 'query', $break );
$failed = $offloader->orphan_store()->add( array( array( $bucket, 'bms3o-test/db-error.txt', 'bms3o-test/db-error.txt' ) ), '' );
$queued = $offloader->queue_orphans( array( array( $bucket, 'bms3o-test/db-error.txt', 'bms3o-test/db-error.txt' ) ) );
remove_filter( 'query', $break );
$wpdb->suppress_errors( false );
bms3o_t( is_array( $failed ) && 1 === count( $failed ), 'add() reports the object that was not written', $failed );
bms3o_t( false === $queued, 'queue_orphans() reports the failure', $queued );

// A leftover that can neither be deleted nor queued is reported as such.
$id     = bms3o_t_import( bms3o_t_image( 100, 80, 'safety-db.png' ) );
$file   = get_post_meta( $id, '_wp_attached_file', true );
$public = $offloader->items()->get( $file )['object_key'];
$filter = bms3o_t_fail(
	static function ( $method ) {
		return 'DELETE' === $method;
	}
);
$wpdb->suppress_errors( true );
add_filter( 'query', $break );
$errors = $offloader->set_privacy( array( $file ), true );
remove_filter( 'query', $break );
$wpdb->suppress_errors( false );
remove_filter( 'pre_http_request', $filter, 10 );
$message = isset( $errors[ $file ] ) ? $errors[ $file ] : '';
bms3o_t( false !== strpos( $message, $public ), 'The message names the object that must be deleted manually', $errors );
$client->delete( $public );
wp_delete_attachment( $id, true );

bms3o_t_section( 'The migration of the legacy queue can be repeated' );

$legacy = array(
	array(
		'bucket'  => $bucket,
		'key'     => 'bms3o-test/legacy-1.txt',
		'path'    => 'bms3o-test/legacy-1.txt',
		'storage' => '',
	),
	array(
		'bucket'  => $bucket,
		'key'     => 'bms3o-test/legacy-2.txt',
		'path'    => 'bms3o-test/legacy-2.txt',
		'storage' => '',
	),
);
update_option( Orphans::LEGACY_OPTION, $legacy, false );
update_option( 'bms3o_db_version', '1' );
$wpdb->suppress_errors( true );
add_filter( 'query', $break );
Items::install();
remove_filter( 'query', $break );
$wpdb->suppress_errors( false );
bms3o_t( is_array( get_option( Orphans::LEGACY_OPTION, null ) ) && 2 === count( get_option( Orphans::LEGACY_OPTION ) ), 'A failed migration keeps the legacy queue' );
bms3o_t( Items::DB_VERSION !== get_option( 'bms3o_db_version' ), 'The schema version stays, so the migration runs again' );
Items::install();
bms3o_t( null === get_option( Orphans::LEGACY_OPTION, null ) && Items::DB_VERSION === get_option( 'bms3o_db_version' ), 'The next attempt completes it' );
$keys = bms3o_t_queued_keys();
bms3o_t( in_array( 'bms3o-test/legacy-1.txt', $keys, true ) && in_array( 'bms3o-test/legacy-2.txt', $keys, true ), 'Both entries are in the queue', $keys );
$offloader->orphan_store()->clear();

bms3o_t_done();
