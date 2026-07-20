=== WP MCP Suite ===
Contributors: teznevise
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Modular content sync, SEO, analytics, reporting, backup and Microsoft 365
integration platform for WordPress, built for medical / YMYL sites.

== Description ==

WP MCP Suite unifies WordPress publishing, a Word/OneDrive editorial mirror,
Excel-based reporting, and SEO/analytics ingestion (Search Console, GA4,
Clarity, internal/backlink graph) behind one admin interface, with
role-based access control and full audit logging.

All 9 functional modules are implemented: Content Sync, SEO Health, Search
Console, Analytics (GA4), Clarity Insights, Link Intelligence, AI Writing
Queue, Backups, and Reporting. Every module ships **disabled by default** —
enable each one from Settings only once its own prerequisites are
configured; the module's admin page tells you if something is still missing.

= What's bundled vs. what needs live credentials =

No `composer install` is required to activate this plugin — `vendor/`
(including `phpoffice/phpword`, used by Content Sync's DOCX conversion) is
already included. Re-running `composer install` is only needed for
optional developer tooling (PHPUnit, PHPCS, PHPStan).

Every module needs its own live credentials before it does anything —
enabling a module with nothing configured yet is safe (it just won't run):

* **Content Sync / Backups** — Microsoft Graph app registration (application
  permissions / client-credentials flow; requires a Microsoft 365 business
  tenant, not a personal OneDrive account).
* **Search Console / Analytics** — a Google Cloud service account added as
  a user on the relevant Search Console and GA4 properties.
* **Clarity Insights** — a static API token generated in the Clarity
  project's Data Export settings.
* **AI Writing Queue** — any OpenAI-compatible chat-completions API
  endpoint, key, and model name.
* **Link Intelligence** (internal link graph) — no credentials needed.
  Backlink data requires choosing and implementing a specific provider
  first — see `BacklinkProviderInterface`.
* **SEO Health** — no credentials; needs Rank Math or Yoast SEO active.
* **Reporting** — no credentials required; OneDrive upload is a bonus if
  Graph is already configured for Content Sync/Backups.

= Requirements before activating =

* PHP 8.1+ with the `sodium`, `zip`, `dom`, `xml`, and `gd` extensions
  (all bundled with PHP by default on virtually any modern host).
* Define `MCP_SUITE_ENCRYPTION_KEY` in `wp-config.php` before enabling any
  module that stores API secrets (Settings screen shows a one-time generator).
* A staging environment is strongly recommended before production rollout,
  per the plugin's own medical-site compliance checklist — see the
  technical specification and each module's PHASE-N-NOTES.md for exactly
  what has and hasn't been verified against a live environment yet.

== Changelog ==

= 1.0.0 =
* All 7 build phases complete: foundation (data model, RBAC, encryption,
  audit log), Content Sync (Microsoft Graph, DOCX conversion, conflict
  detection), SEO + Search Console + GA4, Clarity + Link Intelligence, AI
  Writing Queue (mandatory human-review gate enforced twice — a pure state
  machine and a WordPress-level publish-intercept filter), encrypted
  checksummed backups with resumable OneDrive upload, and Excel/JSON
  reporting (dependency-free XLSX writer).
* `phpoffice/phpword` (and its `phpoffice/math` dependency) vendored
  directly from GitHub releases so the plugin activates without requiring
  `composer install` first.
* 200+ unit tests covering every safety-critical code path: the AI
  publish gate (exhaustive state-machine coverage), the sync conflict
  detector, the backup retention policy, upload chunk math, and the XLSX
  writer's full write-read cycle — see README.md for current totals and
  what remains unverified against live external services.

= 1.0.0-phase1 =
* Initial foundation release: 13-table data model, RBAC (8 custom
  capabilities + MCP Content Editor role), append-only audit log,
  libsodium secret encryption, REST permission base class, feature-flagged
  module registry, admin dashboard/settings/audit-log screens.
