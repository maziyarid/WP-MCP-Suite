# Phase 1 — Build & Verify

## What this phase contains
- The 13-table data model (`includes/Database/Migrations/Migration_1_0_0.php`)
- Security core: capabilities/RBAC, libsodium secret encryption, append-only
  audit logger, REST permission base class (`includes/Core/`)
- Feature-flagged module registry + 9 module stubs (`includes/Modules/`)
- Admin UI shell: dashboard, settings, audit log viewer (`includes/Admin/`)
- Activation/deactivation lifecycle, safe opt-in uninstall

## What this phase deliberately does NOT contain yet
Microsoft Graph/OneDrive sync, DOCX parsing, Search Console/GA4/Clarity/
Uptime Robot ingestion, backlink/internal-link crawling, AI generation,
backup engine, and Excel reporting are all **not implemented** — each is a
registered stub module with a placeholder admin page. Building all of these
in one uncontrolled pass, without a working WordPress instance to run them
against, is how "production-ready" plugins ship with silent data-loss bugs.
They land in later phases, each with its own test coverage, per the phased
plan in the technical specification.

## Local setup

**Update:** as of the same-day fix documented in the root `README.md`,
`vendor/` (including `phpoffice/phpword`) is bundled directly in the
plugin, so `composer install --no-dev` is no longer required just to
activate. The commands below remain accurate for development tooling.

```bash
composer install --no-dev          # only needed if you deleted the bundled vendor/ and want to regenerate it via Packagist
composer install                   # adds phpunit, phpcs, phpstan, wpcs for development
php -l wp-mcp-suite.php            # syntax check any file
```

## Running tests

**Unit suite** — pure logic, no WordPress or database required:
```bash
composer test:unit
```
Covers `Encryption` (round-trip, tamper detection, wrong-key rejection, key
generation) and the migration schema shape (all 13 tables present, every
table has a primary key, correct charset, correctly prefixed, and the audit
table has no update/soft-delete columns that would defeat append-only use).
**This suite has been executed against real PHPUnit 9.6 during development:
18 tests, 70 assertions, 0 failures.**

**Integration suite** — requires a real WordPress + MySQL test environment
(`$wpdb`, hooks, capabilities, REST):
```bash
WP_TESTS_DIR=/path/to/wordpress-tests-lib composer test:integration
```
Not runnable in a sandbox without WordPress installed; wire this into CI
against a real WP test install before deploying Phase 2+.

## Coding standard
`phpcs.xml.dist` targets WordPress Coding Standards + PHPCompatibilityWP
(PHP 8.1+). Run `composer lint` once `composer install` has pulled the dev
dependencies (requires Packagist access, which this build sandbox does not
have).

## Before activating on a real site
1. `vendor/` is already bundled — no composer step needed. (If you deleted
   it and want to regenerate: `composer install --no-dev`, which needs
   Packagist access this build sandbox didn't have.)
2. Add `MCP_SUITE_ENCRYPTION_KEY` to `wp-config.php` (Settings screen has a
   one-time generator) before enabling any credential-storing module.
3. Activate on staging first. Confirm the Audit Log screen records the
   activation event and the Dashboard lists all 9 modules as "Not yet
   enabled."
4. Only then enable modules one at a time, starting with Content Sync in
   Phase 2.
