<?php
/**
 * Files shared by several attachments, as created by the media translation
 * of WPML or Polylang: the second attachment does not upload the file again
 * and does not count as pending, and deleting one of them keeps the objects
 * as long as another attachment uses them.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$bulk      = $plugin->bulk;

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'delete_remote'  => true,
		'sign_all'       => false,
	)
);
wp_set_current_user( 0 );

/**
 * Checks a pair of attachments that share their file.
 *
 * @param int    $original    Attachment that uploaded the file.
 * @param int    $translation Attachment that uses the same file.
 * @param string $label       Label of the case.
 * @return void
 */
function bms3o_t_shared_pair( $original, $translation, $label ) {
	$plugin    = bms3o_t_plugin();
	$offloader = $plugin->offloader;
	$media     = $plugin->attachments;
	$file      = get_post_meta( $original, '_wp_attached_file', true );
	$paths     = $media->paths( $original );
	$keys      = wp_list_pluck( $offloader->items()->get_many( $paths ), 'object_key' );

	bms3o_t( get_post_meta( $translation, '_wp_attached_file', true ) === $file, "{$label}: both attachments use the same file" );
	bms3o_t( ! in_array( $translation, $media->pending_retries(), true ), "{$label}: the second attachment causes no upload error", $media->pending_retries() );
	bms3o_t( 0 !== strpos( wp_get_attachment_url( $translation ), home_url() ), "{$label}: the second attachment is served from the bucket", wp_get_attachment_url( $translation ) );

	$offloader->remove_local( $paths, true );
	bms3o_t( ! is_file( $offloader->absolute( $file ) ), "{$label}: the local copies are removed" );

	wp_delete_attachment( $original, true );
	$offloader->items()->forget( $paths );
	$items  = $offloader->items()->get_many( $paths );
	$exists = array_filter( array_map( 'bms3o_t_object_exists', $keys ) );
	bms3o_t( count( $exists ) === count( $keys ) && count( $items ) === count( $keys ), "{$label}: deleting the original keeps all objects and items", array( count( $keys ), count( $exists ), count( $items ) ) );
	bms3o_t( array( $translation ) === array_values( array_unique( array_map( 'intval', wp_list_pluck( $items, 'source_id' ) ) ) ), "{$label}: the items now belong to the remaining attachment" );
	bms3o_t( 0 !== strpos( wp_get_attachment_url( $translation ), home_url() ), "{$label}: the remaining attachment is still served from the bucket", wp_get_attachment_url( $translation ) );

	wp_delete_attachment( $translation, true );
	$exists = array_filter( array_map( 'bms3o_t_object_exists', $keys ) );
	$offloader->items()->forget( $paths );
	bms3o_t( empty( $exists ) && empty( $offloader->items()->get_many( $paths ) ), "{$label}: deleting the last attachment deletes the objects" );
}

bms3o_t_section( 'Two attachments with the same file' );

$original = bms3o_t_import( bms3o_t_image( 300, 200, 'shared-file.png' ) );
$before   = $bulk->remaining( 'offload' );
$copy     = wp_insert_attachment(
	array(
		'post_title'     => 'shared-file (copy)',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	get_post_meta( $original, '_wp_attached_file', true )
);
wp_update_attachment_metadata( $copy, wp_get_attachment_metadata( $original ) );
bms3o_t( $before === $bulk->remaining( 'offload' ), 'The second attachment does not count as pending', array( $before, $bulk->remaining( 'offload' ) ) );
bms3o_t_shared_pair( $original, $copy, 'Copy' );

// Deleting the attachment that did not upload the file changes nothing.
$original = bms3o_t_import( bms3o_t_image( 300, 200, 'shared-file-2.png' ) );
$file     = get_post_meta( $original, '_wp_attached_file', true );
$copy     = wp_insert_attachment(
	array(
		'post_title'     => 'shared-file-2 (copy)',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	$file
);
wp_update_attachment_metadata( $copy, wp_get_attachment_metadata( $original ) );
$key = $offloader->items()->get( $file )['object_key'];
wp_delete_attachment( $copy, true );
$offloader->items()->forget( array( $file ) );
$item = $offloader->items()->get( $file );
bms3o_t( $item && (int) $item['source_id'] === $original && bms3o_t_object_exists( $key ), 'Deleting the other attachment keeps object and item of the original' );
wp_delete_attachment( $original, true );

bms3o_t_section( 'Polylang media translations' );

if ( ! function_exists( 'PLL' ) || ! PLL()->model->get_language( 'de' ) || empty( PLL()->options['media_support'] ) ) {
	echo "  Polylang with media translation is not set up – skipped.\n";
	bms3o_t_done();
	return;
}

$original = bms3o_t_import( bms3o_t_image( 300, 200, 'polylang-media.png' ) );
pll_set_post_language( $original, 'en' );
$translation = PLL()->model->post->create_media_translation( $original, 'de' );
bms3o_t( $translation > 0 && pll_get_post( $original, 'de' ) === $translation, 'Polylang creates the German media translation', $translation );
bms3o_t_shared_pair( $original, $translation, 'Polylang' );

bms3o_t_done();
