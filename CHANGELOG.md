# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

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

- Private files get signed URLs only in access-checked contexts (BuddyBoss access checks, administrators); content filters and generic attachment URLs never sign private files.
- BuddyBoss media directories stay private even when the BuddyBoss integration is switched off.
- Prefix combinations that would place private files in the public area are rejected.
- On multisite the settings are reserved to super admins.
- BuddyBoss: signed URLs in "direct" mode and for documents/videos only after BuddyBoss' access check for the current user; downloads only inside BuddyBoss' own download functions.
- Bucket/CDN URLs in saved content are only mapped back to known files (no decoding of user input after sanitisation).
- Changing the BuddyBoss "private media" setting moves existing objects (background operation).
- BuddyBoss' direct-access self-test stays active while local copies exist.
- Change detection by size and modification time; multi-network sites get separate keys.
