<?php
/**
 * Settings and tools screen.
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Admin;

use BahrMedia\S3Offload\Bulk;
use BahrMedia\S3Offload\Integrations\BuddyBoss;
use BahrMedia\S3Offload\Plugin;
use BahrMedia\S3Offload\Providers;

defined( 'ABSPATH' ) || exit;

/**
 * Media → S3 Offload.
 */
final class SettingsPage {

	const SLUG = 'bm-s3-offload';

	/**
	 * Plugin.
	 *
	 * @var Plugin
	 */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'admin_post_bms3o_save', array( $this, 'handle_save' ) );
		add_action( 'wp_ajax_bms3o_test', array( $this, 'ajax_test' ) );
		add_action( 'wp_ajax_bms3o_bulk', array( $this, 'ajax_bulk' ) );
		add_action( 'wp_ajax_bms3o_background', array( $this, 'ajax_background' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( BMS3O_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Capability required to manage the plugin. On multisite only super admins
	 * may change the storage (endpoint, credentials, prefixes are shared resources).
	 *
	 * @return string
	 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * URL of the screen.
	 *
	 * @param string $tab Tab.
	 * @return string
	 */
	public static function url( $tab = 'settings' ) {
		return add_query_arg(
			array(
				'page' => self::SLUG,
				'tab'  => $tab,
			),
			admin_url( 'upload.php' )
		);
	}

	/**
	 * Adds the menu entry.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page( 'upload.php', __( 'S3 Offload', 'bm-s3-offload' ), __( 'S3 Offload', 'bm-s3-offload' ), self::capability(), self::SLUG, array( $this, 'render' ) );
	}

	/**
	 * Settings link on the plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'bm-s3-offload' ) . '</a>' );
		return $links;
	}

	/**
	 * Assets for the screen.
	 *
	 * @param string $hook_suffix Current screen.
	 * @return void
	 */
	public function enqueue( $hook_suffix ) {
		if ( 'media_page_' . self::SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'bms3o-admin', BMS3O_URL . 'assets/admin.css', array(), BMS3O_VERSION );
		wp_enqueue_script( 'bms3o-admin', BMS3O_URL . 'assets/admin.js', array(), BMS3O_VERSION, true );
		wp_localize_script(
			'bms3o-admin',
			'bms3oAdmin',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'bms3o_admin' ),
				'providers' => Providers::all(),
				'i18n'      => array(
					'running'   => __( 'Running…', 'bm-s3-offload' ),
					'done'      => __( 'Done.', 'bm-s3-offload' ),
					'stopped'   => __( 'Stopped.', 'bm-s3-offload' ),
					'stop'      => __( 'Stop', 'bm-s3-offload' ),
					'failed'    => __( 'Request failed:', 'bm-s3-offload' ),
					'processed' => __( 'Processed', 'bm-s3-offload' ),
					'errors'    => __( 'Errors', 'bm-s3-offload' ),
					'confirm'   => __( 'This moves all files back to this server and deletes them from the bucket. Continue?', 'bm-s3-offload' ),
					'bgRunning' => __( 'Running in the background', 'bm-s3-offload' ),
					'bgDone'    => __( 'Background operation finished', 'bm-s3-offload' ),
					'bgStopped' => __( 'Background operation stopped', 'bm-s3-offload' ),
				),
			)
		);
	}

	/**
	 * Renders the screen.
	 *
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation only.
		$tab = isset( $_GET['tab'] ) && 'tools' === $_GET['tab'] ? 'tools' : 'settings';
		?>
		<div class="wrap bms3o">
			<h1><?php esc_html_e( 'S3 Offload', 'bm-s3-offload' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::url( 'settings' ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'bm-s3-offload' ); ?></a>
				<a href="<?php echo esc_url( self::url( 'tools' ) ); ?>" class="nav-tab <?php echo 'tools' === $tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Tools', 'bm-s3-offload' ); ?></a>
			</nav>
			<?php
			if ( 'tools' === $tab ) {
				$this->render_tools();
			} else {
				$this->render_settings();
			}
			?>
		</div>
		<?php
	}

	/*
	 * ------------------------------------------------------------------
	 * Settings tab
	 * ------------------------------------------------------------------
	 */

	/**
	 * Settings form.
	 *
	 * @return void
	 */
	private function render_settings() {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bms3o-settings">
			<input type="hidden" name="action" value="bms3o_save">
			<?php wp_nonce_field( 'bms3o_save' ); ?>

			<h2><?php esc_html_e( 'Storage', 'bm-s3-offload' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$options = array();
				foreach ( Providers::all() as $id => $provider ) {
					$options[ $id ] = $provider['label'];
				}
				$this->field(
					'provider',
					__( 'Provider', 'bm-s3-offload' ),
					'select',
					array(
						'options'     => $options,
						'description' => __( 'Selecting a provider pre-fills endpoint, region and addressing style. Any S3-compatible service works.', 'bm-s3-offload' ),
					)
				);
				$this->field(
					'endpoint',
					__( 'Endpoint', 'bm-s3-offload' ),
					'url',
					array(
						'placeholder' => 'https://s3.eu-central-1.amazonaws.com',
						'description' => __( 'Without bucket name. Replace placeholders like {account} with your values.', 'bm-s3-offload' ),
					)
				);
				$this->field( 'region', __( 'Region', 'bm-s3-offload' ), 'text', array( 'placeholder' => 'eu-central-1' ) );
				$this->field( 'bucket', __( 'Bucket', 'bm-s3-offload' ), 'text' );
				$this->field( 'access_key', __( 'Access key ID', 'bm-s3-offload' ), 'text', array( 'autocomplete' => 'off' ) );
				$this->field( 'secret_key', __( 'Secret access key', 'bm-s3-offload' ), 'secret', array( 'description' => __( 'Stored encrypted. Most secure: define BMS3O_SECRET_KEY in wp-config.php.', 'bm-s3-offload' ) ) );
				$this->field( 'path_style', __( 'Path-style URLs', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Use endpoint/bucket/key instead of bucket.endpoint/key (required by MinIO and some providers).', 'bm-s3-offload' ) ) );
				$this->field( 'object_prefix', __( 'Object prefix', 'bm-s3-offload' ), 'text', array( 'description' => __( 'Prepended to every object key, e.g. "wp-content/uploads/". Use a different prefix per site when several sites share a bucket.', 'bm-s3-offload' ) ) );
				$this->field( 'private_prefix', __( 'Private prefix', 'bm-s3-offload' ), 'text', array( 'description' => __( 'Private files are stored below this prefix. Only the object prefix may be publicly readable in your bucket policy.', 'bm-s3-offload' ) ) );
				$this->field(
					'object_acl',
					__( 'Object ACL', 'bm-s3-offload' ),
					'select',
					array(
						'options'     => array(
							''            => __( 'None (bucket policy decides – recommended)', 'bm-s3-offload' ),
							'public-read' => __( 'public-read for public files, private for private files', 'bm-s3-offload' ),
						),
						'description' => __( 'Only for buckets that use ACLs. Many providers have ACLs disabled.', 'bm-s3-offload' ),
					)
				);
				$this->field( 'cache_control', __( 'Cache-Control', 'bm-s3-offload' ), 'text' );
				?>
			</table>

			<h2><?php esc_html_e( 'Behaviour', 'bm-s3-offload' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'copy_on_upload', __( 'Offload new uploads', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Copy new and edited media to the bucket automatically.', 'bm-s3-offload' ) ) );
				$this->field( 'remove_local', __( 'Remove local copies', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Delete files from this server after a successful upload. Files are restored automatically when needed (image editor, thumbnail regeneration).', 'bm-s3-offload' ) ) );
				$this->field( 'delete_remote', __( 'Delete from bucket', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Delete objects when the media item is deleted in WordPress.', 'bm-s3-offload' ) ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Delivery', 'bm-s3-offload' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'rewrite_urls', __( 'Serve from bucket', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Rewrite media URLs to the bucket or CDN. Files without local copy are always served from the bucket.', 'bm-s3-offload' ) ) );
				$this->field(
					'delivery_domain',
					__( 'CDN / custom domain', 'bm-s3-offload' ),
					'url',
					array(
						'placeholder' => 'https://cdn.example.com',
						'description' => __( 'Optional. Must point to the bucket root (the object key is appended). Signed URLs always use the endpoint.', 'bm-s3-offload' ),
					)
				);
				$this->field(
					'assets_domain',
					__( 'CDN for CSS and JavaScript', 'bm-s3-offload' ),
					'url',
					array(
						'placeholder' => 'https://static.example.com',
						'description' => __( 'Optional. A pull CDN whose origin is this website. Scripts and styles of themes, plugins and WordPress are then loaded from it (front end only).', 'bm-s3-offload' ),
					)
				);
				$this->field( 'sign_all', __( 'Signed URLs for all files', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Use a completely private bucket and deliver every file with a pre-signed URL.', 'bm-s3-offload' ) ) );
				$this->field(
					'signed_ttl',
					__( 'Signed URL lifetime', 'bm-s3-offload' ),
					'number',
					array(
						'min'         => 60,
						'max'         => 604800,
						'description' => __( 'Seconds (60–604800). URLs stay identical for half of this time so browsers can cache them.', 'bm-s3-offload' ),
					)
				);
				?>
			</table>

			<h2><?php esc_html_e( 'BuddyBoss', 'bm-s3-offload' ); ?></h2>
			<?php if ( ! BuddyBoss::is_active() ) : ?>
				<p class="description"><?php esc_html_e( 'BuddyBoss Platform is not active. These settings take effect once it is.', 'bm-s3-offload' ); ?></p>
			<?php endif; ?>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'bb_enabled', __( 'Integration', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Offload BuddyBoss photos, documents and videos and deliver them from the bucket.', 'bm-s3-offload' ) ) );
				$this->field( 'bb_private', __( 'Private media', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Store BuddyBoss media as private objects (recommended – respects BuddyBoss privacy settings).', 'bm-s3-offload' ) ) );
				$this->field(
					'bb_delivery',
					__( 'Photo delivery', 'bm-s3-offload' ),
					'select',
					array(
						'options'     => array(
							'proxy'  => __( 'Via BuddyBoss preview URL with access check (stable links, recommended)', 'bm-s3-offload' ),
							'direct' => __( 'Signed bucket URL in the page (faster, links expire)', 'bm-s3-offload' ),
						),
						'description' => __( 'With the preview URL, every request is checked against BuddyBoss privacy and redirected to a short-lived signed URL. Links in e-mails keep working.', 'bm-s3-offload' ),
					)
				);
				$this->field( 'bb_avatars', __( 'Avatars and cover images', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Mirror member and group avatars and cover images to the bucket and serve them from there (local copies are kept).', 'bm-s3-offload' ) ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Integrations', 'bm-s3-offload' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php
				$this->field( 'wc_enabled', __( 'WooCommerce', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Deliver downloadable products from the bucket: after WooCommerce has checked the order, customers are redirected to a short-lived signed URL.', 'bm-s3-offload' ) ) );
				?>
			</table>

			<h2><?php esc_html_e( 'Uninstall', 'bm-s3-offload' ); ?></h2>
			<table class="form-table" role="presentation">
				<?php $this->field( 'remove_data', __( 'Remove data', 'bm-s3-offload' ), 'checkbox', array( 'label' => __( 'Delete settings and the offload table when the plugin is deleted. Objects in the bucket are never deleted.', 'bm-s3-offload' ) ) ); ?>
			</table>

			<?php submit_button(); ?>
		</form>
		<?php
	}

	/**
	 * Renders a form row.
	 *
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param string $type  text|url|number|secret|checkbox|select.
	 * @param array  $args  Extra arguments.
	 * @return void
	 */
	private function field( $key, $label, $type, array $args = array() ) {
		$settings   = $this->plugin->settings;
		$value      = $settings->get( $key );
		$overridden = $settings->is_overridden( $key );
		$id         = 'bms3o-' . str_replace( '_', '-', $key );
		$name       = 'bms3o[' . $key . ']';
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php
				switch ( $type ) {
					case 'checkbox':
						?>
						<label><input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( (bool) $value ); ?> <?php disabled( $overridden ); ?>> <?php echo esc_html( isset( $args['label'] ) ? $args['label'] : '' ); ?></label>
						<?php
						break;

					case 'select':
						?>
						<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" <?php disabled( $overridden ); ?>>
							<?php foreach ( $args['options'] as $option => $option_label ) : ?>
								<option value="<?php echo esc_attr( $option ); ?>" <?php selected( (string) $value, (string) $option ); ?>><?php echo esc_html( $option_label ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php
						break;

					case 'secret':
						$stored = '' !== (string) $value;
						?>
						<input type="password" class="regular-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $overridden ? '' : $name ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $stored ? __( '•••••••• (saved – leave empty to keep)', 'bm-s3-offload' ) : '' ); ?>" <?php disabled( $overridden ); ?>>
						<?php
						break;

					default:
						?>
						<input type="<?php echo esc_attr( 'url' === $type ? 'text' : $type ); ?>" class="<?php echo 'number' === $type ? 'small-text' : 'regular-text'; ?>" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( (string) $value ); ?>"
							<?php
							foreach ( array( 'placeholder', 'min', 'max', 'autocomplete' ) as $attribute ) {
								if ( isset( $args[ $attribute ] ) ) {
									echo ' ' . esc_attr( $attribute ) . '="' . esc_attr( (string) $args[ $attribute ] ) . '"';
								}
							}
							disabled( $overridden );
							?>
						>
						<?php
				}

				if ( $overridden ) {
					echo '<p class="description bms3o-locked">';
					/* translators: %s: constant name */
					printf( esc_html__( 'Defined by %s.', 'bm-s3-offload' ), '<code>BMS3O_' . esc_html( strtoupper( $key ) ) . '</code>' );
					echo '</p>';
				}
				if ( ! empty( $args['description'] ) ) {
					echo '<p class="description">' . esc_html( $args['description'] ) . '</p>';
				}
				?>
			</td>
		</tr>
		<?php
	}

	/**
	 * Saves the settings.
	 *
	 * @return void
	 */
	public function handle_save() {
		if ( ! current_user_can( self::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bm-s3-offload' ), 403 );
		}
		check_admin_referer( 'bms3o_save' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized per field in Settings::save().
		$input       = isset( $_POST['bms3o'] ) && is_array( $_POST['bms3o'] ) ? wp_unslash( $_POST['bms3o'] ) : array();
		$was_private = (bool) $this->plugin->settings->get( 'bb_private' );
		$errors      = $this->plugin->settings->save( $input );

		// Existing BuddyBoss objects follow a changed "private media" setting (in the background).
		if ( (bool) $this->plugin->settings->get( 'bb_private' ) !== $was_private && $this->plugin->bulk->remaining( 'sync_privacy' ) > 0 ) {
			$this->plugin->background->start( 'sync_privacy' );
		}
		if ( ! empty( $errors ) ) {
			set_transient( 'bms3o_save_errors_' . get_current_user_id(), $errors, MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( add_query_arg( 'bms3o-saved', '1', self::url() ) );
		exit;
	}

	/*
	 * ------------------------------------------------------------------
	 * Tools tab
	 * ------------------------------------------------------------------
	 */

	/**
	 * Tools: status, connection test, bulk operations.
	 *
	 * @return void
	 */
	private function render_tools() {
		$settings = $this->plugin->settings;
		$stats    = $this->plugin->offloader->items()->stats();
		$retries  = count( $this->plugin->attachments->pending_retries() );
		?>
		<h2><?php esc_html_e( 'Status', 'bm-s3-offload' ); ?></h2>
		<table class="widefat striped bms3o-stats">
			<tbody>
				<tr><th><?php esc_html_e( 'Offloaded files', 'bm-s3-offload' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['files'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Size in bucket', 'bm-s3-offload' ); ?></th><td><?php echo esc_html( size_format( $stats['bytes'], 1 ) ? size_format( $stats['bytes'], 1 ) : '0 B' ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Without local copy', 'bm-s3-offload' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['local_removed'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Private files', 'bm-s3-offload' ); ?></th><td><?php echo esc_html( number_format_i18n( $stats['private'] ) ); ?></td></tr>
				<tr><th><?php esc_html_e( 'Waiting for retry', 'bm-s3-offload' ); ?></th><td><?php echo esc_html( number_format_i18n( $retries ) ); ?></td></tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Connection test', 'bm-s3-offload' ); ?></h2>
		<p><?php esc_html_e( 'Uploads, reads and deletes a small test file and checks public and private delivery.', 'bm-s3-offload' ); ?></p>
		<p><button type="button" class="button button-secondary" id="bms3o-test" <?php disabled( ! $settings->is_configured() ); ?>><?php esc_html_e( 'Run connection test', 'bm-s3-offload' ); ?></button></p>
		<ul id="bms3o-test-result" class="bms3o-test-result"></ul>

		<h2><?php esc_html_e( 'Bulk operations', 'bm-s3-offload' ); ?></h2>
		<p><?php esc_html_e( 'Run an operation in this browser window (keep the page open) or in the background (WP-Cron; you can close the page). For very large libraries, WP-CLI is fastest: wp bm-s3', 'bm-s3-offload' ); ?></p>
		<div id="bms3o-background" class="bms3o-background notice inline" hidden>
			<p><strong class="bms3o-bg-title"></strong> <span class="bms3o-bg-status"></span></p>
			<progress class="bms3o-progress" max="100" value="0"></progress>
			<p><button type="button" class="button bms3o-bg-stop"><?php esc_html_e( 'Stop', 'bm-s3-offload' ); ?></button></p>
			<ul class="bms3o-errors"></ul>
		</div>
		<div class="bms3o-bulk-grid">
			<?php
			$this->bulk_card( 'offload', __( 'Offload existing media', 'bm-s3-offload' ), __( 'Uploads all media that are not in the bucket yet.', 'bm-s3-offload' ), true );
			$this->bulk_card( 'remove_local', __( 'Remove local copies', 'bm-s3-offload' ), __( 'Deletes local copies of offloaded media after verifying each object in the bucket.', 'bm-s3-offload' ) );
			$this->bulk_card( 'download', __( 'Restore local copies', 'bm-s3-offload' ), __( 'Downloads all files that only exist in the bucket back to this server.', 'bm-s3-offload' ) );
			if ( $this->plugin->bulk->remaining( 'sync_privacy' ) > 0 ) {
				$this->bulk_card( 'sync_privacy', __( 'Apply BuddyBoss privacy setting', 'bm-s3-offload' ), __( 'Moves existing BuddyBoss media to the private or public area according to the "private media" setting.', 'bm-s3-offload' ) );
			}
			if ( BuddyBoss::is_active() ) {
				$this->bulk_card( 'bb_sync', __( 'BuddyBoss avatars and covers', 'bm-s3-offload' ), __( 'Mirrors all existing member and group avatars and cover images to the bucket.', 'bm-s3-offload' ) );
			}
			$this->bulk_card( 'unoffload', __( 'Leave the bucket', 'bm-s3-offload' ), __( 'Restores every file locally and deletes it from the bucket. Use this before switching providers or uninstalling.', 'bm-s3-offload' ), false, true );
			?>
		</div>

		<h2><?php esc_html_e( 'Bucket policy', 'bm-s3-offload' ); ?></h2>
		<p><?php esc_html_e( 'Example policy that makes only the public prefix readable. Private files and everything else stay protected.', 'bm-s3-offload' ); ?></p>
		<pre class="bms3o-policy"><?php echo esc_html( $this->example_policy() ); ?></pre>
		<?php
	}

	/**
	 * Renders a bulk operation card.
	 *
	 * @param string $operation    Operation.
	 * @param string $title        Title.
	 * @param string $description  Description.
	 * @param bool   $remove_local Offer "remove local copies" option.
	 * @param bool   $danger       Destructive operation (asks for confirmation).
	 * @return void
	 */
	private function bulk_card( $operation, $title, $description, $remove_local = false, $danger = false ) {
		$remaining  = $this->plugin->bulk->remaining( $operation );
		$configured = $this->plugin->settings->is_configured();
		$unknown    = $remaining < 0;
		?>
		<div class="bms3o-card<?php echo $danger ? ' bms3o-danger' : ''; ?>" data-operation="<?php echo esc_attr( $operation ); ?>" data-total="<?php echo esc_attr( (string) max( 0, $remaining ) ); ?>" data-danger="<?php echo $danger ? '1' : '0'; ?>">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $description ); ?></p>
			<p class="bms3o-count">
				<?php
				/* translators: %s: number of items */
				printf( esc_html__( 'Pending: %s', 'bm-s3-offload' ), '<strong>' . esc_html( $unknown ? '–' : number_format_i18n( $remaining ) ) . '</strong>' );
				?>
			</p>
			<?php if ( $remove_local ) : ?>
				<p><label><input type="checkbox" class="bms3o-remove-local" <?php checked( (bool) $this->plugin->settings->get( 'remove_local' ) ); ?>> <?php esc_html_e( 'Remove local copies afterwards', 'bm-s3-offload' ); ?></label></p>
			<?php endif; ?>
			<progress class="bms3o-progress" max="100" value="0" hidden></progress>
			<p class="bms3o-status" aria-live="polite"></p>
			<p>
				<button type="button" class="button <?php echo $danger ? 'button-link-delete' : 'button-primary'; ?> bms3o-start" <?php disabled( ! $configured || 0 === $remaining ); ?>><?php esc_html_e( 'Start', 'bm-s3-offload' ); ?></button>
				<button type="button" class="button bms3o-start-background" <?php disabled( ! $configured || 0 === $remaining ); ?>><?php esc_html_e( 'Run in background', 'bm-s3-offload' ); ?></button>
			</p>
			<ul class="bms3o-errors"></ul>
		</div>
		<?php
	}

	/**
	 * Example bucket policy (AWS syntax, understood by most providers).
	 *
	 * @return string
	 */
	private function example_policy() {
		$settings = $this->plugin->settings;
		$bucket   = '' !== $settings->get( 'bucket' ) ? $settings->get( 'bucket' ) : 'my-bucket';
		$policy   = array(
			'Version'   => '2012-10-17',
			'Statement' => array(
				array(
					'Sid'       => 'PublicReadUploads',
					'Effect'    => 'Allow',
					'Principal' => '*',
					'Action'    => array( 's3:GetObject' ),
					'Resource'  => array( 'arn:aws:s3:::' . $bucket . '/' . $settings->get( 'object_prefix' ) . '*' ),
				),
			),
		);
		return (string) wp_json_encode( $policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}

	/*
	 * ------------------------------------------------------------------
	 * AJAX
	 * ------------------------------------------------------------------
	 */

	/**
	 * Verifies capability and nonce of an AJAX request.
	 *
	 * @return void
	 */
	private function verify_ajax() {
		if ( ! current_user_can( self::capability() ) || ! check_ajax_referer( 'bms3o_admin', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'bm-s3-offload' ) ), 403 );
		}
	}

	/**
	 * Connection test.
	 *
	 * @return void
	 */
	public function ajax_test() {
		$this->verify_ajax();
		wp_send_json_success( array( 'steps' => $this->plugin->offloader->test_connection() ) );
	}

	/**
	 * One bulk batch.
	 *
	 * @return void
	 */
	public function ajax_bulk() {
		$this->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		if ( ! in_array( $operation, Bulk::OPERATIONS, true ) || ! $this->plugin->settings->is_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'bm-s3-offload' ) ), 400 );
		}

		$cursor  = isset( $_POST['cursor'] ) ? absint( $_POST['cursor'] ) : 0;
		$options = array( 'remove_local' => ! empty( $_POST['remove_local'] ) );
		// phpcs:enable

		/**
		 * Filters the number of items processed per AJAX request.
		 *
		 * @param int    $size      Batch size.
		 * @param string $operation Operation.
		 */
		$size = (int) apply_filters( 'bms3o_bulk_batch_size', 'bb_sync' === $operation ? 20 : 10, $operation );

		wp_send_json_success( $this->plugin->bulk->run( $operation, $cursor, $size, $options ) );
	}

	/**
	 * Background operations: start, stop, status.
	 *
	 * @return void
	 */
	public function ajax_background() {
		$this->verify_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified in verify_ajax().
		$command   = isset( $_POST['command'] ) ? sanitize_key( wp_unslash( $_POST['command'] ) ) : 'status';
		$operation = isset( $_POST['operation'] ) ? sanitize_key( wp_unslash( $_POST['operation'] ) ) : '';
		$options   = array( 'remove_local' => ! empty( $_POST['remove_local'] ) );
		// phpcs:enable

		$background = $this->plugin->background;
		if ( 'start' === $command ) {
			if ( ! in_array( $operation, Bulk::OPERATIONS, true ) || ! $this->plugin->settings->is_configured() ) {
				wp_send_json_error( array( 'message' => __( 'Invalid request.', 'bm-s3-offload' ) ), 400 );
			}
			if ( ! $background->start( $operation, $options ) ) {
				wp_send_json_error( array( 'message' => __( 'Another background operation is running.', 'bm-s3-offload' ) ), 409 );
			}
		} elseif ( 'stop' === $command ) {
			$background->stop();
		} else {
			$background->nudge();
		}

		$state = $background->state();
		if ( $state ) {
			$state['label'] = $this->operation_label( $state['operation'] );
		}
		wp_send_json_success( array( 'state' => $state ) );
	}

	/**
	 * Human readable name of a bulk operation.
	 *
	 * @param string $operation Operation.
	 * @return string
	 */
	private function operation_label( $operation ) {
		$labels = array(
			'offload'      => __( 'Offload existing media', 'bm-s3-offload' ),
			'remove_local' => __( 'Remove local copies', 'bm-s3-offload' ),
			'download'     => __( 'Restore local copies', 'bm-s3-offload' ),
			'bb_sync'      => __( 'BuddyBoss avatars and covers', 'bm-s3-offload' ),
			'unoffload'    => __( 'Leave the bucket', 'bm-s3-offload' ),
			'sync_privacy' => __( 'Apply BuddyBoss privacy setting', 'bm-s3-offload' ),
		);
		return isset( $labels[ $operation ] ) ? $labels[ $operation ] : $operation;
	}

	/*
	 * ------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------
	 */

	/**
	 * Admin notices.
	 *
	 * @return void
	 */
	public function notices() {
		if ( ! current_user_can( self::capability() ) ) {
			return;
		}

		$screen = get_current_screen();
		$ours   = $screen && 'media_page_' . self::SLUG === $screen->id;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( $ours && isset( $_GET['bms3o-saved'] ) ) {
			$errors = get_transient( 'bms3o_save_errors_' . get_current_user_id() );
			delete_transient( 'bms3o_save_errors_' . get_current_user_id() );
			if ( is_array( $errors ) && ! empty( $errors ) ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'The prefixes were not saved:', 'bm-s3-offload' ) . '</p><ul>';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			} else {
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'bm-s3-offload' ) . '</p></div>';
			}
		}

		if ( $this->plugin->settings->secret_unreadable() ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: link to the settings */
				esc_html__( 'BM S3 Offload cannot decrypt the stored secret key, probably because the WordPress security keys (salts) were changed. Offloading is paused. %s', 'bm-s3-offload' ),
				'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Enter the secret key again', 'bm-s3-offload' ) . '</a>'
			);
			echo '</p></div>';
		}

		if ( ! $this->plugin->settings->is_configured() && $screen && in_array( $screen->id, array( 'plugins', 'upload', 'media_page_' . self::SLUG ), true ) ) {
			echo '<div class="notice notice-warning"><p>';
			printf(
				/* translators: %s: link to the settings */
				esc_html__( 'BM S3 Offload is not configured yet. %s', 'bm-s3-offload' ),
				'<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Configure the bucket', 'bm-s3-offload' ) . '</a>'
			);
			echo '</p></div>';
		}

		if ( ! function_exists( 'simplexml_load_string' ) && $ours ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The PHP extension SimpleXML is missing. Listing objects and error messages from the bucket will not work.', 'bm-s3-offload' ) . '</p></div>';
		}
	}
}
