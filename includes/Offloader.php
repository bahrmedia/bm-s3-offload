<?php
/**
 * File level offload operations.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\S3\S3Exception;
use BahrMedia\S3Offload\Store\Items;
use BahrMedia\S3Offload\Store\Orphans;

defined( 'ABSPATH' ) || exit;

/**
 * Knows nothing about WordPress hooks. Works on paths relative to the
 * uploads base directory and keeps the items table in sync.
 */
final class Offloader {

	/**
	 * Orphans that failed this often are reported as failing repeatedly.
	 */
	const ORPHANS_FAILING = 3;

	/**
	 * Objects the cleanup locks and deletes at a time.
	 */
	const CLEANUP_CHUNK = 100;

	/**
	 * Seconds by which all delete requests of a chunk have ended (the S3 client
	 * shortens timeouts and skips retries accordingly).
	 */
	const CLEANUP_TIME = 60;

	/**
	 * Seconds the object locks of the cleanup outlast its delete requests.
	 */
	const CLEANUP_MARGIN = 60;

	/**
	 * Option with paths whose object may have been deleted although it was in use again.
	 */
	const CONFLICTS_OPTION = 'bms3o_cleanup_conflicts';

	/**
	 * Results of delete_or_queue().
	 */
	const DELETED      = 'deleted';
	const QUEUED       = 'queued';
	const QUEUE_FAILED = 'queue_failed';

	/**
	 * Lifetime of a file lock in seconds; it is extended before every request.
	 */
	const PATH_LOCK_TTL = 600;

	/**
	 * Seconds by which every request under a file lock ends before the lock expires.
	 */
	const PATH_LOCK_MARGIN = 60;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Items repository.
	 *
	 * @var Items
	 */
	private $items;

	/**
	 * File locks held by this instance: path => token and nesting depth.
	 *
	 * @var array<string,array{token:string,depth:int}>
	 */
	private $path_locks = array();

	/**
	 * Queue of objects that still have to be deleted.
	 *
	 * @var Orphans
	 */
	private $orphans;

	/**
	 * Paths whose local copies are deleted at the end of the request.
	 *
	 * @var array<string,true>
	 */
	private $removal_queue = array();

	/**
	 * Depth of trusted code paths that may sign private objects.
	 *
	 * @var int
	 */
	private $private_scope = 0;

	/**
	 * Request cache for signed URLs.
	 *
	 * @var array<string,string>
	 */
	private $signed_cache = array();

	/**
	 * Uploads directory info per site (request cache).
	 *
	 * @var array<int,array{basedir:string,baseurl:string}>
	 */
	private $uploads = array();

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings.
	 * @param Items    $items    Items repository.
	 */
	public function __construct( Settings $settings, Items $items ) {
		$this->settings = $settings;
		$this->items    = $items;
		$this->orphans  = new Orphans();
	}

	/**
	 * Items repository.
	 *
	 * @return Items
	 */
	public function items() {
		return $this->items;
	}

	/**
	 * Settings.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/*
	 * ------------------------------------------------------------------
	 * Paths
	 * ------------------------------------------------------------------
	 */

	/**
	 * Uploads base directory and URL (without trailing slash).
	 *
	 * @return array{basedir:string,baseurl:string}
	 */
	public function uploads() {
		$blog_id = get_current_blog_id();
		if ( ! isset( $this->uploads[ $blog_id ] ) ) {
			$dir                       = wp_get_upload_dir();
			$this->uploads[ $blog_id ] = array(
				'basedir' => untrailingslashit( wp_normalize_path( $dir['basedir'] ) ),
				'baseurl' => untrailingslashit( $dir['baseurl'] ),
			);
		}
		return $this->uploads[ $blog_id ];
	}

	/**
	 * Absolute local path of a relative path.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	public function absolute( $path ) {
		return $this->uploads()['basedir'] . '/' . ltrim( $path, '/' );
	}

	/**
	 * Relative path of an absolute local path, or '' if outside the uploads dir.
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	public function relative( $file ) {
		$base = $this->uploads()['basedir'] . '/';
		$file = wp_normalize_path( $file );
		return 0 === strpos( $file, $base ) ? substr( $file, strlen( $base ) ) : '';
	}

	/**
	 * Object key for a relative path.
	 *
	 * @param string $path       Relative path.
	 * @param bool   $is_private Private object.
	 * @return string
	 */
	public function object_key( $path, $is_private ) {
		$key = $this->key_prefix( $is_private ) . ltrim( $path, '/' );

		/**
		 * Filters the object key of a file.
		 *
		 * @param string $key        Object key.
		 * @param string $path       Path relative to the uploads directory.
		 * @param bool   $is_private Whether the object is private.
		 */
		return (string) apply_filters( 'bms3o_object_key', $key, $path, $is_private );
	}

	/**
	 * Key prefix that corresponds to the uploads base directory of the current site.
	 *
	 * @param bool $is_private Private objects.
	 * @return string
	 */
	public function key_prefix( $is_private ) {
		$prefix = $this->settings->get( 'object_prefix' ) . $this->site_dir();
		return $is_private ? $this->settings->get( 'private_prefix' ) . $prefix : $prefix;
	}

	/**
	 * Whether an object key lies in the current site's key space.
	 *
	 * @param string $key Object key.
	 * @return bool
	 */
	private function owns_key( $key ) {
		foreach ( array( false, true ) as $is_private ) {
			$prefix = $this->key_prefix( $is_private );
			if ( '' !== $prefix && 0 === strpos( $key, $prefix ) ) {
				// Keys of sub-sites start with the main site's prefix too: exclude them.
				$rest = substr( $key, strlen( $prefix ) );
				return '' !== $this->site_dir() || ! preg_match( '#^sites/\d+/#', $rest );
			}
		}
		return false;
	}

	/**
	 * Key segment of the current site on multisite ("sites/2/"), mirroring
	 * WordPress' own uploads layout so that sites sharing a bucket never collide.
	 *
	 * @return string
	 */
	private function site_dir() {
		// Same condition as WordPress' own uploads layout (wp_upload_dir()).
		if ( ! is_multisite() || ( is_main_network() && is_main_site() ) ) {
			return '';
		}
		return 'sites/' . get_current_blog_id() . '/';
	}

	/*
	 * ------------------------------------------------------------------
	 * Upload
	 * ------------------------------------------------------------------
	 */

	/**
	 * Uploads local files and records them.
	 *
	 * Files that are already offloaded with the same size, bucket and key are
	 * skipped unless $force is true. Missing local files are skipped as well
	 * (they may have been removed after a previous offload).
	 *
	 * @param string   $source_type Source type.
	 * @param int      $source_id   Source ID.
	 * @param string[] $paths       Relative paths.
	 * @param bool     $is_private  Store as private object.
	 * @param bool     $force       Upload even if unchanged.
	 * @return array{uploaded:string[],skipped:string[],errors:array<string,string>}
	 */
	public function upload( $source_type, $source_id, array $paths, $is_private, $force = false ) {
		$result = array(
			'uploaded' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);

		$paths = array_values( array_unique( array_filter( $paths, 'strlen' ) ) );
		if ( empty( $paths ) ) {
			return $result;
		}

		$blocked = $this->settings->storage_error();
		if ( '' !== $blocked ) {
			$result['errors'] = array_fill_keys( $paths, $blocked );
			return $result;
		}

		try {
			$client = $this->settings->client();
		} catch ( \InvalidArgumentException $e ) {
			$result['errors'] = array_fill_keys( $paths, $e->getMessage() );
			return $result;
		}

		$bucket = $client->get_bucket();

		foreach ( $paths as $path ) {
			// The current item, the upload, its record and the removal of the previous
			// object are serialised with all other changes of the file.
			if ( ! $this->lock_path( $path ) ) {
				$result['errors'][ $path ] = self::busy_message();
				continue;
			}

			try {
				$file = $this->absolute( $path );
				if ( ! is_file( $file ) ) {
					$result['skipped'][] = $path;
					continue;
				}

				$size  = (int) filesize( $file );
				$mtime = (int) filemtime( $file );
				$key   = $this->object_key( $path, $is_private );
				$item  = $this->fresh_item( $path );

				// Never let two sources share one object.
				if ( $item && ( $item['source_type'] !== $source_type || $item['source_id'] !== (int) $source_id ) ) {
					$result['errors'][ $path ] = sprintf( 'Path is already used by %s #%d.', $item['source_type'], $item['source_id'] );
					continue;
				}

				$stored_mtime = $item && isset( $item['local_mtime'] ) ? (int) $item['local_mtime'] : 0;
				if ( ! $force && $item && $item['bucket'] === $bucket && $item['object_key'] === $key && $item['filesize'] === $size && ( 0 === $stored_mtime || $stored_mtime === $mtime ) ) {
					$result['skipped'][] = $path;
					continue;
				}

				$claim = $this->claim_key( $bucket, $key );
				if ( false === $claim ) {
					$result['errors'][ $path ] = __( 'The object is being cleaned up right now. Please try again.', 'bm-s3-offload' );
					continue;
				}

				try {
					$this->client_for( $bucket, $path )->put_file( $key, $file, $this->upload_headers( $path, $is_private ) );
				} catch ( S3Exception $e ) {
					$this->release_key( $bucket, $key, $claim, false );
					$result['errors'][ $path ] = $e->getMessage();
					continue;
				}

				$saved = $this->items->save(
					array(
						'source_type'   => $source_type,
						'source_id'     => $source_id,
						'path'          => $path,
						'bucket'        => $bucket,
						'object_key'    => $key,
						'is_private'    => $is_private,
						'local_removed' => false,
						'filesize'      => $size,
						'local_mtime'   => $mtime,
					)
				);
				$this->release_key( $bucket, $key, $claim, $saved );
				if ( ! $saved ) {
					// The item still points to the previous object: keep that one. The new object
					// is only an orphan if it lives under another key.
					if ( ! $item || $item['object_key'] !== $key || $item['bucket'] !== $bucket ) {
						$this->delete_or_queue( $bucket, $key, $path );
					}
					$result['errors'][ $path ] = __( 'The upload could not be recorded in the database.', 'bm-s3-offload' );
					continue;
				}

				// The key moved within the bucket (e.g. privacy changed): remove the old object once
				// the item points to the new one, but only if it belongs to this site's key space (never another
				// site's object). Failed deletions are retried by the hourly cron.
				if ( $item && $item['bucket'] === $bucket && $item['object_key'] !== $key && $this->owns_key( $item['object_key'] ) ) {
					$deleted = $this->delete_or_queue( $item['bucket'], $item['object_key'], $path );
					if ( self::QUEUE_FAILED === $deleted ) {
						$result['errors'][ $path ] = self::leftover_message( $deleted, $item['object_key'] );
					} elseif ( self::DELETED !== $deleted && $is_private && ! $item['is_private'] ) {
						// A leftover public copy of a file that became private stays readable: report it.
						$result['errors'][ $path ] = self::leftover_message( $deleted, $item['object_key'] );
					}
				}

				$result['uploaded'][] = $path;
				if ( $item && ( $item['bucket'] !== $bucket || $item['object_key'] !== $key ) ) {
					self::urls_changed( array( $path ) );
				}

				/**
				 * Fires after a file has been uploaded to the bucket.
				 *
				 * @param string $path        Relative path.
				 * @param string $key         Object key.
				 * @param string $source_type Source type.
				 * @param int    $source_id   Source ID.
				 */
				do_action( 'bms3o_uploaded', $path, $key, $source_type, $source_id );
			} finally {
				$this->unlock_path( $path );
			}
		}

		return $result;
	}

	/**
	 * Request headers for an upload.
	 *
	 * @param string $path       Relative path.
	 * @param bool   $is_private Private object.
	 * @return array
	 */
	private function upload_headers( $path, $is_private ) {
		$type    = wp_check_filetype( $path );
		$headers = array(
			'Content-Type' => ! empty( $type['type'] ) ? $type['type'] : 'application/octet-stream',
		);

		$cache_control = $this->settings->get( 'cache_control' );
		if ( '' !== $cache_control ) {
			$headers['Cache-Control'] = $is_private ? 'private, max-age=3600' : $cache_control;
		}

		$acl = $this->settings->get( 'object_acl' );
		if ( '' !== $acl ) {
			$headers['x-amz-acl'] = $is_private ? 'private' : $acl;
		}

		/**
		 * Filters the headers sent with an upload.
		 *
		 * @param array  $headers    Headers.
		 * @param string $path       Relative path.
		 * @param bool   $is_private Whether the object is private.
		 */
		return (array) apply_filters( 'bms3o_upload_headers', $headers, $path, $is_private );
	}

	/*
	 * ------------------------------------------------------------------
	 * Local copies
	 * ------------------------------------------------------------------
	 */

	/**
	 * Queues local copies for deletion at the end of the request, so that
	 * other plugins (e.g. BuddyBoss rotation fix) can still work with them.
	 *
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	public function queue_local_removal( array $paths ) {
		if ( empty( $this->removal_queue ) ) {
			add_action( 'shutdown', array( $this, 'process_removal_queue' ), 5 );
		}
		foreach ( $paths as $path ) {
			$this->removal_queue[ $path ] = true;
		}
	}

	/**
	 * Cancels queued deletions (e.g. when a file is needed locally again).
	 *
	 * @param string[] $paths Relative paths.
	 * @return void
	 */
	public function unqueue_local_removal( array $paths ) {
		foreach ( $paths as $path ) {
			unset( $this->removal_queue[ $path ] );
		}
	}

	/**
	 * Deletes queued local copies.
	 *
	 * @return void
	 */
	public function process_removal_queue() {
		$paths               = array_keys( $this->removal_queue );
		$this->removal_queue = array();

		// After a fatal error (e.g. timeout while creating image sizes) WordPress
		// retries with the local original later: keep everything.
		$error = error_get_last();
		if ( $error && in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ), true ) ) {
			return;
		}

		if ( ! empty( $paths ) ) {
			$this->remove_local( $paths, false );
		}
	}

	/**
	 * Deletes local copies of offloaded files.
	 *
	 * A file is only deleted when it is recorded as offloaded with the same
	 * size; with $verify the object is additionally checked in the bucket.
	 *
	 * @param string[] $paths  Relative paths.
	 * @param bool     $verify Check object existence with a HEAD request.
	 * @return string[] Paths that have been removed.
	 */
	public function remove_local( array $paths, $verify = true ) {
		if ( '' !== $this->settings->storage_error() ) {
			return array(); // Never trust objects on a storage the files were not uploaded to.
		}

		$items   = $this->items->get_many( $paths );
		$removed = array();

		foreach ( array_keys( $items ) as $path ) {
			// The check of the object and the removal run under the file lock: the object
			// cannot be deleted or moved in between.
			if ( ! $this->lock_path( $path ) ) {
				continue; // Another operation changes the file: keep the local copy.
			}

			try {
				$item = $this->fresh_item( $path );
				if ( ! $item ) {
					continue; // Deleted meanwhile (e.g. "Leave the bucket"): keep the local copy.
				}
				$file = $this->absolute( $path );
				if ( ! is_file( $file ) ) {
					if ( ! $item['local_removed'] ) {
						$this->items->set_local_removed( array( $path ), true );
						$removed[] = $path;
					}
					continue;
				}

				clearstatcache( true, $file );
				$stored_mtime = isset( $item['local_mtime'] ) ? (int) $item['local_mtime'] : 0;
				if ( (int) filesize( $file ) !== $item['filesize'] || ( $stored_mtime && (int) filemtime( $file ) !== $stored_mtime ) ) {
					continue; // Changed after upload: keep it, it will be offloaded again.
				}

				if ( $verify ) {
					try {
						$head = $this->client_for( $item['bucket'], $path )->head( $item['object_key'] );
					} catch ( \Exception $e ) {
						continue;
					}
					if ( ! self::head_matches( $head, (int) $item['filesize'] ) ) {
						continue; // Missing or different object (e.g. truncated): keep the local copy.
					}
				}

				// Not wp_delete_file(): plugins use its filter to keep files that other
				// attachments still reference (e.g. Polylang's media translation), which
				// would silently keep every local copy. The object is verified above, so
				// only the local copy goes; the file itself stays in use.
				@unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged
				clearstatcache( true, $file );
				if ( ! is_file( $file ) ) {
					$this->items->set_local_removed( array( $path ), true );
					$removed[] = $path;
				}
			} finally {
				$this->unlock_path( $path );
			}
		}

		return $removed;
	}

	/**
	 * Restores local copies from the bucket.
	 *
	 * @param string[] $paths Relative paths.
	 * @return array{downloaded:string[],errors:array<string,string>}
	 */
	public function download( array $paths ) {
		$result = array(
			'downloaded' => array(),
			'errors'     => array(),
		);

		$this->unqueue_local_removal( $paths );
		$items = $this->items->get_many( $paths );

		$blocked = $this->settings->storage_error();
		if ( '' !== $blocked ) {
			$result['errors'] = array_fill_keys( array_keys( $items ), $blocked );
			return $result;
		}

		foreach ( array_keys( $items ) as $path ) {
			if ( ! $this->lock_path( $path ) ) {
				$result['errors'][ $path ] = self::busy_message();
				continue;
			}

			try {
				$item = $this->fresh_item( $path );
				if ( ! $item ) {
					continue; // Deleted meanwhile.
				}
				$file = $this->absolute( $path );
				if ( is_file( $file ) ) {
					if ( $item['local_removed'] ) {
						$this->items->set_local_removed( array( $path ), false );
					}
					continue;
				}

				try {
					$this->client_for( $item['bucket'], $path )->get_to_file( $item['object_key'], $file );
				} catch ( \Exception $e ) {
					$result['errors'][ $path ] = $e->getMessage();
					continue;
				}

				// The restored file is identical to the object: remember its new timestamp.
				$item['local_mtime']   = (int) filemtime( $file );
				$item['local_removed'] = false;
				if ( ! $this->items->save( $item ) ) {
					$result['errors'][ $path ] = __( 'The restored file could not be recorded in the database.', 'bm-s3-offload' );
					continue;
				}

				$result['downloaded'][] = $path;
			} finally {
				$this->unlock_path( $path );
			}
		}

		return $result;
	}

	/**
	 * Deletes objects from the bucket and forgets the items.
	 *
	 * @param string[] $paths         Relative paths.
	 * @param bool     $require_local Only delete objects whose local copy exists with the
	 *                                recorded size (checked under the file lock), e.g.
	 *                                when leaving the bucket after a restore.
	 * @return array<string,string> Errors by path.
	 */
	public function delete_remote( array $paths, $require_local = false ) {
		$candidates = $this->items->get_many( $paths );
		if ( empty( $candidates ) ) {
			return array();
		}

		$blocked = $this->settings->storage_error();
		if ( '' !== $blocked ) {
			return array_fill_keys( array_keys( $candidates ), $blocked );
		}

		$errors = array();
		$locked = array();
		$items  = array();
		try {
			// Lock every file and read its current item; the deletion and the removal of
			// the item run under the same locks.
			foreach ( array_keys( $candidates ) as $path ) {
				if ( ! $this->lock_path( $path ) ) {
					$errors[ $path ] = self::busy_message();
					continue;
				}
				$locked[] = $path;
				$item     = $this->fresh_item( $path );
				if ( ! $item ) {
					continue; // Deleted meanwhile.
				}
				if ( $require_local ) {
					$file = $this->absolute( $path );
					clearstatcache( true, $file );
					if ( ! is_file( $file ) || (int) filesize( $file ) !== (int) $item['filesize'] ) {
						$errors[ $path ] = __( 'The local copy is missing, so the object is kept in the bucket.', 'bm-s3-offload' );
						continue;
					}
				}
				$items[ $path ] = $item;
			}

			// Every item is deleted from the bucket it was uploaded to.
			$by_bucket = array();
			foreach ( $items as $path => $item ) {
				$by_bucket[ $item['bucket'] ][ $item['object_key'] ] = $path;
			}

			foreach ( $by_bucket as $bucket => $keys ) {
				try {
					$failed = $this->client_for( (string) $bucket, array_values( $keys ) )->delete_many( array_keys( $keys ) );
				} catch ( \Exception $e ) {
					foreach ( $keys as $path ) {
						$errors[ $path ] = $e->getMessage();
					}
					continue;
				}
				foreach ( $failed as $key => $message ) {
					if ( isset( $keys[ $key ] ) ) {
						$errors[ $keys[ $key ] ] = $message;
					}
				}
			}

			$deleted = array_values( array_diff( array_keys( $items ), array_keys( $errors ) ) );
			$this->items->delete( $deleted );
			if ( ! empty( $deleted ) ) {
				self::urls_changed( $deleted );
			}
		} finally {
			foreach ( $locked as $path ) {
				$this->unlock_path( $path );
			}
		}
		return $errors;
	}

	/**
	 * Moves objects between the public and the private prefix (server-side copy).
	 *
	 * @param string[] $paths      Relative paths.
	 * @param bool     $is_private Target state.
	 * @return array<string,string> Errors by path.
	 */
	public function set_privacy( array $paths, $is_private ) {
		$errors  = array();
		$blocked = $this->settings->storage_error();
		if ( '' !== $blocked ) {
			return array_fill_keys( array_keys( $this->items->get_many( $paths ) ), $blocked );
		}

		foreach ( array_keys( $this->items->get_many( $paths ) ) as $path ) {
			// Read, copy, record and delete the previous object under the file lock, so
			// that concurrent changes of the same file cannot delete each other's objects.
			if ( ! $this->lock_path( $path ) ) {
				$errors[ $path ] = self::busy_message();
				continue;
			}

			try {
				$item = $this->fresh_item( $path );
				if ( ! $item ) {
					continue; // Deleted meanwhile.
				}
				$key = $this->object_key( $path, (bool) $is_private );
				if ( $key === $item['object_key'] && (bool) $is_private === (bool) $item['is_private'] ) {
					continue;
				}

				$claim = $this->claim_key( $item['bucket'], $key );
				if ( false === $claim ) {
					$errors[ $path ] = __( 'The object is being cleaned up right now. Please try again.', 'bm-s3-offload' );
					continue;
				}

				try {
					$client = $this->client_for( $item['bucket'], $path );
					$client->copy( $item['object_key'], $key, $this->upload_headers( $path, (bool) $is_private ), (int) $item['filesize'] );
				} catch ( \Exception $e ) {
					$this->release_key( $item['bucket'], $key, $claim, false );
					$errors[ $path ] = $e->getMessage();
					continue;
				}

				$old_key            = $item['object_key'];
				$item['object_key'] = $key;
				$item['is_private'] = (bool) $is_private;
				$saved              = $this->items->save( $item );
				$this->release_key( $item['bucket'], $key, $claim, $saved );
				if ( ! $saved ) {
					// The item still points to the old object: keep it and drop the copy.
					if ( $old_key !== $key ) {
						$this->delete_or_queue( $item['bucket'], $key, $path );
					}
					$errors[ $path ] = __( 'The new state could not be recorded in the database.', 'bm-s3-offload' );
					continue;
				}
				self::urls_changed( array( $path ) );

				// A leftover public copy would stay readable: report it and retry hourly.
				if ( $old_key !== $key && $this->owns_key( $old_key ) ) {
					$deleted = $this->delete_or_queue( $item['bucket'], $old_key, $path );
					if ( self::DELETED !== $deleted ) {
						$errors[ $path ] = self::leftover_message( $deleted, $old_key );
					}
				}
			} finally {
				$this->unlock_path( $path );
			}
		}

		return $errors;
	}

	/**
	 * Deletes an object that is no longer referenced, or remembers it for the
	 * hourly cleanup when the deletion fails.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @param string $path   Relative path the object belonged to.
	 * @return string self::DELETED, self::QUEUED or self::QUEUE_FAILED (neither deleted nor queued).
	 */
	public function delete_or_queue( $bucket, $key, $path ) {
		try {
			$this->client_for( (string) $bucket, (string) $path )->delete( $key );
			return self::DELETED;
		} catch ( \Exception $e ) {
			return $this->queue_orphans( array( array( (string) $bucket, (string) $key, (string) $path ) ) ) ? self::QUEUED : self::QUEUE_FAILED;
		}
	}

	/**
	 * Message about a previous copy that is still in the bucket.
	 *
	 * @param string $state Result of delete_or_queue().
	 * @param string $key   Object key.
	 * @return string
	 */
	private static function leftover_message( $state, $key ) {
		if ( self::QUEUE_FAILED === $state ) {
			/* translators: %s: object key */
			return sprintf( __( 'The previous copy could neither be deleted nor be queued for the cleanup (database error). It stays in the bucket until it is deleted manually: %s', 'bm-s3-offload' ), $key );
		}
		return __( 'The previous copy could not be deleted yet. It stays reachable until the hourly cleanup has removed it.', 'bm-s3-offload' );
	}

	/**
	 * Remembers objects that still have to be deleted from the bucket. Objects
	 * that cannot be queued are written to the PHP error log.
	 *
	 * @param array<int,array{0:string,1:string,2:string}> $objects Bucket, key and path of each object.
	 * @return bool Whether all objects were queued.
	 */
	public function queue_orphans( array $objects ) {
		$failed = $this->orphans->add( $objects, $this->current_storage() );
		foreach ( $failed as $object ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- No other channel: the object would otherwise be forgotten silently.
			error_log( sprintf( 'BM S3 Offload: object %s/%s could not be queued for deletion; delete it manually.', $object[0], $object[1] ) );
		}
		return empty( $failed );
	}

	/**
	 * Moves queued orphans to another storage after a confirmed change of
	 * storage (the objects were copied there, leftovers included).
	 *
	 * @param string $from Previous storage identity.
	 * @param string $to   New storage identity.
	 * @return void
	 */
	public function move_orphans( $from, $to ) {
		$this->orphans->move( (string) $from, (string) $to );
	}

	/**
	 * Objects waiting for deletion, in processing order.
	 *
	 * @return array<string,array{bucket:string,key:string,path:string,storage:string,attempts:int}>
	 */
	public function orphans() {
		$list = array();
		foreach ( $this->orphans->all() as $row ) {
			$list[ $row['object_hash'] ] = array(
				'bucket'   => (string) $row['bucket'],
				'key'      => (string) $row['object_key'],
				'path'     => (string) $row['path'],
				'storage'  => (string) $row['storage'],
				'attempts' => (int) $row['attempts'],
			);
		}
		return $list;
	}

	/**
	 * Queue store (for maintenance and tests).
	 *
	 * @return Orphans
	 */
	public function orphan_store() {
		return $this->orphans;
	}

	/**
	 * Number of orphans whose deletion failed repeatedly.
	 *
	 * @return int
	 */
	public function failing_orphans() {
		return $this->orphans->count( self::ORPHANS_FAILING );
	}

	/**
	 * Storage identity of the configured endpoint.
	 *
	 * @return string
	 */
	private function current_storage() {
		return Settings::storage_id( (string) $this->settings->get( 'endpoint' ) );
	}

	/**
	 * Name of the lock that serialises all changes of a file (its item and its
	 * objects): upload, privacy change, deletion, removal and restore of the
	 * local copy and verify --fix.
	 *
	 * @param string $path Relative path.
	 * @return string
	 */
	private static function path_lock( $path ) {
		return 'bms3o_path_lock_' . md5( (string) $path );
	}

	/**
	 * Locks a file for a change. Locks are re-entrant within this instance.
	 * Order: a file lock is always taken before object locks, never after.
	 *
	 * @param string     $path Relative path.
	 * @param float|null $wait Seconds to wait for another operation (null: filter default).
	 * @return bool False if another operation holds the lock.
	 */
	private function lock_path( $path, $wait = null ) {
		if ( isset( $this->path_locks[ $path ] ) ) {
			++$this->path_locks[ $path ]['depth'];
			return true;
		}
		if ( null === $wait ) {
			/**
			 * Filters how long an operation waits for another operation on the same file (seconds).
			 *
			 * @param float $seconds Wait time.
			 */
			$wait = (float) apply_filters( 'bms3o_file_lock_wait', 10.0 );
		}
		$token = Lock::acquire( self::path_lock( $path ), self::PATH_LOCK_TTL, (float) $wait );
		if ( '' === $token ) {
			return false;
		}
		$this->path_locks[ $path ] = array(
			'token' => $token,
			'depth' => 1,
		);
		return true;
	}

	/**
	 * Releases a file lock of lock_path().
	 *
	 * @param string $path Relative path.
	 * @return void
	 */
	private function unlock_path( $path ) {
		if ( ! isset( $this->path_locks[ $path ] ) || --$this->path_locks[ $path ]['depth'] > 0 ) {
			return;
		}
		Lock::release( self::path_lock( $path ), $this->path_locks[ $path ]['token'] );
		unset( $this->path_locks[ $path ] );
	}

	/**
	 * Announces that the URLs of files changed (their object moved or was
	 * deleted), e.g. for caches that contain URLs.
	 *
	 * @param string[] $paths Relative paths; empty if all URLs may have changed.
	 * @return void
	 */
	public static function urls_changed( array $paths ) {
		/**
		 * Fires when the URLs of files changed: their object moved to another key or
		 * was deleted from the bucket, or a setting changed that affects all URLs.
		 *
		 * @param string[] $paths Relative paths; empty if all URLs may have changed.
		 */
		do_action( 'bms3o_urls_changed', $paths );
	}

	/**
	 * Message for a file that another operation is changing.
	 *
	 * @return string
	 */
	private static function busy_message() {
		return __( 'The file is being changed by another operation right now. Please try again.', 'bm-s3-offload' );
	}

	/**
	 * Current item of a path, read from the database (call it while the file is locked).
	 *
	 * @param string $path Relative path.
	 * @return array|null
	 */
	private function fresh_item( $path ) {
		$this->items->forget( array( $path ) );
		return $this->items->get( $path );
	}

	/**
	 * S3 client for requests on behalf of locked files: before every request
	 * the file locks are extended, and the request ends PATH_LOCK_MARGIN
	 * seconds before they expire. Without a held lock the plain client is used.
	 *
	 * @param string          $bucket Bucket.
	 * @param string|string[] $paths  Paths the requests are made for.
	 * @return \BahrMedia\S3Offload\S3\Client
	 */
	private function client_for( $bucket, $paths ) {
		$client = $this->settings->client( (string) $bucket );
		$held   = array_values( array_intersect( (array) $paths, array_keys( $this->path_locks ) ) );
		if ( empty( $held ) ) {
			return $client;
		}
		return $client->with_guard(
			function () use ( $held ) {
				foreach ( $held as $path ) {
					if ( ! isset( $this->path_locks[ $path ] ) || ! Lock::refresh( self::path_lock( $path ), $this->path_locks[ $path ]['token'], self::PATH_LOCK_TTL ) ) {
						return 0.0;
					}
				}
				return microtime( true ) + self::PATH_LOCK_TTL - self::PATH_LOCK_MARGIN;
			}
		);
	}

	/**
	 * Name of the lock that serialises the cleanup of an object with operations
	 * that use its key again.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return string
	 */
	private static function object_lock( $bucket, $key ) {
		return 'bms3o_object_lock_' . Orphans::hash( (string) $bucket, (string) $key );
	}

	/**
	 * Claims a key before an object is written to it. When the key waits for
	 * deletion in the cleanup queue, the entry is marked as claimed under the
	 * object lock (so a cleanup that is deleting the object right now finishes
	 * first) and the cleanup leaves it alone from then on. The lock is released
	 * right away: the claim does not depend on how long the write takes.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @return bool|null True if claimed, null if the key is not queued (nothing to
	 *                   claim), false if the cleanup is busy with it or the claim
	 *                   could not be recorded.
	 */
	private function claim_key( $bucket, $key ) {
		if ( ! $this->orphans->has( $bucket, $key ) ) {
			return null;
		}

		/**
		 * Filters how long an operation waits for the cleanup of the same object (seconds).
		 *
		 * @param float $seconds Wait time.
		 */
		$lock  = self::object_lock( $bucket, $key );
		$token = Lock::acquire( $lock, 30, (float) apply_filters( 'bms3o_object_lock_wait', 10.0 ) );
		if ( '' === $token ) {
			return false;
		}
		$claimed = $this->orphans->claim( $bucket, $key );
		Lock::release( $lock, $token );
		return $claimed;
	}

	/**
	 * Ends a claim of claim_key(): after a successful write the entry is
	 * removed (the key is in use), otherwise it is due for the cleanup again.
	 *
	 * @param string    $bucket  Bucket.
	 * @param string    $key     Object key.
	 * @param bool|null $claim   Result of claim_key().
	 * @param bool      $written Whether the object was written and recorded.
	 * @return void
	 */
	private function release_key( $bucket, $key, $claim, $written ) {
		if ( true !== $claim ) {
			return;
		}
		if ( $written ) {
			$this->orphans->remove( $bucket, $key );
		} else {
			$this->orphans->unclaim( $bucket, $key );
		}
	}

	/**
	 * Deletes queued orphans (hourly cron). Objects that are in use again
	 * (e.g. a file was made public again) are never deleted.
	 *
	 * The objects are processed in chunks. Every object of a chunk is locked
	 * before it is checked; operations that write to a queued key claim it
	 * under the same lock. The delete requests of a chunk end by a deadline
	 * (the S3 client shortens their timeouts and skips retries accordingly) that
	 * lies well before the locks expire, so a lock cannot run out while a delete
	 * request is still on its way.
	 *
	 * @param int $limit Maximum number of objects per run.
	 * @return int Number of objects still waiting.
	 */
	public function process_orphans( $limit = 200 ) {
		if ( '' !== $this->settings->storage_error() || ! $this->settings->is_configured() ) {
			return $this->orphans->count();
		}

		foreach ( array_chunk( $this->orphans->due( $this->current_storage(), $limit ), self::CLEANUP_CHUNK ) as $chunk ) {
			$this->process_orphan_chunk( $chunk );
		}

		return $this->orphans->count();
	}

	/**
	 * Deletes a chunk of queued orphans.
	 *
	 * @param array[] $rows Queue rows.
	 * @return void
	 */
	private function process_orphan_chunk( array $rows ) {
		// The deadline is taken before the first lock, so it lies at least
		// CLEANUP_MARGIN seconds before every lock of the chunk expires.
		$deadline = microtime( true ) + self::CLEANUP_TIME;
		$locked   = array();
		foreach ( $rows as $row ) {
			$token = Lock::acquire( self::object_lock( $row['bucket'], $row['object_key'] ), self::CLEANUP_TIME + self::CLEANUP_MARGIN );
			if ( '' !== $token ) {
				$row['token'] = $token;
				$locked[]     = $row;
			}
		}

		try {
			// Check the current state only now, while the objects are locked.
			$paths = array_column( $locked, 'path' );
			$this->items->forget( $paths );
			$items = $this->items->get_many( $paths );

			$by_bucket = array();
			foreach ( $locked as $row ) {
				$entry = $this->orphans->get( $row['bucket'], $row['object_key'] );
				if ( null === $entry || Orphans::is_claimed( $entry ) ) {
					continue; // Removed meanwhile, or claimed by an operation that writes to the key.
				}
				$item = isset( $items[ $row['path'] ] ) ? $items[ $row['path'] ] : null;
				if ( $item && $item['bucket'] === $row['bucket'] && $item['object_key'] === $row['object_key'] ) {
					$this->orphans->remove( $row['bucket'], $row['object_key'] ); // Referenced again: never delete.
					continue;
				}
				$by_bucket[ $row['bucket'] ][] = $row;
			}

			foreach ( $by_bucket as $bucket => $bucket_rows ) {
				$keys = array_column( $bucket_rows, 'object_key' );
				try {
					$errors = $this->settings->client( (string) $bucket )->with_deadline( $deadline )->delete_many( $keys );
				} catch ( \Exception $e ) {
					$errors = array_fill_keys( $keys, $e->getMessage() );
				}
				foreach ( $bucket_rows as $row ) {
					if ( isset( $errors[ $row['object_key'] ] ) ) {
						$this->orphans->failed( (string) $bucket, $row['object_key'] ); // Due again later, after the others.
						continue;
					}
					$this->orphans->remove( (string) $bucket, $row['object_key'] );
					if ( ! Lock::holds( self::object_lock( $bucket, $row['object_key'] ), $row['token'] ) ) {
						$this->check_conflict( (string) $bucket, $row['object_key'], $row['path'] );
					}
				}
			}
		} finally {
			foreach ( $locked as $row ) {
				Lock::release( self::object_lock( $row['bucket'], $row['object_key'] ), $row['token'] );
			}
		}
	}

	/**
	 * Checks an object whose lock was lost while it was being deleted (the
	 * deadline makes this practically impossible; e.g. the database clock
	 * jumped). If the key is in use again, the object is uploaded again from
	 * the local copy; otherwise the path is reported.
	 *
	 * @param string $bucket Bucket.
	 * @param string $key    Object key.
	 * @param string $path   Relative path.
	 * @return void
	 */
	private function check_conflict( $bucket, $key, $path ) {
		$this->items->forget( array( $path ) );
		$item  = $this->items->get( $path );
		$entry = $this->orphans->get( $bucket, $key );
		if ( $item && $item['bucket'] === $bucket && $item['object_key'] === $key ) {
			// Never wait for a file lock while holding object locks (an operation that
			// holds the file lock may wait for them); a busy file is reported.
			$result = '';
			if ( $this->lock_path( $path, 0.0 ) ) {
				try {
					$result = $this->verify( $item, true );
				} finally {
					$this->unlock_path( $path );
				}
			}
			if ( 'ok' === $result || 'fixed' === $result ) {
				return;
			}
		} elseif ( null === $entry || ! Orphans::is_claimed( $entry ) ) {
			return; // Not in use.
		}

		$conflicts = get_option( self::CONFLICTS_OPTION, array() );
		$conflicts = is_array( $conflicts ) ? $conflicts : array();
		unset( $conflicts[ $path ] );
		$conflicts[ $path ] = time();
		update_option( self::CONFLICTS_OPTION, array_slice( $conflicts, -100, null, true ), false );
	}

	/**
	 * Paths whose object may have been deleted by the cleanup although it was
	 * in use again (see check_conflict()).
	 *
	 * @return string[]
	 */
	public function cleanup_conflicts() {
		$conflicts = get_option( self::CONFLICTS_OPTION, array() );
		return is_array( $conflicts ) ? array_map( 'strval', array_keys( $conflicts ) ) : array();
	}

	/**
	 * Forgets reported conflicts (after a complete "wp bm-s3 verify").
	 *
	 * @return void
	 */
	public function clear_cleanup_conflicts() {
		delete_option( self::CONFLICTS_OPTION );
	}

	/**
	 * Whether a HEAD response describes an object of the expected size.
	 *
	 * @param array|null $head          Lower-cased response headers or null.
	 * @param int        $expected_size Expected size in bytes.
	 * @return bool
	 */
	private static function head_matches( $head, $expected_size ) {
		return is_array( $head ) && isset( $head['content-length'] ) && (int) $head['content-length'] === (int) $expected_size;
	}

	/**
	 * Checks that the object of an item exists in its bucket and optionally
	 * uploads it again from the local copy.
	 *
	 * @param array $item Item row.
	 * @param bool  $fix  Re-upload missing objects when a local copy exists.
	 * @return string 'ok', 'fixed', 'missing' (no local copy) or an error message
	 *                (e.g. a size mismatch).
	 */
	public function verify( array $item, $fix = false ) {
		$path = (string) $item['path'];
		if ( ! $this->lock_path( $path ) ) {
			return self::busy_message();
		}
		try {
			$item = $this->fresh_item( $path );
			if ( ! $item ) {
				return 'ok'; // Deleted meanwhile: nothing to check.
			}
			$client = $this->client_for( $item['bucket'], $path );
			$head   = $client->head( $item['object_key'] );
			if ( self::head_matches( $head, (int) $item['filesize'] ) ) {
				return 'ok';
			}
			$state = null === $head ? 'missing' : sprintf( 'size mismatch (%s instead of %d bytes)', isset( $head['content-length'] ) ? (int) $head['content-length'] : '?', (int) $item['filesize'] );
			$file  = $this->absolute( $item['path'] );
			if ( ! $fix || ! is_file( $file ) ) {
				return $state;
			}
			$blocked = $this->settings->storage_error();
			if ( '' !== $blocked ) {
				return $blocked;
			}
			$client->put_file( $item['object_key'], $file, $this->upload_headers( $item['path'], $item['is_private'] ) );
			$item['filesize']      = (int) filesize( $file );
			$item['local_mtime']   = (int) filemtime( $file );
			$item['local_removed'] = false;
			if ( ! $this->items->save( $item ) ) {
				return 'uploaded, but the database could not be updated';
			}
			return 'fixed';
		} catch ( \Exception $e ) {
			return $e->getMessage();
		} finally {
			$this->unlock_path( $path );
		}
	}

	/*
	 * ------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------
	 */

	/**
	 * Runs trusted code that may sign private objects, e.g. after BuddyBoss
	 * has checked the viewer's access.
	 *
	 * @param callable $callback Callback.
	 * @return mixed Return value of the callback.
	 */
	public function with_private_access( callable $callback ) {
		++$this->private_scope;
		try {
			return $callback();
		} finally {
			--$this->private_scope;
		}
	}

	/**
	 * Whether an item may be delivered in the current context.
	 *
	 * Private objects get signed URLs only inside a trusted scope or for
	 * administrators. Generic code paths (content filters, attachment URLs
	 * requested by anyone) never hand out signed URLs for private files.
	 *
	 * @param array $item Item row.
	 * @return bool
	 */
	public function can_deliver( array $item ) {
		if ( ! $this->settings->is_configured() ) {
			return false;
		}
		if ( ! $this->is_restricted( $item ) || $this->private_scope > 0 ) {
			return true;
		}

		/**
		 * Filters whether the current user may receive signed URLs for private
		 * files outside of an access-checked context. Default: administrators.
		 *
		 * @param bool  $allowed Whether signed URLs may be generated.
		 * @param array $item    Item row.
		 */
		return (bool) apply_filters( 'bms3o_can_access_private', current_user_can( 'manage_options' ), $item );
	}

	/**
	 * Whether an item is private *and* access restricted.
	 *
	 * Restricted files (e.g. BuddyBoss media, protected shop downloads) get
	 * signed URLs only after an access check. Other private files are
	 * delivered to everybody, but only with expiring signed URLs.
	 *
	 * @param array $item Item row.
	 * @return bool
	 */
	public function is_restricted( array $item ) {
		if ( empty( $item['is_private'] ) ) {
			return false;
		}

		/**
		 * Filters whether a private item needs an access check before it is delivered.
		 *
		 * @param bool  $restricted Default true.
		 * @param array $item       Item row.
		 */
		return (bool) apply_filters( 'bms3o_private_is_restricted', true, $item );
	}

	/**
	 * Delivery URL of an item.
	 *
	 * Returns '' if the item may not be delivered in the current context
	 * (private item outside a trusted scope) or the storage is not configured
	 * correctly; callers then keep the local URL.
	 *
	 * @param array $item Item row.
	 * @param array $args {
	 *     Optional. Delivery arguments.
	 *
	 *     @type bool   $sign        Force a signed URL.
	 *     @type string $disposition Content-Disposition for signed URLs (e.g. "attachment").
	 *     @type int    $ttl         Lifetime of signed URLs in seconds.
	 * }
	 * @return string
	 */
	public function url( array $item, array $args = array() ) {
		if ( ! $this->can_deliver( $item ) ) {
			return '';
		}

		$sign = ! empty( $args['sign'] ) || ! empty( $item['is_private'] ) || $this->settings->get( 'sign_all' );

		try {
			if ( $sign ) {
				return $this->signed_url( $item, $args );
			}

			$domain = $this->settings->get( 'delivery_domain' );
			if ( '' !== $domain ) {
				$url = $domain . '/' . S3\Client::encode_key( $item['object_key'] );
			} else {
				$url = $this->settings->client( $this->bucket_of( $item ) )->object_url( $item['object_key'] );
			}
		} catch ( \InvalidArgumentException $e ) {
			return ''; // Invalid endpoint: keep local URLs instead of breaking the page.
		}

		/**
		 * Filters the public URL of an offloaded file.
		 *
		 * @param string $url  URL.
		 * @param array  $item Item row.
		 */
		return (string) apply_filters( 'bms3o_public_url', $url, $item );
	}

	/**
	 * Bucket of an item ('' = configured bucket).
	 *
	 * @param array $item Item row.
	 * @return string
	 */
	private function bucket_of( array $item ) {
		return isset( $item['bucket'] ) ? (string) $item['bucket'] : '';
	}

	/**
	 * Pre-signed URL. The signing time is rounded to a window of half the
	 * lifetime, so URLs stay identical (and browser-cacheable) within that
	 * window and are always valid for at least half of the lifetime.
	 *
	 * @param array $item Item row.
	 * @param array $args See url().
	 * @return string
	 */
	private function signed_url( array $item, array $args ) {
		$ttl    = ! empty( $args['ttl'] ) ? (int) $args['ttl'] : (int) $this->settings->get( 'signed_ttl' );
		$ttl    = max( 60, $ttl );
		$window = max( 30, (int) floor( $ttl / 2 ) );
		$time   = (int) ( floor( time() / $window ) * $window );

		$query = array();
		if ( ! empty( $args['disposition'] ) ) {
			$query['response-content-disposition'] = $args['disposition'] . '; filename="' . str_replace( '"', '', wp_basename( $item['path'] ) ) . '"';
		}

		$cache_key = $this->bucket_of( $item ) . '|' . $item['object_key'] . '|' . $ttl . '|' . $time . '|' . implode( '|', $query );
		if ( ! isset( $this->signed_cache[ $cache_key ] ) ) {
			$url = $this->settings->client( $this->bucket_of( $item ) )->presign( $item['object_key'], $ttl, $query, $time );

			/**
			 * Filters a pre-signed URL.
			 *
			 * @param string $url  URL.
			 * @param array  $item Item row.
			 * @param int    $ttl  Lifetime in seconds.
			 */
			$this->signed_cache[ $cache_key ] = (string) apply_filters( 'bms3o_signed_url', $url, $item, $ttl );
		}
		return $this->signed_cache[ $cache_key ];
	}

	/**
	 * Delivery URL for a relative path, or null if the file is not offloaded.
	 *
	 * @param string $path Relative path.
	 * @param array  $args See url().
	 * @return string|null
	 */
	public function url_for_path( $path, array $args = array() ) {
		$item = $this->items->get( $path );
		if ( ! $item ) {
			return null;
		}
		$url = $this->url( $item, $args );
		return '' === $url ? null : $url;
	}

	/**
	 * Rewrites a local uploads URL to its bucket URL, if offloaded.
	 *
	 * @param string $url Local URL.
	 * @return string
	 */
	public function rewrite_url( $url ) {
		$path = $this->path_from_url( $url );
		if ( '' === $path ) {
			return $url;
		}
		$new = $this->url_for_path( $path );
		return null === $new ? $url : $new;
	}

	/**
	 * Relative path of a local uploads URL ('' if not an uploads URL).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public function path_from_url( $url ) {
		$base  = preg_replace( '#^https?:#i', '', $this->uploads()['baseurl'] ) . '/';
		$plain = preg_replace( '#^https?:#i', '', strtok( (string) $url, '?#' ) );
		if ( 0 !== strpos( $plain, $base ) ) {
			return '';
		}
		return rawurldecode( substr( $plain, strlen( $base ) ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Connection test
	 * ------------------------------------------------------------------
	 */

	/**
	 * Runs a full round trip against the bucket.
	 *
	 * @return array<int,array{step:string,ok:bool,message:string}>
	 */
	public function test_connection() {
		$steps = array();
		$add   = static function ( $step, $ok, $message = '' ) use ( &$steps ) {
			$steps[] = array(
				'step'    => $step,
				'ok'      => (bool) $ok,
				'message' => (string) $message,
			);
			return (bool) $ok;
		};

		if ( ! $this->settings->is_configured() ) {
			$add( __( 'Configuration', 'bm-s3-offload' ), false, __( 'Endpoint, bucket, access key and secret key are required.', 'bm-s3-offload' ) );
			return $steps;
		}

		try {
			$client = $this->settings->client();
		} catch ( \InvalidArgumentException $e ) {
			$add( __( 'Configuration', 'bm-s3-offload' ), false, $e->getMessage() );
			return $steps;
		}

		$name    = '.bms3o-connection-test-' . wp_generate_password( 12, false ) . '.txt';
		$public  = $this->object_key( $name, false );
		$private = $this->object_key( $name, true );
		$content = 'BM S3 Offload connection test ' . gmdate( 'c' );

		try {
			$client->put_string( $public, $content, $this->upload_headers( $name, false ) );
			$client->put_string( $private, $content, $this->upload_headers( $name, true ) );
			$add( __( 'Upload', 'bm-s3-offload' ), true );
		} catch ( S3Exception $e ) {
			$add( __( 'Upload', 'bm-s3-offload' ), false, $e->getMessage() );
			return $steps;
		}

		try {
			$ok = $content === $client->get_string( $public );
			$add( __( 'Download', 'bm-s3-offload' ), $ok, $ok ? '' : __( 'Content mismatch.', 'bm-s3-offload' ) );
		} catch ( S3Exception $e ) {
			$add( __( 'Download', 'bm-s3-offload' ), false, $e->getMessage() );
		}

		$item = array(
			'path'       => $name,
			'object_key' => $public,
			'is_private' => false,
		);

		if ( ! $this->settings->get( 'sign_all' ) ) {
			$url      = $this->url( $item );
			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$add(
				__( 'Public delivery', 'bm-s3-offload' ),
				200 === $code,
				200 === $code ? $url : sprintf(
					/* translators: 1: HTTP status, 2: URL */
					__( 'HTTP %1$s for %2$s – make the object prefix publicly readable (bucket policy) or enable signed URLs for all files.', 'bm-s3-offload' ),
					$code ? $code : ( is_wp_error( $response ) ? $response->get_error_message() : '?' ),
					$url
				)
			);
		}

		$response = wp_remote_get( $client->object_url( $private ), array( 'timeout' => 15 ) );
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$add(
			__( 'Private objects are protected', 'bm-s3-offload' ),
			in_array( $code, array( 401, 403 ), true ),
			in_array( $code, array( 401, 403 ), true ) ? '' : sprintf(
				/* translators: %s: HTTP status */
				__( 'Anonymous request returned HTTP %s. Private files are publicly readable – restrict the bucket policy to the public prefix.', 'bm-s3-offload' ),
				$code ? $code : '?'
			)
		);

		$item['object_key'] = $private;
		$item['is_private'] = true;
		$signed             = $this->with_private_access(
			function () use ( $item ) {
				return $this->url( $item, array( 'ttl' => 120 ) );
			}
		);
		$response           = wp_remote_get( $signed, array( 'timeout' => 15 ) );
		$code               = (int) wp_remote_retrieve_response_code( $response );
		$add( __( 'Signed URLs', 'bm-s3-offload' ), 200 === $code, 200 === $code ? '' : 'HTTP ' . $code );

		$errors = $client->delete_many( array( $public, $private ) );
		$add( __( 'Delete', 'bm-s3-offload' ), empty( $errors ), implode( '; ', $errors ) );

		return $steps;
	}
}
