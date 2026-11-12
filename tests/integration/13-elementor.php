<?php
/**
 * Elementor: background images in generated CSS (skipped when Elementor is
 * not active). The CSS contains bucket URLs; when they change (leave the
 * bucket, privacy change, settings), Elementor's CSS cache is cleared.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

bms3o_t_section( 'Elementor' );

if ( ! defined( 'ELEMENTOR_VERSION' ) || ! class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
	echo "  Elementor is not active – skipped.\n";
	bms3o_t_done();
	return;
}

$plugin    = bms3o_t_plugin();
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
update_option( 'elementor_css_print_method', 'external' );

/**
 * Generates the CSS of a page and returns the background image URL in it.
 *
 * @param int $page Page ID.
 * @return string
 */
function bms3o_t_elementor_css_url( $page ) {
	$css = \Elementor\Core\Files\CSS\Post::create( $page );
	$css->update();
	$content = (string) file_get_contents( $css->get_path() );
	return preg_match( '/url\("?([^")]+)"?\)/', $content, $m ) ? $m[1] : '';
}

/**
 * Whether Elementor's generated CSS of a page is gone (cache cleared).
 *
 * @param int $page Page ID.
 * @return bool
 */
function bms3o_t_elementor_css_cleared( $page ) {
	do_action( 'shutdown' ); // The cache is cleared once at the end of the request.
	$css = \Elementor\Core\Files\CSS\Post::create( $page );
	return ! is_file( $css->get_path() ) && empty( get_post_meta( $page, '_elementor_css', true ) );
}

$image = bms3o_t_import( bms3o_t_image( 400, 300, 'elementor-background.png' ) );
$offloader->remove_local( $media->paths( $image ), true );
$page = wp_insert_post(
	array(
		'post_type'   => 'page',
		'post_status' => 'publish',
		'post_title'  => 'Elementor background',
	)
);
$data = array(
	array(
		'id'       => 'bms3o01',
		'elType'   => 'container',
		'settings' => array(
			'background_background' => 'classic',
			'background_image'      => array(
				'id'   => $image,
				'url'  => '',
				'size' => '',
			),
		),
		'elements' => array(),
		'isInner'  => false,
	),
);
update_post_meta( $page, '_elementor_edit_mode', 'builder' );
update_post_meta( $page, '_elementor_version', ELEMENTOR_VERSION );
update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );

$url = bms3o_t_elementor_css_url( $page );
bms3o_t( 0 !== strpos( $url, home_url() ) && 200 === bms3o_t_status( $url ), 'The generated CSS uses the bucket URL, which works without local copy', $url );

$errors = $media->set_privacy( $image, true );
bms3o_t( empty( $errors ) && bms3o_t_elementor_css_cleared( $page ), 'A privacy change clears the CSS cache', $errors );
$media->set_privacy( $image, false );
$url = bms3o_t_elementor_css_url( $page );

$plugin->settings->save( array_map( 'strval', array_diff_key( $plugin->settings->all(), array( 'secret_key' => true ) ) ) );
bms3o_t( bms3o_t_elementor_css_cleared( $page ), 'Saving the settings clears the CSS cache' );
$url = bms3o_t_elementor_css_url( $page );

$errors = $media->unoffload( $image );
bms3o_t( empty( $errors ) && bms3o_t_elementor_css_cleared( $page ), '"Leave the bucket" clears the CSS cache', $errors );
$url = bms3o_t_elementor_css_url( $page );
bms3o_t( 0 === strpos( $url, home_url() ) && 200 === bms3o_t_status( $url ), 'Regenerated, the CSS uses the local URL again, which works', $url );

wp_delete_post( $page, true );
wp_delete_attachment( $image, true );

bms3o_t_done();
