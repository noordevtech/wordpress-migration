# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this project is

**NoorDev Migrate** — a WordPress plugin for live site-to-site migration. The repo root **is** the plugin root (deployed as `wp-content/plugins/noordev-migrate/`). The same plugin is installed on both sites: the **source** pushes its database and files batch by batch to the **target**, which stages everything without touching its live site; at 100% (verified) a cutover atomically replaces the target site while keeping the target's own domain.

There is no build step, no Composer, no npm. Plain PHP 7.4+ / WordPress 5.9+, jQuery for the admin dashboard.

## Architecture

One migration = source-side push engine + target-side signed REST API.

```
SOURCE                                          TARGET
NDM_Batch_Runner (engine, one tick ≈ 10s)       NDM_Rest_Api (namespace ndm/v1)
 ├─ NDM_DB_Exporter  → POST table, rows  ─────►  ├─ NDM_DB_Importer  → {prefix}ndmstg_* tables
 ├─ NDM_File_Scanner → POST files-check,         ├─ NDM_File_Receiver → wp-content/ndm-staging/
 │                     file-chunk        ─────►  ├─ NDM_Search_Replace (URL/path rewrite at import)
 ├─ NDM_Client (HMAC-signed HTTP, retries)       └─ NDM_Finalizer (cutover: atomic RENAME TABLE)
 └─ NDM_State (checkpoints in options)
NDM_Admin (dashboard UI + AJAX), NDM_Auth (HMAC), NDM_Log (ring buffer) are shared.
```

File map (`includes/`):

| File | Role |
|---|---|
| `class-ndm-batch-runner.php` | Source engine: stages (db → files → verify → ready → finalizing → done), tick loop, batch splitting, error handling, cron fallback, **the GET_LOCK mutex** |
| `class-ndm-client.php` | Source HTTP client: HMAC signing, exponential-backoff retries, error normalization (`ndm_http_<code>`) |
| `class-ndm-db-exporter.php` | Source: table listing, PK-checkpointed batch fetch, byte cap, row skipping (transients), CREATE statement export |
| `class-ndm-db-importer.php` | Target: staging table creation (FK stripping, collation fallback), idempotent `REPLACE INTO` imports, prefix remapping |
| `class-ndm-file-scanner.php` | Source: JSONL manifest of wp-content (uploads/themes/plugins/mu-plugins/languages), chunk reader |
| `class-ndm-file-receiver.php` | Target: offset-checked chunk writes into staging, MD5 verification, delta detection (`files_needed`) |
| `class-ndm-search-replace.php` | Serialized-data-safe URL/path replacement (runs on the **target at import time**) |
| `class-ndm-finalizer.php` | Target: cutover (maintenance mode, atomic `RENAME TABLE`, preserved options, file promotion), rollback, backup cleanup |
| `class-ndm-rest-api.php` | Target: `ndm/v1` endpoints; ALL use `NDM_Auth::verify_request` as permission callback |
| `class-ndm-state.php` | Checkpoint persistence (`ndm_source_state` / `ndm_dest_state` options) and progress math |
| `class-ndm-auth.php` | HMAC-SHA256 request signing/verification with timestamp window |
| `class-ndm-admin.php` | Single admin page (both roles), AJAX handlers, settings forms |
| `class-ndm-log.php` | Ring-buffer activity log in one option |

## Invariants — do not break these

1. **Resumability is the core feature.** Every acknowledged batch advances a persisted checkpoint (table + `last_pk`/`offset`, file index + byte offset). Any change to batching must keep checkpoints consistent — including when a batch is cut early (byte cap) or rows are skipped (`skip_row`): skipped/truncated rows must still advance `next_last_pk`/`next_offset` correctly.
2. **All writes are idempotent** so a lost acknowledgement can be re-sent safely: rows use `REPLACE INTO`; file chunks are offset-checked (a chunk below the staged size truncates and rewrites). Never introduce a write that double-applies on retry.
3. **Only one engine loop may run at a time.** The dashboard AJAX loop and the WP-Cron fallback both drive `tick()`; the MySQL named lock (`GET_LOCK`) in `NDM_Batch_Runner` serializes them. Any new entry point that mutates sync state must take the same lock (see `request_cutover()`).
4. **The target's domain is preserved.** URL/path rewriting happens at import time in `NDM_Search_Replace`; `NDM_Finalizer` re-asserts `siteurl`/`home` and other preserved options after the table swap. The list is filterable via `ndm_preserved_options`.
5. **Verification counts must match export filters.** If you skip rows on export (`NDM_DB_Exporter::skip_row`), the same filter must apply in `count_rows()` — otherwise verify flags a false mismatch and loops forever re-queuing the table.
6. **Never report success on unverified state.** `create_stage_table` checks the table actually exists after CREATE; keep that pattern for new DDL.

## Hard-won lessons (production failures already fixed — don't regress them)

- **Serialized PHP objects** (Action Scheduler schedules, etc.): unserializing with `allowed_classes => false` yields `__PHP_Incomplete_Class`; reading/writing its properties is a PHP fatal. `NDM_Search_Replace` must leave such objects untouched — they round-trip through `serialize()` byte-identical.
- **Foreign keys**: plugins like Shield Security use FK constraints whose `REFERENCES` point at source-prefixed live tables. `strip_foreign_keys()` removes them from staged CREATEs (imports are alphabetical, not dependency-ordered).
- **`$wpdb->last_error` is reset by every query.** Capture it immediately after the statement you care about, before any follow-up query (existence checks included).
- **Batch size must be capped in bytes** (`MAX_BATCH_BYTES`, filter `ndm_max_batch_bytes`), not only rows — and `send_rows()` auto-splits on 5xx down to 25 rows. Both mechanisms matter: some hosts fail on payload size, and splitting can't fix per-row problems.
- **MySQL-8-only collations** (`utf8mb4_0900_*`) fail on MariaDB targets → retried as `utf8mb4_unicode_ci`.
- **Mixed plugin versions between the two sites** produce failures that look like data bugs. The handshake carries `plugin => NDM_VERSION` and `warn_on_version_mismatch()` logs loudly. Users update via zip/SFTP — assume the target may lag.
- **Transients are skipped** (`skip_row`) — they bloated `wp_options` enough to fill the target's disk. "Table is full" = target DB volume out of space (env issue, not code).
- **HTTP 409 from the rows endpoint** means the staging table vanished; the source self-heals by re-sending the structure. 4xx errors are never retried or split; 5xx are.

## Conventions

- WordPress Coding Standards: tabs, Yoda conditions, `esc_*` on output, nonces + `current_user_can( 'manage_options' )` on every admin action, `$wpdb->prepare` for values (table names are backtick-stripped and interpolated — keep the `str_replace( '`', '', ... )` sanitization pattern).
- Class-per-file, `NDM_` prefix, loaded via explicit `require_once` in `noordev-migrate.php` (no autoloader).
- Options are all `ndm_*`; wp-content artifacts are `ndm-staging/`, `ndm-tmp/`; table prefixes `ndmstg_` (staging), `ndmbak_` (pre-cutover backup), `ndmold_` (pre-rollback).
- Site-to-site payload values are base64-encoded per column (binary-safe); `null` stays `null`.
- Text domain `noordev-migrate` on every user-facing string.

## Versioning & release workflow (every user-facing change)

The user deploys from this branch by copying files to both live sites, so **every fix gets a version bump** to make "which build is running where" verifiable:

1. Bump the `Version:` header **and** `NDM_VERSION` in `noordev-migrate.php` (they must match).
2. Bump `Stable tag:` and add a changelog entry in `readme.txt`.
3. Commit with a descriptive message; push to the designated branch. Tags cannot be pushed (403) — version lives in files only.

## Testing

No test framework or CI. Verify changes with:

- `php -l` on every touched PHP file, `node --check` on touched JS.
- For tricky pure logic (search-replace, SQL rewriting), write a standalone repro script in the session scratchpad that stubs the few WP functions needed (`is_serialized`, `untrailingslashit`, …) and asserts behavior — see the serialized-object and FK-strip fixes for the pattern. Do not commit these scripts.
- There is no WordPress instance in this environment; real validation happens on the user's live source (artgraphique.ca) + Railway target. Expect the user to paste Activity-log excerpts — design error messages to be diagnosable from that log alone.

## Known limitations (documented, intentional for v1.0.x)

- No multisite support.
- FK constraints are not recreated on the target after cutover.
- Users/passwords migrate with the DB (post-cutover login uses source credentials).
- DB "delta" on re-verify re-sends drifted tables in full (files are hash-skipped incrementally).
