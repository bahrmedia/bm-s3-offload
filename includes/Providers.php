<?php
/**
 * Provider presets.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

defined( 'ABSPATH' ) || exit;

/**
 * Presets only pre-fill the endpoint, region and addressing style in the admin
 * screen. Any S3-compatible service works with the "custom" preset.
 */
final class Providers {

	/**
	 * All presets.
	 *
	 * Endpoint placeholders: {region}, {account}.
	 *
	 * @return array<string,array{label:string,endpoint:string,region:string,path_style:bool,docs:string}>
	 */
	public static function all() {
		$providers = array(
			'aws'          => array(
				'label'      => 'Amazon S3',
				'endpoint'   => 'https://s3.{region}.amazonaws.com',
				'region'     => 'eu-central-1',
				'path_style' => false,
				'docs'       => 'https://docs.aws.amazon.com/general/latest/gr/s3.html',
			),
			'r2'           => array(
				'label'      => 'Cloudflare R2',
				'endpoint'   => 'https://{account}.r2.cloudflarestorage.com',
				'region'     => 'auto',
				'path_style' => true,
				'docs'       => 'https://developers.cloudflare.com/r2/api/s3/api/',
			),
			'wasabi'       => array(
				'label'      => 'Wasabi',
				'endpoint'   => 'https://s3.{region}.wasabisys.com',
				'region'     => 'eu-central-1',
				'path_style' => false,
				'docs'       => 'https://docs.wasabi.com/docs/what-are-the-service-urls-for-wasabi-s-different-storage-regions',
			),
			'b2'           => array(
				'label'      => 'Backblaze B2',
				'endpoint'   => 'https://s3.{region}.backblazeb2.com',
				'region'     => 'eu-central-003',
				'path_style' => false,
				'docs'       => 'https://www.backblaze.com/docs/cloud-storage-s3-compatible-api',
			),
			'digitalocean' => array(
				'label'      => 'DigitalOcean Spaces',
				'endpoint'   => 'https://{region}.digitaloceanspaces.com',
				'region'     => 'fra1',
				'path_style' => false,
				'docs'       => 'https://docs.digitalocean.com/products/spaces/',
			),
			'hetzner'      => array(
				'label'      => 'Hetzner Object Storage',
				'endpoint'   => 'https://{region}.your-objectstorage.com',
				'region'     => 'fsn1',
				'path_style' => false,
				'docs'       => 'https://docs.hetzner.com/storage/object-storage/',
			),
			'ionos'        => array(
				'label'      => 'IONOS S3 Object Storage',
				'endpoint'   => 'https://s3.{region}.ionoscloud.com',
				'region'     => 'eu-central-1',
				'path_style' => false,
				'docs'       => 'https://docs.ionos.com/cloud/storage-and-backup/ionos-object-storage',
			),
			'scaleway'     => array(
				'label'      => 'Scaleway Object Storage',
				'endpoint'   => 'https://s3.{region}.scw.cloud',
				'region'     => 'fr-par',
				'path_style' => false,
				'docs'       => 'https://www.scaleway.com/en/docs/object-storage/',
			),
			'minio'        => array(
				'label'      => 'MinIO / self-hosted',
				'endpoint'   => 'http://127.0.0.1:9000',
				'region'     => 'us-east-1',
				'path_style' => true,
				'docs'       => 'https://min.io/docs/',
			),
			'custom'       => array(
				'label'      => __( 'Other S3-compatible service', 'bm-s3-offload' ),
				'endpoint'   => '',
				'region'     => 'us-east-1',
				'path_style' => false,
				'docs'       => '',
			),
		);

		/**
		 * Filters the provider presets.
		 *
		 * @param array $providers Presets.
		 */
		return (array) apply_filters( 'bms3o_providers', $providers );
	}

	/**
	 * Label of a provider.
	 *
	 * @param string $id Provider ID.
	 * @return string
	 */
	public static function label( $id ) {
		$all = self::all();
		return isset( $all[ $id ] ) ? $all[ $id ]['label'] : $all['custom']['label'];
	}
}
