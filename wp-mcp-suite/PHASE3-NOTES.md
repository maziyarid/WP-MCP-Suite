# Phase 3 — SEO Bridge + Search Console + GA4

## What this phase contains
- **SEO module**: read-only Rank Math / Yoast metadata bridge, a pure
  per-post health-check rule engine (title/description length, missing
  focus keyword, thin content, missing schema override, missing
  breadcrumbs, unexpected/missing noindex), and a separate cross-post
  duplicate-meta-description detector that runs as a SQL-side `GROUP BY`
  pass rather than loading every description into PHP memory.
- **Google auth layer** (`includes/Core/Google/`): service-account
  JWT-bearer auth (RFC 7523), shared by Search Console and GA4 — same
  headless-friendly reasoning as Graph's application permissions in Phase
  2. Uses PHP's built-in `openssl` extension for RS256 signing, so unlike
  PHPWord this has **no unverifiable external dependency** — the crypto is
  genuinely tested in this sandbox with real generated keypairs.
- **Search Console module**: daily pull of the previous-3-days'
  clicks/impressions/CTR/position by page/query/country/device, upserted
  on its natural key.
- **Analytics (GA4) module**: daily pull of the previous-2-days' sessions/
  users/engagement/conversions/events by landing page, upserted on its
  natural key.
- **Generic HTTP layer** (`includes/Core/Http/`): `HttpClientInterface`
  used by Google's clients. Microsoft Graph still uses its own parallel
  interface from Phase 2 — consolidating the two is explicitly deferred to
  the Phase 8 hardening pass rather than risking a regression in already-
  verified Phase 2 code. Both follow the identical shape on purpose.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox (syntax-checked + executed against real PHPUnit 9.6):**
- `SeoHealthChecker` — all 12 rules, boundary conditions (exactly-at-threshold
  cases), zero-word-count edge case.
- `DuplicateDescriptionDetector` — grouping, multi-way duplicates, whitespace
  normalization, empty-description exclusion.
- `GscResponseParser` / `GaResponseParser` — parsed against realistic fixture
  JSON matching Google's documented response shapes, including malformed-row
  and missing-field resilience. GA4's parser is specifically tested to map by
  header **name**, not column position.
- **`JwtSigner` — a full real RSA keypair is generated with `openssl_pkey_new`
  and the produced JWT's signature is verified with `openssl_verify` against
  the matching public key, and confirmed to fail against a different keypair.**
  This is genuine cryptographic verification, not a mock.
- `GoogleAuthService` — token fetch/cache/expiry-buffer/invalidate, assertion
  claim shape (`iss`/`scope`/`aud`/`iat`/`exp`), and that two module instances
  (GSC vs GA4) cache tokens independently — all via a fake HTTP client, no
  live network call.
- **114/114 PHP files pass `php -l`. 90 unit tests, 222 assertions, 0
  failures, 1 legitimate skip** (the PHPWord round-trip test carried over
  from Phase 2, unrelated to this phase's code).

**NOT verified here, and must be verified in a real environment:**
- `GoogleGscApiClient` / `GoogleGa4ApiClient` — request shapes were written
  against Google's documented API contracts, never called against a live
  Search Console property or GA4 property. First real test: configure
  credentials on Settings, enable the module, and check the admin page /
  `wp_mcp_gsc_history` / `wp_mcp_ga_history` after the next cron tick (or
  trigger the REST `pull-now` route manually).
- `RankMathAdapter` / `YoastAdapter` — postmeta key names are drawn from
  each plugin's documented, stable public conventions but were not read
  against a real Rank Math or Yoast installation. Verify by opening the SEO
  Health admin page on a site with one of those plugins active and a few
  posts with known-good/known-bad SEO fields, and confirming the flagged
  issues match expectations.
- Everything touching `$wpdb` or WordPress hooks directly
  (`SeoSnapshotRepository`, `GscHistoryRepository`, `GaHistoryRepository`,
  `GoogleSettingsController`) needs the `tests/integration` suite against a
  real WordPress install.

## Known, deliberate scope limits (not bugs)
- SEO health checks treat every published, publicly-queryable post as
  "expected to be indexed" — there's no per-page override yet for
  intentionally-noindexed utility pages (e.g. a cart or thank-you page). A
  false `expected_noindex_missing` flag on such a page is expected until a
  future phase adds per-page overrides.
- Yoast's schema-type detection reads only page-level overrides, not the
  site-wide default Yoast falls back to — see `YoastAdapter`'s docblock.
- Rank Math's schema-type detection reads the single "Rich Snippet" primary
  type field, not the full JSON-LD schema graph Rank Math can build from blocks.

## Before enabling these modules on a real site
1. **SEO**: just needs Rank Math or Yoast active — no credentials to configure.
2. **Search Console / GA4**: create a Google Cloud service account, download
   its JSON key, add its email as a Search Console user and a GA4 Viewer,
   then enter the email + private key + site URL + property ID on Settings.
3. Enable each module individually and check its admin page after the next
   cron tick (or trigger its REST `*-now` route) before assuming it's working.
