<?php
/**
 * Prepares BuddyBoss Platform for the integration tests (run with wp eval-file).
 *
 * @package BahrMedia\S3Offload
 */

// BuddyBoss runs its installer on the first admin request after activation and
// would reset the components set below: run it now.
if ( function_exists( 'bp_is_update' ) && bp_is_update() ) {
	bp_version_updater();
}

$bms3o_components = bp_get_option( 'bp-active-components', array() );
foreach ( array( 'activity', 'groups', 'media', 'messages', 'friends', 'members', 'xprofile', 'settings', 'notifications' ) as $bms3o_component ) {
	$bms3o_components[ $bms3o_component ] = 1;
}
bp_update_option( 'bp-active-components', $bms3o_components );

require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-schema.php';
bp_core_install( $bms3o_components );

foreach ( array( 'bp_media_profile_media_support', 'bp_media_group_media_support', 'bp_media_messages_media_support' ) as $bms3o_option ) {
	bp_update_option( $bms3o_option, 1 );
}

echo "BuddyBoss prepared.\n";
