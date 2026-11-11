<?php
/**
 * Plugin settings.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\S3\Client;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the settings in a single option. Every setting can be overridden by
 * a constant or environment variable named BMS3O_<KEY>, e.g.:
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
			// BuddyBoss.
			'bb_enabled'      => true,
			'bb_private'      => true,
			'bb_delivery'     => 'proxy',
			'bb_avatars'      => true,
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

		foreach ( $defaults as $key => $default ) {
			$value = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
			if ( in_array( $key, self::SECRETS, true ) ) {
				$value = Crypto::decrypt( (string) $value );
			}

			$override = self::override( $key );
			if ( null !== $override ) {
				$value = $override;
			}

			$resolved[ $key ] = self::cast( $value, $default );
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

		foreach ( $defaults as $key => $default ) {
			if ( $this->is_overridden( $key ) ) {
				continue;
			}

			if ( is_bool( $default ) ) {
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
					$stored[ $key ] = Crypto::encrypt( $secret );
				}
				continue;
			}

			$stored[ $key ] = self::sanitize( $key, $input[ $key ], $default );
		}

		$previous = get_option( self::OPTION, array() );
		$previous = is_array( $previous ) ? $previous : array();
		$errors   = $this->validate_prefixes(
			$this->is_overridden( 'object_prefix' ) ? (string) $this->get( 'object_prefix' ) : (string) ( isset( $stored['object_prefix'] ) ? $stored['object_prefix'] : $defaults['object_prefix'] ),
			$this->is_overridden( 'private_prefix' ) ? (string) $this->get( 'private_prefix' ) : (string) ( isset( $stored['private_prefix'] ) ? $stored['private_prefix'] : $defaults['private_prefix'] )
		);
		if ( ! empty( $errors ) ) {
			foreach ( array( 'object_prefix', 'private_prefix' ) as $key ) {
				$stored[ $key ] = isset( $previous[ $key ] ) ? $previous[ $key ] : $defaults[ $key ];
			}
		}

		update_option( self::OPTION, $stored, true );
		$this->flush();
		return $errors;
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
		$this->resolved = array();
		$this->clients  = array();
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
	 * @param mixed $default Default value.
	 * @return mixed
	 */
	private static function cast( $value, $default ) {
		if ( is_bool( $default ) ) {
			if ( is_string( $value ) ) {
				return in_array( strtolower( trim( $value ) ), array( '1', 'true', 'yes', 'on' ), true );
			}
			return (bool) $value;
		}
		if ( is_int( $default ) ) {
			return (int) $value;
		}
		return is_scalar( $value ) ? (string) $value : (string) $default;
	}

	/**
	 * Sanitizes a single value.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $value   Raw value.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	private static function sanitize( $key, $value, $default ) {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		switch ( $key ) {
			case 'endpoint':
			case 'delivery_domain':
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
				return is_int( $default ) ? (int) $value : sanitize_text_field( $value );
		}
	}
}
