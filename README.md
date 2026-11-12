# BM S3 Offload

Offload the WordPress media library to **any S3-compatible object storage** – with first-class **BuddyBoss** support.

**Free. Open source (GPL-2.0-or-later). No Pro version, no file quotas. No SDK.**

[Deutsche Kurzbeschreibung weiter unten](#deutsch)

---

## Why another offload plugin?

In November 2026 we analysed the free versions of seven popular offload plugins. Each has its strengths, but none of them offers all of the following in its free version: bulk migration, private files with signed URLs, WP-CLI, any S3-compatible endpoint and BuddyBoss media with BuddyBoss' privacy checks. Several paid versions do (e.g. WP Offload Media with its BuddyBoss integration).

| Free version analysed | Bulk migration | Private files / signed URLs | WP-CLI | Any S3 endpoint | BuddyBoss media | Bundled SDK |
|---|---|---|---|---|---|---|
| WP Offload Media Lite 3.4.4 | paid | paid | paid | fixed providers | paid version | AWS + GCS, 23 MB |
| Media Cloud 4.7.4 | paid | ✔ (per media type paid) | partly | ✔ | paid | AWS, 29 MB |
| Advanced Media Offloader 4.7.3 | ✔ (in batches) | – | ✔ | ✔ | – | AWS, 5.9 MB |
| WP-Stateless 4.5.0 | ✔ | – | ✔ | Google Cloud Storage only | free add-on | Google, 16 MB |
| Infinite Uploads 3.5.2 | ✔ (within the quota) | – | ✔ | own cloud service only | avatars/covers stay local | AWS, 54 MB |
| Media Cloud Sync 1.5.0 | ✔ (cron; browser paid) | paid | basic | ✔ | paid | AWS + GCS, 20 MB |
| Offload Media – Cloud Storage 1.9.0 | paid | ✔ | – | AWS/GCS/DO/R2 (others paid) | – | AWS + GCS, 57 MB |
| **BM S3 Offload** | ✔ browser, background, WP-CLI | ✔ | ✔ | ✔ | ✔ with BuddyBoss' access checks | none (< 400 KB in total) |

Versions as analysed on 2026-11-11 from their source code (ZIPs from wordpress.org); SDK sizes are the size of the bundled SDK directory on disk after unpacking (`du -sh` of the vendor/SDK folder). Plans and features of other plugins change – check their current documentation.

BM S3 Offload is free without a Pro version, ships its own small S3 client instead of a vendor SDK, and integrates BuddyBoss' own access checks. Its core features are covered by end-to-end tests against WordPress, a real S3 server (MinIO), BuddyBoss Platform and WooCommerce; BuddyBoss' commercial document and video add-ons are not part of the tests.

## Features

- **Every S3-compatible provider** – Amazon S3, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean Spaces, Hetzner Object Storage, IONOS, Scaleway, MinIO, Garage, Ceph … (presets included, any custom endpoint works; virtual-hosted or path-style addressing).
- **Automatic offload** of new uploads: original, every image size, the unscaled original (`-scaled` images) and image-editor backups.
- **Remove local copies** (optional). Files are restored on demand when WordPress needs them (image editor, `wp media regenerate`, BuddyBoss thumbnail regeneration) and removed again afterwards.
- **Delivery** from the bucket or a CDN / custom domain. URLs in post content are rewritten on output – the database keeps local URLs, so you are never locked in.
- **Private files** with pre-signed URLs (AWS Signature V4). Signed URLs are stable for half of their lifetime, so browsers can cache them.
  - *Private* media (marked in the media library): delivered to everybody, but only with expiring signed URLs – hotlink protection, links stop working after the lifetime.
  - When files become private, copies already cached by a CDN stay reachable until their `max-age` expires – purge the CDN if needed.
  - *Restricted* media (BuddyBoss photos/documents/videos, WooCommerce and Easy Digital Downloads product files): signed URLs only after the owning plugin has checked the access – never by generic filters such as post content.
- **Make media private or public** per file or in bulk – objects are moved inside the bucket (server-side copy, no re-upload).
- **Bulk tools** in the admin – in the browser or **in the background via WP-Cron** (close the page and come back later) – and **WP-CLI** for huge libraries.
- **WooCommerce**: downloadable products are delivered from the bucket. WooCommerce checks the order, then the customer is redirected to a short-lived signed URL – downloads never pass through your server.
- **CDN for scripts and styles**: rewrite theme, plugin and core assets to a pull CDN (front end only).
- **WebP/AVIF copies** created by image optimisers (`photo.jpg.webp`, EWWW, WebP Express) are offloaded, rewritten and removed together with the image.
- **Page builders**: Elementor output is rewritten like post content.
- **Verify** (`wp bm-s3 verify --fix`): checks that every offloaded file exists in the bucket and repairs missing objects from local copies.
- **Leave the bucket**: move everything back to the server with one click.
- **Media library**: status column, filter by storage state, row actions and bulk actions (offload, remove local copy, restore, make private/public, remove from bucket).
- **Safe by design**: local files are only deleted after a successful upload (and never after a fatal error), bulk removal verifies each object first, file names stay unique even when the colliding file only exists in the bucket, editors never save bucket URLs into posts, a broken configuration falls back to local URLs instead of breaking the site, and prefixes that would expose private files are rejected.
- **Configuration via wp-config.php or environment variables** for every setting.
- **Multisite** compatible: one table per site, sub-site keys mirror WordPress' layout (`…/uploads/sites/2/…`) so sites can share a bucket, settings are reserved to super admins.
- Translations: English, German.

### BuddyBoss

Tested with BuddyBoss Platform 3.7.1.

| What | How |
|---|---|
| Photos (activity, profiles, groups, forums, messages) | Stored as **private objects**. Delivered through BuddyBoss' own preview URLs: every request runs BuddyBoss' access check (public, logged-in, friends, only me, group members, message participants, forums) and is then redirected to a short-lived signed URL. Links in e-mails carry BuddyBoss' signed receiver segment and keep working. Optional "direct" mode embeds signed URLs in pages that BuddyBoss has already filtered by privacy. |
| Documents, videos (BuddyBoss add-ons) | Private objects, delivered with signed URLs via BuddyBoss' delivery filters – only after BuddyBoss' access check for the current user. Not tested end-to-end (the add-ons are commercial); links in e-mails expire after the signed URL lifetime. |
| Downloads | BuddyBoss' download handler checks permissions and then serves the bucket file. |
| Avatars and cover images (members, groups) | Mirrored to the bucket and served from there. Local copies are kept because BuddyBoss detects them by scanning the file system. |
| Thumbnail regeneration | Originals are restored temporarily and the new sizes are offloaded. |

BuddyBoss' symlink option is disabled automatically while media are offloaded, and its "direct access" check in the media settings knows about the offload.

### WooCommerce and Easy Digital Downloads

| What | How |
|---|---|
| WooCommerce product files (`woocommerce_uploads/`) | Restricted private objects. After WooCommerce has validated the order, download limit and expiry, the customer is redirected to a signed URL (valid for 10 minutes, `Content-Disposition: attachment`). |
| Easy Digital Downloads files (`edd/`) | Stored as restricted private objects (backup); local copies are always kept, EDD serves them as usual. |

## Requirements

- WordPress 6.2+
- PHP 7.4+ – only extensions that every WordPress host provides (cURL or OpenSSL streams, SimpleXML, sodium)

## Installation

1. Download the latest release ZIP from [Releases](https://github.com/bahrmedia/bm-s3-offload/releases) and upload it in *Plugins → Add New → Upload Plugin*.
2. Go to *Media → S3 Offload*, select your provider and enter endpoint, region, bucket and credentials.
3. Click *Tools → Run connection test*.
4. Optional: *Tools → Offload existing media*.

### Bucket setup

Public files are stored below the **object prefix** (default `wp-content/uploads/`), private files below the **private prefix** (default `private/wp-content/uploads/`). Make only the object prefix publicly readable, e.g.:

```json
{
  "Version": "2012-10-17",
  "Statement": [{
    "Sid": "PublicReadUploads",
    "Effect": "Allow",
    "Principal": "*",
    "Action": ["s3:GetObject"],
    "Resource": ["arn:aws:s3:::my-bucket/wp-content/uploads/*"]
  }]
}
```

The connection test verifies that public files are readable and private files are not. Alternatively enable *Signed URLs for all files* and keep the whole bucket private.

On multisite, sub-sites use keys below `{object_prefix}sites/{blog_id}/`, exactly like the local uploads folder, so all sites can share one bucket and one set of credentials.

The access key needs `s3:PutObject`, `s3:GetObject`, `s3:DeleteObject`, `s3:ListBucket` and the multipart permissions (`s3:AbortMultipartUpload`, `s3:ListMultipartUploadParts`) on the bucket.

### Configuration in wp-config.php

Every setting can be defined as constant (or environment variable) `BMS3O_<SETTING>`. Defined settings are locked in the admin screen.

```php
define( 'BMS3O_PROVIDER', 'r2' );
define( 'BMS3O_ENDPOINT', 'https://<account-id>.r2.cloudflarestorage.com' );
define( 'BMS3O_REGION', 'auto' );
define( 'BMS3O_BUCKET', 'my-bucket' );
define( 'BMS3O_ACCESS_KEY', '…' );
define( 'BMS3O_SECRET_KEY', '…' );
define( 'BMS3O_PATH_STYLE', true );
define( 'BMS3O_DELIVERY_DOMAIN', 'https://media.example.com' );
define( 'BMS3O_REMOVE_LOCAL', true );
```

| Setting | Default | Description |
|---|---|---|
| `provider` | `custom` | Preset (`aws`, `r2`, `wasabi`, `b2`, `digitalocean`, `hetzner`, `ionos`, `scaleway`, `minio`, `custom`) |
| `endpoint` | – | Endpoint URL without bucket |
| `region` | `us-east-1` | Signing region |
| `bucket` | – | Bucket name |
| `access_key` / `secret_key` | – | Credentials (the secret is stored encrypted if saved in the admin) |
| `path_style` | `false` | Path-style URLs (`endpoint/bucket/key`) |
| `object_prefix` | `wp-content/uploads/` | Prefix of all object keys |
| `private_prefix` | `private/` | Additional prefix of private objects |
| `object_acl` | `''` | `public-read` to set ACLs on upload (only for buckets with ACLs) |
| `cache_control` | `public, max-age=31536000` | Cache-Control header of public objects |
| `copy_on_upload` | `true` | Offload new uploads |
| `remove_local` | `false` | Remove local copies after upload |
| `delete_remote` | `true` | Delete objects when a media item is deleted |
| `rewrite_urls` | `true` | Serve files from the bucket / CDN |
| `delivery_domain` | `''` | CDN or custom domain pointing to the bucket root |
| `sign_all` | `false` | Deliver every file with a signed URL |
| `signed_ttl` | `3600` | Lifetime of signed URLs in seconds |
| `bb_enabled` | `true` | BuddyBoss integration |
| `bb_private` | `true` | Store BuddyBoss media (`bb_medias/`, `bb_documents/`, `bb_videos/`) as private objects – also when the integration is switched off |
| `bb_delivery` | `proxy` | `proxy` (BuddyBoss preview URL + access check) or `direct` (signed URL in the page) |
| `bb_avatars` | `true` | Mirror avatars and cover images |
| `wc_enabled` | `true` | Deliver WooCommerce downloads from the bucket |
| `assets_domain` | `''` | Pull CDN for scripts and styles (origin = this site) |
| `remove_data` | `false` | Delete settings and table on uninstall |

## WP-CLI

```bash
wp bm-s3 status                      # configuration and statistics
wp bm-s3 test                        # connection test
wp bm-s3 offload --all [--remove-local]
wp bm-s3 offload 12 34 --force       # (re-)upload single items
wp bm-s3 remove-local --all          # verifies every object before deleting
wp bm-s3 download --all              # restore local copies
wp bm-s3 unoffload --all --yes       # move everything back and empty the bucket
wp bm-s3 bb-sync                     # mirror existing BuddyBoss avatars and covers
wp bm-s3 privacy 12 34 --private     # expiring signed URLs (or --public)
wp bm-s3 verify [--fix]              # check every object (existence and size), repair from local copies
wp bm-s3 storage [confirm]           # storage check; confirm a change of storage
wp bm-s3 background start offload --remove-local   # run in the background (WP-Cron)
wp bm-s3 background status|stop
```

## Changing the storage

The plugin works with one storage at a time. To move to another provider or endpoint:

1. Copy all objects with a tool built for it, e.g. `rclone sync old:bucket new:bucket` (or the provider's migration service, such as Cloudflare R2 Super Slurper).
2. Enter the new endpoint (and credentials) and confirm *All objects have been copied* – or, if the endpoint is set in `wp-config.php`, run `wp bm-s3 storage confirm`.
3. Check the new storage with `wp bm-s3 verify` (`--fix` uploads missing objects from local copies).

While the endpoint differs from the storage the files were uploaded to, the plugin pauses all uploads, deletions and removals of local copies and shows a notice. Changing only the bucket on the same endpoint needs no confirmation: existing files keep their bucket.

## How it works

```
upload → WordPress creates sizes → wp_update_attachment_metadata
       → BM S3 Offload uploads new/changed files (multipart > 16 MiB)
       → records them in {prefix}bms3o_items (one row per file)
       → optional: deletes local copies at the end of the request
output → wp_get_attachment_url / image_downsize / srcset / content filters
       → bucket, CDN or signed URL (one indexed query per text, cached)
```

- **S3 client**: `includes/S3/Client.php` – AWS Signature V4 on top of the WordPress HTTP API: PutObject, multipart upload, GetObject (streamed to disk), HeadObject, CopyObject (multipart copy above 5 GiB), DeleteObject(s), ListObjectsV2, pre-signed URLs. Network errors, throttling and 5xx responses are retried twice.
- **Cleanup**: objects whose deletion failed (e.g. the previous public copy of a file made private) are retried by the hourly cron; objects that are referenced again are never deleted. The queue is protected against concurrent requests, failing entries move to the end, and entries only run on the storage they belong to. Queued deletions block a change of storage like offloaded files do.
- **No lock-in**: content in the database keeps local URLs. Deactivating the plugin (after *Leave the bucket* if local copies were removed) restores the original behaviour.

### Hooks for developers

| Hook | Type | Purpose |
|---|---|---|
| `bms3o_settings` | filter | Resolved settings |
| `bms3o_providers` | filter | Provider presets |
| `bms3o_should_offload_attachment` | filter | Skip automatic offload for an attachment |
| `bms3o_attachment_paths` | filter | Files that belong to an attachment |
| `bms3o_attachment_is_private` | filter | Store an attachment as private object |
| `bms3o_can_access_private` | filter | Allow signed URLs for restricted files outside access-checked contexts (default: administrators) |
| `bms3o_private_is_restricted` | filter | Whether a private file needs an access check (default: protected directories) |
| `bms3o_can_remove_local` | filter | Whether the local copies of an attachment may be removed |
| `bms3o_object_key` | filter | Object key of a file |
| `bms3o_upload_headers` | filter | Headers sent with an upload |
| `bms3o_public_url` / `bms3o_signed_url` | filter | Delivery URLs |
| `bms3o_content_filters` | filter | Text filters whose output is rewritten |
| `bms3o_http_args` | filter | Arguments of every S3 HTTP request |
| `bms3o_bulk_batch_size` | filter | Items per batch of a bulk operation in the browser |
| `bms3o_bulk_time_limit` | filter | Time budget of a browser bulk request in seconds (default 20, at most half of `max_execution_time`) |
| `bms3o_uploaded` | action | After a file has been uploaded |
| `bms3o_storage_changed` | action | After a confirmed change of storage (previous and new storage identity) |

## Development

Every push runs in GitHub Actions:

- `php -l` on PHP 7.4 – 8.4
- WordPress Coding Standards and PHPCompatibility
- **End-to-end tests** (`tests/integration/`) against real WordPress installations (6.2 / PHP 7.4 and latest / PHP 8.3 with BuddyBoss Platform and WooCommerce) and a real S3 server (MinIO): S3 client, media lifecycle, privacy, BuddyBoss privacy matrix, WooCommerce orders, bulk and background operations, failure handling (failed deletions and database writes, storage changes, retries). See [tests/integration/README.md](tests/integration/README.md).

## License

GPL-2.0-or-later. © Bahr Media. See [LICENSE](LICENSE).

---

## Deutsch

**BM S3 Offload** lagert die WordPress-Mediathek in beliebigen S3-kompatiblen Objektspeicher aus – kostenlos, ohne Pro-Version und ohne Dateikontingente. Es kommt ohne SDK aus: Der S3-Client mit Signature V4 ist Teil des Plugins.

- Alle S3-kompatiblen Anbieter (AWS, Cloudflare R2, Wasabi, Backblaze B2, DigitalOcean, Hetzner, IONOS, Scaleway, MinIO …)
- Automatischer Offload, Massenmigration bestehender Medien (Browser und WP-CLI), lokale Kopien optional entfernen und jederzeit zurückholen
- Auslieferung über Bucket, CDN oder signierte URLs; Inhalte in der Datenbank bleiben unverändert (kein Lock-in)
- **BuddyBoss**: Fotos, Dokumente und Videos werden privat gespeichert und nur nach der Zugriffsprüfung von BuddyBoss ausgeliefert (öffentlich, Freunde, nur ich, Gruppen, Nachrichten, Foren). Profil-, Gruppen- und Titelbilder werden gespiegelt.
- Alle Einstellungen auch per `wp-config.php` (`BMS3O_*`), Zugangsdaten in der Datenbank verschlüsselt
- Oberfläche auf Deutsch und Englisch
