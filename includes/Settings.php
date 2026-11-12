<?php
/**
 * Plugin settings.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\S3\Client;
use BahrMedia\S3Offload\Store\Items;
use BahrMedia\S3Offload\Store\Orphans;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the settings in a single option.
 *
 * Every setting can be overridden by a constant or environment variable
 * named BMS3O_<KEY>. Example:
 *
 *     define( 'BMS3O_ACCESS_KEY', '…' );
 *     define( 'BMS3O_SECRET_KEY', '…' );
 *     define( 'BMS3O_REMOVE_LOCAL', true );
 *
 * Overridden settings are read-only in the admin screen.
 */
final class Settings {

	const OPTION = 'bms3o_settings';

	/**
	 * Storage (normalised endpoint) the offloaded files were uploaded to.
	 */
	const STORAGE_OPTION = 'bms3o_storage';

	/**
	 * Settings that are stored encrypted.
	 */
	const SECRETS = array( 'secret_key' );

	/**
	 * Resolved settings per site (request cache).
	 *
	 * @var array<int,array>
	 */
	private $resolved = array();

	/**
	 * S3 clients per site (lazy).
	 *
	 * @var array<int,Client>
	 */
	private $clients = array();

	/**
	 * Result of storage_error() per site (request cache).
	 *
	 * @var array<int,string>
	 */
	private $storage_errors = array();

	/**
	 * Default values. The type of each default defines the type of the setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Storage.
			'provider'        => 'custom',
			'endpoint'        => '',
			'region'          => 'us-east-1',
			'bucket'          => '',
			'access_key'      => '',
			'secret_key'      => '',
			'path_style'      => false,
			'object_prefix'   => 'wp-content/uploads/',
			'private_prefix'  => 'private/',
			'object_acl'      => '',
			'cache_control'   => 'public, max-age=31536000',
			// Behaviour.
			'copy_on_upload'  => true,
			'remove_local'    => false,
			'delete_remote'   => true,
			// Delivery.
			'rewrite_urls'    => true,
			'delivery_domain' => '',
			'sign_all'        => false,
			'signed_ttl'      => 3600,
			'assets_domain'   => '',
			// BuddyBoss.
			'bb_enabled'      => true,
			'bb_private'      => true,
			'bb_delivery'     => 'proxy',
			'bb_avatars'      => true,
			// Integrations.
			'wc_enabled'      => true,
			// Misc.
			'remove_data'     => false,
		);
	}

	/**
	 * All settings with overrides applied.
	 *
	 * @return array
	 */
	public function all() {
		$blog_id = get_current_blog_id();
		if ( isset( $this->resolved[ $blog_id ] ) ) {
			return $this->resolved[ $blog_id ];
		}

		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();
		$resolved = array();

		foreach ( $defaults as $key => $fallback ) {
			$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : $fallback;
			if ( in_array( $key, self::SECRETS, true ) ) {
				$value = Crypto::decrypt( (string) $value );
			}

			$override = self::override( $key );
			if ( null !== $override ) {
				$value = $override;
			}

			$resolved[ $key ] = self::cast( $value, $fallback );
		}

		/**
		 * Filters the resolved settings.
		 *
		 * @param array $resolved Settings.
		 */
		$this->resolved[ $blog_id ] = (array) apply_filters( 'bms3o_settings', $resolved );
		return $this->resolved[ $blog_id ];
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public function get( $key ) {
		$all = $this->all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a setting is defined by constant or environment variable.
	 *
	 * @param string $key Setting key.
	 * @return bool
	 */
	public function is_overridden( $key ) {
		return null !== self::override( $key );
	}

	/**
	 * Sanitizes and saves settings. Overridden settings are ignored.
	 *
	 * @param array $input Unslashed input.
	 * @return string[] Validation errors (invalid prefixes are not saved).
	 */
	public function save( array $input ) {
		$stored   = get_option( self::OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$defaults = self::defaults();

		foreach ( $defaults as $key => $fallback ) {
			if ( $this->is_overridden( $key ) ) {
				continue;
			}

			if ( is_bool( $fallback ) ) {
				$stored[ $key ] = ! empty( $input[ $key ] );
				continue;
			}

			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}

			if ( in_array( $key, self::SECRETS, true ) ) {
				$secret = trim( (string) $input[ $key ] );
				// An empty field keeps the stored secret.
				if ( '' !== $secret ) {
					try {
						$stored[ $key ] = Crypto::encrypt( $secret );
					} catch ( \RuntimeException $e ) {
						// Never store the secret in plain text; keep the previous value.
						$encrypt_error = __( 'The secret key could not be encrypted and was not saved. Define BMS3O_SECRET_KEY in wp-config.php instead.', 'bm-s3-offload' );
					}
				}
				continue;
			}

			$stored[ $key ] = self::sanitize( $key, $input[ $key ], $fallback );
		}

		$previous = get_option( self::OPTION, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$storage  = $this->check_storage_change( $stored, $previous, ! empty( $input['storage_moved'] ) );
		$errors   = $this->validate_prefixes(
			$this->is_overridden( 'object_prefix' ) ? (string) $this->get( 'object_prefix' ) : (string) ( isset( $stored['object_prefix'] ) ? $stored['object_prefix'] : $defaults['object_prefix'] ),
			$this->is_overridden( 'private_prefix' ) ? (string) $this->get( 'private_prefix' ) : (string) ( isset( $stored['private_prefix'] ) ? $stored['private_prefix'] : $defaults['private_prefix'] )
		);
		if ( ! empty( $errors ) ) {
			foreach ( array( 'object_prefix', 'private_prefix' ) as $key ) {
				$stored[ $key ] = isset( $previous[ $key ] ) ? $previous[ $key ] : $defaults[ $key ];
			}
		}

		if ( '' !== $storage ) {
			$errors[] = $storage;
		}
		if ( isset( $encrypt_error ) ) {
			$errors[] = $encrypt_error;
		}

		update_option( self::OPTION, $stored, true );
		$this->flush();
		return $errors;
	}

	/**
	 * Handles a change of the endpoint while saving.
	 *
	 * The items table only knows bucket and key of each object, not the
	 * storage. Switching to another storage while files are offloaded would
	 * make every item point to objects that may not exist there (or belong to
	 * somebody else). The change is therefore only accepted when the user
	 * confirms that all objects have been copied to the new storage (e.g. with
	 * rclone); otherwise the previous endpoint is kept.
	 *
	 * @param array $stored    New settings (changed in place).
	 * @param array $previous  Previously stored settings.
	 * @param bool  $confirmed The user confirmed that the objects were copied.
	 * @return string Error message or ''.
	 */
	private function check_storage_change( array &$stored, array $previous, $confirmed ) {
		$new_endpoint = $this->is_overridden( 'endpoint' ) ? (string) $this->get( 'endpoint' ) : ( isset( $stored['endpoint'] ) ? (string) $stored['endpoint'] : '' );
		$recorded     = (string) get_option( self::STORAGE_OPTION, '' );
		if ( '' === $recorded && isset( $previous['endpoint'] ) ) {
			$recorded = self::storage_id( (string) $previous['endpoint'] );
		}

		$new = self::storage_id( $new_endpoint );
		if ( '' === $new || $new === $recorded ) {
			return '';
		}
		if ( '' === $recorded || $confirmed || ! self::has_items() ) {
			update_option( self::STORAGE_OPTION, $new, true );
			if ( '' !== $recorded ) {
				self::storage_changed( $recorded, $new );
			}
			return '';
		}
		if ( $this->is_overridden( 'endpoint' ) ) {
			return ''; // Cannot be reverted here; storage_error() pauses all bucket operations.
		}

		$stored['endpoint'] = isset( $previous['endpoint'] ) ? $previous['endpoint'] : '';
		return __( 'The endpoint was not changed because files are offloaded to the current storage. Copy all objects to the new storage first (e.g. with rclone), then change the endpoint again and confirm the copy.', 'bm-s3-offload' );
	}

	/**
	 * Normalised storage identity of an endpoint (scheme, host and port).
	 *
	 * @param string $endpoint Endpoint URL.
	 * @return string
	 */
	public static function storage_id( $endpoint ) {
		$parts = wp_parse_url( trim( (string) $endpoint ) );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$scheme = isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ? 'http' : 'https';
		$port   = isset( $parts['port'] ) && ( 'http' === $scheme ? 80 : 443 ) !== (int) $parts['port'] ? ':' . (int) $parts['port'] : '';
		return $scheme . '://' . strtolower( $parts['host'] ) . $port;
	}

	/**
	 * Whether anything is bound to the current storage: offloaded files or
	 * objects that still have to be deleted there.
	 *
	 * @return bool
	 */
	private static function has_items() {
		return ( new Orphans() )->count() > 0 || ( new Items() )->count() > 0;
	}

	/**
	 * Announces a confirmed change of storage.
	 *
	 * @param string $from Previous storage identity.
	 * @param string $to   New storage identity.
	 * @return void
	 */
	private static function storage_changed( $from, $to ) {
		/**
		 * Fires after the storage has changed (confirmed, or nothing was bound to the old one).
		 *
		 * @param string $from Previous storage identity.
		 * @param string $to   New storage identity.
		 */
		do_action( 'bms3o_storage_changed', $from, $to );
	}

	/**
	 * Error message when the configured endpoint differs from the storage the
	 * files were offloaded to, '' otherwise. While the storage differs, the
	 * plugin does not upload, delete or move objects and keeps local copies.
	 *
	 * This happens when the endpoint is changed by a constant or environment
	 * variable; the admin screen refuses such a change without confirmation.
	 *
	 * @return string
	 */
	public function storage_error() {
		$blog_id = get_current_blog_id();
		if ( isset( $this->storage_errors[ $blog_id ] ) ) {
			return $this->storage_errors[ $blog_id ];
		}

		$current  = self::storage_id( (string) $this->get( 'endpoint' ) );
		$recorded = (string) get_option( self::STORAGE_OPTION, '' );
		$error    = '';
		if ( '' !== $current && $current !== $recorded ) {
			if ( '' === $recorded || ! self::has_items() ) {
				update_option( self::STORAGE_OPTION, $current, true ); // First use (or upgrade from 1.0.0).
			} else {
				$error = sprintf(
					/* translators: 1: previous storage, 2: configured storage */
					__( 'The files were offloaded to %1$s, but the endpoint is now %2$s. Bucket operations are paused. Copy all objects to the new storage, then confirm it with "wp bm-s3 storage confirm" or in the settings.', 'bm-s3-offload' ),
					$recorded,
					$current
				);
			}
		}

		$this->storage_errors[ $blog_id ] = $error;
		return $error;
	}

	/**
	 * Confirms that all objects have been copied to the configured storage.
	 *
	 * @return void
	 */
	public function confirm_storage() {
		$from = (string) get_option( self::STORAGE_OPTION, '' );
		$to   = self::storage_id( (string) $this->get( 'endpoint' ) );
		update_option( self::STORAGE_OPTION, $to, true );
		if ( '' !== $from && $from !== $to ) {
			self::storage_changed( $from, $to );
		}
		$this->flush();
	}

	/**
	 * Checks that private objects can never fall under the public prefix.
	 *
	 * Public keys:  {object_prefix}{path}
	 * Private keys: {private_prefix}{object_prefix}{path}
	 *
	 * @param string $object_prefix  Object prefix.
	 * @param string $private_prefix Private prefix.
	 * @return string[] Errors.
	 */
	public function validate_prefixes( $object_prefix, $private_prefix ) {
		$errors = array();
		if ( '' === $object_prefix ) {
			$errors[] = __( 'The object prefix must not be empty, otherwise a public bucket policy would also cover private files.', 'bm-s3-offload' );
		}
		if ( '' === $private_prefix ) {
			$errors[] = __( 'The private prefix must not be empty, otherwise private files would get public keys.', 'bm-s3-offload' );
		}
		if ( '' !== $object_prefix && '' !== $private_prefix && 0 === strpos( $private_prefix, $object_prefix ) ) {
			$errors[] = __( 'The private prefix must not start with the object prefix, otherwise private files would be inside the public area.', 'bm-s3-offload' );
		}
		return $errors;
	}

	/**
	 * Whether a stored secret exists but cannot be decrypted (e.g. after the
	 * WordPress salts were changed).
	 *
	 * @return bool
	 */
	public function secret_unreadable() {
		if ( $this->is_overridden( 'secret_key' ) ) {
			return false;
		}
		$stored = get_option( self::OPTION, array() );
		$value  = is_array( $stored ) && isset( $stored['secret_key'] ) ? (string) $stored['secret_key'] : '';
		return 0 === strpos( $value, Crypto::PREFIX ) && '' === Crypto::decrypt( $value );
	}

	/**
	 * Clears request caches (after saving).
	 *
	 * @return void
	 */
	public function flush() {
		$this->resolved       = array();
		$this->clients        = array();
		$this->storage_errors = array();
	}

	/**
	 * Whether all settings required to talk to the bucket are present.
	 *
	 * @return bool
	 */
	public function is_configured() {
		$all = $this->all();
		return '' !== $all['endpoint'] && '' !== $all['bucket'] && '' !== $all['access_key'] && '' !== $all['secret_key'];
	}

	/**
	 * S3 client for the configured bucket or another bucket on the same endpoint.
	 *
	 * @param string $bucket Bucket name ('' = configured bucket).
	 * @return Client
	 * @throws \InvalidArgumentException When the endpoint is invalid.
	 */
	public function client( $bucket = '' ) {
		$all    = $this->all();
		$bucket = '' === (string) $bucket ? $all['bucket'] : (string) $bucket;
		$cache  = get_current_blog_id() . '|' . $bucket;
		if ( ! isset( $this->clients[ $cache ] ) ) {
			$this->clients[ $cache ] = new Client(
				array(
					'endpoint'   => $all['endpoint'],
					'region'     => $all['region'],
					'bucket'     => $bucket,
					'access_key' => $all['access_key'],
					'secret_key' => $all['secret_key'],
					'path_style' => $all['path_style'],
				)
			);
		}
		return $this->clients[ $cache ];
	}

	/**
	 * Constant / environment override for a key.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	private static function override( $key ) {
		$name = 'BMS3O_' . strtoupper( $key );
		if ( defined( $name ) ) {
			return constant( $name );
		}
		$env = getenv( $name );
		return false === $env ? null : $env;
	}

	/**
	 * Casts a value to the type of its default.
	 *
	 * @param mixed $value   Value.
	 * @param mixed $fallback Default value (defines the type).
	 * @return mixed
	 */
	private static function cast( $value, $fallback ) {
		if ( is_bool( $fallback ) ) {
			if ( is_string( $value ) ) {
				return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
			}
			return (bool) $value;
		}
		if ( is_int( $fallback ) ) {
			return (int) $value;
		}
		return is_scalar( $value ) ? (string) $value : (string) $fallback;
	}

	/**
	 * Sanitizes a single value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $fallback Default value.
	 * @return mixed
	 */
	private static function sanitize( $key, $value, $fallback ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		switch ( $key ) {
			case 'endpoint':
			case 'delivery_domain':
			case 'assets_domain':
				return untrailingslashit( esc_url_raw( $value, array( 'http', 'https' ) ) );

			case 'bucket':
				return strtolower( preg_replace( '/[^A-Za-z0-9.\-_]/', '', $value ) );

			case 'object_prefix':
			case 'private_prefix':
				$value = trim( preg_replace( '#/+#', '/', str_replace( '\\', '/', $value ) ), '/' );
				$value = implode( '/', array_filter( array_map( 'sanitize_file_name', explode( '/', $value ) ), 'strlen' ) );
				return '' === $value ? '' : $value . '/';

			case 'signed_ttl':
				return max( 60, min( 604800, (int) $value ) );

			case 'object_acl':
				return in_array( $value, array( '', 'public-read' ), true ) ? $value : '';

			case 'bb_delivery':
				return in_array( $value, array( 'proxy', 'direct' ), true ) ? $value : 'proxy';

			case 'provider':
				return array_key_exists( $value, Providers::all() ) ? $value : 'custom';

			default:
				return is_int( $fallback ) ? (int) $value : sanitize_text_field( $value );
		}
	}
}
