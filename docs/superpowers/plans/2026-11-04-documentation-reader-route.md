---
created_on: 2026-11-04
module: ai
---
# Documentation reader route. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** A client can read a passage of the end-user documentation in full, and the whole document it belongs to, through one route that applies the access rules of the palette search, and a documentation reference names a document by an opaque key that carries its kind.

**Architecture:** Each chunk of the user index carries its position in the document (`section`) and an opaque document key. The search item and the assistant citation expose `kind`, the key as `reference` and `section`. A new retrieval-only route, `GET /app/crud/detail/{module}/documentation`, queries the same index with the same access filter plus a term on the document key and returns the chunks in order. It reads the index and never the source file, so access stays decided in one place.

**Tech Stack:** PHP 8.5, Laravel 12, Elasticsearch (user documentation index), Pest 4. No new dependency.

**Spec:** `docs/superpowers/specs/2026-11-04-reference-reader-design.md` at the stack root (sections 3, 4.1 to 4.4, 6 and 7). It spans this repository and `laraplate-ui`; the client plan is `docs/superpowers/plans/2026-11-04-reference-reader.md` at the stack root and waits for Task 4 here.

**Builds on:** `specs/2026-10-21-palette-documentation-search-design.md` (the search route, `DocumentationSearch`, `DocumentationPassage`).

## Global Constraints

- `declare(strict_types=1);`, braces, explicit types, `final`/`readonly` as the surrounding code does, `#[Override]` when overriding. Never remove `final`, `readonly` or `static` to ease a test.
- Run Pint from the repository root with an explicit file list: `vendor/bin/pint --format agent <files>`. Never with an empty list. `--dirty` does not see the module submodules.
- Tests in `Modules/AI/tests/`, Pest, factories for setup, no class declared in a test file (stubs go to `tests/Stubs/`).
- **No path leaves the server.** `canonical_source` is never returned, logged or placed in a response. The key is a hash.
- **Access is decided once.** The read applies `DocumentationRetrievalContext::elasticsearchFilter()` and adds a term on the key. It never reads a file and never re-implements an audience, permission, tenant or module rule.
- A document the person cannot retrieve answers 404, never 403.
- The route calls no language model and builds no prompt.
- Documented behaviour goes to `Modules/AI/docs/`, not only to this plan.

**Touch points, read before coding:**
- `Modules/AI/app/Services/Documentation/Chunking/MarkdownAwareSplitter.php` (where a chunk is made, metadata copied from the document).
- `Modules/AI/app/Services/DocumentationService.php` (`splitDocuments()`, `storeDocuments()`, `batchBySource()`, `retrieveForInApp()`).
- `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php` (mapping around line 85, `similaritySearchForContext()`, `mapHitsToDocuments()`).
- `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php` (`safeDocuments()`, the metadata subset it keeps) and `DocumentationRetrievalContext.php` (`elasticsearchFilter()`).
- `Modules/AI/app/Services/Documentation/Search/DocumentationPassage.php`, `DocumentationSearch.php`, `Http/Controllers/DocumentationSearchController.php`, `Http/Requests/DocumentationSearchRequest.php`.
- `Modules/AI/app/Services/Assistance/InAppAssistanceService.php` (the documentation citation projection, around line 449) and `Services/ApplicationContent/ApplicationContentCitationMapper.php`.
- `Modules/AI/tests/Feature/Assistance/` and the existing tests of the documentation search route.

---

### Task 1: Settle the checks that change the schema

- [x] **Step 1:** Read how `canonical_source` is declared in a user document (front matter) and write the answer under a *Decision* line in the spec section 6, check 1: is it unique per locale, or must the key hash `module`, `locale` and `canonical_source` together? Default to hashing all three.
- [x] **Step 2:** Confirm in a test against a real index, or in the Elasticsearch documentation, that the `metadata` object accepts two new keywords and an integer without recreating the index (spec check 4). Write the answer under the same *Decision* section. If the index must be recreated, Task 6 carries the recreate and the upgrade note says so.
- [x] **Step 3:** Read `ASSISTANT_EVALUATION.md` and the evaluation service for what it compares in a citation (spec check 3). Write whether `kind` and the opaque `reference` break it, and what the harness must compare instead (the label).

---

### Task 2: Position and key at indexing time

**Files:**
- Modify: `Modules/AI/app/Services/Documentation/DocumentationMetadata.php` (a `documentKey()` helper).
- Modify: `Modules/AI/app/Services/Documentation/Chunking/MarkdownAwareSplitter.php`.
- Modify: `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php` (mapping of the user index).
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php` (the safe subset).
- Test: `Modules/AI/tests/Unit/` and `Modules/AI/tests/Feature/` next to the existing splitter and retrieval tests.

- [x] **Step 1: Test first** — a document split into n chunks gives `section` 0 to n-1 in order and the same `document_key` on every chunk; two documents of different locale or module get different keys; the same document twice gets the same key; the key matches a fixed-length pattern and contains no part of the path; a chunk of a document without `canonical_source` gets no key (so the user corpus, which admits only declared metadata, still refuses it).
- [x] **Step 2: Implement** `DocumentationMetadata::documentKey(array $metadata): ?string` (a hash of the decision of Task 1), prefixed `d_`. The splitter sets `section` on each chunk it emits and the key on all of them. *Done differently:* `DocumentationMetadata::markPositions()` is called from `DocumentationService::splitDocuments()` instead, because `SplitterFactory` can return the `sentence` or `delimiter` splitter of Neuron too, and the position must not depend on the driver.
- [x] **Step 3: Mapping.** Add `section` (integer) and `document_key` (keyword) to the `metadata` properties of the user index, per the decision of Task 1.
- [x] **Step 4: Safe subset.** `safeDocuments()` keeps `section` and `document_key` among the metadata it hands on. Nothing else is added.
- [x] **Step 5:** run the tests (PASS), Pint, commit: `feat(ai): documentation chunks carry their position and an opaque document key`.

---

### Task 3: `kind`, the opaque reference and the section in search items and citations

**Files:**
- Modify: `Modules/AI/app/Services/Documentation/Search/DocumentationPassage.php`.
- Modify: `Modules/AI/app/Services/Assistance/InAppAssistanceService.php` (documentation citations).
- Modify: `Modules/AI/app/Services/ApplicationContent/ApplicationContentCitationMapper.php` (`kind: content`).
- Test: the existing tests of those classes, and a new Feature test.

- [x] **Step 1: Test first** — a search item and an assistant documentation citation carry `kind: documentation`, `reference` equal to the document key (never the label), `section` as an integer and the unchanged `label`, `excerpt`, `heading_breadcrumb`, `module`; an application-content citation carries `kind: content` and its canonical `/app/...` reference unchanged; a chunk indexed before Task 2 (no key) is still returned, with the label as `reference` and no `section`, and the item says `kind: documentation`.
- [x] **Step 2: Implement.** `DocumentationPassage::fromDocument()` reads the key and the section from the document metadata. The citation projection does the same. A chunk with no key keeps the old behaviour so that nothing breaks before the first reindex.
- [x] **Step 3: Evaluation harness.** Apply the decision of Task 1, step 3, so the harness still compares what it compared. Nothing to change: it compares labels only. *Also needed:* `AssistanceContextPolicy` accepted only `/app/...` references and a closed key set, so it now admits `kind` and `section` and takes the document key as the reference of a `documentation` citation.
- [x] **Step 4:** run the tests (PASS), including `AssistantBaselineGateTest` and the assistant evaluation tests; Pint; commit: `feat(ai): documentation and content citations carry their kind and an opaque reference`.

---

### Task 4: The read, over the index

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php` (a method that reads the chunks of one document).
- Create: `Modules/AI/app/Services/Documentation/Search/DocumentationReader.php`.
- Create: `Modules/AI/app/Services/Documentation/Search/DocumentationSection.php` (value object).
- Test: `Modules/AI/tests/Feature/Documentation/DocumentationReaderTest.php`.

- [x] **Step 1: Test first** — with chunks indexed for three documents: reading a key returns that document's chunks in `section` order; with a `section` it returns that chunk and its neighbour on each side (fewer at the ends); a key the person's permissions do not cover returns nothing, as does a key of another tenant, another locale, or outside the module scope; a key that does not exist returns nothing; the access filter is the one of the search (assert with the same fixtures the search tests use); no result carries `canonical_source`.
- [x] **Step 2: Implement the store method.** `chunksOfDocument(string $document_key, DocumentationRetrievalContext $context, ?int $from, ?int $to): array`: a filter query (no vector), the filter of `elasticsearchFilter()` plus a term on `metadata.document_key`, sorted by `metadata.section`, bounded (a document is capped at a fixed number of sections, for example 200, so a wrong key or a huge document cannot return an unbounded body). Same user-index guard as `similaritySearchForContext()`.
- [x] **Step 3: Implement `DocumentationReader`.** It takes the user, the module and the key, builds the access context and scope exactly as `DocumentationSearch` does, calls the store, runs the results through the same `safeDocuments()` policy (move that method to a shared place if it is private and the reader needs it, keeping one copy), and returns `DocumentationSection` objects (`section`, `heading_breadcrumb`, `content`) with the document label and module. The content is the chunk text as stored, plain, with the `> Section:` prefix line the splitter adds removed.
- [x] **Step 4:** (the tests use a mocked Elasticsearch client for the store, and an injected `read` closure for the reader: the access filter is asserted as the exact output of `elasticsearchFilter()` sent to the index, since no live index exists here; `DocumentationRead` is the value object that carries the whole response.) Run the tests (PASS), Pint, commit: `feat(ai): read the chunks of a documentation document under the access rules of the search`.

---

### Task 5: The route

**Files:**
- Create: `Modules/AI/app/Http/Controllers/DocumentationReadController.php` (or a `read` method of `DocumentationSearchController` if its size allows).
- Create: `Modules/AI/app/Http/Requests/DocumentationReadRequest.php`.
- Create: `Modules/AI/app/Http/Resources/DocumentationReadResource.php`.
- Modify: `Modules/AI/routes/web.php`.
- Test: `Modules/AI/tests/Feature/Documentation/DocumentationReadRouteTest.php`.

- [x] **Step 1: Test first** — signed-in user reads a document they may read, with and without `section`; `total` and `from` are right; a document the person cannot read answers 404 with the same body as a document that does not exist; the guest account is refused; a request that is not signed in is 401; with `features.faq.enabled` off it is 403 `FEATURE_DISABLED`; an unknown or malformed `{module}` is 404; a malformed `reference` or a non-integer `section` is 422 (after the checks above, as in the search route); a reference of another module answers 404; the route is throttled; no response contains a path.
- [x] **Step 2: Implement.** `GET /app/crud/detail/{module}/documentation`, `reference` required (pattern of the key), `section` optional integer from 0. The checks run in the order of `DocumentationSearchController::search()` (user, guest, feature, module) before the input is validated. Register it next to the search route, before Core's generic `detail/{module}/{entity}`, and name it `documentation.read`. The response is the envelope of the spec 4.3, through the resource. An unavailable index is 503 like the search.
- [x] **Step 3: Throttle.** Reuse the limiter of the search route, or add `documentation-read` with its own per-minute setting if the search limit would starve reading, and document the key.
- [x] **Step 4:** check that no module has a model named `documentation` (the entity name is reserved, as the search plan already requires) and that Core's `detail/{module}/{entity}` still serves every other entity.
- [x] **Step 5:** (done differently: the action is `DocumentationSearchController::read()` with a shared `allowedUser()` check, there is no separate resource class because the search route has none either, `DocumentationRead::toArray()` builds the body; the new limiter is `documentation-read`, key `ai.features.faq.read.rate_per_minute`, default 120; a `reference` that is not a document key, for example a label from before the first reindex, is 404 and not 422, so the client's "the document changed" path is one status; only a missing or overlong `reference` and a bad `section` are 422.) Run the tests (PASS), Pint, commit: `feat(ai): GET /app/crud/detail/{module}/documentation reads a documentation passage or document`.

---

### Task 6: Reindex and the upgrade path

- [x] **Step 1: Test first** — a reference whose chunks were indexed before Task 2 (no `document_key` in the index) answers 404, and the search still returns its items with the label as `reference`, so the client can show "the document changed, search again" without a broken link.
- [x] **Step 2:** (the command has no test of its own; the service it calls is tested on both paths, incremental and full rebuild, with the agent mocked.) Run `ai:index-rag-docs --full` against a test index in a test (the existing command tests show how) and assert that the chunks now carry `section` and `document_key`. If Task 1 decided that the index must be recreated, the test covers the recreate.
- [x] **Step 3:** run the tests (PASS), Pint, commit: `test(ai): documentation reads before and after a full reindex`.

---

### Task 7: Documentation

**Files:**
- Modify: `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` (the documentation search section: the read route, the key, `section`, `kind`).
- Modify: `Modules/AI/docs/rag/MODULE.md` and `Modules/AI/docs/rag/ASSISTANT_PROPOSALS_DEVELOPER.md` (the citation shape).
- Modify: `Modules/AI/README.md` (upgrade note: run `ai:index-rag-docs --full` once, and the throttle key if Task 5 added one).
- Modify: `docs/superpowers/plans/INDEX.md` (one line for this plan).

- [ ] **Step 1:** document the route, the response, the access rules, the 404 rule, the cap on sections, and the fields added to a search item and a citation.
- [ ] **Step 2:** write the upgrade note: before a full reindex an old reference answers 404 and the palette shows that the document changed.
- [ ] **Step 3:** add the plan line to the index (name and what it covers, no status).
- [ ] **Step 4:** at the end of the plan add `status: completed` to the front matter, a `## Delivery status (date): ...` section and the `**Documented in:**` line naming the files above.
