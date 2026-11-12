<?php
/**
 * Enable Media Replace (skipped when it is not active): replacing offloaded
 * images with and without local copies, with the same and with a new file
 * name. The new files are offloaded, objects of old files are deleted and
 * URLs in content are replaced.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

bms3o_t_section( 'Enable Media Replace' );

if ( ! class_exists( '\EnableMediaReplace\Controller\ReplaceController' ) ) {
	echo "  Enable Media Replace is not active – skipped.\n";
	bms3o_t_done();
	return;
}

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
delete_option( 'bms3o_retry' );

$cases = array(
	array( 'replace', false ),
	array( 'replace', true ),
	array( 'replace_and_search', false ),
	array( 'replace_and_search', true ),
);
foreach ( $cases as $case ) {
	$mode    = $case[0];
	$removed = $case[1];
	$label   = ( 'replace' === $mode ? 'Same name' : 'New name' ) . ( $removed ? ', without local copy' : ', with local copy' );
	$name    = 'emr-' . wp_generate_password( 6, false, false );

	$id        = bms3o_t_import( bms3o_t_image( 1200, 800, $name . '.png' ) );
	$old_url   = wp_get_attachment_url( $id );
	$old_paths = $media->paths( $id );
	$old_keys  = wp_list_pluck( $offloader->items()->get_many( $old_paths ), 'object_key' );
	$file      = get_post_meta( $id, '_wp_attached_file', true );
	$meta      = wp_get_attachment_metadata( $id );
	$base      = wp_get_upload_dir()['baseurl'];
	$post      = wp_insert_post(
		array(
			'post_status'  => 'publish',
			'post_title'   => $label,
			'post_content' => '<img src="' . $base . '/' . $file . '"><img src="' . $base . '/' . dirname( $file ) . '/' . $meta['sizes']['medium']['file'] . '">',
		)
	);
	if ( $removed ) {
		$offloader->remove_local( $old_paths, true );
	}

	// Replace with an image of another aspect ratio: most size names change.
	$controller = new \EnableMediaReplace\Controller\ReplaceController( $id );
	$ready      = $controller->setupParams(
		array(
			'replace_type'      => $mode,
			'timestamp_replace' => 1,
			'new_date'          => current_time( 'mysql' ),
			'new_filename'      => $name . '-new.png',
			'uploadFile'        => bms3o_t_image( 700, 1000, 'emr-upload.png' ),
			'new_location'      => false,
			'location_dir'      => null,
		)
	);
	bms3o_t( $ready && true === $controller->run(), "{$label}: EMR replaces the file" );

	$paths = $media->paths( $id );
	$offloader->items()->forget( array_merge( $paths, $old_paths ) );
	$items = $offloader->items()->get_by_source( 'attachment', $id );
	$file  = get_post_meta( $id, '_wp_attached_file', true );
	$head  = $settings->client()->head( $items[ $file ]['object_key'] );
	bms3o_t( empty( array_diff( $paths, array_keys( $items ) ) ) && (int) $head['content-length'] === (int) $items[ $file ]['filesize'], "{$label}: all new files are offloaded", array_diff( $paths, array_keys( $items ) ) );
	$stale = array_diff( array_keys( $items ), $paths );
	$left  = array_filter( array_diff( $old_keys, wp_list_pluck( $items, 'object_key' ) ), 'bms3o_t_object_exists' );
	bms3o_t( empty( $stale ) && empty( $left ), "{$label}: objects and items of old files are deleted", array( $stale, $left ) );
	bms3o_t( 0 !== strpos( wp_get_attachment_url( $id ), home_url() ) && 200 === bms3o_t_status( wp_get_attachment_url( $id ) ), "{$label}: the attachment is served from the bucket", wp_get_attachment_url( $id ) );
	bms3o_t( ! in_array( $id, $media->pending_retries(), true ), "{$label}: no upload error" );
	bms3o_t( wp_get_attachment_url( $id ) !== $old_url, "{$label}: the URL changes, so caches load the new file", array( $old_url, wp_get_attachment_url( $id ) ) );

	clean_post_cache( $post );
	$content = get_post_field( 'post_content', $post );
	if ( 'replace_and_search' === $mode ) {
		bms3o_t( false !== strpos( $content, $base . '/' . $file ) && false === strpos( $content, $old_paths[0] ), "{$label}: the content uses the new local URL", $content );
	}
	$rendered = apply_filters( 'the_content', $content );
	preg_match_all( '/src="([^"]+)"/', $rendered, $m );
	$broken = array_filter(
		$m[1],
		static function ( $url ) {
			return 200 !== bms3o_t_status( html_entity_decode( $url ) );
		}
	);
	bms3o_t( 2 === count( $m[1] ) && empty( $broken ), "{$label}: the images in the content work", $broken );

	wp_delete_post( $post, true );
	$media->restore_local( $id );
	wp_delete_attachment( $id, true );
}

bms3o_t_done();
