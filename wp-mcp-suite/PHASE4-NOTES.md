# Phase 4 — Clarity + Link Intelligence

## What this phase contains
- **Internal link graph**: a pure HTML-parsing extractor (`InternalLinkExtractor`,
  built on PHP's `DOMDocument`) that finds internal links in rendered post
  content, resolves absolute and root-relative URLs, classifies host
  matches (including `www.` normalization), and captures anchor text plus
  a surrounding-paragraph context snippet. Runs as a bounded/resumable
  daily cron batch, same shape as Content Sync and SEO's crons, and prunes
  links that were removed since the last crawl.
- **Backlink graph**: `BacklinkProviderInterface` + `NullBacklinkProvider`.
  Per the technical specification's open decision #2, no specific backlink
  data provider was named in the original requirements, so none was
  guessed at here. The admin page and weekly cron both honestly report
  "not configured" rather than faking data. `BacklinkRepository` (upsert +
  lost-link detection) is fully built and ready — implementing a real
  provider later is a single new class behind the existing interface.
- **Clarity module**: real API client against Microsoft's documented
  `project-live-insights` endpoint, respecting the confirmed 10-requests/
  project/day quota (the module pulls once per day and skips if that day's
  data already exists, unless manually forced) and the 3-day data
  retention window.

## The Clarity field-mapping caveat — read this before trusting rage/dead/quickback/scroll numbers
Microsoft's official documentation (learn.microsoft.com, confirmed via
live fetch during this build) gives the exact response shape and, for the
**Traffic** metric specifically, the exact field names (`totalSessionCount`
etc.) via its own sample response. It does **not** publish the exact
`information`-object field names for the Rage Click Count, Dead Click
Count, Quickback Click, or Scroll Depth metrics, or confirm the exact key
used for a `URL` dimension breakdown (only an `OS` example is shown).

`ClarityResponseParser::METRIC_FIELD_MAP` isolates every one of these
assumptions in one small, clearly-commented array. **Sessions (Traffic) is
verified; the other four are a best-effort guess pending a real API
response.** The admin page shows a visible notice about this automatically
whenever any mapping is unconfirmed. To fix: generate a Clarity API token,
make one real request with `dimension1=URL`, inspect the JSON for the
non-Traffic metric groups, and update the field names in that one array —
nothing else in the codebase needs to change.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox (syntax-checked + executed against real PHPUnit 9.6):**
- `InternalLinkExtractor` — absolute/root-relative resolution, external-link
  exclusion, `www.` normalization, mailto/tel/anchor skipping, malformed-HTML
  resilience, and **a real UTF-8/Persian round-trip test** (anchor text and
  context snippet), which matters directly since this plugin's real target
  sites are Persian-language.
- `ClarityResponseParser` — parsed against a fixture built directly from
  Microsoft's own documented sample response shape; multi-metric merging,
  multi-page separation, scroll-depth averaging, and the confidence-flag
  accessor all tested.
- **132/132 PHP files pass `php -l`. 110 unit tests, 266 assertions, 0
  failures, 1 legitimate skip** (PHPWord, carried over from Phase 2).

**NOT verified here, and must be verified in a real environment:**
- `ClarityApiClient` — request shape matches the documented contract but
  was never called against a live Clarity project. First real test:
  configure the token, enable the module, and check the admin page and
  the field-mapping notice after the next cron tick.
- `InternalLinkRepository`, `BacklinkRepository`, `ClarityHistoryRepository`,
  and every settings controller touch `$wpdb`/hooks directly and need the
  `tests/integration` suite against a real WordPress install.
- `url_to_postid()` resolution in `InternalLinkRepository::upsert_many()`
  (mapping a crawled URL back to a WP post ID) relies on WordPress's own
  rewrite-rule matching and was not exercised against a real permalink structure.

## Before enabling these modules on a real site
1. **Link Intelligence**: no credentials needed for the internal link graph;
   just enable the module. Backlinks stay empty until a provider is chosen
   and implemented — see `BacklinkProviderInterface`.
2. **Clarity**: generate an API token in the Clarity project (Settings →
   Data Export), paste it into Settings, enable the module, and check the
   field-mapping notice on its admin page.
