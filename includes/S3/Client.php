<?php
/**
 * Minimal, dependency-free S3 client (AWS Signature Version 4).
 *
 * Implements exactly the operations the plugin needs on top of the
 * WordPress HTTP API. Works with every S3-compatible provider that
 * supports SigV4 (AWS, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean
 * Spaces, Hetzner, IONOS, Scaleway, MinIO, Garage, Ceph …).
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\S3;

defined( 'ABSPATH' ) || exit;

/**
 * S3 client.
 */
class Client {

	const ALGORITHM = 'AWS4-HMAC-SHA256';

	/**
	 * Files larger than this are uploaded with multipart upload.
	 */
	const MULTIPART_THRESHOLD = 16777216; // 16 MiB.

	/**
	 * Size of a single multipart chunk (S3 minimum is 5 MiB).
	 */
	const PART_SIZE = 8388608; // 8 MiB.

	/**
	 * Objects larger than this are copied with a multipart copy (S3 limit for CopyObject).
	 */
	const COPY_THRESHOLD = 5368709120; // 5 GiB.

	/**
	 * Part size of a multipart copy (adapted so that at most 10,000 parts are needed).
	 */
	const COPY_PART_SIZE = 536870912; // 512 MiB.

	/**
	 * HTTP status codes that are retried (throttling and temporary server errors).
	 */
	const RETRY_STATUS = array( 429, 500, 502, 503, 504 );

	/**
	 * Scheme of the endpoint ("https" or "http").
	 *
	 * @var string
	 */
	private $scheme;

	/**
	 * Host (incl. non-default port) of the endpoint.
	 *
	 * @var string
	 */
	private $host;

	/**
	 * Region used for signing.
	 *
	 * @var string
	 */
	private $region;

	/**
	 * Bucket name.
	 *
	 * @var string
	 */
	private $bucket;

	/**
	 * Access key ID.
	 *
	 * @var string
	 */
	private $access_key;

	/**
	 * Secret access key.
	 *
	 * @var string
	 */
	private $secret_key;

	/**
	 * Whether to use path-style addressing (endpoint/bucket/key).
	 *
	 * @var bool
	 */
	private $path_style;

	/**
	 * HTTP timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Number of retries for temporary failures.
	 *
	 * @var int
	 */
	private $retries;

	/**
	 * Point in time (microtime) after which no request may run, 0 for none.
	 *
	 * @var float
	 */
	private $deadline = 0.0;

	/**
	 * Called before every request; returns the deadline of that request
	 * (microtime), or 0 to stop (see with_guard()).
	 *
	 * @var callable|null
	 */
	private $guard = null;

	/**
	 * Size above which copies use a multipart copy.
	 *
	 * @var int
	 */
	private $copy_threshold;

	/**
	 * Minimum part size of a multipart copy.
	 *
	 * @var int
	 */
	private $copy_part_size;

	/**
	 * Constructor.
	 *
	 * @param array $config {
	 *     Client configuration.
	 *
	 *     @type string $endpoint   Endpoint URL, e.g. "https://s3.eu-central-1.amazonaws.com".
	 *     @type string $region     Signing region, e.g. "eu-central-1" (R2: "auto").
	 *     @type string $bucket     Bucket name.
	 *     @type string $access_key Access key ID.
	 *     @type string $secret_key Secret access key.
	 *     @type bool   $path_style Use path-style URLs.
	 *     @type int    $timeout    HTTP timeout in seconds.
	 *     @type int    $retries    Retries for network errors, throttling and 5xx responses (default 2).
	 *     @type int    $copy_threshold Size in bytes above which copies use a multipart copy (default 5 GiB).
	 *     @type int    $copy_part_size Part size of a multipart copy (default 512 MiB, minimum 5 MiB).
	 * }
	 * @throws \InvalidArgumentException When the endpoint is not a valid URL.
	 */
	public function __construct( array $config ) {
		$parts = wp_parse_url( isset( $config['endpoint'] ) ? trim( (string) $config['endpoint'] ) : '' );
		if ( empty( $parts['host'] ) ) {
			throw new \InvalidArgumentException( 'Invalid S3 endpoint.' );
		}

		$this->scheme = ( isset( $parts['scheme'] ) && 'http' === strtolower( $parts['scheme'] ) ) ? 'http' : 'https';
		$this->host   = strtolower( $parts['host'] );
		if ( ! empty( $parts['port'] ) && ! ( 'https' === $this->scheme && 443 === (int) $parts['port'] ) && ! ( 'http' === $this->scheme && 80 === (int) $parts['port'] ) ) {
			$this->host .= ':' . (int) $parts['port'];
		}

		$this->region     = ! empty( $config['region'] ) ? (string) $config['region'] : 'us-east-1';
		$this->bucket     = isset( $config['bucket'] ) ? (string) $config['bucket'] : '';
		$this->access_key = isset( $config['access_key'] ) ? (string) $config['access_key'] : '';
		$this->secret_key = isset( $config['secret_key'] ) ? (string) $config['secret_key'] : '';
		$this->path_style = ! empty( $config['path_style'] );
		$this->timeout    = ! empty( $config['timeout'] ) ? (int) $config['timeout'] : 300;
		$this->retries    = isset( $config['retries'] ) ? max( 0, (int) $config['retries'] ) : 2;

		$this->copy_threshold = ! empty( $config['copy_threshold'] ) ? (int) $config['copy_threshold'] : self::COPY_THRESHOLD;
		$this->copy_part_size = max( 5242880, ! empty( $config['copy_part_size'] ) ? (int) $config['copy_part_size'] : self::COPY_PART_SIZE );
	}

	/**
	 * Bucket name.
	 *
	 * @return string
	 */
	public function get_bucket() {
		return $this->bucket;
	}

	/**
	 * Public (unsigned) URL of an object.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public function object_url( $key ) {
		list( $host, $path ) = $this->address( $key );
		return $this->scheme . '://' . $host . $path;
	}

	/**
	 * Uploads a local file. Large files are uploaded in parts.
	 *
	 * @param string $key     Object key.
	 * @param string $file    Absolute local path.
	 * @param array  $headers Extra headers (Content-Type, Cache-Control, x-amz-acl …).
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function put_file( $key, $file, array $headers = array() ) {
		if ( ! is_readable( $file ) ) {
			throw new S3Exception( sprintf( 'File not readable: %s', $file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}

		$size = filesize( $file );
		if ( false !== $size && $size > self::MULTIPART_THRESHOLD ) {
			$this->multipart_upload( $key, $file, $headers );
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local file, binary safe read.
		$body = file_get_contents( $file );
		if ( false === $body ) {
			throw new S3Exception( sprintf( 'File not readable: %s', $file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}
		$this->put_string( $key, $body, $headers );
	}

	/**
	 * Uploads a string.
	 *
	 * @param string $key     Object key.
	 * @param string $body    Content.
	 * @param array  $headers Extra headers.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function put_string( $key, $body, array $headers = array() ) {
		if ( ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/octet-stream';
		}
		$this->request( 'PUT', $key, array(), $headers, $body );
	}

	/**
	 * Fetches object metadata.
	 *
	 * @param string $key Object key.
	 * @return array|null Lower-cased response headers, or null if the object does not exist.
	 * @throws S3Exception On failure other than 404.
	 */
	public function head( $key ) {
		try {
			$response = $this->request( 'HEAD', $key );
		} catch ( S3Exception $e ) {
			if ( 404 === $e->get_http_status() ) {
				return null;
			}
			throw $e;
		}
		return $response['headers'];
	}

	/**
	 * Downloads an object into a local file (streamed, no memory overhead).
	 *
	 * The file is written to a temporary name first and moved into place
	 * only after a successful download.
	 *
	 * @param string $key         Object key.
	 * @param string $destination Absolute local path.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function get_to_file( $key, $destination ) {
		$dir = dirname( $destination );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new S3Exception( sprintf( 'Cannot create directory: %s', $dir ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}

		$tmp = $destination . '.bms3o-' . wp_generate_password( 8, false ) . '.part';
		try {
			$this->request( 'GET', $key, array(), array(), '', $tmp );
		} catch ( S3Exception $e ) {
			wp_delete_file( $tmp );
			throw $e;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Atomic move within the same directory.
		if ( ! rename( $tmp, $destination ) ) {
			wp_delete_file( $tmp );
			throw new S3Exception( sprintf( 'Cannot write file: %s', $destination ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}
	}

	/**
	 * Returns an object's content as string. Intended for small objects only.
	 *
	 * @param string $key Object key.
	 * @return string
	 * @throws S3Exception On failure.
	 */
	public function get_string( $key ) {
		$response = $this->request( 'GET', $key );
		return $response['body'];
	}

	/**
	 * Copies an object within the bucket (server side, no download/upload).
	 *
	 * Objects larger than 5 GiB are copied with a multipart copy when their size
	 * is passed.
	 *
	 * @param string   $source_key Source key.
	 * @param string   $target_key Target key.
	 * @param array    $headers    Headers of the new object. When given, they replace the
	 *                             metadata (Content-Type, Cache-Control, x-amz-acl …).
	 * @param int|null $size       Size of the source object in bytes, if known.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function copy( $source_key, $target_key, array $headers = array(), $size = null ) {
		if ( null !== $size && (int) $size > $this->copy_threshold ) {
			$this->multipart_copy( $source_key, $target_key, $headers, (int) $size );
			return;
		}

		$directive                           = empty( $headers ) ? 'COPY' : 'REPLACE';
		$headers['x-amz-copy-source']        = '/' . rawurlencode( $this->bucket ) . '/' . self::encode_key( ltrim( (string) $source_key, '/' ) );
		$headers['x-amz-metadata-directive'] = $directive;

		$response = $this->request( 'PUT', $target_key, array(), $headers );

		// S3 may answer 200 OK with an error document for this operation.
		$doc = self::parse_xml( $response['body'] );
		if ( $doc && 'Error' === $doc->getName() ) {
			throw new S3Exception( self::text( $doc, 'Message' ), 500, self::text( $doc, 'Code' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}
	}

	/**
	 * Deletes an object. Deleting a missing object is not an error.
	 *
	 * @param string $key Object key.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function delete( $key ) {
		try {
			$this->request( 'DELETE', $key );
		} catch ( S3Exception $e ) {
			if ( 404 !== $e->get_http_status() ) {
				throw $e;
			}
		}
	}

	/**
	 * Returns a copy of the client whose requests all end by a point in time:
	 * the timeout of every request is shortened to the time left, retries that
	 * would end later are skipped and no request starts after it. Callers that
	 * hold a lock while they wait for the requests can rely on this bound.
	 *
	 * @param float $deadline Point in time (microtime(true)).
	 * @return Client
	 */
	public function with_deadline( $deadline ) {
		$client           = clone $this;
		$client->deadline = (float) $deadline;
		return $client;
	}

	/**
	 * Returns a copy of the client that calls a guard before every single
	 * request, also before every part of a multipart transfer and before every
	 * retry. The guard extends the caller's lock and returns the point in time
	 * (microtime) by which the request has to end; the request's timeout is
	 * shortened accordingly. If the guard returns a time that has passed (e.g.
	 * 0 because the lock was lost), no further request is sent.
	 *
	 * @param callable $guard Returns the deadline of the next request.
	 * @return Client
	 */
	public function with_guard( callable $guard ) {
		$client        = clone $this;
		$client->guard = $guard;
		return $client;
	}

	/**
	 * Deletes several objects with as few requests as possible.
	 *
	 * Falls back to single deletes for providers that do not implement
	 * the DeleteObjects API.
	 *
	 * @param string[] $keys Object keys.
	 * @return array<string,string> Map of key => error message for keys that could not be deleted.
	 */
	public function delete_many( array $keys ) {
		$keys   = array_values( array_unique( array_filter( array_map( 'strval', $keys ), 'strlen' ) ) );
		$errors = array();

		foreach ( array_chunk( $keys, 1000 ) as $chunk ) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
			foreach ( $chunk as $key ) {
				$xml .= '<Object><Key>' . htmlspecialchars( $key, ENT_XML1 | ENT_QUOTES, 'UTF-8' ) . '</Key></Object>';
			}
			$xml .= '</Delete>';

			try {
				$response = $this->request(
					'POST',
					'',
					array( 'delete' => '' ),
					array(
						'Content-Type' => 'application/xml',
						'Content-MD5'  => base64_encode( md5( $xml, true ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Required by the S3 API.
					),
					$xml
				);
			} catch ( S3Exception $e ) {
				if ( in_array( $e->get_http_status(), array( 400, 405, 501 ), true ) || 'NotImplemented' === $e->get_s3_code() ) {
					foreach ( $chunk as $key ) {
						try {
							$this->delete( $key );
						} catch ( S3Exception $single ) {
							$errors[ $key ] = $single->getMessage();
						}
					}
					continue;
				}
				foreach ( $chunk as $key ) {
					$errors[ $key ] = $e->getMessage();
				}
				continue;
			}

			$doc = self::parse_xml( $response['body'] );
			foreach ( self::nodes( $doc, 'Error' ) as $error ) {
				$errors[ self::text( $error, 'Key' ) ] = self::text( $error, 'Code' ) . ': ' . self::text( $error, 'Message' );
			}
		}

		return $errors;
	}

	/**
	 * Lists objects (ListObjectsV2).
	 *
	 * @param string $prefix             Key prefix.
	 * @param int    $max_keys           Maximum number of keys (<= 1000).
	 * @param string $continuation_token Token from a previous call.
	 * @return array{objects: array<int,array{key:string,size:int}>, next: string}
	 * @throws S3Exception On failure.
	 */
	public function list_objects( $prefix = '', $max_keys = 1000, $continuation_token = '' ) {
		$query = array(
			'list-type' => '2',
			'max-keys'  => (string) max( 1, min( 1000, (int) $max_keys ) ),
		);
		if ( '' !== $prefix ) {
			$query['prefix'] = $prefix;
		}
		if ( '' !== $continuation_token ) {
			$query['continuation-token'] = $continuation_token;
		}

		$response = $this->request( 'GET', '', $query );
		$doc      = self::parse_xml( $response['body'] );
		$objects  = array();
		$next     = '';

		if ( $doc ) {
			foreach ( self::nodes( $doc, 'Contents' ) as $item ) {
				$objects[] = array(
					'key'  => self::text( $item, 'Key' ),
					'size' => (int) self::text( $item, 'Size' ),
				);
			}
			if ( 'true' === self::text( $doc, 'IsTruncated' ) ) {
				$next = self::text( $doc, 'NextContinuationToken' );
			}
		}

		return array(
			'objects' => $objects,
			'next'    => $next,
		);
	}

	/**
	 * Creates a pre-signed GET URL.
	 *
	 * @param string   $key       Object key.
	 * @param int      $ttl       Lifetime in seconds (max. 7 days).
	 * @param array    $query     Additional query parameters (e.g. response-content-disposition).
	 * @param int|null $timestamp Signing time; pass a rounded value to get cacheable URLs.
	 * @return string
	 */
	public function presign( $key, $ttl = 3600, array $query = array(), $timestamp = null ) {
		$time = null === $timestamp ? time() : (int) $timestamp;
		$ttl  = max( 1, min( 604800, (int) $ttl ) );

		list( $host, $path ) = $this->address( $key );

		$amz_date = gmdate( 'Ymd\THis\Z', $time );
		$scope    = $this->scope( $time );

		$query['X-Amz-Algorithm']     = self::ALGORITHM;
		$query['X-Amz-Credential']    = $this->access_key . '/' . $scope;
		$query['X-Amz-Date']          = $amz_date;
		$query['X-Amz-Expires']       = (string) $ttl;
		$query['X-Amz-SignedHeaders'] = 'host';

		$canonical_query = self::canonical_query( $query );
		$canonical       = implode(
			"\n",
			array(
				'GET',
				$path,
				$canonical_query,
				'host:' . $host . "\n",
				'host',
				'UNSIGNED-PAYLOAD',
			)
		);

		$signature = $this->signature( $canonical, $amz_date, $time );

		return $this->scheme . '://' . $host . $path . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	/**
	 * Checks that the bucket is reachable with the configured credentials.
	 *
	 * @return void
	 * @throws S3Exception On failure.
	 */
	public function head_bucket() {
		$this->request( 'HEAD', '' );
	}

	/**
	 * Multipart upload, streamed part by part.
	 *
	 * @param string $key     Object key.
	 * @param string $file    Local file.
	 * @param array  $headers Headers for the object.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	private function multipart_upload( $key, $file, array $headers ) {
		if ( ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/octet-stream';
		}

		$response  = $this->request( 'POST', $key, array( 'uploads' => '' ), $headers );
		$doc       = self::parse_xml( $response['body'] );
		$upload_id = self::text( $doc, 'UploadId' );
		if ( '' === $upload_id ) {
			throw new S3Exception( 'Multipart upload could not be initiated.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming read of a local file.
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			$this->abort_multipart( $key, $upload_id );
			throw new S3Exception( sprintf( 'File not readable: %s', $file ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}

		$parts = array();
		try {
			$number = 1;
			while ( ! feof( $handle ) ) {
				$chunk = self::read_chunk( $handle, self::PART_SIZE );
				if ( '' === $chunk ) {
					break;
				}
				$part_response = $this->request(
					'PUT',
					$key,
					array(
						'partNumber' => (string) $number,
						'uploadId'   => $upload_id,
					),
					array( 'Content-Type' => 'application/octet-stream' ),
					$chunk
				);
				$etag          = isset( $part_response['headers']['etag'] ) ? (string) $part_response['headers']['etag'] : '';
				if ( '' === $etag ) {
					throw new S3Exception( 'Missing ETag for uploaded part.' );
				}
				$parts[ $number ] = $etag;
				++$number;
			}
		} catch ( S3Exception $e ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->abort_multipart( $key, $upload_id );
			throw $e;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$this->complete_multipart( $key, $upload_id, $parts );
	}

	/**
	 * Copies a large object in parts (UploadPartCopy).
	 *
	 * @param string $source_key Source key.
	 * @param string $target_key Target key.
	 * @param array  $headers    Headers of the new object.
	 * @param int    $size       Size of the source object in bytes.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	private function multipart_copy( $source_key, $target_key, array $headers, $size ) {
		if ( ! isset( $headers['Content-Type'] ) ) {
			$headers['Content-Type'] = 'application/octet-stream';
		}

		$response  = $this->request( 'POST', $target_key, array( 'uploads' => '' ), $headers );
		$upload_id = self::text( self::parse_xml( $response['body'] ), 'UploadId' );
		if ( '' === $upload_id ) {
			throw new S3Exception( 'Multipart copy could not be initiated.' );
		}

		$source    = '/' . rawurlencode( $this->bucket ) . '/' . self::encode_key( ltrim( (string) $source_key, '/' ) );
		$part_size = max( $this->copy_part_size, (int) ceil( $size / 10000 ) );
		$parts     = array();

		try {
			for ( $number = 1, $start = 0; $start < $size; ++$number, $start += $part_size ) {
				$end      = min( $size, $start + $part_size ) - 1;
				$response = $this->request(
					'PUT',
					$target_key,
					array(
						'partNumber' => (string) $number,
						'uploadId'   => $upload_id,
					),
					array(
						'x-amz-copy-source'       => $source,
						'x-amz-copy-source-range' => 'bytes=' . $start . '-' . $end,
					)
				);
				$doc      = self::parse_xml( $response['body'] );
				if ( $doc && 'Error' === $doc->getName() ) {
					throw new S3Exception( self::text( $doc, 'Message' ), 500, self::text( $doc, 'Code' ) );
				}
				$etag = self::text( $doc, 'ETag' );
				if ( '' === $etag ) {
					throw new S3Exception( 'Missing ETag for copied part.' );
				}
				$parts[ $number ] = $etag;
			}
		} catch ( S3Exception $e ) {
			$this->abort_multipart( $target_key, $upload_id );
			throw $e;
		}

		$this->complete_multipart( $target_key, $upload_id, $parts );
	}

	/**
	 * Completes a multipart upload (aborts it on failure).
	 *
	 * @param string            $key       Object key.
	 * @param string            $upload_id Upload ID.
	 * @param array<int,string> $parts     ETags by part number.
	 * @return void
	 * @throws S3Exception On failure.
	 */
	private function complete_multipart( $key, $upload_id, array $parts ) {
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
		foreach ( $parts as $number => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $number . '</PartNumber><ETag>' . htmlspecialchars( $etag, ENT_XML1, 'UTF-8' ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		try {
			$response = $this->request( 'POST', $key, array( 'uploadId' => $upload_id ), array( 'Content-Type' => 'application/xml' ), $xml );
			// S3 may answer 200 OK with an error document for this operation.
			$doc = self::parse_xml( $response['body'] );
			if ( $doc && 'Error' === $doc->getName() ) {
				throw new S3Exception( self::text( $doc, 'Message' ), 500, self::text( $doc, 'Code' ) );
			}
		} catch ( S3Exception $e ) {
			$this->abort_multipart( $key, $upload_id );
			throw $e;
		}
	}

	/**
	 * Reads exactly $length bytes unless EOF is reached.
	 *
	 * @param resource $handle File handle.
	 * @param int      $length Number of bytes.
	 * @return string
	 */
	private static function read_chunk( $handle, $length ) {
		$data    = '';
		$missing = $length;
		while ( $missing > 0 && ! feof( $handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$read = fread( $handle, $missing );
			if ( false === $read || '' === $read ) {
				break;
			}
			$data    .= $read;
			$missing -= strlen( $read );
		}
		return $data;
	}

	/**
	 * Aborts a multipart upload, ignoring errors.
	 *
	 * @param string $key       Object key.
	 * @param string $upload_id Upload ID.
	 * @return void
	 */
	private function abort_multipart( $key, $upload_id ) {
		try {
			$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
		} catch ( S3Exception $e ) {
			unset( $e ); // Nothing more we can do; lifecycle rules clean up leftovers.
		}
	}

	/**
	 * Performs a signed request and retries network errors, throttling and
	 * temporary server errors with exponential backoff (0.5 s, 1 s … plus jitter).
	 *
	 * @param string $method   HTTP method.
	 * @param string $key      Object key ('' for bucket-level operations).
	 * @param array  $query    Query parameters.
	 * @param array  $headers  Request headers.
	 * @param string $body     Request body.
	 * @param string $filename Stream the response body into this file.
	 * @return array{status:int, headers:array, body:string}
	 * @throws S3Exception On transport or S3 errors.
	 */
	private function request( $method, $key, array $query = array(), array $headers = array(), $body = '', $filename = '' ) {
		for ( $attempt = 0; ; ++$attempt ) {
			if ( null !== $this->guard ) {
				$this->deadline = (float) call_user_func( $this->guard );
				if ( $this->deadline <= microtime( true ) ) {
					throw new S3Exception( 'The lock of the file was lost; the request was not sent.' );
				}
			}
			$started = microtime( true );
			if ( $this->deadline > 0 && $started >= $this->deadline ) {
				throw new S3Exception( 'The time limit of the operation was reached before the request was sent.' );
			}
			try {
				return $this->send( $method, $key, $query, $headers, $body, $filename );
			} catch ( S3Exception $e ) {
				$status = $e->get_http_status();
				// Requests that ran for a long time (e.g. timeouts of large uploads) are not
				// repeated, so a request never blocks for a multiple of the timeout.
				if ( $attempt >= $this->retries || ( 0 !== $status && ! in_array( $status, self::RETRY_STATUS, true ) ) || microtime( true ) - $started > 60 ) {
					throw $e;
				}
				$pause = (int) ( 500000 * pow( 2, $attempt ) ) + wp_rand( 0, 250000 );
				if ( $this->deadline > 0 && microtime( true ) + $pause / 1000000 >= $this->deadline ) {
					throw $e;
				}
				usleep( $pause );
			}
		}
	}

	/**
	 * Performs a single signed request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $key      Object key ('' for bucket-level operations).
	 * @param array  $query    Query parameters.
	 * @param array  $headers  Request headers.
	 * @param string $body     Request body.
	 * @param string $filename Stream the response body into this file.
	 * @return array{status:int, headers:array, body:string}
	 * @throws S3Exception On transport or S3 errors.
	 */
	private function send( $method, $key, array $query = array(), array $headers = array(), $body = '', $filename = '' ) {
		list( $host, $path ) = $this->address( $key );

		$time         = time();
		$amz_date     = gmdate( 'Ymd\THis\Z', $time );
		$payload_hash = hash( 'sha256', (string) $body );

		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;

		// Canonical headers: host and all x-amz-* headers.
		$signed = array( 'host' => $host );
		foreach ( $headers as $name => $value ) {
			$lower = strtolower( $name );
			if ( 0 === strpos( $lower, 'x-amz-' ) ) {
				$signed[ $lower ] = trim( preg_replace( '/\s+/', ' ', (string) $value ) );
			}
		}
		ksort( $signed );

		$canonical_headers = '';
		foreach ( $signed as $name => $value ) {
			$canonical_headers .= $name . ':' . $value . "\n";
		}
		$signed_headers  = implode( ';', array_keys( $signed ) );
		$canonical_query = self::canonical_query( $query );

		$canonical = implode(
			"\n",
			array( $method, $path, $canonical_query, $canonical_headers, $signed_headers, $payload_hash )
		);

		$headers['Authorization'] = sprintf(
			'%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
			self::ALGORITHM,
			$this->access_key,
			$this->scope( $time ),
			$signed_headers,
			$this->signature( $canonical, $amz_date, $time )
		);

		$url  = $this->scheme . '://' . $host . $path . ( '' !== $canonical_query ? '?' . $canonical_query : '' );
		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'body'        => ( 'GET' === $method || 'HEAD' === $method || 'DELETE' === $method ) ? null : (string) $body,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'user-agent'  => 'BM-S3-Offload/' . ( defined( 'BMS3O_VERSION' ) ? BMS3O_VERSION : 'dev' ) . '; WordPress',
		);
		if ( '' !== $filename ) {
			$args['stream']   = true;
			$args['filename'] = $filename;
		}

		/**
		 * Filters the HTTP arguments of an S3 request.
		 *
		 * @param array  $args   Arguments for wp_remote_request().
		 * @param string $method HTTP method.
		 * @param string $url    Request URL.
		 */
		$args = apply_filters( 'bms3o_http_args', $args, $method, $url );

		if ( $this->deadline > 0 ) {
			// Applied after the filter: the bound must hold whatever the filter returns.
			$left = $this->deadline - microtime( true );
			if ( $left <= 0 ) {
				throw new S3Exception( 'The time limit of the operation was reached before the request was sent.' );
			}
			$args['timeout'] = min( isset( $args['timeout'] ) ? (float) $args['timeout'] : (float) $this->timeout, max( 0.5, $left ) );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			throw new S3Exception( $response->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}

		$status       = (int) wp_remote_retrieve_response_code( $response );
		$raw_headers  = wp_remote_retrieve_headers( $response );
		$headers_out  = array();
		$header_items = is_object( $raw_headers ) && method_exists( $raw_headers, 'getAll' ) ? $raw_headers->getAll() : (array) $raw_headers;
		foreach ( $header_items as $name => $value ) {
			$headers_out[ strtolower( $name ) ] = is_array( $value ) ? implode( ', ', $value ) : $value;
		}
		$response_body = (string) wp_remote_retrieve_body( $response );

		if ( $status < 200 || $status >= 300 ) {
			if ( '' !== $filename && is_readable( $filename ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Error document written by the stream.
				$response_body = (string) file_get_contents( $filename, false, null, 0, 65536 );
			}
			$code    = '';
			$message = sprintf( 'HTTP %d', $status );
			$doc     = '' !== $response_body ? self::parse_xml( $response_body ) : null;
			if ( '' !== self::text( $doc, 'Code' ) ) {
				$code    = self::text( $doc, 'Code' );
				$message = trim( $code . ': ' . self::text( $doc, 'Message' ), ': ' );
			}
			throw new S3Exception( $message, $status, $code ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Not output here; messages are escaped where they are displayed.
		}

		return array(
			'status'  => $status,
			'headers' => $headers_out,
			'body'    => $response_body,
		);
	}

	/**
	 * Host and canonical (encoded) path for a key.
	 *
	 * @param string $key Object key.
	 * @return array{0:string,1:string}
	 */
	private function address( $key ) {
		$encoded_key = self::encode_key( ltrim( (string) $key, '/' ) );
		if ( $this->path_style ) {
			$path = '/' . rawurlencode( $this->bucket ) . ( '' !== $encoded_key ? '/' . $encoded_key : '' );
			return array( $this->host, $path );
		}
		return array( $this->bucket . '.' . $this->host, '/' . $encoded_key );
	}

	/**
	 * URL-encodes a key, keeping slashes.
	 *
	 * @param string $key Object key.
	 * @return string
	 */
	public static function encode_key( $key ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );
	}

	/**
	 * Canonical query string as required by SigV4.
	 *
	 * @param array $query Query parameters.
	 * @return string
	 */
	private static function canonical_query( array $query ) {
		$encoded = array();
		foreach ( $query as $name => $value ) {
			$encoded[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
		}
		ksort( $encoded, SORT_STRING );

		$pairs = array();
		foreach ( $encoded as $name => $value ) {
			$pairs[] = $name . '=' . $value;
		}
		return implode( '&', $pairs );
	}

	/**
	 * Credential scope.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private function scope( $time ) {
		return gmdate( 'Ymd', $time ) . '/' . $this->region . '/s3/aws4_request';
	}

	/**
	 * Calculates the request signature.
	 *
	 * @param string $canonical Canonical request.
	 * @param string $amz_date  ISO8601 basic date.
	 * @param int    $time      Unix time.
	 * @return string
	 */
	private function signature( $canonical, $amz_date, $time ) {
		$string_to_sign = implode(
			"\n",
			array( self::ALGORITHM, $amz_date, $this->scope( $time ), hash( 'sha256', $canonical ) )
		);

		$k_date    = hash_hmac( 'sha256', gmdate( 'Ymd', $time ), 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
		$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );

		return hash_hmac( 'sha256', $string_to_sign, $k_signing );
	}

	/**
	 * Text of the first child element with the given name (S3 XML uses
	 * PascalCase element names, so they are accessed by name, not as properties).
	 *
	 * @param \SimpleXMLElement|null $node Parent element.
	 * @param string                 $name Element name.
	 * @return string
	 */
	private static function text( $node, $name ) {
		$found = self::nodes( $node, $name );
		return empty( $found ) ? '' : (string) $found[0];
	}

	/**
	 * Child elements with the given name.
	 *
	 * @param \SimpleXMLElement|null $node Parent element.
	 * @param string                 $name Element name.
	 * @return \SimpleXMLElement[]
	 */
	private static function nodes( $node, $name ) {
		$found = array();
		if ( $node instanceof \SimpleXMLElement ) {
			foreach ( $node->children() as $child ) {
				if ( $child->getName() === $name ) {
					$found[] = $child;
				}
			}
		}
		return $found;
	}

	/**
	 * Parses an XML response safely (no network, no entity expansion).
	 *
	 * @param string $xml XML string.
	 * @return \SimpleXMLElement|null
	 */
	private static function parse_xml( $xml ) {
		if ( '' === trim( (string) $xml ) || ! function_exists( 'simplexml_load_string' ) ) {
			return null;
		}
		$previous = libxml_use_internal_errors( true );
		$doc      = simplexml_load_string( $xml, 'SimpleXMLElement', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return false === $doc ? null : $doc;
	}
}
