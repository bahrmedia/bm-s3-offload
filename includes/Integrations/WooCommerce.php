<?php
/**
 * WooCommerce integration.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Integrations;

use BahrMedia\S3Offload\Offloader;

defined( 'ABSPATH' ) || exit;

/**
 * Serves downloadable product files from the bucket.
 *
 * Product files live in uploads/woocommerce_uploads/ and are stored as
 * restricted private objects. When a customer downloads a file, WooCommerce
 * checks the order and permissions; only then the download is redirected to
 * a short-lived signed URL. Downloads never pass through the web server.
 */
final class WooCommerce {

	/**
	 * Lifetime of download URLs in seconds.
	 */
	const DOWNLOAD_TTL = 600;

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	private $offloader;

	/**
	 * Signed URLs created in this request.
	 *
	 * @var array<string,true>
	 */
	private $signed = array();

	/**
	 * Constructor.
	 *
	 * @param Offloader $offloader Offloader.
	 */
	public function __construct( Offloader $offloader ) {
		$this->offloader = $offloader;
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( ! $this->offloader->settings()->get( 'wc_enabled' ) ) {
			return;
		}
		add_filter( 'woocommerce_download_product_filepath', array( $this, 'filter_file_path' ), 99, 1 );
		add_filter( 'woocommerce_file_download_method', array( $this, 'filter_download_method' ), 99, 3 );
	}

	/**
	 * Replaces the path of an offloaded product file with a signed URL.
	 *
	 * WooCommerce applies this filter before it validates the order, the
	 * remaining downloads and the expiry; on failure it stops with an error
	 * and never reveals the URL. The URL is only handed out by download().
	 *
	 * @param string $file_path File URL or path of the product download.
	 * @return string
	 */
	public function filter_file_path( $file_path ) {
		if ( ! is_string( $file_path ) || '' === $file_path ) {
			return $file_path;
		}

		$path = $this->offloader->path_from_url( $file_path );
		if ( '' === $path && false === strpos( $file_path, '://' ) ) {
			$path = $this->offloader->relative( $file_path );
		}
		$item = '' === $path ? null : $this->offloader->items()->get( $path );
		if ( ! $item ) {
			return $file_path;
		}

		// Restricted files of other plugins (e.g. BuddyBoss media) are never handed out as product files.
		if ( $this->offloader->is_restricted( $item ) && 0 !== strpos( $item['path'], 'woocommerce_uploads/' ) ) {
			return $file_path;
		}

		$offloader = $this->offloader;
		$url       = $offloader->with_private_access(
			static function () use ( $offloader, $item ) {
				return $offloader->url(
					$item,
					array(
						'sign'        => true,
						'disposition' => 'attachment',
						'ttl'         => self::DOWNLOAD_TTL,
					)
				);
			}
		);

		if ( '' === $url ) {
			return $file_path;
		}

		$this->signed[ $url ] = true;
		return $url;
	}

	/**
	 * Redirects to signed bucket URLs instead of streaming them through PHP.
	 *
	 * @param string $method     Download method.
	 * @param int    $product_id Product ID.
	 * @param string $file_path  File URL.
	 * @return string
	 */
	public function filter_download_method( $method, $product_id, $file_path ) {
		return isset( $this->signed[ $file_path ] ) ? 'redirect' : $method;
	}
}
