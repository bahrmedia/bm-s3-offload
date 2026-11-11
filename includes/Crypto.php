<?php
/**
 * Symmetric encryption for secrets stored in the database.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Encrypts values with libsodium (bundled with PHP >= 7.2 and polyfilled by
 * WordPress core). The key is derived from the site's authentication salt, so
 * a database dump alone does not reveal the S3 secret.
 *
 * Defining the secret as constant in wp-config.php remains the most secure option.
 */
final class Crypto {

	const PREFIX = 'bms3o:v1:';

	/**
	 * Encrypts a value. Empty values stay empty.
	 *
	 * @param string $plain Plain text.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! self::available() ) {
			return $plain;
		}

		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		} catch ( \Exception $e ) {
			return $plain;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary-safe storage.
		return self::PREFIX . base64_encode( $nonce . $cipher );
	}

	/**
	 * Decrypts a value produced by encrypt(). Unencrypted values are returned unchanged.
	 *
	 * @param string $stored Stored value.
	 * @return string Plain text, or '' if decryption fails (e.g. after a salt change).
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::PREFIX ) ) {
			return $stored;
		}
		if ( ! self::available() ) {
			return '';
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Binary-safe storage.
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}

		try {
			$plain = sodium_crypto_secretbox_open(
				substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
				self::key()
			);
		} catch ( \Exception $e ) {
			return '';
		}

		return false === $plain ? '' : $plain;
	}

	/**
	 * Whether libsodium is available.
	 *
	 * @return bool
	 */
	private static function available() {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_secretbox_open' );
	}

	/**
	 * Derives the 32 byte key from the site salts.
	 *
	 * @return string
	 */
	private static function key() {
		return hash( 'sha256', 'bm-s3-offload|' . wp_salt( 'auth' ) . '|' . wp_salt( 'secure_auth' ), true );
	}
}
