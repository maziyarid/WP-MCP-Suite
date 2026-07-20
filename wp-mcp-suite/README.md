# WP MCP Suite — v1.0.0

This is the complete, cumulative build: all 7 phases from the technical
specification, plus a same-day fix that vendors `phpoffice/phpword`
directly from GitHub so the plugin **activates without requiring
`composer install`**. This file is the current, accurate status —
where it disagrees with something written earlier in an individual
`PHASE-N-NOTES.md` file, this one is correct (those files are kept as an
honest build log, not rewritten to hide what changed).

## Install

1. Upload this whole folder to `wp-content/plugins/wp-mcp-suite/` (or zip
   it and use Plugins → Add New → Upload Plugin).
2. Add an encryption key to `wp-config.php` before activating — the
   Settings screen will show you a generator once the plugin is active,
   but no module that stores a credential will work until this constant exists:
   ```php
   define( 'MCP_SUITE_ENCRYPTION_KEY', 'paste-a-generated-key-here' );
   ```
3. Activate the plugin. No composer step needed — `vendor/` is bundled.
4. Every module is **disabled by default**. Go to MCP Suite → Settings,
   configure credentials for whichever modules you want, then enable them
   in the Modules section. Each module's own admin page will tell you if
   something is still missing.

## What's genuinely verified vs. what still needs a live environment

This was built in a sandbox with no live WordPress install, no real
Microsoft/Google/Clarity credentials, and no Packagist access. Two
different kinds of "unverified" ended up in this codebase, and it matters
which is which:

**Converted from "unverified" to actually verified, same day as Phase 7:**
`phpoffice/phpword` was fetched directly from its GitHub releases
(`codeload.github.com` was reachable even though `packagist.org` wasn't)
and vendored into `vendor/phpoffice/phpword` and `vendor/phpoffice/math`
(its one dependency). The Content Sync module's HTML→DOCX→HTML round-trip
test now runs for real — **it is no longer a self-skipping test**, it
actually exercises the real library and passes.

**Still genuinely unverified — needs a real WordPress + live credentials:**
- Every live external API call: Microsoft Graph (OneDrive upload/download/
  delete), Google (Search Console, GA4), Clarity. All were built against
  each service's documented contract, syntax-checked, and — where the
  logic could be isolated from the live HTTP call — unit tested with fakes.
  None have been called against a real tenant/property/project.
- Everything that touches `$wpdb` or a WordPress hook directly (every
  repository class, every settings controller, the `wp_insert_post_data`
  publish-gate filter) needs the `tests/integration` suite against a real
  WordPress install — see `tests/bootstrap.php` for how to wire that up.
- A real database restore from a generated backup has not been attempted.
- The generated `.xlsx` file was verified structurally (valid zip, valid
  XML, correct cell values, round-tripped through `ZipArchive` and
  `SimpleXMLElement`) but has not been opened in actual Excel/Google Sheets.
- Rank Math/Yoast postmeta reading, Clarity's non-Traffic metric field
  names (session counts are confirmed from Microsoft's docs; rage
  clicks/dead clicks/quickbacks/scroll depth are a documented best-effort
  guess — see `PHASE4-NOTES.md`), and the backlink provider (intentionally
  not implemented — see `BacklinkProviderInterface`'s docblock).

**What IS genuinely, deeply tested** (not just "written carefully"):
real RSA keypair generation and signature verification for Google's JWT
auth (`JwtSignerTest`), the AI publish-gate state machine exhaustively
across every (state, human_reviewed) combination, the sync-conflict
decision matrix, the backup retention policy's "never delete an unverified
backup" rule, the resumable-upload chunk math (gap/overlap sweep across
many file sizes), the XLSX writer's full write-read cycle including a real
`gzdecode()` bug caught and fixed during testing, and Persian/Farsi UTF-8
correctness across the internal-link extractor, backup archiver, and
worksheet writer — real content, not filler, since this plugin's actual
target sites are Persian-language.

**Current totals:** 140 plugin PHP files (`includes/`), 34 test files, 203
unit tests, 1456 assertions, all passing, against real PHPUnit 9.6. Run
`composer test:unit` to reproduce.

## Open decisions that were deliberately not guessed at

See the technical specification's §13 for the full list. The two still
genuinely open:
- **Backlink data provider** — no specific vendor was named in the
  original requirements; implement `BacklinkProviderInterface` once one is chosen.
- **AI provider** — built against the generic OpenAI-compatible
  chat-completions contract, which works today with OpenAI itself or any
  compatible provider; confirm whether Blackbox/You.com specifically
  expose that contract or need a bespoke adapter.

## Per-phase detail

`PHASE1-NOTES.md` through `PHASE7-NOTES.md` in this same folder document
each phase's specific scope, design decisions, and verification status at
the time it was built. Read this file first; use those for the deeper
per-module detail (e.g. exact Clarity field-name assumptions, exact Graph
scopes needed, exact cron schedule).
