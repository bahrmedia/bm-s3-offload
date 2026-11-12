=== BM S3 Offload ===
Contributors: bahrmedia
Tags: s3, offload, buddyboss, woocommerce, cdn
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.9
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Offload your media library to any S3-compatible storage with first-class BuddyBoss support. Free, no Pro version, no SDK.

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
* `wp bm-s3 verify --fix`: check and repair bucket contents (existence and size)
* All settings configurable in wp-config.php (`BMS3O_*` constants)
* Credentials stored encrypted
* No SDK, no external dependencies – an own, lightweight S3 client (AWS Signature V4)
* Core features end-to-end tested against real S3 storage (MinIO), BuddyBoss Platform and WooCommerce

= BuddyBoss =

* Photos, documents and videos are stored as private objects and only delivered after BuddyBoss' own access check (public, logged-in, friends, only me, groups, messages, forums)
* Links in notification e-mails keep working
* Member and group avatars and cover images are mirrored to the bucket
* Thumbnail regeneration and downloads keep working without local files

= WooCommerce =

* Downloadable product files are private objects. After WooCommerce has checked the order, customers are redirected to a short-lived signed URL – downloads never pass through your server.

= WP-CLI =

`wp bm-s3 status|test|offload|remove-local|download|unoffload|privacy|verify|storage|background|bb-sync`

== Installation ==

1. Install and activate the plugin.
2. Go to Media → S3 Offload, choose your provider and enter endpoint, region, bucket and credentials.
3. Run the connection test in the Tools tab.
4. Optionally offload your existing media in the Tools tab.

Make only the object prefix (default `wp-content/uploads/`) publicly readable in your bucket policy; the Tools tab shows an example policy. Private files are stored below `private/`.

== Frequently Asked Questions ==

= Is there a file or size limit? =

There are no quotas and no limits on the number of files. Files are uploaded in parts above 16 MiB and copied in parts above 5 GiB.

= Can I use several storages or move to another provider? =

One storage at a time. To move, copy all objects with a tool such as rclone, then enter the new endpoint and confirm the copy (or run `wp bm-s3 storage confirm`). Until then the plugin pauses all bucket operations.

= What does "private" mean? =

Private media library files are delivered to every visitor, but only with expiring signed URLs. BuddyBoss media and shop downloads are additionally access-checked: signed URLs are only handed out after BuddyBoss or WooCommerce has checked the visitor's permission.

= Does it change my content? =

No. URLs in the database stay local and are rewritten on output, so you can deactivate the plugin at any time (use "Leave the bucket" first if you removed local copies).

= Which permissions does the access key need? =

s3:PutObject, s3:GetObject, s3:DeleteObject, s3:ListBucket and the multipart upload permissions on the bucket.

== Changelog ==

= 1.0.9 =
* Fixed: a file replaced under the same name kept its URL, so browsers and CDNs could show the old file for a long time. Public URLs now carry a version.

= 1.0.8 =
* Fixed: deleting one language version of a translated attachment (WPML, Polylang) deleted the objects of the others.
* Fixed: "Remove local copies" did nothing while Polylang's media translation was active.
* Fixed: Elementor's generated CSS kept outdated bucket URLs after privacy changes or "Leave the bucket".
* Fixed: Enable Media Replace left the old files in the bucket and, with a new file name, did not update URLs in content.

= 1.0.7 =
* Fixed: concurrent changes of the same file (e.g. two opposite privacy changes) could delete all of its objects. All changes of a file are now serialised.
* Fixed: "Leave the bucket" running at the same time as "Remove local copies" could lose files.

= 1.0.6 =
* Fixed: a cleanup whose delete request was slower than its lock could delete an object that was in use again.
* Fixed: a failed upload or privacy change to a key waiting for deletion dropped the deletion.
* Fixed: database errors while queuing deletions were ignored, and a failed migration of the old queue lost its entries.

= 1.0.5 =
* Faster admin pages and bulk operations with large media libraries: existence checks stop at the first row, the counters of the tools tab are cached, new index for local copies.

= 1.0.4 =
* Fixed: concurrent changes of the cleanup queue could get lost (the queue is now a table).
* Fixed: the cleanup could delete an object that was used again at the same moment.
* Fixed: an expired lock could be extended or released by its previous holder.

= 1.0.3 =
* Fixed: the cleanup queue could lose entries added during a cleanup run, ignored the storage they belong to and let permanently failing entries block the others.
* Fixed: a re-upload that made a file private did not report a public copy that could not be deleted yet.
* Fixed: the lock of background operations was not atomic.
* Notice for objects that could not be deleted after several attempts; time budget of bulk operations checked after every item.

= 1.0.2 =
* Notice when WooCommerce downloads or BuddyBoss media exist only in the bucket while their integration is switched off.
* Bulk operations in the browser: time budget per request instead of a fixed number of items; failed requests are retried after 3, 10 and 30 seconds.

= 1.0.1 =
* Fixed: uninstall could remove the mapping of files that exist only in the bucket.
* Fixed: when making files private, a public copy that could not be deleted stayed reachable without notice; it is now reported and removed by an hourly cleanup.
* Fixed: changing the endpoint while files are offloaded required no confirmation and could treat objects as present on the new storage.
* Fixed: failed database writes and failed deletions of deleted media were not handled.
* Fixed: local copies of WooCommerce downloads and BuddyBoss media could be removed while the integration was switched off.
* Local copies are only removed when the object in the bucket has the same size.
* Secrets are never stored in plain text when encryption fails.
* Retries for network errors, throttling and temporary server errors; multipart copy above 5 GiB.

= 1.0.0 =
* Initial release.
