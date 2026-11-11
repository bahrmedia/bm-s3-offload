<?php
/**
 * Configures the plugin for the integration tests (run with wp eval-file).
 *
 * Environment: BMS3O_TEST_ENDPOINT, BMS3O_TEST_BUCKET, BMS3O_TEST_KEY, BMS3O_TEST_SECRET.
 *
 * @package BahrMedia\S3Offload
 */

BahrMedia\S3Offload\Plugin::instance()->settings->save(
	array(
		'provider'       => 'minio',
		'endpoint'       => getenv( 'BMS3O_TEST_ENDPOINT' ),
		'region'         => 'us-east-1',
		'bucket'         => getenv( 'BMS3O_TEST_BUCKET' ),
		'access_key'     => getenv( 'BMS3O_TEST_KEY' ),
		'secret_key'     => getenv( 'BMS3O_TEST_SECRET' ),
		'path_style'     => '1',
		'object_prefix'  => 'wp-content/uploads/',
		'private_prefix' => 'private/',
		'copy_on_upload' => '1',
		'delete_remote'  => '1',
		'rewrite_urls'   => '1',
		'signed_ttl'     => '3600',
		'bb_enabled'     => '1',
		'bb_private'     => '1',
		'bb_delivery'    => 'proxy',
		'bb_avatars'     => '1',
		'wc_enabled'     => '1',
	)
);

echo BahrMedia\S3Offload\Plugin::instance()->settings->is_configured() ? "Plugin configured.\n" : "Configuration failed.\n";
