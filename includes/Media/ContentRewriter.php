<?php
/**
 * Rewrites uploads URLs in rendered content.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Media;

use BahrMedia\S3Offload\Offloader;

defined( 'ABSPATH' ) || exit;

/**
 * The database keeps local URLs, so the site never gets locked in to a
 * provider. On output, all uploads URLs of a text are collected and resolved
 * with a single (cached) lookup.
 */
final class ContentRewriter {

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	private $offloader;

	/**
	 * Compiled pattern per site (lazy).
	 *
	 * @var array<int,string>
	 */
	private $patterns = array();

	/**
	 * Constructor.
	 *
	 * @param Offloader $offloader Offloader.
	 */
	public function __construct( Offloader $offloader ) {
		$this->offloader = $offloader;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		$filters = array(
			'the_content',
			'the_excerpt',
			'widget_text',
			'widget_text_content',
			'widget_block_content',
			'comment_text',
			'term_description',
			'get_the_author_description',
			// bbPress / BuddyBoss forums and activity.
			'bbp_get_topic_content',
			'bbp_get_reply_content',
			'bp_get_activity_content_body',
			'bp_get_activity_content',
			'bp_get_the_thread_message_content',
			'bp_get_group_description',
		);

		/**
		 * Filters the list of text filters whose output is rewritten.
		 *
		 * @param string[] $filters Filter names.
		 */
		foreach ( (array) apply_filters( 'bms3o_content_filters', $filters ) as $filter ) {
			add_filter( $filter, array( $this, 'rewrite' ), 99 );
		}

		// Editors insert the (bucket) URLs they get from WordPress: store local URLs instead.
		add_filter( 'wp_insert_post_data', array( $this, 'localize_post_data' ), 10, 1 );
	}

	/**
	 * Replaces bucket, CDN and signed URLs in saved posts with local URLs.
	 *
	 * @param array $data Slashed post data.
	 * @return array
	 */
	public function localize_post_data( $data ) {
		foreach ( array( 'post_content', 'post_excerpt' ) as $field ) {
			if ( isset( $data[ $field ] ) && is_string( $data[ $field ] ) ) {
				$data[ $field ] = $this->localize( $data[ $field ] );
			}
		}
		return $data;
	}

	/**
	 * Replaces URLs of offloaded files (bucket, CDN, signed) with local uploads URLs.
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public function localize( $content ) {
		if ( '' === $content || ! $this->offloader->settings()->is_configured() ) {
			return $content;
		}

		$bases = array();
		foreach ( array( false, true ) as $is_private ) {
			$prefix = $this->offloader->key_prefix( $is_private );
			$domain = (string) $this->offloader->settings()->get( 'delivery_domain' );
			if ( '' !== $domain ) {
				$bases[] = preg_replace( '#^https?:#i', '', $domain ) . '/' . \BahrMedia\S3Offload\S3\Client::encode_key( $prefix );
			}
			try {
				$bases[] = preg_replace( '#^https?:#i', '', $this->offloader->settings()->client()->object_url( $prefix ) );
			} catch ( \InvalidArgumentException $e ) {
				unset( $e );
			}
		}

		$bases = array_unique( array_filter( $bases ) );
		$found = false;
		foreach ( $bases as $base ) {
			if ( false !== stripos( $content, $base ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return $content;
		}

		$quoted = array();
		foreach ( $bases as $base ) {
			$quoted[] = preg_quote( $base, '#' );
		}
		$local = $this->offloader->uploads()['baseurl'] . '/';

		return (string) preg_replace_callback(
			'#(?:https?:)?(?:' . implode( '|', $quoted ) . ')([^\s"\'<>()\\\\?\#,]+)(?:\?[^\s"\'<>]*)?#i',
			static function ( $found ) use ( $local ) {
				return $local . rawurldecode( $found[1] );
			},
			$content
		);
	}

	/**
	 * Rewrites all offloaded uploads URLs in a text.
	 *
	 * @param string|mixed $content Content.
	 * @return string|mixed
	 */
	public function rewrite( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return $content;
		}

		$host = wp_parse_url( $this->offloader->uploads()['baseurl'], PHP_URL_HOST );
		if ( $host && false === stripos( $content, $host ) ) {
			return $content; // Fast path: no uploads URL in the text.
		}

		if ( ! preg_match_all( $this->pattern(), $content, $matches ) ) {
			return $content;
		}

		$urls = array();
		foreach ( array_unique( $matches[0] ) as $url ) {
			// The query string (e.g. "?ver=2") is dropped together with the URL.
			$path = $this->offloader->path_from_url( $url );
			if ( '' !== $path ) {
				$urls[ $url ] = $path;
			}
		}
		if ( empty( $urls ) ) {
			return $content;
		}

		$items   = $this->offloader->items()->get_many( array_values( $urls ) );
		$rewrite = (bool) $this->offloader->settings()->get( 'rewrite_urls' );
		$replace = array();

		foreach ( $urls as $url => $path ) {
			if ( ! isset( $items[ $path ] ) ) {
				continue;
			}
			$item = $items[ $path ];
			// Private files are only delivered through access-checked code paths.
			if ( $item['is_private'] || ( ! $rewrite && ! $item['local_removed'] ) ) {
				continue;
			}
			$new = $this->offloader->url( $item );
			if ( '' === $new ) {
				continue;
			}
			// Signed URLs contain "&" which must be encoded inside HTML attributes.
			$replace[ $url ] = false !== strpos( $new, '?' ) ? esc_url( $new ) : $new;
		}

		if ( empty( $replace ) ) {
			return $content;
		}

		// strtr() always prefers the longest match, so "a.jpg" never replaces part of "a.jpg.webp".
		return strtr( $content, $replace );
	}

	/**
	 * Regex matching URLs below the uploads base URL (http, https or protocol relative).
	 *
	 * @return string
	 */
	private function pattern() {
		$blog_id = get_current_blog_id();
		if ( ! isset( $this->patterns[ $blog_id ] ) ) {
			$base                       = preg_replace( '#^https?:#i', '', $this->offloader->uploads()['baseurl'] );
			$this->patterns[ $blog_id ] = '#(?:https?:)?' . preg_quote( $base, '#' ) . '/[^\s"\'<>()\\\\?\#,]+(?:\?[^\s"\'<>]*)?#i';
		}
		return $this->patterns[ $blog_id ];
	}
}
