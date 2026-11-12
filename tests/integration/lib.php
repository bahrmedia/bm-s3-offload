<?php
/**
 * Helpers for the integration tests.
 *
 * The tests run inside WordPress via `wp eval-file` against a real S3 server
 * (MinIO in CI). See tests/integration/README.md.
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.PHP.DevelopmentFunctions

use BahrMedia\S3Offload\Plugin;

$GLOBALS['bms3o_t_failures'] = 0;
$GLOBALS['bms3o_t_count']    = 0;
$GLOBALS['bms3o_t_tmp_dirs'] = array();

// Temporary test images are removed at the end of every test file, also after failures.
register_shutdown_function(
	static function () {
		foreach ( $GLOBALS['bms3o_t_tmp_dirs'] as $dir ) {
			foreach ( (array) glob( $dir . '/*' ) as $file ) {
				if ( is_file( $file ) ) {
					unlink( $file );
				}
			}
			if ( is_dir( $dir ) ) {
				rmdir( $dir );
			}
		}
	}
);

/**
 * Asserts a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Description.
 * @param mixed  $detail    Shown on failure.
 * @return bool
 */
function bms3o_t( $condition, $label, $detail = null ) {
	++$GLOBALS['bms3o_t_count'];
	if ( $condition ) {
		echo "  \033[32m✔\033[0m {$label}\n";
		return true;
	}
	++$GLOBALS['bms3o_t_failures'];
	echo "  \033[31m✘ {$label}\033[0m" . ( null === $detail ? '' : ' – ' . ( is_scalar( $detail ) ? $detail : wp_json_encode( $detail ) ) ) . "\n";
	return false;
}

/**
 * Prints a section title.
 *
 * @param string $title Title.
 * @return void
 */
function bms3o_t_section( $title ) {
	echo "\n\033[1m{$title}\033[0m\n";
}

/**
 * Ends a test file: exit code 1 on failures.
 *
 * @return void
 */
function bms3o_t_done() {
	$failures = $GLOBALS['bms3o_t_failures'];
	echo "\n{$GLOBALS['bms3o_t_count']} assertions, {$failures} failures\n";
	if ( $failures ) {
		exit( 1 );
	}
}

/**
 * The plugin.
 *
 * @return Plugin
 */
function bms3o_t_plugin() {
	return Plugin::instance();
}

/**
 * Updates settings stored in the database (constants still win).
 *
 * @param array $values Settings.
 * @return void
 */
function bms3o_t_settings( array $values ) {
	$stored = get_option( 'bms3o_settings', array() );
	update_option( 'bms3o_settings', array_merge( is_array( $stored ) ? $stored : array(), $values ) );
	bms3o_t_plugin()->settings->flush();
}

/**
 * Base URL of the site for HTTP tests.
 *
 * @return string
 */
function bms3o_t_url() {
	$url = getenv( 'BMS3O_TEST_URL' );
	return untrailingslashit( $url ? $url : home_url() );
}

/**
 * Creates a PNG test image.
 *
 * @param int    $width  Width.
 * @param int    $height Height.
 * @param string $name   File name.
 * @return string Path of a temporary file.
 */
function bms3o_t_image( $width = 800, $height = 600, $name = 'test.png' ) {
	$dir  = trailingslashit( get_temp_dir() ) . 'bms3o-test-' . wp_generate_password( 6, false );
	$file = $dir . '/' . $name;
	wp_mkdir_p( $dir );
	$GLOBALS['bms3o_t_tmp_dirs'][] = $dir;
	$image = imagecreatetruecolor( $width, $height );
	imagefill( $image, 0, 0, imagecolorallocate( $image, wp_rand( 0, 255 ), wp_rand( 0, 255 ), wp_rand( 0, 255 ) ) );
	imagestring( $image, 5, 10, 10, wp_generate_password( 12, false ), imagecolorallocate( $image, 255, 255, 255 ) );
	if ( preg_match( '/\.jpe?g$/i', $name ) ) {
		imagejpeg( $image, $file, 85 );
	} else {
		imagepng( $image, $file );
	}
	imagedestroy( $image );
	return $file;
}

/**
 * Imports a file into the media library like an upload.
 *
 * @param string $file Path.
 * @return int Attachment ID.
 * @throws RuntimeException When the import fails.
 */
function bms3o_t_import( $file ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$tmp = wp_tempnam( basename( $file ) );
	copy( $file, $tmp );
	$id = media_handle_sideload(
		array(
			'name'     => basename( $file ),
			'tmp_name' => $tmp,
		),
		0
	);
	if ( is_wp_error( $id ) ) {
		throw new RuntimeException( $id->get_error_message() );
	}
	return (int) $id;
}

/**
 * HTTP GET without following redirects.
 *
 * @param string $url    URL.
 * @param string $cookie Cookie header.
 * @return array{code:int,location:string,body:string,headers:array}
 */
function bms3o_t_get( $url, $cookie = '' ) {
	$response = wp_remote_get(
		$url,
		array(
			'redirection' => 0,
			'timeout'     => 30,
			'headers'     => '' === $cookie ? array() : array( 'Cookie' => $cookie ),
		)
	);
	if ( is_wp_error( $response ) ) {
		return array(
			'code'     => 0,
			'location' => '',
			'body'     => $response->get_error_message(),
			'headers'  => array(),
		);
	}
	return array(
		'code'     => (int) wp_remote_retrieve_response_code( $response ),
		'location' => (string) wp_remote_retrieve_header( $response, 'location' ),
		'body'     => (string) wp_remote_retrieve_body( $response ),
		'headers'  => wp_remote_retrieve_headers( $response ),
	);
}

/**
 * HTTP status of a URL (anonymous).
 *
 * @param string $url URL.
 * @return int
 */
function bms3o_t_status( $url ) {
	return bms3o_t_get( $url )['code'];
}

/**
 * Logged-in cookie header for a user.
 *
 * @param int $user_id User ID.
 * @return string
 */
function bms3o_t_cookie( $user_id ) {
	$expiration = time() + DAY_IN_SECONDS;
	$token      = WP_Session_Tokens::get_instance( $user_id )->create( $expiration );
	return LOGGED_IN_COOKIE . '=' . rawurlencode( wp_generate_auth_cookie( $user_id, $expiration, 'logged_in', $token ) );
}

/**
 * Creates (or returns) a test user.
 *
 * @param string $login Login.
 * @param string $role  Role.
 * @return int User ID.
 */
function bms3o_t_user( $login, $role = 'subscriber' ) {
	$user = get_user_by( 'login', $login );
	if ( $user ) {
		return (int) $user->ID;
	}
	return (int) wp_insert_user(
		array(
			'user_login' => $login,
			'user_pass'  => wp_generate_password(),
			'user_email' => $login . '@example.test',
			'role'       => $role,
		)
	);
}

/**
 * Whether an object exists in the bucket.
 *
 * @param string $key Object key.
 * @return bool
 */
function bms3o_t_object_exists( $key ) {
	return null !== bms3o_t_plugin()->settings->client()->head( $key );
}
