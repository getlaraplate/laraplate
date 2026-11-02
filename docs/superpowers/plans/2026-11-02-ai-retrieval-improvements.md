---
created_on: 2026-11-02
module: ai, core
---
# AI retrieval improvements. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** Nine independent retrieval and operations improvements for the AI and Core modules, none tied to a vendor, a model or a hosting choice.

**Architecture:** Core owns query understanding and engine behaviour (dates, request words, unstemmed field, similarity floor); the AI module owns profiles, probes, the judge, media analysis and analytics. Index-changing items apply on the next rebuild.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. No new dependency.

**Spec:** `../specs/2026-11-02-ai-retrieval-improvements-design.md` (decisions 1 to 12).

## Global Constraints

- One branch per repo that changes (`Modules/Core`, `Modules/AI`, root for docs), one PR per repo and per task group; submodule pointers are bumped by the owner.
- No vendor, model or hosting name in code, config defaults, docs or tests.
- Anything that costs a model call or changes stored data is off by default.
- Settings hold only choices (toggles, dropdowns); URLs and keys stay in config and env.
- Tests that declare classes use `Modules/{Module}/tests/Stubs/`. Pint from the root with an explicit file list. `php artisan test --compact <path>` on the narrowest paths; the full suite is the owner's.
- Retrieval behaviour changes are compared on/off with `test:ai:evaluate-application-content-retrieval-strategies` or `test:ai:evaluate-documentation`; the numbers go in the delivery status.

## Review Focus

1. **A date filter must never empty a result set silently.** No recognised date means no filter; an interval with no hit falls through the widening windows and then to no filter.
2. **The judge must not make things worse unnoticed.** Failure, timeout or an empty verdict falls back to the unjudged candidates; the stage is off by default.
3. **A profile change must not leave a stale floor or reference.** `minSimilarity` and the drift reference are per profile key.
4. **Index-changing tasks (unstemmed field, chunk context) must announce a rebuild** through the mapping comparator or the content hash, not apply half a mapping.
5. **Request-word removal must never produce an empty keyword query.**

---

### Task 1: Dates in the query become a filter (Core, AI)

**Files:**
- Create: `Modules/Core/app/Search/Services/DateExpressionResolver.php`
- Modify: `Modules/Core/app/Search/Services/SimpleQueryIntentParser.php`, `FallbackSearchPlanner.php`, `Modules/Core/app/Search/Traits/Searchable.php` (declared date field), the Elasticsearch and Database engines (apply the interval), `Modules/AI/app/Services/LlmQueryIntentParser.php` (asks for an interval)
- Test: `Modules/Core/tests/Unit/Search/DateExpressionResolverTest.php`, `SimpleQueryIntentParserTest.php`, `Modules/Core/tests/Feature/Search/QueryDateFilterTest.php`

**Interfaces:**
- Produces: `DateExpressionResolver::resolve(string $query, DateTimeImmutable $now, string $locale): ?array{from: string, to: string}` (ISO dates, inclusive); a model property `$searchDateField` (`?string`, default `null`) read through a public `Searchable::searchDateField(): ?string`; config `core.search.recency_windows_days` (default `[90, 365]`).

- [x] **Step 1: Failing tests**: `resolve` maps "of 2024" to the year, "March 2025" to the month, "last month" and "last year" relative to `$now`, and returns `null` for text with no date; both Italian and English patterns; `use explicit request dates over text dates`; `null date field means no filter`; `an interval with no hit widens through recency_windows_days and ends unfiltered`.
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement** the resolver (pattern tables per locale, no model call), feed `date_range` in `SimpleQueryIntentParser` and the planner, apply it as a range filter on `searchDateField()` in both engines; "recent" phrases use the windows in order and stop at the first with enough sources; `LlmQueryIntentParser` may supply the interval when rules found none.
- [x] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(core): dates in the query become a filter`.

### Task 2: Optional relevance judge (AI)

**Files:**
- Create: `Modules/AI/app/Ai/Rag/Judge/RelevanceJudge.php`, `JudgeVerdict.php`
- Modify: the documentation answer pipeline at its single retrieval-to-answer seam (confirm in step 1), `Modules/AI/database/seeders/AIDatabaseSeeder.php` (settings), `Modules/AI/config/config.php`
- Test: `Modules/AI/tests/Unit/Rag/RelevanceJudgeTest.php`, `Modules/AI/tests/Integration/DocumentationJudgeStageTest.php`

**Interfaces:**
- Produces: `RelevanceJudge::judge(string $question, array $candidates, int $round): JudgeVerdict`; `JudgeVerdict { list<int> $relevant; ?string $nextQuery }`; settings `features.rag.judge.enabled` (false), `.max_rounds` (3), `.min_sources` (3), `.excerpt_chars` (600).

- [x] **Step 1: Confirm the seam**: read where retrieved documents are handed to the answering agent, and the analytics record, and note the class and method here.
- [x] **Step 2: Failing tests**: `off by default leaves candidates untouched`; `keeps only the relevant ids`; `stops once min_sources are accepted`; `stops at max_rounds`; `a failure or timeout returns the unjudged candidates`; `a candidate is judged once`; `each round is logged with query, candidates and kept ids`.
- [x] **Step 3: Implement** with temperature 0 and a fixed JSON schema, excerpt bounded by `excerpt_chars`, next query "more precise" on the first round and "broader, with synonyms" after; the settings text carries the mid-size-model hint.
- [-] **Step 4: Compare** judge off and on with `test:ai:evaluate-documentation`; record the numbers for the delivery status. *Not run by the agent: it needs a live index and a model server. The owner runs it before enabling the judge; the setting is off by default.*
- [x] **Step 5: Run** the tests; expect pass. Pint. Commit `feat(ai): optional relevance judge`.

### Task 3: Embedding drift guard (AI)

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/EmbeddingDriftReference.php`
- Modify: `Modules/AI/app/Console/EmbeddingsProbeCommand.php`, `EmbeddingDimensionProbe.php` (shares the embed call)
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingDriftReferenceTest.php`, `Modules/AI/tests/Feature/EmbeddingsProbeCommandTest.php`

**Interfaces:**
- Produces: `EmbeddingDriftReference::save(EmbeddingModelProfile $profile, array $vectors): void`, `::compare(EmbeddingModelProfile $profile, array $vectors): float` (lowest cosine over the sample), stored as JSON under `storage/app/ai/embedding-references/{key}.json`; probe option `--reference=save|compare`; config `ai.features.embeddings.drift_floor` (default `0.999`).

- [x] **Step 1: Failing tests**: `save then compare of identical vectors is 1.0`; `compare fails below the floor and names the lowest text`; `compare without a saved reference is a clear error`; `references are per profile key`.
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement** a fixed sample of short multilingual texts shipped with the module; the probe embeds them through the profile's provider.
- [x] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(ai): embedding drift reference in the probe`.

### Task 4: Declared languages on profiles (AI)

**Files:**
- Modify: `Modules/AI/app/Ai/Embeddings/EmbeddingModelProfile.php`, `EmbeddingModelRegistry.php`, the settings page that lists profiles (confirm in step 1), `EmbeddingsProbeCommand.php`, `Modules/AI/config/config.php`
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingModelProfileLanguagesTest.php`, `EmbeddingModelRegistryTest.php`

**Interfaces:**
- Produces: `EmbeddingModelProfile::$languages` (`list<string>`, default `[]` meaning unknown; `['*']` any); `EmbeddingModelProfile::coversLanguages(array $locales): bool`; registry reads `languages` from each profile in `ai.features.embeddings.models`.

- [x] **Step 1: Confirm the seam**: find where the settings page renders profiles and where installation locales are listed.
- [x] **Step 2: Failing tests**: `unknown languages warn nothing`; `a profile declaring only some of the installation locales warns and names the missing ones`; `* covers everything`; `probe prints the warning and still succeeds`.
- [x] **Step 3: Implement** the attribute, the check, the warning in the settings page and in the probe; optional `--cross-language` flag embeds one sentence in two locales and reports their cosine.
- [x] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(ai): profiles declare their languages`.

### Task 5: Keyword branch cleanup and unstemmed sub-field (Core)

**Files:**
- Modify: `Modules/Core/app/Search/Services/SearchQueryAnalyzer.php` (request words), `Modules/Core/app/Search/Translators/ElasticsearchTranslator.php` (sub-field), `Modules/Core/app/Search/Engines/ElasticsearchEngine.php` (query uses both fields)
- Test: `Modules/Core/tests/Unit/Search/SearchQueryAnalyzerTest.php`, `ElasticsearchTranslatorTest.php`, `Modules/Core/tests/Feature/Search/UnstemmedFieldQueryTest.php`

**Interfaces:**
- Produces: `SearchQueryAnalyzer::analyze()` drops per-locale request words from the keyword branch only, config `core.search.request_words` (map of locale to list); each analysed text field gains `fields.exact` with an analyzer that does not stem.

- [x] **Step 1: Failing tests**: `request words are dropped from the keyword terms and kept in the vector query`; `a query made only of request words keeps its terms`; `the mapping of a localised text field has an exact sub-field`; `the text match queries the stemmed and the exact field together`; `a proper name matches through the exact field`.
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement**; the mapping difference is picked up by the mapping comparator, which reports the rebuild.
- [-] **Step 4: Compare** before and after with `test:ai:evaluate-application-content-retrieval-strategies`. *Not run by the agent: it needs a live index (rebuilt for the new sub-field) and a model server. The owner runs it after the rebuild.*
- [x] **Step 5: Run** the tests; expect pass. Pint. Commit `feat(core): request words and an unstemmed field in keyword search`.

### Task 6: Similarity floor per profile (Core, AI)

**Files:**
- Modify: `Modules/Core/app/Search/DTOs/VectorProfile.php` (adds `?float $minSimilarity`), the Elasticsearch and Database engines, `Modules/AI/app/Ai/Embeddings/EmbeddingModelProfile.php` (`minSimilarity`, `vectorProfile()`), `Modules/AI/app/Console/TuneRetrievalCommand.php` (recommends a value)
- Test: `Modules/Core/tests/Feature/Search/KnnSimilarityFloorTest.php`, `Modules/AI/tests/Unit/Embeddings/EmbeddingModelProfileTest.php`

**Interfaces:**
- Produces: `VectorProfile::$minSimilarity` (`?float`, default `null`: no floor); the Elasticsearch kNN clause carries `similarity` when set; the Database engine adds the matching distance condition.

- [x] **Step 1: Failing tests**: `no floor leaves the kNN clause unchanged`; `a floor is sent in the kNN clause`; `the database engine drops rows beyond the floor`; `the profile passes its floor through vectorProfile()`; `switching profile switches the floor`.
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement**; the tuning command prints the recommended floor next to its other output and never writes it.
- [x] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(core): optional similarity floor carried by the vector profile`.

### Task 7: Chunk context for application content (AI, Core)

**Files:**
- Modify: `Modules/AI/app/Services/EmbeddingService.php` (`chunksFor`: no flattening before the split, header on each chunk), `Modules/Core/app/Search/Traits/Searchable.php` (`$embedHeader` declaration), `Modules/AI/app/Services/ModelEmbeddingSynchronizer.php` (header in the content hash)
- Test: `Modules/AI/tests/Unit/Services/EmbeddingServiceChunkingTest.php`, `Modules/AI/tests/Feature/ModelEmbeddingSynchronizerHeaderTest.php`

**Interfaces:**
- Produces: model property `$embedHeader` (`?string` or a closure-returning method `embedHeader(): ?string`, confirm against `$embed` style in step 1); every chunk text is `header . "\n" . chunk`; the splitter receives the original line structure and whitespace is normalised after splitting.

- [x] **Step 1: Confirm the declaration style** by reading how `$embed` is declared and read.
- [x] **Step 2: Failing tests**: `without a header chunks are unchanged apart from structure`; `every chunk of a long text starts with the header`; `paragraph breaks survive until the split`; `changing the header changes the content hash, so the locale is re-embedded`.
- [x] **Step 3: Implement**; re-embedding stays hash-driven, so `ai:embeddings:repair` picks it up.
- [x] **Step 4: Compare** before and after with the application-content evaluation command.
- [x] **Step 5: Run** the tests; expect pass. Pint. Commit `feat(ai): chunk context header for application content`.

### Task 8: Near-duplicate images (AI)

**Files:**
- Create: `Modules/AI/app/Services/Media/ImageFingerprint.php`
- Modify: `Modules/AI/app/Jobs/AnalyzeMediaJob.php`, the media analysis model and its migration (create migration edited in place, pre-stable), `Modules/AI/config/config.php`
- Test: `Modules/AI/tests/Unit/Media/ImageFingerprintTest.php`, `Modules/AI/tests/Feature/AnalyzeMediaNearDuplicateTest.php`

**Interfaces:**
- Produces: `ImageFingerprint::of(string $imageBytes): string` (64-bit difference hash, hex) and `::distance(string $a, string $b): int`; setting `features.media_analysis.near_duplicate_distance` (`0` = off, default).

- [ ] **Step 1: Failing tests**: `the same image resized has distance within the threshold`; `different images exceed it`; `with distance 0 the job behaves as today`; `within the distance the earlier description is reused and no model call is made`.
- [ ] **Step 2: Run** them; expect failure.
- [ ] **Step 3: Implement**; the exact `content_hash` lookup stays first.
- [ ] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(ai): reuse the description of near-duplicate images`.

### Task 9: Tokens per role and data egress indicator (AI)

**Files:**
- Modify: `Modules/AI/app/Services/Documentation/Analytics/RagQueryRecorder.php` and the analytics index mapping command, the settings page that lists roles and servers (confirm in step 1)
- Test: `Modules/AI/tests/Unit/Analytics/RagQueryRecorderTokensTest.php`, `Modules/AI/tests/Feature/RoleEgressIndicatorTest.php`

**Interfaces:**
- Produces: analytics fields `tokens_in_{role}` and `tokens_out_{role}` (int, absent when the provider reports none); `AiServers`-based `RoleEgress::leavesInstallation(string $role): ?bool` (`null` when unknown).

- [ ] **Step 1: Confirm the seams**: where token usage is available on a response, and where the settings page names a role's provider.
- [ ] **Step 2: Failing tests**: `usage is recorded per role when the provider reports it`; `missing usage records nothing`; `a role served by a configured self-hosted server does not leave the installation`; `an external provider does`; `an unknown provider shows no claim`.
- [ ] **Step 3: Implement** and add the fields to the analytics index mapping (new index only; existing indexes keep working).
- [ ] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(ai): tokens per role in analytics and a data egress indicator`.

### Task 10: Documentation and closing

**Files:** the lists of spec section 6; `plans/INDEX.md` and `specs/INDEX.md` (one line each); the superseded or related notes only where a document names behaviour that changed.

- [ ] **Step 1: Module documents**, rewritten for the present state, including every new setting, config key and probe option, and the on/off comparison numbers.
- [ ] **Step 2: Indexes and closing.** When every box is ticked: `status: completed`, a `## Delivery status (date): ...` section with the comparison numbers and divergences, and a `**Documented in:**` line naming the module docs, as `ClosedPlansPointToDocumentationTest` requires.
- [ ] **Step 3: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` and `bash plan-status --open`. Commit `docs: AI retrieval improvements`.
