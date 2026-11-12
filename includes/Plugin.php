<?php
/**
 * Plugin bootstrap.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload;

use BahrMedia\S3Offload\Admin\MediaLibrary;
use BahrMedia\S3Offload\Admin\SettingsPage;
use BahrMedia\S3Offload\Integrations\BuddyBoss;
use BahrMedia\S3Offload\Integrations\WooCommerce;
use BahrMedia\S3Offload\Media\Attachments;
use BahrMedia\S3Offload\Media\ContentRewriter;
use BahrMedia\S3Offload\Store\Counts;
use BahrMedia\S3Offload\Store\Items;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the components together.
 */
final class Plugin {

	const CRON_HOOK = 'bms3o_cron';

	/**
	 * Singleton.
	 *
	 * @var Plugin|null
	 */
	private static $instance;

	/**
	 * Settings.
	 *
	 * @var Settings
	 */
	public $settings;

	/**
	 * Offloader.
	 *
	 * @var Offloader
	 */
	public $offloader;

	/**
	 * Attachments module.
	 *
	 * @var Attachments
	 */
	public $attachments;

	/**
	 * BuddyBoss integration (null if BuddyBoss is not active).
	 *
	 * @var BuddyBoss|null
	 */
	public $buddyboss;

	/**
	 * Bulk operations.
	 *
	 * @var Bulk
	 */
	public $bulk;

	/**
	 * Background runner for bulk operations.
	 *
	 * @var Background
	 */
	public $background;

	/**
	 * Returns (and on first call boots) the plugin.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * Boots all components.
	 *
	 * @return void
	 */
	private function boot() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
		$this->maybe_upgrade();

		$this->settings    = new Settings();
		$this->offloader   = new Offloader( $this->settings, new Items() );
		$this->attachments = new Attachments( $this->offloader );
		add_action( self::CRON_HOOK, array( $this->offloader, 'process_orphans' ), 10, 0 );
		add_action( 'bms3o_storage_changed', array( $this->offloader, 'move_orphans' ), 10, 2 );
		add_action( 'add_attachment', array( Counts::class, 'flush' ), 10, 0 );
		add_action( 'delete_attachment', array( Counts::class, 'flush' ), 10, 0 );
		$this->attachments->register();
		( new ContentRewriter( $this->offloader ) )->register();
		( new Assets( $this->settings ) )->register();

		if ( BuddyBoss::is_active() ) {
			$this->buddyboss = new BuddyBoss( $this->offloader, $this->attachments );
			$this->buddyboss->register();
		}

		if ( WooCommerce::is_active() ) {
			( new WooCommerce( $this->offloader ) )->register();
		}

		$this->bulk       = new Bulk( $this->attachments, $this->buddyboss );
		$this->background = new Background( $this->bulk, $this->offloader );
		$this->background->register();

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new MediaLibrary( $this ) )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command( 'bm-s3', new Cli\Command( $this ) );
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK );
		}
	}

	/**
	 * Loads bundled translations (translations from translate.wordpress.org take precedence).
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'bm-s3-offload', false, dirname( plugin_basename( BMS3O_FILE ) ) . '/languages' );
	}

	/**
	 * Creates/updates the database table when needed (also covers multisite sites).
	 *
	 * @return void
	 */
	private function maybe_upgrade() {
		if ( Items::DB_VERSION !== get_option( 'bms3o_db_version' ) ) {
			Items::install();
		}
	}

	/**
	 * Activation.
	 *
	 * @return void
	 */
	public static function activate() {
		Items::install();
	}

	/**
	 * Deactivation.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( Background::HOOK );
		wp_unschedule_hook( Attachments::ASYNC_HOOK );
	}
}
