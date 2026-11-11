<?php
/**
 * S3 client against a real S3 server.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

use BahrMedia\S3Offload\S3\Client;
use BahrMedia\S3Offload\S3\S3Exception;

$settings = bms3o_t_plugin()->settings;
$client   = $settings->client();
$prefix   = 'bms3o-tests/' . wp_generate_password( 8, false ) . '/';

bms3o_t_section( 'S3 client' );

$client->head_bucket();
bms3o_t( true, 'HEAD bucket' );

$key = $prefix . 'ümlaut & space+plus.txt';
$client->put_string( $key, 'hello', array( 'Content-Type' => 'text/plain' ) );
$head = $client->head( $key );
bms3o_t( is_array( $head ) && '5' === (string) $head['content-length'], 'PUT + HEAD with special characters in the key' );
bms3o_t( 'hello' === $client->get_string( $key ), 'GET string' );
bms3o_t( null === $client->head( $prefix . 'missing.txt' ), 'HEAD of a missing object returns null' );

$client->copy( $key, $prefix . 'copy.txt' );
bms3o_t( 'hello' === $client->get_string( $prefix . 'copy.txt' ), 'Server-side copy' );

$client->copy( $key, $prefix . 'copy2.txt', array( 'Content-Type' => 'text/markdown' ) );
$head = $client->head( $prefix . 'copy2.txt' );
bms3o_t( isset( $head['content-type'] ) && 0 === strpos( $head['content-type'], 'text/markdown' ), 'Copy with replaced metadata' );

// Multipart: 20 MiB.
$big = wp_tempnam( 'bms3o-big' );
$fh  = fopen( $big, 'wb' );
for ( $i = 0; $i < 20; $i++ ) {
	fwrite( $fh, random_bytes( 1048576 ) );
}
fclose( $fh );
$client->put_file( $prefix . 'big.bin', $big );
$download = wp_tempnam( 'bms3o-big-dl' );
unlink( $download );
$client->get_to_file( $prefix . 'big.bin', $download );
bms3o_t( md5_file( $big ) === md5_file( $download ), 'Multipart upload and streamed download (20 MiB)' );
unlink( $big );
unlink( $download );

$signed   = $client->presign( $key, 60 );
$response = wp_remote_get( $signed );
bms3o_t( 200 === wp_remote_retrieve_response_code( $response ) && 'hello' === wp_remote_retrieve_body( $response ), 'Pre-signed GET' );

$signed   = $client->presign( $key, 60, array( 'response-content-disposition' => 'attachment; filename="a b.txt"' ) );
$response = wp_remote_get( $signed );
bms3o_t( false !== strpos( (string) wp_remote_retrieve_header( $response, 'content-disposition' ), 'attachment' ), 'Pre-signed GET with Content-Disposition' );

$expired = $client->presign( $key, 60, array(), time() - 3600 );
bms3o_t( 403 === wp_remote_retrieve_response_code( wp_remote_get( $expired ) ), 'Expired pre-signed URL is rejected' );

$list = $client->list_objects( $prefix, 2 );
bms3o_t( 2 === count( $list['objects'] ) && '' !== $list['next'], 'ListObjectsV2 with pagination', $list );
$list2 = $client->list_objects( $prefix, 1000, $list['next'] );
bms3o_t( count( $list2['objects'] ) >= 2, 'ListObjectsV2 continuation token' );

$keys   = array( $key, $prefix . 'copy.txt', $prefix . 'copy2.txt', $prefix . 'big.bin', $prefix . 'never-existed.txt' );
$errors = $client->delete_many( $keys );
bms3o_t( empty( $errors ) && empty( $client->list_objects( $prefix )['objects'] ), 'DeleteObjects (batch)', $errors );

$client->delete( $prefix . 'never-existed.txt' );
bms3o_t( true, 'Deleting a missing object is not an error' );

try {
	$bad = new Client(
		array(
			'endpoint'   => $settings->get( 'endpoint' ),
			'region'     => $settings->get( 'region' ),
			'bucket'     => $settings->get( 'bucket' ),
			'access_key' => $settings->get( 'access_key' ),
			'secret_key' => 'wrong',
			'path_style' => $settings->get( 'path_style' ),
		)
	);
	$bad->put_string( $prefix . 'x.txt', 'x' );
	bms3o_t( false, 'Wrong secret is rejected' );
} catch ( S3Exception $e ) {
	bms3o_t( 403 === $e->get_http_status() && 'SignatureDoesNotMatch' === $e->get_s3_code(), 'Wrong secret is rejected with SignatureDoesNotMatch', $e->getMessage() );
}

try {
	new Client( array( 'endpoint' => 'not a url' ) );
	bms3o_t( false, 'Invalid endpoint throws' );
} catch ( InvalidArgumentException $e ) {
	bms3o_t( true, 'Invalid endpoint throws InvalidArgumentException' );
}

$steps  = bms3o_t_plugin()->offloader->test_connection();
$failed = array_filter(
	$steps,
	static function ( $step ) {
		return ! $step['ok'];
	}
);
bms3o_t( empty( $failed ), 'Connection test passes', $failed );

bms3o_t_done();
