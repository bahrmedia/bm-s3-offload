<?php
/**
 * Router for PHP's built-in web server (pretty permalinks).
 *
 * Usage: php -S localhost:8080 -t /path/to/wordpress tests/integration/router.php
 *
 * @package BahrMedia\S3Offload
 */

$bms3o_root = $_SERVER['DOCUMENT_ROOT'];
$bms3o_path = (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

if ( '/' !== $bms3o_path && file_exists( $bms3o_root . $bms3o_path ) ) {
	if ( is_dir( $bms3o_root . $bms3o_path ) && ! file_exists( rtrim( $bms3o_root . $bms3o_path, '/' ) . '/index.php' ) ) {
		require $bms3o_root . '/index.php';
		return true;
	}
	return false; // Serve the file (or directory index) as is.
}

$_SERVER['SCRIPT_NAME'] = '/index.php';
require $bms3o_root . '/index.php';
