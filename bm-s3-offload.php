<?php
/**
 * Plugin Name:       BM S3 Offload
 * Plugin URI:        https://github.com/bahrmedia/bm-s3-offload
 * Description:       Offloads the media library to any S3-compatible object storage (AWS S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, Hetzner, IONOS, MinIO …) with first-class BuddyBoss support. Free, open source, no Pro version, no SDK.
 * Version:           1.0.4
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Bahr Media
 * Author URI:        https://bahrmedia.de
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       bm-s3-offload
 * Domain Path:       /languages
 *
 * @package BahrMedia\S3Offload
 */

defined( 'ABSPATH' ) || exit;

define( 'BMS3O_VERSION', '1.0.4' );
define( 'BMS3O_FILE', __FILE__ );
define( 'BMS3O_DIR', plugin_dir_path( __FILE__ ) );
define( 'BMS3O_URL', plugin_dir_url( __FILE__ ) );

/*
 * PSR-4 autoloader for the BahrMedia\S3Offload namespace.
 */
spl_autoload_register(
	static function ( $class_name ) {
		$prefix = 'BahrMedia\\S3Offload\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$relative = substr( $class_name, strlen( $prefix ) );
		$file     = BMS3O_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'BahrMedia\\S3Offload\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BahrMedia\\S3Offload\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'BahrMedia\\S3Offload\\Plugin', 'instance' ) );
