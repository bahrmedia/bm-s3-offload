<?php
/**
 * Media library integration (list view, edit screen).
 *
 * @package BahrMedia\S3Offload
 */

namespace BahrMedia\S3Offload\Admin;

use BahrMedia\S3Offload\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Status column, row actions and bulk actions.
 */
final class MediaLibrary {

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
		add_filter( 'manage_media_columns', array( $this, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'media_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-upload', array( $this, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-upload', array( $this, 'handle_bulk' ), 10, 3 );
		add_action( 'admin_post_bms3o_item', array( $this, 'handle_single' ) );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'submitbox' ), 20 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
		add_action( 'admin_head-upload.php', array( $this, 'column_style' ) );
	}

	/**
	 * Labels of the actions.
	 *
	 * @return array<string,string>
	 */
	private static function actions() {
		return array(
			'offload'      => __( 'Offload to bucket', 'bm-s3-offload' ),
			'remove_local' => __( 'Remove local copy', 'bm-s3-offload' ),
			'download'     => __( 'Restore local copy', 'bm-s3-offload' ),
			'unoffload'    => __( 'Remove from bucket', 'bm-s3-offload' ),
		);
	}

	/**
	 * Adds the status column.
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function add_column( $columns ) {
		$columns['bms3o'] = '<span class="dashicons dashicons-cloud" aria-hidden="true"></span><span class="screen-reader-text">' . esc_html__( 'Bucket', 'bm-s3-offload' ) . '</span>';
		return $columns;
	}

	/**
	 * Narrow column.
	 *
	 * @return void
	 */
	public function column_style() {
		echo '<style>.fixed .column-bms3o{width:2.5em}.column-bms3o .dashicons{color:#2271b1}.column-bms3o .bms3o-local{color:#a7aaad}</style>';
	}

	/**
	 * Renders the status column.
	 *
	 * @param string $column        Column name.
	 * @param int    $attachment_id Attachment ID.
	 * @return void
	 */
	public function render_column( $column, $attachment_id ) {
		if ( 'bms3o' !== $column ) {
			return;
		}
		$item = $this->plugin->attachments->main_item( (int) $attachment_id );
		if ( ! $item ) {
			echo '<span class="dashicons dashicons-media-default bms3o-local" title="' . esc_attr__( 'Local only', 'bm-s3-offload' ) . '"></span>';
			return;
		}

		$title = $item['local_removed'] ? __( 'In bucket only', 'bm-s3-offload' ) : __( 'In bucket and local', 'bm-s3-offload' );
		if ( $item['is_private'] ) {
			$title .= ' · ' . __( 'private', 'bm-s3-offload' );
		}
		$icon = $item['is_private'] ? 'dashicons-lock' : ( $item['local_removed'] ? 'dashicons-cloud' : 'dashicons-cloud-saved' );
		echo '<span class="dashicons ' . esc_attr( $icon ) . '" title="' . esc_attr( $title ) . '"></span><span class="screen-reader-text">' . esc_html( $title ) . '</span>';
	}

	/**
	 * Applicable actions for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string[]
	 */
	private function applicable( $attachment_id ) {
		if ( ! $this->plugin->settings->is_configured() ) {
			return array();
		}
		$items = $this->plugin->offloader->items()->get_by_source( 'attachment', (int) $attachment_id );
		if ( empty( $items ) ) {
			return array( 'offload' );
		}

		$removed = 0;
		foreach ( $items as $item ) {
			$removed += $item['local_removed'] ? 1 : 0;
		}

		$actions = array( 'offload' );
		if ( $removed < count( $items ) ) {
			$actions[] = 'remove_local';
		}
		if ( $removed > 0 ) {
			$actions[] = 'download';
		}
		$actions[] = 'unoffload';
		return $actions;
	}

	/**
	 * Row actions in the list view.
	 *
	 * @param array    $actions Actions.
	 * @param \WP_Post $post    Attachment.
	 * @return array
	 */
	public function row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$labels = self::actions();
		foreach ( $this->applicable( $post->ID ) as $action ) {
			$actions[ 'bms3o_' . $action ] = '<a href="' . esc_url( $this->action_url( $action, $post->ID ) ) . '">' . esc_html( $labels[ $action ] ) . '</a>';
		}
		return $actions;
	}

	/**
	 * URL of a single action.
	 *
	 * @param string $action        Action.
	 * @param int    $attachment_id Attachment ID.
	 * @return string
	 */
	private function action_url( $action, $attachment_id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'     => 'bms3o_item',
					'operation'  => $action,
					'attachment' => (int) $attachment_id,
				),
				admin_url( 'admin-post.php' )
			),
			'bms3o_item_' . $action . '_' . (int) $attachment_id
		);
	}

	/**
	 * Handles a single action.
	 *
	 * @return void
	 */
	public function handle_single() {
		$operation     = isset( $_GET['operation'] ) ? sanitize_key( wp_unslash( $_GET['operation'] ) ) : '';
		$attachment_id = isset( $_GET['attachment'] ) ? absint( $_GET['attachment'] ) : 0;

		check_admin_referer( 'bms3o_item_' . $operation . '_' . $attachment_id );
		if ( ! array_key_exists( $operation, self::actions() ) || ! current_user_can( 'edit_post', $attachment_id ) || ! $this->plugin->settings->is_configured() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'bm-s3-offload' ), 403 );
		}

		$error = $this->plugin->bulk->process( $operation, $attachment_id, array( 'force' => true ) );
		$this->redirect_back( 1, '' === $error ? 0 : 1 );
	}

	/**
	 * Bulk actions in the list view.
	 *
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public function bulk_actions( $actions ) {
		if ( $this->plugin->settings->is_configured() ) {
			foreach ( self::actions() as $action => $label ) {
				$actions[ 'bms3o_' . $action ] = $label;
			}
		}
		return $actions;
	}

	/**
	 * Handles a bulk action (nonce checked by WordPress' list table).
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Attachment IDs.
	 * @return string
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 0 !== strpos( $action, 'bms3o_' ) ) {
			return $redirect;
		}
		$operation = substr( $action, 6 );
		if ( ! array_key_exists( $operation, self::actions() ) ) {
			return $redirect;
		}

		$done   = 0;
		$failed = 0;
		foreach ( array_map( 'intval', (array) $ids ) as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				++$failed;
				continue;
			}
			$error = $this->plugin->bulk->process( $operation, $id, array( 'force' => true ) );
			'' === $error ? ++$done : ++$failed;
		}

		return add_query_arg(
			array(
				'bms3o_done'   => $done,
				'bms3o_failed' => $failed,
			),
			remove_query_arg( array( 'bms3o_done', 'bms3o_failed' ), $redirect )
		);
	}

	/**
	 * Redirects back to the referring screen with a result.
	 *
	 * @param int $done   Successful operations.
	 * @param int $failed Failed operations.
	 * @return void
	 */
	private function redirect_back( $done, $failed ) {
		$referer = wp_get_referer();
		$target  = $referer ? $referer : admin_url( 'upload.php?mode=list' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'bms3o_done'   => (int) ( $done - $failed ),
					'bms3o_failed' => (int) $failed,
				),
				remove_query_arg( array( 'bms3o_done', 'bms3o_failed' ), $target )
			)
		);
		exit;
	}

	/**
	 * Result notice.
	 *
	 * @return void
	 */
	public function notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Display only.
		if ( ! isset( $_GET['bms3o_done'], $_GET['bms3o_failed'] ) ) {
			return;
		}
		$done   = absint( $_GET['bms3o_done'] );
		$failed = absint( $_GET['bms3o_failed'] );
		// phpcs:enable

		$class = $failed ? 'notice-warning' : 'notice-success';
		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>';
		/* translators: 1: number of successful items, 2: number of failed items */
		printf( esc_html__( 'S3 Offload: %1$d done, %2$d failed.', 'bm-s3-offload' ), (int) $done, (int) $failed );
		if ( $failed ) {
			echo ' <a href="' . esc_url( SettingsPage::url( 'tools' ) ) . '">' . esc_html__( 'Run the connection test', 'bm-s3-offload' ) . '</a>';
		}
		echo '</p></div>';
	}

	/**
	 * Status and actions on the attachment edit screen.
	 *
	 * @return void
	 */
	public function submitbox() {
		$post = get_post();
		if ( ! $post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		$item = $this->plugin->attachments->main_item( $post->ID );
		echo '<div class="misc-pub-section misc-pub-bms3o"><span class="dashicons dashicons-cloud" aria-hidden="true"></span> ';
		if ( $item ) {
			echo esc_html( $item['local_removed'] ? __( 'In bucket only', 'bm-s3-offload' ) : __( 'In bucket and local', 'bm-s3-offload' ) );
			echo '<br><code style="word-break:break-all">' . esc_html( $item['bucket'] . '/' . $item['object_key'] ) . '</code>';
		} else {
			esc_html_e( 'Local only', 'bm-s3-offload' );
		}

		$labels = self::actions();
		$links  = array();
		foreach ( $this->applicable( $post->ID ) as $action ) {
			$links[] = '<a href="' . esc_url( $this->action_url( $action, $post->ID ) ) . '">' . esc_html( $labels[ $action ] ) . '</a>';
		}
		if ( $links ) {
			echo '<br>' . implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
		}
		echo '</div>';
	}
}
