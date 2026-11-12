<?php
/**
 * Enable Media Replace integration.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Integrations;

use BahrMedia\S3Offload\Media\Attachments;
use BahrMedia\S3Offload\Offloader;
use BahrMedia\S3Offload\Store\Items;

defined( 'ABSPATH' ) || exit;

/**
 * Enable Media Replace deletes the old files of an attachment, writes the new
 * file and replaces the old URLs in content with the new ones. Two things are
 * needed for offloaded attachments:
 *
 * - While it runs, attachment URLs stay local: content stores local URLs, and
 *   EMR looks for the URLs WordPress returns. With bucket URLs it would find
 *   nothing and keep the old (removed) file names in content.
 * - Afterwards the objects of old files the attachment no longer has (other
 *   size names, or the old name when replaced with a new file name) are
 *   deleted from the bucket, like EMR deleted the local files. Otherwise the
 *   replaced image would stay reachable under its old URLs.
 */
final class EnableMediaReplace {

	/**
	 * Attachments module.
	 *
	 * @var Attachments
	 */
	private $attachments;

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	private $offloader;

	/**
	 * Paths of the attachments being replaced, before the replacement.
	 *
	 * @var array<int,string[]>
	 */
	private $old_paths = array();

	/**
	 * Constructor.
	 *
	 * @param Attachments $attachments Attachments module.
	 * @param Offloader   $offloader   Offloader.
	 */
	public function __construct( Attachments $attachments, Offloader $offloader ) {
		$this->attachments = $attachments;
		$this->offloader   = $offloader;
	}

	/**
	 * Whether Enable Media Replace is active.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'EMR_VERSION' );
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'wp_handle_replace', array( $this, 'start' ), 10, 1 );
		add_action( 'emr_after_remove_current', array( $this, 'remember' ), 10, 3 );
		add_action( 'enable-media-replace-upload-done', array( $this, 'finish' ), 10, 3 );
	}

	/**
	 * A replacement starts: keep attachment URLs local.
	 *
	 * @param array $args Arguments ('post_id').
	 * @return void
	 */
	public function start( $args ) {
		$post_id = is_array( $args ) && isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		if ( $post_id > 0 && ! isset( $this->old_paths[ $post_id ] ) ) {
			$this->old_paths[ $post_id ] = $this->attachments->paths( $post_id );
			$this->attachments->use_local_urls( true );
		}
	}

	/**
	 * Remembers the files EMR has just removed (metadata before the replacement).
	 *
	 * @param int   $post_id      Attachment ID.
	 * @param mixed $meta         Metadata before the replacement.
	 * @param mixed $backup_sizes Backup sizes (part of the paths anyway).
	 * @return void
	 */
	public function remember( $post_id, $meta, $backup_sizes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Hook signature.
		$post_id = (int) $post_id;
		if ( is_array( $meta ) && isset( $this->old_paths[ $post_id ] ) ) {
			$this->old_paths[ $post_id ] = array_values( array_unique( array_merge( $this->old_paths[ $post_id ], $this->attachments->paths( $post_id, $meta ) ) ) );
		}
	}

	/**
	 * The replacement is done: URLs are delivered from the bucket again, and
	 * the objects of files the attachment no longer has are deleted.
	 *
	 * @param string $target_url Target URL.
	 * @param string $source_url Source URL.
	 * @param int    $post_id    Attachment ID.
	 * @return void
	 */
	public function finish( $target_url, $source_url, $post_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundBeforeLastUsed -- Hook signature.
		$post_id = (int) $post_id;
		if ( ! isset( $this->old_paths[ $post_id ] ) ) {
			return;
		}
		$old = $this->old_paths[ $post_id ];
		unset( $this->old_paths[ $post_id ] );
		$this->attachments->use_local_urls( false );

		$stale = array();
		foreach ( $this->offloader->items()->get_many( array_diff( $old, $this->attachments->paths( $post_id ) ) ) as $path => $item ) {
			if ( Items::SOURCE_POST === $item['source_type'] && $post_id === (int) $item['source_id'] ) {
				$stale[ $path ] = $item;
			}
		}
		if ( empty( $stale ) || ! $this->offloader->settings()->is_configured() ) {
			return;
		}

		// Objects that could not be deleted are retried by the hourly cleanup.
		$orphans = array();
		foreach ( array_keys( $this->offloader->delete_remote( array_keys( $stale ) ) ) as $path ) {
			$orphans[] = array( $stale[ $path ]['bucket'], $stale[ $path ]['object_key'], $path );
		}
		$this->offloader->items()->delete( array_keys( $stale ) );
		if ( ! empty( $orphans ) ) {
			$this->offloader->queue_orphans( $orphans );
		}
	}
}
