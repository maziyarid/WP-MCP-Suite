# Phase 2 — Microsoft Graph Auth + Content Sync

## What this phase contains
- **Graph auth layer** (`includes/Core/Graph/`): OAuth2 client-credentials
  token service with caching, expiry-safety buffer, and bounded transport
  retries. Application permissions (not delegated) — see
  `GraphCredentialsRepository`'s docblock for why, and the technical
  specification §13 decision 1.
- **Content Sync module** (`includes/Modules/ContentSync/`): a pure,
  fully-unit-tested decision engine (`SyncEngine` + `SyncOrchestrator`) for
  detecting and resolving WordPress-vs-OneDrive drift, wrapped by real
  WordPress/Graph adapters (`WpContentProvider`, `GraphOneDriveProvider`,
  `WpdbContentMapRepository`) and a PHPWord-backed DOCX converter.
- **Admin UI**: Graph credentials form, per-module enable/disable toggles,
  a content-sync status table, and a post-edit-screen meta box that is the
  explicit, human-in-the-loop PHI opt-in gate — no post is ever mirrored
  without someone deliberately clicking "Opt into Content Sync" first.
- **REST API**: `mcp-suite/v1/content-sync/{sync,opt-in,status}`, all
  routed through the Phase 1 `RestControllerBase` (nonce + capability
  enforced structurally).
- **Cron**: hourly batch sync with a resume cursor (`mcp_suite_cron_content_sync`),
  bounded to 20 posts per tick, that wraps around rather than growing unbounded.

## The core design decision: conflict handling is never automatic
`SyncEngine::decide()` is a pure four-way truth table (skip / export /
import / conflict) tested exhaustively in `SyncEngineTest`. When both
WordPress and the OneDrive mirror changed since the last sync, the system
**always** stops and flags it for human review — it never guesses which
side is "right." `SyncOrchestratorTest` specifically asserts that a
conflict does not upload to OneDrive, does not create a WordPress revision,
and is not silently re-resolved on a later tick with no further changes.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox (syntax-checked + executed against real PHPUnit 9.6):**
- `SyncEngine` — the conflict-decision truth table, all branches.
- `SyncOrchestrator` — export / import / conflict / PHI-gate / failure-handling,
  using fakes for WordPress, OneDrive, and DOCX conversion.
- `GraphAuthService` — token fetch, cache reuse, expiry-buffer refresh,
  invalidate-and-refetch, client-credentials request shape, error/retry
  classification — using a fake HTTP client, no live network call.
- **73/73 PHP files pass `php -l`. 45 unit tests, 130 assertions, 0 failures, 1 legitimate skip.**

**Update, same day:** `phpoffice/phpword` (and its one dependency,
`phpoffice/math`) has since been vendored directly into `vendor/` from
GitHub releases (Packagist wasn't reachable from the build sandbox, but
`codeload.github.com` was) and the round-trip test now runs for real
instead of self-skipping — see `PHASE7-NOTES.md`'s update note and the
root `README.md` for the current, accurate verification status. The
"NOT verified" item below is kept for the historical record of what this
phase looked like on first delivery.

**NOT verified here, and must be verified in a real environment before this phase is "done" per the spec's definition of done:**
- `PhpWordDocxConverter` — depends on `phpoffice/phpword`, which this
  sandbox cannot fetch (no Packagist access). Run `composer install` then
  `composer test:unit` in a real environment; `PhpWordDocxConverterTest`
  self-skips here and will run for real there. Treat the HTML→DOCX→HTML
  round-trip as unverified until that test has actually run green.
- `GraphOneDriveProvider` — real Graph endpoint shapes (upload/download/
  metadata) were written against Microsoft's documented API, not exercised
  against a live tenant. The first real test should be: configure
  credentials on Settings, opt a single low-stakes post in, trigger "Sync
  Now" via the REST route, and check `wp_mcp_sync_log`.
- `WpdbContentMapRepository`, `WpContentProvider`, `ContentSyncMetaBox`,
  `GraphSettingsController`, `FeatureFlagsController` — all touch `$wpdb`
  or WordPress hooks directly and need the `tests/integration` suite
  against a real WordPress install (not runnable in this sandbox).

## Before enabling Content Sync on a real site
1. Azure AD app registration in the target Microsoft 365 tenant, with
   admin-consented Graph **application** permission `Files.ReadWrite.All`
   (or `Sites.ReadWrite.All`, scoped to the specific drive if possible —
   don't grant tenant-wide if a narrower consent option exists).
2. `phpoffice/phpword` is already bundled in `vendor/` — no composer step needed.
3. `MCP_SUITE_ENCRYPTION_KEY` in `wp-config.php` (Settings screen shows a generator).
4. Enter Tenant ID / Client ID / Client Secret / Drive ID / Mirror Folder
   Path on Settings → Save.
5. Enable "Content Sync" in the Modules section of Settings.
6. Opt in exactly **one** low-stakes post first (the meta box on its edit
   screen), trigger a manual sync, and check both the Content Sync status
   table and the Audit Log before opting in more content or waiting for cron.
