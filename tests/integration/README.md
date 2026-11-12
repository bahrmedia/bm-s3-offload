# Integration tests

End-to-end tests against a real WordPress installation and a real S3 server.
They run in GitHub Actions on every push (see `.github/workflows/ci.yml`):
WordPress 6.2 / PHP 7.4 and the latest WordPress / PHP 8.3 with BuddyBoss
Platform and WooCommerce, MinIO as S3 server.

| File | Covers |
|---|---|
| `01-client.php` | S3 client: signing, special characters, multipart, copy, list, batch delete, presigning, errors |
| `02-attachments.php` | Upload, sizes, URLs, srcset, content rewriting, no lock-in on save, remove local, image editor and regeneration without local files, unique names, restore, delete |
| `03-privacy-safety.php` | Private media, restricted directories, invalid configuration, prefix validation, secret encryption |
| `04-bulk.php` | Bulk operations, background runner, verify, WebP/AVIF sidecars |
| `05-buddyboss.php` | BuddyBoss privacy (only me, public, private groups), e-mail links, direct mode, avatars, cover images |
| `06-woocommerce.php` | Downloadable products: order → signed redirect → correct file |
| `08-cleanup-queue.php` | Cleanup queue: entries added during a run, storage identity, storage change guard, rotation of failing entries, re-upload reports a reachable public copy, no deletion of an object written again during the cleanup, migration of the 1.0.3 queue, locks with owner |
| `07-robustness.php` | Failed deletions (privacy change, deleted media) and cleanup, failed database writes, size check before removing local copies, storage change guard, retries, multipart copy, uninstall guard |
| `09-counters.php` | Index for the pending counters (incl. upgrade from schema 3), existence checks with `LIMIT 1`, `has_pending()` against `remaining()`, counter cache: invalidation by items, attachments and settings, lifetime filter, tools tab |
| `10-cleanup-safety.php` | Cleanup under slow storage and failures: delete requests end before the locks expire (also with a raised timeout), deadline of the S3 client, a lost lock does not lose a reused object (restore from the local copy, report otherwise), failed copy/upload keeps the queued deletion, claims and their expiry, database errors while queuing, repeatable migration of the legacy queue |
| `11-file-locks.php` | Concurrent changes of the same file (a second Offloader instance acts as another request): opposite privacy changes, upload with privacy change against a privacy change, "Leave the bucket" against "Remove local copies", file lock extended before every multipart part, no request after a lost lock, no lock left behind |
| `12-shared-files.php` | Attachments sharing a file (copy and real Polylang media translation): no second upload, not pending, deleting the original hands the items over, deleting the last one deletes the objects |
| `13-elementor.php` | Elementor (skipped if inactive): background image in generated CSS from the bucket, CSS cache cleared after privacy change, settings save and "Leave the bucket" |
| `14-enable-media-replace.php` | Enable Media Replace (skipped if inactive): same and new file name, with and without local copy – new files offloaded, old objects deleted, content URLs replaced and working |
| `15-optimizers.php` | Files changed by an optimiser after the upload are uploaded again on metadata save; with EWWW: optimised files and WebP copies in the bucket |

## Running locally

Requirements: WordPress with this plugin, an S3 server (e.g. MinIO with a
bucket whose `wp-content/uploads/*` prefix is publicly readable, see
`public-policy.json`), WP-CLI.

```bash
BMS3O_TEST_ENDPOINT=http://127.0.0.1:9000 BMS3O_TEST_BUCKET=wordpress \
BMS3O_TEST_KEY=minioadmin BMS3O_TEST_SECRET=minioadmin \
  wp --path=/path/to/wordpress eval-file tests/integration/setup.php

WP="wp --path=/path/to/wordpress" tests/integration/run.sh
```

The tests create their own media, users and orders and delete them again.
Run them against a test site only.
