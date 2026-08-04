=== NoorDev Migrate ===
Contributors: noordev
Tags: migration, clone, move site, backup, sync
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live site-to-site migration: batched, resumable sync with atomic cutover that keeps the target domain.

== Description ==

NoorDev Migrate pushes a live WordPress site to a new host batch by batch. Data lands in a staging area on the target, so the target site keeps working during the sync. When the sync reaches 100% (verified by row counts and file hashes), a cutover atomically replaces the target site with the migrated content — while preserving the domain connected to the target.

* Database synced in primary-key-checkpointed batches, files in md5-verified 512 KB chunks.
* Interruptions resume from the last checkpoint, never from the beginning.
* HMAC-signed site-to-site requests; no passwords exchanged.
* Serialized-data-safe URL rewriting to the target domain, table-prefix remapping included.
* Atomic RENAME TABLE cutover with automatic pre-cutover backups and one-click rollback.
* Runs in the background via WP-Cron; the dashboard accelerates it while open.

== Installation ==

1. Install and activate the plugin on BOTH the source and the target site.
2. On the target, open Migrate and copy the Site URL and Connection Key.
3. On the source, open Migrate, paste both values, save, and press "Start sync".
4. At 100%, press "Replace target site now" (or enable automatic cutover).

== Changelog ==

= 1.0.0 =
* Initial release.
