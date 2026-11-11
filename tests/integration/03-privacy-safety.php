<?php
/**
 * Private media, restricted directories and safety nets.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

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
wp_set_current_user( 0 );

bms3o_t_section( 'Make private / public' );

$id   = bms3o_t_import( bms3o_t_image( 640, 480, 'private-test.png' ) );
$file = get_post_meta( $id, '_wp_attached_file', true );

$errors = $media->set_privacy( $id, true );
$item   = $offloader->items()->get( $file );
bms3o_t( empty( $errors ) && $item['is_private'] && 0 === strpos( $item['object_key'], $plugin->settings->get( 'private_prefix' ) ), 'Objects moved below the private prefix', $errors );
bms3o_t( ! bms3o_t_object_exists( $offloader->object_key( $file, false ) ), 'Public object removed' );
bms3o_t( 403 === bms3o_t_status( $plugin->settings->client()->object_url( $item['object_key'] ) ), 'Private object is not publicly readable' );

$url = wp_get_attachment_url( $id );
bms3o_t( false !== strpos( $url, 'X-Amz-Signature' ) && 200 === bms3o_t_status( $url ), 'Visitors get an expiring signed URL', $url );
$html = apply_filters( 'the_content', '<img src="' . $base . '/' . $file . '">' );
bms3o_t( false !== strpos( $html, 'X-Amz-Signature' ), 'Content gets signed URLs for private media' );

$offloader->remove_local( $media->paths( $id ), true );
wp_update_attachment_metadata( $id, wp_get_attachment_metadata( $id ) );
bms3o_t( $offloader->items()->get( $file )['is_private'], 'Privacy survives later metadata updates' );

$errors = $media->set_privacy( $id, false );
$item   = $offloader->items()->get( $file );
bms3o_t( empty( $errors ) && ! $item['is_private'] && 200 === bms3o_t_status( wp_get_attachment_url( $id ) ), 'Made public again', $errors );

bms3o_t_section( 'Restricted directories' );

$dir = $offloader->uploads()['basedir'] . '/woocommerce_uploads/bms3o-test';
wp_mkdir_p( $dir );
copy( bms3o_t_image( 100, 100, 'product.png' ), $dir . '/product.png' );
$protected = wp_insert_attachment(
	array(
		'post_title'     => 'protected',
		'post_mime_type' => 'image/png',
		'post_status'    => 'inherit',
	),
	$dir . '/product.png'
);
wp_update_attachment_metadata( $protected, array() );
$pitem = $offloader->items()->get( 'woocommerce_uploads/bms3o-test/product.png' );
bms3o_t( $pitem && $pitem['is_private'], 'Shop downloads are stored privately' );
bms3o_t( 0 === strpos( wp_get_attachment_url( $protected ), $base ), 'Restricted files: no signed URL for visitors' );
$html = apply_filters( 'the_content', '<a href="' . $base . '/woocommerce_uploads/bms3o-test/product.png">x</a>' );
bms3o_t( false === strpos( $html, 'X-Amz' ), 'Restricted files: no signed URL in content' );
$errors = $media->set_privacy( $protected, false );
bms3o_t( ! empty( $errors ) && $offloader->items()->get( 'woocommerce_uploads/bms3o-test/product.png' )['is_private'], 'Restricted files cannot be made public' );
$signed = $offloader->with_private_access(
	static function () use ( $offloader, $pitem ) {
		return $offloader->url( $pitem );
	}
);
bms3o_t( 200 === bms3o_t_status( $signed ), 'Trusted code paths can sign restricted files' );

$editor  = bms3o_t_user( 'bms3o_editor', 'editor' );
$library = new BahrMedia\S3Offload\Admin\MediaLibrary( $plugin );
wp_set_current_user( $editor );
$actions = $library->row_actions( array(), get_post( $protected ) );
$storage = preg_grep( '/^bms3o_/', array_keys( $actions ) );
bms3o_t( empty( $storage ), 'Editors get no storage actions for protected files', $storage );
$actions = $library->row_actions( array(), get_post( $id ) );
bms3o_t( isset( $actions['bms3o_offload'] ), 'Editors keep storage actions for normal media' );
wp_set_current_user( 0 );

bms3o_t_section( 'Safety nets' );

$endpoint = $plugin->settings->get( 'endpoint' );
bms3o_t_settings( array( 'endpoint' => 'not a url' ) );
$fallback = wp_get_attachment_url( $id );
bms3o_t( 0 === strpos( $fallback, $base ), 'Invalid endpoint: local URLs instead of a fatal error', $fallback );
bms3o_t_settings( array( 'endpoint' => $endpoint ) );

$settings = $plugin->settings;
bms3o_t( ! empty( $settings->validate_prefixes( '', 'private/' ) ), 'Empty object prefix rejected' );
bms3o_t( ! empty( $settings->validate_prefixes( 'wp-content/uploads/', '' ) ), 'Empty private prefix rejected' );
bms3o_t( ! empty( $settings->validate_prefixes( 'media/', 'media/private/' ) ), 'Private prefix inside the public prefix rejected' );
bms3o_t( empty( $settings->validate_prefixes( 'wp-content/uploads/', 'private/' ) ), 'Default prefixes accepted' );

$enc = BahrMedia\S3Offload\Crypto::encrypt( 'sëcret\\with/backslash' );
bms3o_t( 0 === strpos( $enc, 'bms3o:v1:' ) && 'sëcret\\with/backslash' === BahrMedia\S3Offload\Crypto::decrypt( $enc ), 'Secrets are encrypted and decrypted losslessly' );

wp_delete_attachment( $id, true );
wp_delete_attachment( $protected, true );
bms3o_t( null === $offloader->items()->get( 'woocommerce_uploads/bms3o-test/product.png' ), 'Cleanup' );

bms3o_t_done();
