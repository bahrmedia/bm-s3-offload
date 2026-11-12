<?php
/**
 * Elementor integration.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor writes the URLs of background images into generated CSS (files in
 * uploads/elementor/css or post meta). It gets them from WordPress, so they are
 * bucket URLs while a file is offloaded – and they stay in the CSS when the
 * URL changes later (privacy change, "Leave the bucket", other endpoint or
 * CDN). Whenever URLs change, Elementor's CSS cache is cleared once at the end
 * of the request; Elementor regenerates it on the next page view.
 */
final class Elementor {

	/**
	 * Whether the cache has to be cleared at the end of the request.
	 *
	 * @var bool
	 */
	private $dirty = false;

	/**
	 * Whether Elementor is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'bms3o_urls_changed', array( $this, 'mark' ), 10, 0 );
		add_action( 'shutdown', array( $this, 'flush' ), 0 );
	}

	/**
	 * Remembers that URLs changed.
	 *
	 * @return void
	 */
	public function mark() {
		$this->dirty = true;
	}

	/**
	 * Clears Elementor's CSS cache if URLs changed during this request.
	 *
	 * @return void
	 */
	public function flush() {
		if ( ! $this->dirty || ! class_exists( '\Elementor\Plugin' ) || empty( \Elementor\Plugin::$instance->files_manager ) ) {
			return;
		}
		$this->dirty = false;
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}
}
