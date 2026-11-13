# Changelog

All notable changes to this project are documented in this file. The format follows [Keep a Changelog](https://keepachangelog.com/) and the project uses [Semantic Versioning](https://semver.org/).

## [1.0.13] – 2026-11-13

Preparation for the WordPress.org plugin directory; no functional changes.

### Added

- `readme.txt`: sections *External services* (which data is sent to the configured S3 storage and when; terms and privacy policies of the provider presets) and *Screenshots*.
- Screenshots for the plugin directory in `.wordpress-org/` (not part of the plugin ZIP).
- Release workflow: job `wordpress-org` publishes tagged versions to the plugin directory's SVN repository (`10up/action-wordpress-plugin-deploy`). It is skipped until the repository secrets `SVN_USERNAME` and `SVN_PASSWORD` are set.

### Changed

- Plugin Check: exceptions of the S3 client are annotated – their messages are not output where they are thrown and are escaped where they are displayed.
- Tests: `02-attachments.php` switches off EWWW's own resizing of large uploads while it checks WordPress' scaling.

## [1.0.12] – 2026-11-13

### Fixed

- While the handover of shared files to the remaining attachment was pending (after it failed, see 1.0.10), *Make private* / *Make public* on that attachment reported success but moved no object. Pending handovers of the file are now completed first; if that fails again, the operation is refused with a message.
- *Leave the bucket* on a translation of an attachment (media translations sharing the file) did nothing and reported success. It now works on the files of all attachments sharing the main file, with the same handover check.
- The media library offered only *Offload* for translations, as their files belong to the attachment that uploaded them. The actions now consider all attachments sharing the file.
- The retry lists could still lose entries when the lock of 1.0.11 could not be acquired within 5 seconds (the change was made anyway). The lists no longer use a lock: every entry is a row of its own in the options table (`bms3o_retry_entry_<ID>`, `bms3o_handover_entry_<md5 file>_<md5 path>`), added, changed and removed with single statements. The lists of 1.0.10/1.0.11 are migrated on upgrade (schema version 6).

## [1.0.11] – 2026-11-12

### Fixed

- The hourly retry of failed uploads tried to upload all files of an attachment, also those another attachment with the same main file had uploaded (media translations). It failed with "Path is already used" until the attachment was dropped after five attempts. It now skips these files, like the upload itself does.
- The retry list (`bms3o_retry`) and the list of pending handovers (`bms3o_handover`) were changed by read-modify-write without a lock: when a request added an entry while the hourly cron worked off the list, one of the changes could be lost. Changes now run under a short lock (`bms3o_retry_lock`, `bms3o_handover_lock`, waits up to 5 seconds) and on the current list from the database; the cron applies its results after the uploads instead of writing back the list it read before.

## [1.0.10] – 2026-11-12

### Fixed

- Attachments that share a file (media translations): when the items of the shared files could not be handed over to the remaining attachment (database error), the objects were deleted anyway – without local copies the translation lost its file. Shared files are now never deleted when one of the attachments is deleted. A failed handover is retried by the hourly cron (`bms3o_handover`, shown as *Waiting for retry* and as `pending_handover` in `wp bm-s3 status`); if no attachment uses the files by then, they are deleted as usual. `Items::reassign()` now returns the paths that could not be updated.
- *Make private* / *Make public* on a translation of an attachment reported success but changed nothing: the objects belong to the attachment that uploaded the file, so no files were found. Privacy now applies to all attachments sharing the file – the objects are moved and the state is stored for each of them. When the items are handed over, the remaining attachment takes over their privacy, so a later privacy sync does not move them back.

### Documentation

- WPML is not tested (only Polylang); it uses the same mechanism (several attachments with the same file).
- Private images as Elementor backgrounds: Elementor caches its CSS, while signed URLs expire – such backgrounds stop working after the signed URL lifetime.

## [1.0.9] – 2026-11-12

### Fixed

- A file replaced under the same name (e.g. with Enable Media Replace) kept its URL; with the default `Cache-Control: public, max-age=31536000`, browsers and CDNs could show the old file for up to a year. Public URLs now carry a version (`?v=…`, from size and modification time of the uploaded file), so they change with the file. Filter `bms3o_url_version` (return `''` to omit it). Signed URLs are unchanged. Editors still store local URLs without the version.
- A re-upload under the same key now also fires `bms3o_urls_changed` (the URL version changes), so Elementor regenerates its CSS.

## [1.0.8] – 2026-11-12

### Fixed

- Attachments that share a file (media translations of WPML or Polylang): deleting the attachment that uploaded the file deleted the objects although other attachments still used them – without local copies the translations lost their file. The items are now handed over to the remaining attachment (WebP/AVIF copies included). The other attachments no longer try to upload the file again and no longer count as pending in *Offload existing media*.
- *Remove local copies* did nothing while Polylang's media translation was active: Polylang blocks `wp_delete_file()` for every file that an attachment references. Local copies are now removed directly (after the object has been verified as before).
- Elementor writes the bucket URLs of background images into its generated CSS; after a privacy change, *Leave the bucket* or a change of settings these URLs pointed to deleted or moved objects. Elementor's CSS cache is now cleared when URLs change (action `bms3o_urls_changed`).
- Enable Media Replace: objects of the old files (other size names, or all files when replaced with a new name) stayed in the bucket and the replaced image remained reachable under its old URLs; when replacing with a new name, the URLs in content were not replaced because EMR searched for bucket URLs while content stores local ones. During a replacement attachment URLs now stay local, and afterwards the objects of old files are deleted.
- Deleting an attachment that had no items of its own left its entry in the retry list.

### Changed

- The integration tests also run with Polylang, Elementor, Enable Media Replace and EWWW Image Optimizer (new tests 12–15).

## [1.0.7] – 2026-11-12

### Fixed

- Concurrent changes of the same file could delete each other's objects: two opposite privacy changes (or an upload that changes the privacy and a privacy change) both reported success while neither object existed any more. Every operation that changes a file – upload, privacy change, deletion from the bucket, removal and restore of the local copy and `verify --fix` – now holds a lock on that file and reads its item only once it holds the lock; copying, recording and deleting the previous object run under it. A second operation waits up to 10 seconds (filter `bms3o_file_lock_wait`) and otherwise reports that the file is busy.
- File locks cannot run out during long transfers: before every request – every part of a multipart transfer and every retry included – the lock is extended (600 seconds) and the request's timeout is shortened so that it ends at least 60 seconds before the lock expires. A request is never sent after the lock was lost.
- "Leave the bucket" could lose a file when "Remove local copies" ran at the same time (the local copy was removed after the restore and the object deleted afterwards). Objects are now only deleted while the restored local copy still exists, checked under the file lock.

## [1.0.6] – 2026-11-12

### Fixed

- The object locks of the cleanup (120 seconds) could expire while a delete request was still running (timeout 300 seconds plus retries, all objects locked up front). Another request could then reuse the key, and the late delete removed the object in use. The cleanup now locks and deletes in chunks of 100 objects; the delete requests of a chunk end by a deadline 60 seconds after the chunk started (timeouts are shortened to the time left, also when `bms3o_http_args` raises them, and retries that would end later are skipped), and the locks last 60 seconds longer. Should a lock be lost anyway, the cleanup checks the key: an object in use again is uploaded again from the local copy, otherwise the file is reported in the admin until `wp bm-s3 verify` has run.
- A failed upload or copy to a key that waited for deletion removed the queue entry, so a leftover public copy stayed reachable. Writes now claim the entry instead of removing it (schema version 5); the cleanup leaves claimed entries alone, a successful write removes the entry and a failed one releases the claim. Claims of processes that died expire after a day.
- Database errors while queuing deletions were ignored: `Orphans::add()` now returns the objects that were not written, `queue_orphans()` reports and logs them, and the message about a leftover copy names the object that has to be deleted manually. The migration of the 1.0.1 – 1.0.3 queue only deletes the old option after every entry has been written; otherwise the schema version stays and the migration runs again.

## [1.0.5] – 2026-11-12

### Changed

- Performance with large tables (measured with one million items):
  - Checks whether any files or queued deletions exist (settings page, endpoint check on save) and whether an operation has anything to do stop at the first row (`LIMIT 1`) instead of counting the whole table.
  - The statistics and "pending" counters of the tools tab are cached. The plugin drops the cache whenever it changes items, settings or attachments; the lifetime (filter `bms3o_count_cache_ttl`, default 5 minutes, 0 disables it) only matters for changes made outside the plugin.
  - New index `(source_type, local_removed, source_id)` (schema version 4). Bulk batches for "Remove local copies" and "Restore local copies" no longer scan the table when few or no files match (642 ms → 0.1 ms per batch without matches).
- The tools tab and `wp bm-s3 status` count the cleanup queue in the database instead of loading it.

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
