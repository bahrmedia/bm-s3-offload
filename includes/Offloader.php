<?php
/**
 * File level offload operations.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\S3\S3Exception;
use BahrMedia\S3Offload\Store\Items;

defined( 'ABSPATH' ) || exit;

/**
 * Knows nothing about WordPress hooks. Works on paths relative to the
 * uploads base directory and keeps the items table in sync.
 */
final class Offloader {

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

		try {
			$client = $this->settings->client();
		} catch ( \InvalidArgumentException $e ) {
			foreach ( $paths as $path ) {
				$result['errors'][ $path ] = $e->getMessage();
			}
			return $result;
		}

		$bucket   = $client->get_bucket();
		$existing = $this->items->get_many( $paths );

		foreach ( $paths as $path ) {
			$file = $this->absolute( $path );
			if ( ! is_file( $file ) ) {
				$result['skipped'][] = $path;
				continue;
			}

			$size  = (int) filesize( $file );
			$mtime = (int) filemtime( $file );
			$key   = $this->object_key( $path, $is_private );
			$item  = isset( $existing[ $path ] ) ? $existing[ $path ] : null;

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

			try {
				$client->put_file( $key, $file, $this->upload_headers( $path, $is_private ) );
			} catch ( S3Exception $e ) {
				$result['errors'][ $path ] = $e->getMessage();
				continue;
			}

			// The key moved within the bucket (e.g. privacy changed): remove the old object,
			// but only if it belongs to this site's key space (never another site's object).
			if ( $item && $item['bucket'] === $bucket && $item['object_key'] !== $key && $this->owns_key( $item['object_key'] ) ) {
				try {
					$client->delete( $item['object_key'] );
				} catch ( S3Exception $e ) {
					unset( $e ); // Leftover objects are harmless.
				}
			}

			$this->items->save(
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
			$result['uploaded'][] = $path;

			/**
			 * Fires after a file has been uploaded to the bucket.
			 *
			 * @param string $path        Relative path.
			 * @param string $key         Object key.
			 * @param string $source_type Source type.
			 * @param int    $source_id   Source ID.
			 */
			do_action( 'bms3o_uploaded', $path, $key, $source_type, $source_id );
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
		$items   = $this->items->get_many( $paths );
		$removed = array();

		foreach ( $items as $path => $item ) {
			$file = $this->absolute( $path );
			if ( ! is_file( $file ) ) {
				if ( ! $item['local_removed'] ) {
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
					$head = $this->settings->client( $item['bucket'] )->head( $item['object_key'] );
				} catch ( \Exception $e ) {
					continue;
				}
				if ( null === $head ) {
					continue;
				}
			}

			wp_delete_file( $file );
			if ( ! is_file( $file ) ) {
				$removed[] = $path;
			}
		}

		$this->items->set_local_removed( $removed, true );
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
		$done  = array();

		foreach ( $items as $path => $item ) {
			$file = $this->absolute( $path );
			if ( is_file( $file ) ) {
				if ( $item['local_removed'] ) {
					$done[] = $path;
				}
				continue;
			}

			try {
				$this->settings->client( $item['bucket'] )->get_to_file( $item['object_key'], $file );
			} catch ( \Exception $e ) {
				$result['errors'][ $path ] = $e->getMessage();
				continue;
			}

			// The restored file is identical to the object: remember its new timestamp.
			$item['local_mtime']   = (int) filemtime( $file );
			$item['local_removed'] = false;
			$this->items->save( $item );

			$done[]                 = $path;
			$result['downloaded'][] = $path;
		}

		$this->items->set_local_removed( $done, false );
		return $result;
	}

	/**
	 * Deletes objects from the bucket and forgets the items.
	 *
	 * @param string[] $paths Relative paths.
	 * @return array<string,string> Errors by path.
	 */
	public function delete_remote( array $paths ) {
		$items = $this->items->get_many( $paths );
		if ( empty( $items ) ) {
			return array();
		}

		// Every item is deleted from the bucket it was uploaded to.
		$by_bucket = array();
		foreach ( $items as $path => $item ) {
			$by_bucket[ $item['bucket'] ][ $item['object_key'] ] = $path;
		}

		$errors = array();
		foreach ( $by_bucket as $bucket => $keys ) {
			try {
				$failed = $this->settings->client( (string) $bucket )->delete_many( array_keys( $keys ) );
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

		$this->items->delete( array_diff( array_keys( $items ), array_keys( $errors ) ) );
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
		$errors = array();

		foreach ( $this->items->get_many( $paths ) as $path => $item ) {
			$key = $this->object_key( $path, (bool) $is_private );
			if ( $key === $item['object_key'] && (bool) $is_private === (bool) $item['is_private'] ) {
				continue;
			}

			try {
				$client = $this->settings->client( $item['bucket'] );
				$client->copy( $item['object_key'], $key, $this->upload_headers( $path, (bool) $is_private ) );
			} catch ( \Exception $e ) {
				$errors[ $path ] = $e->getMessage();
				continue;
			}

			$old_key            = $item['object_key'];
			$item['object_key'] = $key;
			$item['is_private'] = (bool) $is_private;
			$this->items->save( $item );

			if ( $old_key !== $key && $this->owns_key( $old_key ) ) {
				try {
					$client->delete( $old_key );
				} catch ( \Exception $e ) {
					unset( $e ); // A leftover copy is harmless; the item points to the new key.
				}
			}
		}

		return $errors;
	}

	/**
	 * Checks that the object of an item exists in its bucket and optionally
	 * uploads it again from the local copy.
	 *
	 * @param array $item Item row.
	 * @param bool  $fix  Re-upload missing objects when a local copy exists.
	 * @return string 'ok', 'fixed', 'missing' (no local copy) or an error message.
	 */
	public function verify( array $item, $fix = false ) {
		try {
			$client = $this->settings->client( $item['bucket'] );
			if ( null !== $client->head( $item['object_key'] ) ) {
				return 'ok';
			}
			$file = $this->absolute( $item['path'] );
			if ( ! $fix || ! is_file( $file ) ) {
				return 'missing';
			}
			$client->put_file( $item['object_key'], $file, $this->upload_headers( $item['path'], $item['is_private'] ) );
			$item['filesize']      = (int) filesize( $file );
			$item['local_mtime']   = (int) filemtime( $file );
			$item['local_removed'] = false;
			$this->items->save( $item );
			return 'fixed';
		} catch ( \Exception $e ) {
			return $e->getMessage();
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
