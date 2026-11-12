<?php
/**
 * Image optimisers: files changed after the upload are uploaded again when
 * the optimiser saves the metadata (as EWWW does after optimising in the
 * background or in bulk); with EWWW active, an upload is checked for the
 * optimised files and WebP copies in the bucket.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

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
	)
);

/**
 * Creates a JPEG photo-like test image.
 *
 * @param string $name File name.
 * @return string Temporary file.
 */
function bms3o_t_photo( $name ) {
	$image = imagecreatetruecolor( 1200, 800 );
	for ( $i = 0; $i < 3000; $i++ ) {
		imagefilledellipse( $image, wp_rand( 0, 1200 ), wp_rand( 0, 800 ), wp_rand( 5, 80 ), wp_rand( 5, 80 ), imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	}
	$file = trailingslashit( get_temp_dir() ) . $name;
	imagejpeg( $image, $file, 95 );
	return $file;
}

/**
 * Paths whose object differs in size from the local file.
 *
 * @param int $id Attachment ID.
 * @return string[]
 */
function bms3o_t_out_of_sync( $id ) {
	$plugin = bms3o_t_plugin();
	$paths  = $plugin->attachments->paths( $id );
	$plugin->offloader->items()->forget( $paths );
	$differ = array();
	foreach ( $paths as $path ) {
		$file = $plugin->offloader->absolute( $path );
		$item = $plugin->offloader->items()->get( $path );
		clearstatcache( true, $file );
		$head = $item ? $plugin->settings->client()->head( $item['object_key'] ) : null;
		if ( ! is_file( $file ) || ! $head || (int) $head['content-length'] !== (int) filesize( $file ) ) {
			$differ[] = $path;
		}
	}
	return $differ;
}

bms3o_t_section( 'Files changed by an optimiser after the upload' );

$id   = bms3o_t_import( bms3o_t_photo( 'optimiser-later.jpg' ) );
$file = get_post_meta( $id, '_wp_attached_file', true );
bms3o_t( empty( bms3o_t_out_of_sync( $id ) ), 'After the upload, all objects match the local files' );

// The optimiser recompresses the files and saves the metadata (EWWW in the background or in bulk).
foreach ( $media->paths( $id ) as $path ) {
	$local = $offloader->absolute( $path );
	if ( preg_match( '/\.jpe?g$/i', $path ) && is_file( $local ) ) {
		$image = imagecreatefromjpeg( $local );
		imagejpeg( $image, $local, 40 );
	}
}
wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );
bms3o_t( empty( bms3o_t_out_of_sync( $id ) ), 'Saving the metadata uploads the optimised files again', bms3o_t_out_of_sync( $id ) );
wp_delete_attachment( $id, true );

bms3o_t_section( 'EWWW Image Optimizer' );

if ( ! defined( 'EWWW_IMAGE_OPTIMIZER_VERSION' ) ) {
	echo "  EWWW Image Optimizer is not active – skipped.\n";
	bms3o_t_done();
	return;
}

update_option( 'ewww_image_optimizer_jpg_level', 10 );
update_option( 'ewww_image_optimizer_webp', true );
update_option( 'ewww_image_optimizer_background_optimization', false );

$id    = bms3o_t_import( bms3o_t_photo( 'ewww-upload.jpg' ) );
$paths = $media->paths( $id );
$webp  = preg_grep( '/\.webp$/', $paths );
bms3o_t( empty( bms3o_t_out_of_sync( $id ) ), 'All files, optimised by EWWW, are in the bucket with their local size', bms3o_t_out_of_sync( $id ) );
bms3o_t( ! empty( $webp ) && count( $offloader->items()->get_many( $webp ) ) === count( $webp ), 'EWWW\'s WebP copies are offloaded', $webp );
$offloader->remove_local( $paths, true );
$url = wp_get_attachment_url( $id );
bms3o_t( 0 !== strpos( $url, home_url() ) && 200 === bms3o_t_status( $url ), 'Without local copies the image is served from the bucket', $url );
$media->restore_local( $id );
wp_delete_attachment( $id, true );
delete_option( 'ewww_image_optimizer_jpg_level' );
delete_option( 'ewww_image_optimizer_webp' );
delete_option( 'ewww_image_optimizer_background_optimization' );

bms3o_t_done();
