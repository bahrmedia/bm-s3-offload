# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
