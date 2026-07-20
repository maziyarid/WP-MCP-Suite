# Phase 5 — AI Writing Queue

## What this phase contains
- **Pluggable AI provider**: `AiProviderInterface` + a real, working
  `OpenAiCompatibleProvider` against the widely-adopted OpenAI
  chat-completions contract (works with OpenAI itself or any provider
  exposing the same endpoint shape) — genuinely usable today, not a
  placeholder, while not hardcoding Blackbox/You.com per the spec's open
  decision #3.
- **PHI prompt guard**: runs before every generation call, blocking
  SSN-like patterns, MRN patterns, explicit patient-identifying phrasing,
  DOB-like patterns, and site-configured keyword phrases — the request to
  the AI provider is never made if the guard trips.
- **The mandatory human-review gate, enforced twice**:
  1. `AiRunStatusTransition` — a pure state machine that makes PUBLISHED
     unreachable from any state without `human_reviewed = true`. Every
     status change in the codebase goes through this.
  2. A `wp_insert_post_data` filter (`AiWritingModule::block_unreviewed_ai_publish`)
     that runs on **every** post save in WordPress — not just this
     module's own REST route — and rewrites an attempted "publish" back to
     "draft" for any post tracked in `wp_mcp_ai_runs` that hasn't been
     reviewed. This means publishing an AI draft directly through the
     normal block editor, quick-edit, or any other plugin's code path is
     also blocked, not just this module's own UI. That's what makes this
     "enforced in code," per the spec's explicit wording, rather than a
     convention the built-in UI happens to follow.
- **Review workflow**: generation creates a WordPress draft post (the
  actual reviewable content lives there, in the normal editor — not
  duplicated into a custom review UI) plus an audit-trailed `wp_mcp_ai_runs`
  row. A human reviews the draft normally, then uses "Approve & Publish" or
  "Reject" on the AI Writing Queue screen — the only two code paths that
  can move a run out of `drafted`/`edited`.

## Design choice: synchronous generation, not an async queue
The original draft's cron list includes "Hourly: check AI draft queue and
log completions," which implies an async job queue. An OpenAI-compatible
chat-completions call is a single synchronous HTTP request/response, not a
long-running job — there is no queue to check in that architecture. Rather
than build a fake queue to match the cron's literal wording, generation
happens synchronously when triggered (REST `POST /ai-writing/generate`),
and the hourly cron does something real instead: it audit-logs a count of
drafts that have been waiting for review, which is genuinely useful
visibility. If a future provider needs true async (e.g. a batch API), the
queue semantics belong in that provider's adapter, not bolted onto every
provider whether it needs them or not.

## What's real vs. what needs a live environment to verify

**Verified in this sandbox (syntax-checked + executed against real PHPUnit 9.6):**
- `PhiPromptGuard` — every built-in pattern, plus explicit tests that
  ordinary marketing copy using the word "patient" or containing an
  unrelated date is **not** falsely blocked (a guard that cries wolf gets
  disabled, which is worse than a narrower one that stays on).
- `AiRunStatusTransition` — **exhaustively tested**, including a
  full-matrix invariant test asserting PUBLISHED is unreachable without
  `human_reviewed = true` from every possible starting state. This is the
  most safety-critical logic in the phase and has the deepest test coverage
  to match.
- **147/147 PHP files pass `php -l`. 134 unit tests, 307 assertions, 0
  failures, 1 legitimate skip** (PHPWord, carried over from Phase 2).

**NOT verified here, and must be verified in a real environment:**
- `OpenAiCompatibleProvider` — request/response shape matches the
  documented OpenAI contract but was never called against a live endpoint.
- The `wp_insert_post_data` filter's interaction with the full WordPress
  save pipeline (autosaves, revisions, Gutenberg's REST-based saves,
  scheduled publishing via `wp_cron`) needs `tests/integration` against a
  real WordPress install — filter-based interception is exactly the kind
  of code that can have edge cases only a real save pipeline exercises.
- `AiRunRepository`, both settings controllers, and the admin-post form
  handlers all touch `$wpdb`/hooks directly — same integration-suite
  requirement as every other phase.

## Before enabling this module on a real site
1. Get API access to an OpenAI-compatible endpoint (OpenAI directly, or
   whichever provider is chosen per open decision #3) — base URL, API key, model name.
2. Enter them on Settings, along with any clinic-specific PHI blocklist
   phrases (e.g. real patient names staff know must never reach a prompt).
3. Enable the module, generate one low-stakes test draft, and confirm:
   the draft appears as a WordPress draft post, the AI Writing Queue shows
   it pending review, and clicking "Publish" directly in the block editor
   (without using Approve) gets reverted to draft with the warning notice
   — that's the gate working as intended, not a bug.
