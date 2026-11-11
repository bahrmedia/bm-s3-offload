<?php
/**
 * Static asset delivery through a pull CDN.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Rewrites the URLs of enqueued scripts and styles (themes, plugins,
 * wp-includes) to a pull CDN whose origin is this site. Fonts and images
 * referenced relatively from CSS are then loaded from the CDN as well.
 *
 * Only front-end requests are affected; the admin and the customizer always
 * load assets from the site itself.
 */
final class Assets {

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Site URL prefixes (without scheme) that may be rewritten.
	 *
	 * @var string[]|null
	 */
	private $prefixes;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		if ( '' === $this->settings->get( 'assets_domain' ) || is_admin() ) {
			return;
		}
		add_filter( 'script_loader_src', array( $this, 'rewrite' ), 99 );
		add_filter( 'style_loader_src', array( $this, 'rewrite' ), 99 );
	}

	/**
	 * Rewrites an asset URL to the CDN.
	 *
	 * @param string|mixed $src Asset URL.
	 * @return string|mixed
	 */
	public function rewrite( $src ) {
		if ( ! is_string( $src ) || '' === $src || is_customize_preview() ) {
			return $src;
		}

		$plain = preg_replace( '#^https?:#i', '', $src );
		foreach ( $this->prefixes() as $prefix ) {
			if ( 0 === strpos( $plain, $prefix['url'] ) ) {
				return $this->settings->get( 'assets_domain' ) . $prefix['path'] . substr( $plain, strlen( $prefix['url'] ) );
			}
		}
		return $src;
	}

	/**
	 * URL prefixes of wp-content and wp-includes, mapped to their path on the CDN.
	 *
	 * @return array<int,array{url:string,path:string}>
	 */
	private function prefixes() {
		if ( null === $this->prefixes ) {
			$this->prefixes = array();
			foreach ( array( content_url(), includes_url() ) as $url ) {
				$url  = untrailingslashit( preg_replace( '#^https?:#i', '', $url ) );
				$path = (string) wp_parse_url( 'http:' . $url, PHP_URL_PATH );

				$this->prefixes[] = array(
					'url'  => $url,
					'path' => untrailingslashit( $path ),
				);
			}
		}
		return $this->prefixes;
	}
}
