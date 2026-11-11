<?php
/**
 * Media library lifecycle.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/image-edit.php';

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$base      = $offloader->uploads()['baseurl'];

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'rewrite_urls'   => true,
		'sign_all'       => false,
	)
);

bms3o_t_section( 'Upload' );

$name  = 'bms3o-' . wp_generate_password( 6, false, false ) . '.jpg';
$id    = bms3o_t_import( bms3o_t_image( 3000, 2000, $name ) );
$paths = $media->paths( $id );
$items = $offloader->items()->get_by_source( 'attachment', $id );
$meta  = wp_get_attachment_metadata( $id );

bms3o_t( ! empty( $meta['original_image'] ), 'Big image was scaled by WordPress (original kept)' );
bms3o_t( count( $paths ) >= 5 && count( $items ) === count( $paths ), 'Original, scaled image and all sizes are offloaded', array( count( $paths ), count( $items ) ) );

$url = wp_get_attachment_url( $id );
bms3o_t( 0 !== strpos( $url, $base ), 'Attachment URL points to the bucket', $url );
bms3o_t( 200 === bms3o_t_status( $url ), 'Attachment URL is reachable' );

$thumb = wp_get_attachment_image_url( $id, 'thumbnail' );
bms3o_t( 0 !== strpos( $thumb, $base ) && 200 === bms3o_t_status( $thumb ), 'Thumbnail URL points to the bucket', $thumb );

$srcset = (string) wp_get_attachment_image_srcset( $id, 'large' );
bms3o_t( '' !== $srcset && false === strpos( $srcset, $base ), 'srcset candidates point to the bucket', $srcset );

$local = $base . '/' . get_post_meta( $id, '_wp_attached_file', true );
$html  = apply_filters( 'the_content', '<p><img src="' . $local . '?ver=3"> <a href="' . $base . '/does/not/exist.jpg">x</a></p>' );
bms3o_t( false === strpos( $html, $local ) && false === strpos( $html, '?ver=3' ), 'Content URLs are rewritten, query string dropped', $html );
bms3o_t( false !== strpos( $html, $base . '/does/not/exist.jpg' ), 'Unknown uploads URLs stay untouched' );

bms3o_t_section( 'No lock-in' );

$post_id = wp_insert_post(
	array(
		'post_title'   => 'bms3o test',
		'post_status'  => 'draft',
		'post_content' => '<img src="' . $url . '"><img src="' . $thumb . '">',
	)
);
$saved   = get_post_field( 'post_content', $post_id, 'raw' );
bms3o_t( false !== strpos( $saved, $local ) && false === strpos( $saved, $url ) && false === strpos( $saved, $thumb ), 'Bucket URLs are saved as local URLs', $saved );
wp_delete_post( $post_id, true );

bms3o_t_section( 'Remove local copies' );

$removed = $media->remove_local( $id );
$exists  = array_filter( array_map( array( $offloader, 'absolute' ), $paths ), 'file_exists' );
bms3o_t( count( $removed ) === count( $paths ) && empty( $exists ), 'All local files removed after verification', $exists );
bms3o_t( 200 === bms3o_t_status( wp_get_attachment_url( $id ) ), 'Files are still delivered from the bucket' );

bms3o_t_section( 'Image editor without local files' );

$edit_path = _load_image_to_edit_path( $id );
bms3o_t( is_string( $edit_path ) && file_exists( $edit_path ), 'Original is restored for the image editor', $edit_path );
$editor = wp_get_image_editor( $edit_path );
bms3o_t( ! is_wp_error( $editor ), 'Image editor can open the file' );
wp_set_current_user( 1 );
$_REQUEST['history'] = '[{"r":90}]';
$_REQUEST['target']  = 'all';
$_REQUEST['context'] = '';
$saved_image         = wp_save_image( $id );
bms3o_t( empty( $saved_image->error ), 'Rotated image saved', isset( $saved_image->error ) ? $saved_image->error : null );
$offloader->process_removal_queue();
$paths  = $media->paths( $id );
$exists = array_filter( array_map( array( $offloader, 'absolute' ), $paths ), 'file_exists' );
bms3o_t( empty( $exists ), 'After editing, the attachment is bucket-only again (incl. new files)', array_values( $exists ) );
bms3o_t( 200 === bms3o_t_status( wp_get_attachment_url( $id ) ), 'Edited image is delivered from the bucket' );

bms3o_t_section( 'Thumbnail regeneration without local files' );

$original = wp_get_original_image_path( $id );
bms3o_t( file_exists( $original ), 'Original restored for regeneration (WP-CLI context)' );
wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $original ) );
$offloader->process_removal_queue();
$exists = array_filter( array_map( array( $offloader, 'absolute' ), $media->paths( $id ) ), 'file_exists' );
bms3o_t( empty( $exists ), 'Regenerated sizes offloaded and removed locally', array_values( $exists ) );

bms3o_t_section( 'Unique file names with bucket-only files' );

$first = bms3o_t_import( bms3o_t_image( 400, 300, 'same-name.png' ) );
$media->remove_local( $first );
$second = bms3o_t_import( bms3o_t_image( 400, 300, 'same-name.png' ) );
$file1  = get_post_meta( $first, '_wp_attached_file', true );
$file2  = get_post_meta( $second, '_wp_attached_file', true );
bms3o_t( $file1 !== $file2, 'Second upload gets its own file name', array( $file1, $file2 ) );
bms3o_t( count( $offloader->items()->get_by_source( 'attachment', $second ) ) > 0, 'Second upload is offloaded' );

bms3o_t_section( 'Restore and leave the bucket' );

$restore = $media->restore_local( $first );
bms3o_t( empty( $restore['errors'] ) && file_exists( get_attached_file( $first ) ), 'Local copies restored' );
$errors = $media->unoffload( $first );
bms3o_t( empty( $errors ) && empty( $offloader->items()->get_by_source( 'attachment', $first ) ), 'Unoffload removes items', $errors );
bms3o_t( 0 === strpos( wp_get_attachment_url( $first ), $base ), 'URL is local again' );

bms3o_t_section( 'Delete' );

$keys = array();
foreach ( $offloader->items()->get_by_source( 'attachment', $id ) as $item ) {
	$keys[] = $item['object_key'];
}
wp_delete_attachment( $id, true );
wp_delete_attachment( $first, true );
wp_delete_attachment( $second, true );
$left = array_filter( $keys, 'bms3o_t_object_exists' );
bms3o_t( empty( $left ) && empty( $offloader->items()->get_by_source( 'attachment', $id ) ), 'Deleting the attachment deletes all objects and items', $left );

bms3o_t_done();
