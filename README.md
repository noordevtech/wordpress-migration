# NoorDev Migrate

Live WordPress site-to-site migration plugin. Syncs a running site to a new host **batch by batch**, resumes automatically after any interruption, and — once the sync reaches **100%** — atomically **replaces the target site while keeping the target's own domain**.

## How it works

The same plugin is installed on **both** sites:

```
┌────────────── SOURCE ──────────────┐        ┌────────────── TARGET ──────────────┐
│  Batch engine (AJAX loop + cron)   │        │  Signed REST API (ndm/v1)           │
│  • DB rows in PK-checkpointed      │ HTTPS  │  • rows → staging tables            │
│    batches (500 rows default)      │ ─────► │    {prefix}ndmstg_*                 │
│  • files in 512 KB chunks,         │ HMAC   │  • chunks → wp-content/ndm-staging  │
│    md5-verified                    │ signed │  • live site untouched during sync  │
│  • checkpoint saved per batch      │        │  • cutover = atomic RENAME TABLE    │
└────────────────────────────────────┘        └─────────────────────────────────────┘
```

1. **Connect** – The target generates a connection key (`Migrate` admin page). Enter the target URL + key on the source. All requests are HMAC-SHA256 signed with a timestamp window; no passwords travel over the wire.
2. **Sync database** – Every table is exported in primary-key-checkpointed batches and written into *staging* tables on the target (`REPLACE INTO`, so retries are idempotent). URL and path rewriting to the **target's domain** happens at import time, safely handling PHP-serialized data, JSON-escaped URLs and Gutenberg block attributes. Table-prefix differences (including `{prefix}user_roles` / `{prefix}capabilities`) are remapped automatically.
3. **Sync files** – uploads, themes, plugins, mu-plugins and languages are transferred in 512 KB chunks into a staging folder, each file verified by MD5. Files already staged with a matching hash are skipped, so re-runs are incremental delta passes.
4. **Verify** – Row counts are compared source vs. staged. Tables that drifted while syncing (it's a live site) are automatically re-queued for a delta pass until both sides match — that's your **100%**.
5. **Cutover** – Triggered manually ("Replace target site now") or automatically (setting). The target enters maintenance mode, swaps all staged tables in with a **single atomic `RENAME TABLE`** (previous tables kept as `ndmbak_*` backups), restores its own `siteurl`/`home` so the **domain connected to the target is preserved**, promotes staged files (optionally deleting files that don't exist on the source), flushes caches and exits maintenance mode. Downtime is seconds.
6. **Rollback** – One click restores the pre-cutover tables from the backups if anything looks wrong.

## Resumability

- Every acknowledged batch advances a persistent checkpoint (`table + last primary key`, `file + byte offset`). An interrupted migration **continues where it stopped — never from the beginning**.
- Failed batches retry with exponential backoff (2s/4s/8s); after a hard error, WP-Cron auto-resumes from the checkpoint (up to 10 consecutive failures before requiring a manual Resume).
- The sync keeps running via WP-Cron even when the admin tab is closed; opening the dashboard speeds it up with an AJAX tick loop.
- Chunk/row writes are idempotent (offset-checked appends, `REPLACE INTO`), so a lost acknowledgement can never corrupt data on retry.

## Install

Copy this repository into `wp-content/plugins/noordev-migrate/` on **both** sites and activate it. WordPress 5.9+ / PHP 7.4+.

## Settings

| Setting | Default | Meaning |
|---|---|---|
| Rows per batch | 500 | Lower on hosts with tight memory/request limits |
| Automatic cutover | off | Replace the target automatically at 100% |
| Replace mode (delete extra files) | on | On cutover, delete target files absent from the source |

## Notes & caveats

- Users and passwords are migrated with the database: after cutover, log in to the target with the **source** site's credentials.
- Multisite networks are not supported in this version.
- The target's pre-cutover database stays available as `ndmbak_*` tables until you delete them from the dashboard.
