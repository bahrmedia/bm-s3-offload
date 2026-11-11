<?php
/**
 * Exception thrown by the S3 client.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\S3;

defined( 'ABSPATH' ) || exit;

/**
 * Carries the HTTP status and the S3 error code (e.g. "NoSuchKey") of a failed request.
 */
class S3Exception extends \RuntimeException {

	/**
	 * S3 error code from the XML error document, if any.
	 *
	 * @var string
	 */
	private $s3_code;

	/**
	 * HTTP status code (0 for transport errors).
	 *
	 * @var int
	 */
	private $http_status;

	/**
	 * Constructor.
	 *
	 * @param string $message     Human readable message.
	 * @param int    $http_status HTTP status code.
	 * @param string $s3_code     S3 error code.
	 */
	public function __construct( $message, $http_status = 0, $s3_code = '' ) {
		parent::__construct( $message, $http_status );
		$this->http_status = (int) $http_status;
		$this->s3_code     = (string) $s3_code;
	}

	/**
	 * S3 error code, e.g. "AccessDenied".
	 *
	 * @return string
	 */
	public function get_s3_code() {
		return $this->s3_code;
	}

	/**
	 * HTTP status code.
	 *
	 * @return int
	 */
	public function get_http_status() {
		return $this->http_status;
	}
}
