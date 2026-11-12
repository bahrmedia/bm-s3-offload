# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.4] – 2026-11-12

### Fixed

- Cleanup queue: when its lock could not be acquired within five seconds, changes were written anyway and could overwrite each other. The queue is now a table (`{prefix}bms3o_orphans`, one row per object); every change is a single atomic statement and needs no lock. The queue of 1.0.1 – 1.0.3 is migrated on update.
- The cleanup checked whether an object was in use again before deleting it, but without synchronisation: a file made public again at that moment could lose its object. The cleanup now locks every object before checking and deleting it; uploads and privacy changes that write to a queued key take the same lock and remove the entry first (otherwise they report a temporary error).
- Locks had no owner: a request whose lock had expired and been taken over could extend or release the new holder's lock. Locks now carry a random token; extending and releasing only work with it, and a background slice that lost its lock stops without writing its state.

### Changed

- Failed cleanup entries are due again after five minutes, after the other entries.
- Filter `bms3o_object_lock_wait`: how long an upload or privacy change waits for the cleanup of the same object (default 10 seconds).

## [1.0.3] – 2026-11-12

### Fixed

- Cleanup queue: entries added by another request while the hourly cleanup was running were lost. Queue changes are now serialised with an atomic lock and the cleanup only changes the entries it processed.
- Queued deletions did not block a change of storage, and the cleanup ran them against the new storage. They now block an unconfirmed change, carry the storage they belong to and only run there; a confirmed change takes them along.
- A re-upload that made a file private reported success although the previous public copy could not be deleted yet; it is now reported like a privacy change.
- The lock of background operations used `add_option()`, which is not atomic (check, then upsert): slices started at the same moment could both acquire it. Both locks now use an atomic `INSERT IGNORE` (class `Lock`); in a test with 20 simultaneous processes exactly one acquires it (previously 7–12).
- Entries that failed permanently blocked the following ones (always the first 200 were tried). Failed entries now move to the end of the queue.

### Changed

- Objects that could not be deleted after several attempts are shown as a notice; the tools tab and `wp bm-s3 status` show the cleanup queue.
- The time budget of bulk operations (browser and background) is checked after every item instead of after every batch of 10.
- README: method of the SDK size measurement.

## [1.0.2] – 2026-11-12

### Added

- Admin notice when WooCommerce downloads or BuddyBoss media exist only in the bucket while their integration is inactive or switched off (they cannot be delivered then), with the way out: switch the integration on or restore the local copies.

### Changed

- Bulk operations in the browser process batches until a time budget of 20 seconds per request is used up (at most half of `max_execution_time`) instead of a fixed 10 items, so fewer requests are needed for small files and large files do not run into timeouts. Filter `bms3o_bulk_time_limit`.
- Failed requests of a bulk operation in the browser (network errors, timeouts, proxy errors) are retried after 3, 10 and 30 seconds instead of stopping the operation. Refusals by the server (permissions, invalid request) still stop it.

## [1.0.1] – 2026-11-12

### Fixed

- Uninstall with "Remove data" dropped the offload table even when files existed only in the bucket (the table check always failed).
- Making files private: when the previous public copy could not be deleted, the change was reported as successful and the public URL stayed reachable. It is now reported and the copy is removed by an hourly cleanup. The same applies when a re-upload moves a file.
- Changing the endpoint while files are offloaded is only accepted with the confirmation that all objects have been copied. If the endpoint is changed by constant or environment variable, all bucket operations (uploads, deletions, removal of local copies, privacy changes) are paused until `wp bm-s3 storage confirm`.
- Failed database writes are detected: objects are only deleted after the new state has been recorded.
- Objects of deleted media whose deletion failed are no longer forgotten but retried by the hourly cleanup.
- Local copies of WooCommerce downloads and BuddyBoss media are only removed while the matching integration delivers them from the bucket (previously also with the integration switched off or the plugin inactive, which broke downloads and media).

### Changed

- Local copies are only removed when the object in the bucket has the same size; `wp bm-s3 verify` also reports and repairs size mismatches.
- When the secret key cannot be encrypted, it is not saved (previously stored in plain text).
- The S3 client retries network errors, throttling (429) and temporary server errors (5xx) twice with backoff.
- Objects larger than 5 GiB are copied with a multipart copy (making them private or public).
- `wp bm-s3 storage` and `wp bm-s3 status` show the storage check and pending cleanups.
- README: dated, per-product comparison instead of generalised claims; precise wording on tests, limits and "private".

## [1.0.0] – 2026-11-11

### Added

- Dependency-free S3 client (AWS Signature V4): PutObject, streamed multipart upload, streamed download, HeadObject, DeleteObject(s), ListObjectsV2, pre-signed URLs.
- Provider presets for Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, Hetzner, IONOS, Scaleway and MinIO; any custom endpoint.
- Automatic offload of new uploads, all image sizes, unscaled originals and image-editor backups.
- Optional removal of local copies with on-demand restore for the image editor and thumbnail regeneration.
- Delivery from bucket, CDN/custom domain or with cacheable pre-signed URLs; output rewriting of post content.
- Unique file names even when colliding files only exist in the bucket.
- BuddyBoss integration: private photos/documents/videos with BuddyBoss access checks, mirrored avatars and cover images.
- Admin screen with connection test, statistics, bulk operations and example bucket policy.
- Media library column, row actions and bulk actions.
- WP-CLI commands `wp bm-s3 …`.
- Settings overridable by `BMS3O_*` constants or environment variables; encrypted secret storage.
- English and German translations.
- Make media private (expiring signed URLs) or public, per file or in bulk (server-side copy).
- WooCommerce: downloadable products are redirected to short-lived signed URLs after WooCommerce's checks; Easy Digital Downloads files are kept private and local.
- Bulk operations in the background via WP-Cron, controllable in the admin and with `wp bm-s3 background`.
- Large uploads (> 64 MiB) are offloaded in the background after the upload request.
- Media library filter by storage state.
- Pull CDN for theme, plugin and core scripts and styles.
- WebP/AVIF copies of image optimisers (`photo.jpg.webp`) are offloaded, rewritten and removed with the image.
- Elementor output is rewritten.
- `wp bm-s3 verify [--fix]` checks every object and repairs missing ones from local copies.
- End-to-end test suite (WordPress 6.2/PHP 7.4 and latest/PHP 8.3, MinIO, BuddyBoss, WooCommerce) in CI.

### Security

- Restricted files (BuddyBoss media, WooCommerce and Easy Digital Downloads files) get signed URLs only in access-checked contexts (BuddyBoss/WooCommerce access checks, administrators); content filters and generic attachment URLs never sign them. Private media library files are delivered to everybody with expiring signed URLs.
- BuddyBoss media directories stay private even when the BuddyBoss integration is switched off.
- Prefix combinations that would place private files in the public area are rejected.
- On multisite the settings are reserved to super admins.
- BuddyBoss: signed URLs in "direct" mode and for documents/videos only after BuddyBoss' access check for the current user; downloads only inside BuddyBoss' own download functions.
- Bucket/CDN URLs in saved content are only mapped back to known files (no decoding of user input after sanitisation).
- Changing the BuddyBoss "private media" setting moves existing objects (background operation).
- BuddyBoss' direct-access self-test stays active while local copies exist.
- Change detection by size and modification time; multi-network sites get separate keys.
