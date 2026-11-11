=== BM S3 Offload ===
Contributors: bahrmedia
Tags: s3, offload, buddyboss, woocommerce, cdn
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload your media library to any S3-compatible storage with first-class BuddyBoss support. Free, no limits, no SDK.

== Description ==

BM S3 Offload copies your media to Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, Hetzner, IONOS, Scaleway, MinIO or any other S3-compatible object storage and serves it from there.

Everything is free – there is no Pro version:

* Automatic offload of new uploads including all image sizes (large uploads in the background)
* Bulk migration of existing media – in the browser, in the background (WP-Cron) or with WP-CLI
* Optional removal of local copies – restored automatically when WordPress needs them
* Delivery from the bucket, a CDN or with pre-signed URLs
* Make single files private (expiring signed URLs) or public
* Media library: status column, filter, row and bulk actions
* CDN for theme, plugin and core scripts and styles
* WebP/AVIF copies of image optimisers are offloaded too
* "Leave the bucket": move everything back with one click
* `wp bm-s3 verify --fix`: check and repair bucket contents
* All settings configurable in wp-config.php (`BMS3O_*` constants)
* Credentials stored encrypted
* No SDK, no external dependencies – an own, lightweight S3 client (AWS Signature V4)
* End-to-end tested against real S3 storage, BuddyBoss and WooCommerce

= BuddyBoss =

* Photos, documents and videos are stored as private objects and only delivered after BuddyBoss' own access check (public, logged-in, friends, only me, groups, messages, forums)
* Links in notification e-mails keep working
* Member and group avatars and cover images are mirrored to the bucket
* Thumbnail regeneration and downloads keep working without local files

= WooCommerce =

* Downloadable product files are private objects. After WooCommerce has checked the order, customers are redirected to a short-lived signed URL – downloads never pass through your server.

= WP-CLI =

`wp bm-s3 status|test|offload|remove-local|download|unoffload|privacy|verify|background|bb-sync`

== Installation ==

1. Install and activate the plugin.
2. Go to Media → S3 Offload, choose your provider and enter endpoint, region, bucket and credentials.
3. Run the connection test in the Tools tab.
4. Optionally offload your existing media in the Tools tab.

Make only the object prefix (default `wp-content/uploads/`) publicly readable in your bucket policy; the Tools tab shows an example policy. Private files are stored below `private/`.

== Frequently Asked Questions ==

= Is there a file or size limit? =

No.

= Does it change my content? =

No. URLs in the database stay local and are rewritten on output, so you can deactivate the plugin at any time (use "Leave the bucket" first if you removed local copies).

= Which permissions does the access key need? =

s3:PutObject, s3:GetObject, s3:DeleteObject, s3:ListBucket and the multipart upload permissions on the bucket.

== Changelog ==

= 1.0.0 =
* Initial release.
