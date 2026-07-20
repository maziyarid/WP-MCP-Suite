# Phase 7 — Reporting (Excel Workbook + JSON Export)

## What this phase contains
- **A dependency-free XLSX writer** (`Core\Xlsx\`): raw OOXML SpreadsheetML
  generation via PHP's built-in `ZipArchive`, with no `phpoffice/phpspreadsheet`
  dependency. Unlike Content Sync's DOCX conversion (Phase 2, which
  genuinely needs an unfetchable-in-this-sandbox Composer package), this
  has **no external dependency at all**, which means — unlike DOCX — the
  full file generation was actually built and verified here, not just
  written against a documented contract and left unverified.
- **`ReportDataAggregator`**: pulls from every module's repository
  (Content Sync, SEO, Search Console, Analytics, Clarity, Internal Links,
  Backlinks, AI Runs, Backups) into the sheet structure spec's
  "Copilot-ready data layer" section calls for.
- **`ReportJsonTransformer`**: the same aggregated data as normalized JSON
  (an array of `{column: value}` objects per sheet, not raw arrays), which
  is what makes it genuinely reasoning-friendly for a tool like Copilot
  rather than requiring the consumer to already know column order.
- **Weekly cron**: rebuilds both files locally (always, in the WordPress
  uploads directory) and additionally uploads both to OneDrive when Graph
  is configured — reporting works even for a site that hasn't set up
  Content Sync or Backups.

## "One sheet per domain" — the interpretation this phase used
The original draft's wording ("one sheet per domain") was written with a
possible multi-domain aggregation platform in mind. This plugin's actual
architecture (confirmed across every phase so far) is one plugin instance
per WordPress install, with its own database — there is no cross-site
aggregation layer. "One sheet per domain" is therefore implemented here as
**one sheet per data-source module** (Content Sync, SEO, Search Console,
etc.), which is what actually maps onto this plugin's real data model. If
true multi-domain aggregation (e.g., a dashboard spanning your 40+ managed
sites) turns out to be wanted, that is a materially different feature —
it would need either a central aggregation service each site's plugin
reports into, or this reporting module invoked once per site with the
outputs merged afterward — not a change to this module in isolation.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox — and more thoroughly than any previous phase's
file-generation code, specifically because this one has no external
dependency (syntax-checked + executed against real PHPUnit 9.6):**
- `ColumnRef` — the index-to-letter conversion, including the tricky
  single-to-double-letter (Z→AA) and double-to-triple-letter (ZZ→AAA)
  boundaries, plus a 1000-column uniqueness sweep.
- `WorksheetXmlBuilder` — output parsed back with `SimpleXMLElement` to
  verify actual structure (not string-matching): correct cell types for
  strings/numbers/booleans/nulls, correct cell references across multiple
  rows and columns, proper XML escaping of `& < > " '`, control-character
  stripping, `INF`/`NAN` safety, and a Persian/Farsi round-trip.
- `SheetNameSanitizer` — Excel's real naming restrictions (31 chars,
  forbidden characters), including multibyte truncation by character
  count, not byte count.
- **`WorkbookWriter` — a real XLSX file is built, then reopened with
  `ZipArchive` and its worksheet XML reparsed, confirming the actual
  produced file (not just its XML fragments) is structurally correct,**
  including a full write-read cycle with real Persian content.
- `ReportJsonTransformer` — header/data-row transformation, short-row
  null-filling, and end-to-end `json_encode()` with Persian content.
- **176/176 PHP files pass `php -l`. 203 unit tests, 1454 assertions, 0
  failures, 1 legitimate skip** (PHPWord, carried over from Phase 2 — at
  the time this phase was written, the one file-generation dependency in
  the plugin that remained genuinely unverified. It has since been
  vendored directly from GitHub and verified for real — see the root
  `README.md` for current status).

**NOT verified here, and must be verified in a real environment:**
- `ReportDataAggregator` touches every module's `$wpdb`-backed repository
  and needs `tests/integration` against a real WordPress install with
  actual data in each table.
- The produced `.xlsx` file was verified structurally (valid zip, valid
  XML, correct cell values) but was never opened in real Excel or Google
  Sheets — do that once before treating the report as reliably openable
  by an actual human or Copilot.
- OneDrive upload reuses `GraphBackupUploader`, which itself is unverified
  against a live Graph tenant — see `PHASE6-NOTES.md`.

## Before enabling this module on a real site
1. No new credentials needed — reuses Graph (optional, for OneDrive
   upload) and reads from whichever other modules are already enabled.
2. Trigger one manual rebuild (`POST /reporting/rebuild-now`) and actually
   open the downloaded `.xlsx` in Excel/Google Sheets/LibreOffice before
   relying on the weekly cron.
