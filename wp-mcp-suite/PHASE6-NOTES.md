# Phase 6 — Backup Engine

## What this phase contains
- **`DatabaseDumper`**: a PHP-native, `$wpdb`-based full database dump —
  deliberately not shelling out to `mysqldump`, since shared/managed
  WordPress hosting (this plugin's realistic target environment) commonly
  disables `shell_exec` entirely. Streams table-by-table and in row
  batches via a generator, so a large table is never fully materialized
  in PHP memory during the dump step itself.
- **`BackupArchiver`**: gzip compression via PHP's built-in zlib.
- **Encryption**: reuses the same `Core\Encryption` (libsodium) service as
  every other secret in the plugin, per spec's "encryption at rest" requirement.
- **`GraphBackupUploader`**: a real Microsoft Graph **resumable** upload
  (createUploadSession + chunked PUT), used unconditionally for backups
  regardless of size — unlike Content Sync's single-DOCX uploader (4MB
  simple-upload limit), backup archives are essentially always larger than
  that. Built as a separate class specifically to avoid touching
  Phase 2's already-verified upload path.
- **Verification**: after upload, the file is **re-downloaded and
  re-hashed locally**, compared against the pre-upload checksum — not just
  trusting Graph's own (different-algorithm) content hash as a stand-in.
  A job is only ever marked `verified` after this round-trip succeeds.
- **`BackupRetentionPolicy`**: the code-level enforcement of "never delete
  a backup whose upload status is unknown" — only `VERIFIED` jobs are ever
  eligible for pruning, and at least one is always kept even if
  misconfigured with a retention count of zero.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox (syntax-checked + executed against real PHPUnit 9.6):**
- `UploadChunkPlanner` — **11 tests, 50 assertions**, including a
  gap/overlap sweep across many file sizes and exact `Content-Range`
  header string verification. Off-by-one errors here would silently
  corrupt every backup, so this got the deepest coverage in the phase.
- `BackupArchiver` — real gzip round-trip, corruption detection, and a
  Persian/UTF-8 content round-trip (real dumps are full of this plugin's
  actual Farsi post content). **Caught and fixed a real bug during
  testing**: `gzdecode()` emits a PHP warning (not just a `false` return)
  on corrupt input, which — unsuppressed — would have thrown a raw engine
  warning instead of the intended, catchable `BackupException`.
- `BackupRetentionPolicy` — exhaustively tested, including the specific
  "uploaded but not yet verified must never be pruned" case the negative
  requirement names explicitly, and a misconfigured-zero-retention safety case.
- **163/163 PHP files pass `php -l`. 161 unit tests, 377 assertions, 0
  failures, 1 legitimate skip** (PHPWord, carried over from Phase 2).

**NOT verified here, and must be verified in a real environment:**
- `GraphBackupUploader`'s actual HTTP calls (createUploadSession, chunked
  PUT, download, delete) — request shapes match Microsoft's documented
  resumable-upload contract but were never exercised against a live
  OneDrive drive. First real test: enable the module with Graph configured,
  trigger a manual backup via REST (`POST /backup/run-now`), and confirm
  it reaches `verified` status in Backup History.
- `DatabaseDumper` was not run against a real WordPress database — verify
  the dump is actually restorable (import it into a scratch database and
  confirm the site loads) before relying on it for disaster recovery.
- Very large databases: whole-payload `gzencode()`/`Encryption::encrypt()`
  both operate on the fully-concatenated dump string, which is a real
  memory ceiling for sites with a genuinely large database. Fine for
  typical WordPress sites; a future streaming-encryption enhancement would
  be needed for multi-GB databases.

## Before enabling this module on a real site
1. Microsoft Graph must already be configured (shared with Content Sync —
   no separate credentials for backups).
2. Set the backup folder path and retention count on Settings.
3. Trigger one manual backup (`POST /backup/run-now`) and confirm it
   reaches `verified`, not `failed`, before relying on the weekly cron.
4. Actually test a restore at least once, on staging — a backup that has
   never been restored is unverified in the way that matters most.
