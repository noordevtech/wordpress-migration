=== NoorDev Migrate ===
Contributors: noordev
Tags: migration, clone, move site, backup, sync
Requires at least: 5.9
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.0.2
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

= 1.0.2 =
* Fix: tables with FOREIGN KEY constraints (e.g. Shield Security logs) failed to stage — their REFERENCES clauses pointed at source table names absent on the destination. Foreign keys are now stripped from staging tables; imports are order-independent.
* Fix: the real database error from a failed CREATE was wiped by the follow-up existence check and reported as "unknown database error"; the error is now captured immediately.

= 1.0.1 =
* Fix: concurrent sync loops (dashboard + WP-Cron) could race and drop a staging table mid-import; the engine is now serialized behind a MySQL named lock.
* Fix: tables storing serialized PHP objects (e.g. Action Scheduler schedules) crashed the destination during URL rewriting; such objects now pass through untouched.
* Fix: oversized row batches could exceed the destination's memory/request limits; batches are now capped at ~1 MB of payload and auto-split on rejection down to 25 rows.
* Fix: MySQL-8-only collations (utf8mb4_0900_*) are retried as utf8mb4_unicode_ci on MariaDB destinations.
* Improvement: staging tables are verified to exist after creation and before accepting rows; a missing table self-heals by re-sending the structure.
* Improvement: destination errors now surface as readable messages (real cause, file and line) instead of the WordPress critical-error page.
* Improvement: import endpoints raise memory and execution-time limits.

= 1.0.0 =
* Initial release.
