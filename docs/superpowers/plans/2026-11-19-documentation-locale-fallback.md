---
created_on: 2026-11-19
module: ai
status: completed
---
# English fallback for the user documentation. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [x]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** A caller whose locale has no translation of a user guide gets the English one, and a caller whose locale has a translation gets that instead.

**Architecture:** `DocumentationRetrievalContext::elasticsearchFilter()` matches `metadata.locale` with a `terms` query over the caller's locale and the fallback `en`. `InAppDocumentationRetrieval::retrieve()` drops the fallback-locale chunks of a document that was also retrieved in the caller's locale. Nothing in the index, the mapping or the indexing changes.

**Tech Stack:** PHP 8.5, Laravel 13, Pest. No new dependency, no new setting, no new environment variable.

**Spec:** `../specs/2026-11-19-documentation-locale-fallback-design.md`.

## Delivery status (2026-11-19): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md` (*Language of a user document*), `Modules/AI/docs/SEARCH_AND_TRANSLATION.md`.

Every task shipped in getlaraplate/laraai#31. Divergences from the steps:

- **The preference is its own class,** `DocumentationLocalePreference`, called from `InAppDocumentationRetrieval::retrieve()`, instead of a private step of `retrieve()`: a document without a canonical source cannot reach `retrieve()` (the audience policy refuses it), so only a pure class could test that rule.
- **Task 3 added one test, not a change:** the read filter shares `elasticsearchFilter()`, so the English document key opens for an Italian caller with no code of its own; the test pins it through `chunksOfDocument()`.
- **The full AI suite had 17 failures from `vendor/laravel/...` files missing** while another process touched the dependencies; the three Assistance folders they sit in pass on a rerun (275 passed). The whole suite was not run a second time.
- **Not built, as the spec says:** on-the-fly or stored translations, a visible "shown in English" marker, a translation of the ten guides.

## Global Constraints

- Branch `feat/documentation-locale-fallback` in `Modules/AI` (code, tests, module docs); the spec and this plan are in the root `laraplate` repo. One PR per repo; submodule pointers are bumped by the owner.
- The fallback is a class constant, not a setting. No change to the index mapping or to what the indexing writes.
- Tests that declare classes use `Modules/AI/tests/Stubs/`. No test is deleted; the ones that encode the single-locale `term` move to `terms`.
- Run Pint from the root with an explicit file list. Narrow `php artisan test --compact <path>` while working; the full AI module suite passes before the PR is opened.
- Do not write to the remote Elasticsearch from an agent. A read-only retrieval probe is allowed.

## Review Focus

1. **The permission rule is untouched.** A guide that requires a permission is still hidden from a caller who lacks it, in the English fallback as in any locale.
2. **The preference is by document, not by chunk.** Two chunks are the same document when `module` and `canonical_source` are equal; a guide with another source is never dropped.
3. **Only the caller's locale and `en` are admitted.** A guide in a third locale stays excluded.
4. **The caller's locale `en` is not a regression:** one value in the filter, no preference step to apply.

---

### Task 1: The filter admits the fallback locale

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DocumentationRetrievalContext.php`.
- Modify: `Modules/AI/tests/Stubs/Documentation/FakeDocumentationSearch.php` (the visibility it models).
- Test: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/InAppDocumentationRetrievalTest.php` and a new `DocumentationLocaleFallbackTest.php`.

- [x] **Step 1: Test first.** The filter holds a `terms` on `metadata.locale` with the caller's locale and `en`, and a single value for an English caller; a caller with locale `it` retrieves an English-only guide through `FakeDocumentationSearch`; a guide in `de` stays excluded for an `it` caller; a guide that requires a permission is hidden from an `it` caller without it, in English as in Italian.
- [x] **Step 2: Implement** `FALLBACK_LOCALE` and `acceptedLocales()` (caller's locale first, then `en`, no duplicate) and use them in `elasticsearchFilter()`. The fake store models the same rule by calling `acceptedLocales()`.
- [x] **Step 3:** update the tests that encode the single `term` (not delete them), run the narrow paths (PASS), Pint, commit: `feat(ai): the user documentation falls back to English`.

---

### Task 2: The caller's language wins

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php` (`retrieve()`).
- Test: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/DocumentationLocaleFallbackTest.php`.

- [x] **Step 1: Test first.** An `it` and an `en` chunk of the same document (same module and canonical source) both retrieved give only the `it` one; an `en` chunk of a document with no `it` chunk is kept beside the translated one; chunks of two different documents are never merged; for an `en` caller nothing is dropped; the order of the returned passages is the order of the search.
- [x] **Step 2: Implement** the preference on the raw documents, before the safe projection. A document with no `canonical_source` is never dropped.
- [x] **Step 3:** run the narrow paths (PASS), Pint, commit: `feat(ai): a translated user guide wins over its English source`.

---

### Task 3: The reader and the existing assertions

**Files:**
- Test: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/` (the read path) and the assistant evaluation tests.

- [x] **Step 1: Test first.** The read of an English document key under an `it` context passes the read filter (both locales admitted).
- [x] **Step 2:** run `InAppDocumentationRetrievalTest`, `DocumentationPermissionRequirementsTest`, `DocumentationRetrievalScopeTest`, the documentation route tests and the assistant evaluation tests; fix only assertions that encode the single-locale filter.
- [x] **Step 3:** commit with Task 1 or 2 as fits.

---

### Task 4: Documentation

**Files:**
- Modify: `Modules/AI/docs/rag/MODULE.md` (user corpus retrieval: locales admitted, the preference, how a translation is added, the limits).
- Modify: `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` only if it states the single-locale filter.
- `Modules/AI/README.md`: no setting or variable changed; state the judgment when finishing.

- [x] **Step 1: Write** the behaviour, the rule to add a translation (same `canonical_source`, `locale: it`) and the accepted limits.
- [x] **Step 2: Check** `grep -rn "metadata.locale\|exact locale\|user's locale" Modules/AI/docs`, nothing stale.
- [x] **Step 3:** commit: `docs(ai): the English fallback of the user documentation`.

---

### Task 5: Verification (read-only)

- [x] **Step 1:** with the real user index, ask one question under a context with locale `it` and under `en`: the same passages come back for both. Done read-only with hand-built contexts for `en`, `it` and `de`, with and without `core_settings.*`: the same pages come back for the three locales, and the permission rule still hides the `core_settings` pages.
- [x] **Step 2:** full AI module suite; PR. Done: 2476 passed and 7 skipped, plus 17 failures that were `vendor/laravel/...` files missing while another process touched the dependencies; the three Assistance test folders they sit in pass on a rerun (275 passed).

---

## Out of scope

On-the-fly or stored translations, a visible "shown in English" marker, per-locale fallback chains, translating the ten guides (spec "Out of scope").
