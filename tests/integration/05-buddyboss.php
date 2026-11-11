<?php
/**
 * BuddyBoss Platform integration (skipped when BuddyBoss is not active).
 *
 * @package BahrMedia\S3Offload
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DiscouragedPHPFunctions

require getenv( 'BMS3O_TEST_DIR' ) . '/lib.php';

bms3o_t_section( 'BuddyBoss' );

if ( ! defined( 'BP_PLATFORM_VERSION' ) || ! function_exists( 'bp_is_active' ) || ! bp_is_active( 'media' ) || ! bp_is_active( 'groups' ) ) {
	echo "  BuddyBoss Platform with media and groups is not active – skipped.\n";
	bms3o_t_done();
	return;
}

$plugin    = bms3o_t_plugin();
$offloader = $plugin->offloader;
$media     = $plugin->attachments;
$site      = bms3o_t_url();

bms3o_t_settings(
	array(
		'copy_on_upload' => true,
		'remove_local'   => false,
		'rewrite_urls'   => true,
		'bb_enabled'     => true,
		'bb_private'     => true,
		'bb_delivery'    => 'proxy',
		'bb_avatars'     => true,
	)
);

$anna = bms3o_t_user( 'bms3o_anna' );
$ben  = bms3o_t_user( 'bms3o_ben' );

/**
 * Creates a BuddyBoss photo the way BuddyBoss stores uploads.
 *
 * @param int    $user_id  Owner.
 * @param string $privacy  Privacy.
 * @param int    $group_id Group.
 * @return array{media_id:int,attachment_id:int}
 */
function bms3o_t_bb_photo( $user_id, $privacy, $group_id = 0 ) {
	wp_set_current_user( $user_id );
	add_filter( 'upload_dir', 'bp_media_upload_dir_script' );
	$attachment_id = bms3o_t_import( bms3o_t_image( 900, 600, 'bb-photo.jpg' ) );
	remove_filter( 'upload_dir', 'bp_media_upload_dir_script' );
	update_post_meta( $attachment_id, 'bp_media_upload', 1 );
	update_post_meta( $attachment_id, 'bp_media_saved', 1 );

	$media_id = bp_media_add(
		array(
			'attachment_id' => $attachment_id,
			'user_id'       => $user_id,
			'privacy'       => $privacy,
			'group_id'      => $group_id,
			'title'         => 'bms3o test',
		)
	);
	wp_set_current_user( 0 );

	return array(
		'media_id'      => (int) $media_id,
		'attachment_id' => (int) $attachment_id,
	);
}

/**
 * Switches the user for WordPress and BuddyBoss (BuddyBoss caches the logged-in user).
 *
 * @param int $user_id User ID (0 = logged out).
 * @return void
 */
function bms3o_t_bb_login( $user_id ) {
	wp_set_current_user( $user_id );
	remove_all_filters( 'bp_loggedin_user_id' );
	add_filter(
		'bp_loggedin_user_id',
		static function () use ( $user_id ) {
			return $user_id;
		}
	);
}

/**
 * Result of requesting a URL as a user: 'bucket' (redirect to a reachable
 * bucket URL), 'denied' or the HTTP status.
 *
 * @param string $url     URL.
 * @param int    $user_id User (0 = anonymous).
 * @return string
 */
function bms3o_t_fetch_as( $url, $user_id ) {
	$response = bms3o_t_get( $url, $user_id ? bms3o_t_cookie( $user_id ) : '' );
	if ( 302 === $response['code'] && '' !== $response['location'] ) {
		return 200 === bms3o_t_status( $response['location'] ) ? 'bucket' : 'broken-redirect';
	}
	if ( false !== strpos( $response['body'], 'Silence is golden' ) || 403 === $response['code'] ) {
		return 'denied';
	}
	return (string) $response['code'];
}

$private = bms3o_t_bb_photo( $anna, 'onlyme' );
$public  = bms3o_t_bb_photo( $anna, 'public' );

$file = get_post_meta( $private['attachment_id'], '_wp_attached_file', true );
$item = $offloader->items()->get( $file );
bms3o_t( 0 === strpos( $file, 'bb_medias/' ) && $item && $item['is_private'], 'BuddyBoss photos are stored as private objects', $file );
bms3o_t( 0 === strpos( wp_get_attachment_url( $private['attachment_id'] ), $offloader->uploads()['baseurl'] ), 'No signed URL for anonymous generic requests' );

$private_url = bp_media_get_preview_image_url( $private['media_id'], $private['attachment_id'], 'bb-media-activity-image' );
$public_url  = bp_media_get_preview_image_url( $public['media_id'], $public['attachment_id'], 'bb-media-activity-image' );
bms3o_t( false !== strpos( $private_url, '/bb-media-preview/' ), 'Proxy mode uses BuddyBoss preview URLs', $private_url );

foreach ( array( 'with local files', 'without local files' ) as $round ) {
	bms3o_t( 'bucket' === bms3o_t_fetch_as( $private_url, $anna ), "Owner sees her private photo ({$round})" );
	bms3o_t( 'denied' === bms3o_t_fetch_as( $private_url, $ben ), "Other members are denied ({$round})" );
	bms3o_t( 'denied' === bms3o_t_fetch_as( $private_url, 0 ), "Visitors are denied ({$round})" );
	bms3o_t( 'bucket' === bms3o_t_fetch_as( $public_url, 0 ), "Public photos are visible to visitors ({$round})" );

	$media->remove_local( $private['attachment_id'] );
	$media->remove_local( $public['attachment_id'] );
}

$email_url = bp_media_get_preview_image_url( $private['media_id'], $private['attachment_id'], 'bb-media-activity-image', true, $anna );
bms3o_t( 'bucket' === bms3o_t_fetch_as( $email_url, 0 ), 'E-mail links (signed receiver) work without login' );
$forged = preg_replace( '#/[^/]+$#', '/' . base64_encode( 'receiver_' . $anna . '_forged' ), $email_url );
bms3o_t( 'denied' === bms3o_t_fetch_as( $forged, 0 ), 'Forged receiver segment is denied' );

bms3o_t_section( 'BuddyBoss downloads' );

$download = add_query_arg(
	array(
		'attachment_id'       => $private['attachment_id'],
		'download_media_file' => 1,
		'media_file'          => $private['media_id'],
		'media_type'          => 'media',
	),
	$site . '/'
);
$owner    = bms3o_t_get( $download, bms3o_t_cookie( $anna ) );
bms3o_t( 200 === $owner['code'] && strlen( $owner['body'] ) > 1000, 'Owner can download her private photo (local copy removed)', $owner['code'] );
$other = bms3o_t_get( $download, bms3o_t_cookie( $ben ) );
bms3o_t( 200 !== $other['code'] || strlen( $other['body'] ) < 1000, 'Other members cannot download it' );
$bypass = bms3o_t_get(
	add_query_arg(
		array(
			'attachment_id'       => $private['attachment_id'],
			'download_media_file' => 1,
		),
		$site . '/'
	)
);
bms3o_t( false === strpos( $bypass['location'] . $bypass['body'], 'X-Amz' ), 'Incomplete download parameters never yield a signed URL', $bypass['location'] );

bms3o_t_settings( array( 'bb_delivery' => 'direct' ) );
$direct = bp_media_get_preview_image_url( $public['media_id'], $public['attachment_id'], 'bb-media-activity-image' );
bms3o_t( false !== strpos( $direct, 'X-Amz-Signature' ) && 200 === bms3o_t_status( $direct ), 'Direct mode embeds signed bucket URLs', $direct );
bms3o_t_bb_login( $ben );
$foreign = bp_media_get_preview_image_url( $private['media_id'], $private['attachment_id'], 'bb-media-activity-image' );
bms3o_t( false === strpos( $foreign, 'X-Amz' ) && false !== strpos( $foreign, '/bb-media-preview/' ), 'Direct mode: no signed URL for media the user may not see (any listing)', $foreign );
bms3o_t_bb_login( $anna );
$own = bp_media_get_preview_image_url( $private['media_id'], $private['attachment_id'], 'bb-media-activity-image' );
bms3o_t( false !== strpos( $own, 'X-Amz-Signature' ), 'Direct mode: signed URL for the owner' );
bms3o_t_bb_login( 0 );
bms3o_t_settings( array( 'bb_delivery' => 'proxy' ) );

bms3o_t_section( 'BuddyBoss privacy setting changes' );

bms3o_t_settings( array( 'bb_private' => false ) );
do {
	$batch = $plugin->bulk->run( 'sync_privacy', 0, 50 );
} while ( ! $batch['done'] );
bms3o_t( ! $offloader->items()->get( $file )['is_private'], 'Turning "private media" off moves existing objects to the public prefix' );
bms3o_t_settings( array( 'bb_private' => true ) );
bms3o_t( $plugin->bulk->remaining( 'sync_privacy' ) > 0, 'Turning it on again reports pending objects' );
do {
	$batch = $plugin->bulk->run( 'sync_privacy', 0, 50 );
} while ( ! $batch['done'] );
$item = $offloader->items()->get( $file );
bms3o_t( $item['is_private'] && 403 === bms3o_t_status( $plugin->settings->client()->object_url( $item['object_key'] ) ) && 0 === $plugin->bulk->remaining( 'sync_privacy' ), 'Existing objects are private again and not publicly readable' );

bms3o_t( false === apply_filters( 'bb_media_check_default_access', false ) || $plugin->settings->get( 'remove_local' ), 'BuddyBoss keeps checking direct access while local copies exist' );

bms3o_t_section( 'BuddyBoss private group' );

$group_id = groups_create_group(
	array(
		'creator_id' => $anna,
		'name'       => 'bms3o secret ' . wp_generate_password( 4, false ),
		'status'     => 'private',
	)
);
$group    = bms3o_t_bb_photo( $anna, 'grouponly', (int) $group_id );
$media->remove_local( $group['attachment_id'] );
$group_url = bp_media_get_preview_image_url( $group['media_id'], $group['attachment_id'], 'bb-media-activity-image' );
bms3o_t( 'denied' === bms3o_t_fetch_as( $group_url, $ben ), 'Non-members are denied' );
groups_join_group( $group_id, $ben );
bms3o_t( 'bucket' === bms3o_t_fetch_as( $group_url, $ben ), 'Members see group photos' );

bms3o_t_section( 'BuddyBoss avatars and cover images' );

$avatar_dir = bp_core_avatar_upload_path() . '/avatars/' . $anna;
wp_mkdir_p( $avatar_dir );
copy( bms3o_t_image( 300, 300, 'a.png' ), $avatar_dir . '/bms3otest-bpfull.png' );
copy( bms3o_t_image( 150, 150, 'a.png' ), $avatar_dir . '/bms3otest-bpthumb.png' );
do_action( 'xprofile_avatar_uploaded', $anna, 'crop', array() );
$avatar = bp_core_fetch_avatar(
	array(
		'item_id' => $anna,
		'html'    => false,
		'type'    => 'full',
	)
);
bms3o_t( false === strpos( $avatar, $offloader->uploads()['baseurl'] ) && 200 === bms3o_t_status( $avatar ), 'Avatar is served from the bucket', $avatar );
bms3o_t( bp_get_user_has_avatar( $anna ), 'BuddyBoss still detects the avatar' );

$cover_dir = bp_attachments_uploads_dir_get( 'basedir' ) . '/members/' . $anna . '/cover-image';
wp_mkdir_p( $cover_dir );
copy( bms3o_t_image( 1200, 300, 'c.png' ), $cover_dir . '/bms3otest-bp-cover-image.png' );
do_action( 'xprofile_cover_image_uploaded', $anna, 'bms3otest-bp-cover-image.png', '', 1 );
$cover_url  = bp_attachments_get_attachment( 'url', array( 'item_id' => $anna ) );
$cover_path = bp_attachments_get_attachment( 'path', array( 'item_id' => $anna ) );
bms3o_t( false === strpos( $cover_url, $offloader->uploads()['baseurl'] ) && 200 === bms3o_t_status( $cover_url ), 'Cover image URL comes from the bucket', $cover_url );
bms3o_t( is_string( $cover_path ) && file_exists( $cover_path ), 'Cover image path lookups stay local', $cover_path );

bp_core_delete_existing_avatar(
	array(
		'item_id' => $anna,
		'object'  => 'user',
	)
);
$plugin->buddyboss->process_sync_queue();
bms3o_t( empty( $offloader->items()->get_in_dir( 'avatars/' . $anna ) ), 'Deleting the avatar deletes its objects' );

bp_attachments_delete_file(
	array(
		'item_id'    => $anna,
		'object_dir' => 'members',
		'type'       => 'cover-image',
	)
);
do_action( 'xprofile_cover_image_deleted', $anna );
bms3o_t( empty( $offloader->items()->get_in_dir( 'buddypress/members/' . $anna . '/cover-image' ) ), 'Deleting the cover image deletes its objects' );

bms3o_t_section( 'BuddyBoss cleanup' );

foreach ( array( $private, $public, $group ) as $photo ) {
	bp_media_delete( array( 'id' => $photo['media_id'] ) );
}
bms3o_t( null === $offloader->items()->get( $file ), 'Deleting BuddyBoss media deletes the objects' );
groups_delete_group( $group_id );

bms3o_t_done();
