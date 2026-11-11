<?php
/**
 * WooCommerce downloadable products (skipped when WooCommerce is not active).
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

bms3o_t_section( 'WooCommerce' );

if ( ! class_exists( 'WooCommerce' ) ) {
	echo "  WooCommerce is not active – skipped.\n";
	bms3o_t_done();
	return;
}

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;

bms3o_t_settings( array( 'wc_enabled' => true ) );
update_option( 'woocommerce_file_download_method', 'force' );
update_option( 'woocommerce_downloads_require_login', 'no' );

// Product file in woocommerce_uploads, as uploaded in the product editor.
$dir = $offloader->uploads()['basedir'] . '/woocommerce_uploads/' . gmdate( 'Y/m' );
wp_mkdir_p( $dir );
$file = $dir . '/ebook-' . wp_generate_password( 6, false, false ) . '.zip';
file_put_contents( $file, random_bytes( 200000 ) );
$md5           = md5_file( $file );
$attachment_id = wp_insert_attachment(
	array(
		'post_title'     => 'ebook',
		'post_mime_type' => 'application/zip',
		'post_status'    => 'inherit',
	),
	$file
);
wp_update_attachment_metadata( $attachment_id, array() );
$item = $offloader->items()->get( $offloader->relative( $file ) );
bms3o_t( $item && $item['is_private'], 'Product file is a private object' );

$plugin->attachments->remove_local( $attachment_id );
bms3o_t( ! file_exists( $file ), 'Local copy removed' );

$download = new WC_Product_Download();
$download->set_id( wp_generate_uuid4() );
$download->set_name( 'E-Book' );
$download->set_file( $offloader->uploads()['baseurl'] . '/' . $item['path'] );

$product = new WC_Product_Simple();
$product->set_name( 'bms3o e-book' );
$product->set_regular_price( '1' );
$product->set_virtual( true );
$product->set_downloadable( true );
$product->set_downloads( array( $download ) );
$product->save();

$order = wc_create_order();
$order->add_product( $product, 1 );
$order->set_billing_email( 'buyer@example.test' );
$order->calculate_totals();
$order->set_status( 'completed' );
$order->save();
wc_downloadable_product_permissions( $order->get_id(), true );

$items = $order->get_downloadable_items();
bms3o_t( ! empty( $items ), 'Order grants a download' );
$url = $items[0]['download_url'];

$response = bms3o_t_get( $url );
bms3o_t( 302 === $response['code'] && false !== strpos( $response['location'], 'X-Amz-Signature' ), 'Download redirects to a signed bucket URL (no streaming through PHP)', $response['code'] . ' ' . $response['location'] );

$bucket = wp_remote_get( $response['location'], array( 'timeout' => 30 ) );
bms3o_t( 200 === wp_remote_retrieve_response_code( $bucket ) && md5( wp_remote_retrieve_body( $bucket ) ) === $md5, 'Customer receives the correct file' );
bms3o_t( false !== strpos( (string) wp_remote_retrieve_header( $bucket, 'content-disposition' ), 'attachment' ), 'Served as attachment' );

$forged = bms3o_t_get( add_query_arg( 'key', 'wc_order_forged', $url ) );
bms3o_t( 302 !== $forged['code'] || false === strpos( $forged['location'], 'X-Amz' ), 'Invalid order key gets no bucket URL' );

// A product file pointing to another plugin's restricted media must not be signed.
$bb_dir = $offloader->uploads()['basedir'] . '/bb_medias/bms3o-wc';
wp_mkdir_p( $bb_dir );
file_put_contents( $bb_dir . '/member.jpg', random_bytes( 1000 ) );
$member_id = wp_insert_attachment(
	array(
		'post_title'     => 'member photo',
		'post_mime_type' => 'image/jpeg',
		'post_status'    => 'inherit',
	),
	$bb_dir . '/member.jpg'
);
wp_update_attachment_metadata( $member_id, array() );
$wc_integration = new BahrMedia\S3Offload\Integrations\WooCommerce( $offloader );
$member_url     = $offloader->uploads()['baseurl'] . '/bb_medias/bms3o-wc/member.jpg';
bms3o_t( $member_url === $wc_integration->filter_file_path( $member_url ), 'Restricted files of other plugins are never signed as product downloads' );
wp_delete_attachment( $member_id, true );

$order->delete( true );
$product->delete( true );
wp_delete_attachment( $attachment_id, true );
bms3o_t( null === $offloader->items()->get( $item['path'] ), 'Cleanup' );

bms3o_t_done();
