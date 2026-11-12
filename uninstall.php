<?php
/**
 * Uninstall: removes settings and the offload table if the user opted in.
 *
 * Objects in the bucket are never touched.
 *
 * @package BahrMedia\S3Offload
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Removes the data of the current site.
 *
 * @return void
 */
function bms3o_uninstall_site() {
	global $wpdb;

	$settings = get_option( 'bms3o_settings', array() );
	$remove   = is_array( $settings ) && ! empty( $settings['remove_data'] );
	if ( defined( 'BMS3O_REMOVE_DATA' ) ) {
		$remove = (bool) BMS3O_REMOVE_DATA;
	}

	wp_clear_scheduled_hook( 'bms3o_cron' );
	wp_clear_scheduled_hook( 'bms3o_background_run' );
	wp_unschedule_hook( 'bms3o_offload_attachment' );

	if ( ! $remove ) {
		return;
	}

	$table = $wpdb->prefix . 'bms3o_items';

	// Files that only exist in the bucket would lose their mapping: keep everything
	// until "Leave the bucket" (or "Restore local copies") has been run.
	$exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $exists ) {
		$bucket_only = $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE local_removed = 1" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		// Keep everything when the count fails (null) or files exist only in the bucket.
		if ( null === $bucket_only || (int) $bucket_only > 0 ) {
			return;
		}
	}

	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$orphans = $wpdb->prefix . 'bms3o_orphans';
	$wpdb->query( "DROP TABLE IF EXISTS {$orphans}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange
	delete_option( 'bms3o_settings' );
	delete_option( 'bms3o_db_version' );
	delete_option( 'bms3o_retry' );
	delete_option( 'bms3o_background' );
	delete_option( 'bms3o_background_lock' );
	delete_option( 'bms3o_orphans_lock' );
	delete_option( 'bms3o_orphans' );
	delete_option( 'bms3o_storage' );
	delete_transient( 'bms3o_bb_dirs' );
}

if ( is_multisite() ) {
	$bms3o_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $bms3o_site_ids as $bms3o_site_id ) {
		switch_to_blog( $bms3o_site_id );
		bms3o_uninstall_site();
		restore_current_blog();
	}
} else {
	bms3o_uninstall_site();
}
