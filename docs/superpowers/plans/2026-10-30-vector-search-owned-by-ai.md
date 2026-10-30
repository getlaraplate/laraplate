---
created_on: 2026-10-30
module: core, ai, cms, sao
---
# Vector search owned by the AI module. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** One switch (`features.embeddings.enabled`) and one source (the AI module) for the model, dimensions and similarity of vector search; Core keeps no `search.vector.*` setting and runs keyword search when AI gives it no profile.

**Architecture:** Core defines `VectorProfile` and `IVectorProfileProvider` (default: `null`); `VectorProfileContext` (renamed from `VectorModelContext`) overrides the whole profile during a switch. AI binds `ActiveEmbeddingProfileProvider`, which reads `features.embeddings.enabled` and the new managed setting `features.embeddings.serving_model`. Core engines and services read the profile instead of `core.search.vector.*`. The five Core settings are deleted last.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. No new dependency.

**Spec:** `../specs/2026-10-30-vector-search-owned-by-ai-design.md` (decisions 1 to 8, sections 4 to 8).

## Global Constraints

- Branch `feat/vector-search-owned-by-ai` in each repo that changes (`Modules/Core`, `Modules/AI`, `Modules/CMS`, `Modules/SAO`, and the root for docs); one PR per repo; submodule pointers are bumped by the owner, never by an agent.
- No migration. The five Core settings are deleted from the database by hand (Task 5, owner-gated) and by the seeder no longer creating them.
- No `core.search.vector.*` read anywhere in `app/`, `database/` or `config/` when the plan ends; no default `384` or `'cosine'` left in Core.
- The profile vocabulary for similarity stays as today (`cosine` default, value passed through); translating it per engine is out of scope.
- Tests that declare classes use `Modules/{Module}/tests/Stubs/` or `tests/Support/`, never the test file. No test is deleted; existing ones move to the helper of Task 1.
- Run Pint from the root with an explicit file list. Run `php artisan test --compact <path>` on the narrowest paths; the full suite is the owner's. If `bootstrap/cache/config.php` exists the suite aborts: `config:clear` first.

## Review Focus

1. **A switch must never leave a window where search uses vectors mid-rebuild.** `suspended` is answered from the state while it is `running` or `failed`, and the state is written before the first re-embed (the old code set `suspended_reason` at the same points).
2. **Feature switched off after being on.** Indexing writes no vector field, search is keyword-only with no exception, an index that still has the mapping is left alone.
3. **`features.embeddings.serving_model` names a profile no longer in config.** The provider returns `null` and logs a warning; it never throws in every request.
4. **Cost.** `IVectorProfileProvider::current()` and the availability guard run per indexed document and per search: no database query in either (settings are read from runtime config).
5. **PostgreSQL with no profile.** `DatabaseEngine` throws a clear `InvalidArgumentException` naming the missing profile, not a SQL error.
6. **AI module absent.** An application without it indexes with no vector field and searches by keywords.

---

### Task 1: The contract and the context (Core)

**Files:**
- Create: `Modules/Core/app/Search/DTOs/VectorProfile.php`, `Modules/Core/app/Search/Contracts/IVectorProfileProvider.php`, `Modules/Core/app/Search/Services/NullVectorProfileProvider.php`, `Modules/Core/tests/Support/FakeVectorProfile.php`
- Move: `Modules/Core/app/Search/Support/VectorModelContext.php` to `VectorProfileContext.php`; its test `Modules/Core/tests/Unit/Search/VectorModelContextTest.php` to `VectorProfileContextTest.php`
- Modify: `Modules/Core/app/Providers/SearchServiceProvider.php`, `Modules/Core/app/Search/Traits/Searchable.php` (the `VectorModelContext` use only)

**Interfaces:**
- Produces: `final readonly class VectorProfile { __construct(public string $modelKey, public int $dimensions, public string $similarity) }`; `interface IVectorProfileProvider { public function current(): ?VectorProfile; }`; `VectorProfileContext::get(): ?VectorProfile`, `::modelKey(): ?string`, `::using(VectorProfile $profile, Closure $callback): mixed`; `FakeVectorProfile::use(?VectorProfile $profile = new VectorProfile('a:one', 384, 'cosine')): void` and `::none(): void` (bind a fixed provider instance in the container, and clear the context override).

- [x] **Step 1: Failing tests** in `VectorProfileContextTest`: `it returns null with the default binding` (container resolves `NullVectorProfileProvider`); `it returns the provider profile outside using`; `using overrides the whole profile (key, dimensions, similarity) and restores the previous one, also when the callback throws`; `modelKey returns the key of the profile or null`.
- [x] **Step 2: Run** `php artisan test --compact Modules/Core/tests/Unit/Search/VectorProfileContextTest.php`; expect failure.
- [x] **Step 3: Implement** the three classes and the rename (`git mv`); bind `singletonIf(IVectorProfileProvider::class, NullVectorProfileProvider::class)` in `SearchServiceProvider`; `FakeVectorProfile` binds `app()->instance(IVectorProfileProvider::class, ...)` with an anonymous-free fixed provider kept in `tests/Support/FixedVectorProfileProvider.php`.
- [x] **Step 4: Run** the test; expect pass. Commit `feat(core): vector profile contract and context`.

### Task 2: Core consumers read the profile (Core, CMS, SAO)

**Files:**
- Modify (Core): `app/Search/Traits/Searchable.php` (schema `embedding` field dimensions; private `vectorSearchEnabled()` becomes `VectorProfileContext::get() !== null`), `app/Search/Engines/ElasticsearchEngine.php`, `TypesenseEngine.php`, `DatabaseEngine.php`, `app/Search/Translators/ElasticsearchTranslator.php`, `app/Search/Services/VectorSearchAvailability.php`, `AdvancedSearchService.php` (`relaxedPlan()`), `FallbackSearchPlanner.php`
- Modify (CMS, SAO): `Modules/CMS/app/Models/Content.php` (around line 549), `Modules/SAO/app/Models/Ticket.php` (around line 432): the `vector` option of the embeddings field
- Test: the roughly twenty-seven Core tests and the CMS and SAO tests that set `core.search.vector.*` (list: `grep -rln "core.search.vector" Modules/*/tests tests`), each moved to `FakeVectorProfile::use()` / `::none()`

**Interfaces:**
- Consumes: `VectorProfileContext::get()` from Task 1.
- Produces: `VectorSearchAvailability::check()` answers `disabled` when the profile is `null`, `dimension_mismatch` when the index holds another length than `get()->dimensions`, `yes` otherwise (`suspended` is no longer Core's).

- [x] **Step 1: Failing tests**, one assertion set per call site (names are the existing test files' style):
  - `VectorSearchConfigTest`: Elasticsearch mapping has `dense_vector` with the profile's `dims` and `similarity`; with `::none()` there is no `embedding` property.
  - Typesense mapping: `embedding` `float[]` field present with a profile, absent without (Typesense needs no dimension; read 2026-10-30).
  - `ElasticsearchTranslator`: a nested `vector` field uses the option when given, else the profile; with neither it emits no `dense_vector`.
  - `PgvectorDatabaseEngineQueryTest`: the cast, operator and `model_key` literal come from the profile; with `::none()` it throws `InvalidArgumentException` whose message names the missing vector profile.
  - `FallbackSearchPlannerTest`: `hybrid` iff a profile exists and the query has no digits. `AdvancedSearchVectorGuardTest`: `relaxedPlan()` sets `use_vector` only with a profile and an embedder.
  - `VectorSearchAvailabilityTest`: `disabled`, `dimension_mismatch`, `yes`; no test sets a suspension any more.
  - `SearchableEmbeddingsArrayTest` / `SearchableEmbeddingsModelKeyTest`: the field dimensions and the stamped key come from the profile.
  - CMS `Content` and SAO `Ticket`: the field carries the profile's dimensions and similarity; with no profile it carries no `vector` option.
- [x] **Step 2: Run** the touched Core, CMS and SAO tests; expect failures.
- [x] **Step 3: Implement** each call site on `VectorProfileContext::get()`; remove every `384` and `'cosine'` default. `Searchable` keeps the `VectorProfileContext::using()` call it already makes around index documents.
- [x] **Step 4: Run** the same tests plus `Modules/Core/tests/Integration/Search`; expect pass. Pint on the changed files from the root. Commit per repo: `refactor(core): read the vector profile instead of core.search.vector`, same for CMS and SAO.

### Task 3: The AI provider, the registry and the seeder (AI)

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/ActiveEmbeddingProfileProvider.php`
- Modify: `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php` (`activeKey()`), `Modules/AI/app/Providers/AIServiceProvider.php`, `Modules/AI/database/seeders/AIDatabaseSeeder.php`, `Modules/AI/config/config.php` (comments that name the Core keys), `Modules/AI/app/Services/EmbeddingsGate.php` (docblock only)
- Test: `Modules/AI/tests/Unit/Embeddings/ActiveEmbeddingProfileProviderTest.php`, `EmbeddingModelRegistryTest.php`, `Modules/AI/tests/Feature/EmbeddingSettingsSeedTest.php`, `Modules/AI/tests/Unit/Services/EmbeddingsGateTest.php`, `Modules/AI/tests/Integration/HandleModelIndexingListenerTest.php`

**Interfaces:**
- Consumes: `IVectorProfileProvider`, `VectorProfile` (Task 1).
- Produces: `ActiveEmbeddingProfileProvider::current(): ?VectorProfile`; the managed setting `features.embeddings.serving_model` (config `ai.features.embeddings.serving_model`) read by `EmbeddingModelRegistry::activeKey()`, which still falls back to the first configured profile.

- [x] **Step 1: Failing tests**:
  - `ActiveEmbeddingProfileProviderTest`: `null when features.embeddings.enabled is off`; `null and one warning logged when the active key is not in config`; `returns VectorProfile(key, dimensions, similarity) of the active profile when on`; `runs no database query` (`DB::listen` records zero).
  - `EmbeddingModelRegistryTest`: `activeKey reads ai.features.embeddings.serving_model`, falls back to the first configured profile when it is empty.
  - `EmbeddingSettingsSeedTest`: `features.embeddings.serving_model` is seeded `managed`, default the stored serving model else the registry's first configured key; a second seed does not overwrite a stored value; `features.embeddings.model` defaults to it. `storedServingModel()` reads `features.embeddings.serving_model`.
  - `HandleModelIndexingListenerTest`: **with only `features.embeddings.enabled` on and a profile configured, an embeddable searchable model dispatches `GenerateEmbeddingsJob`** (the bug that started this).
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement.** The provider returns `null` unless `ai_config_bool('ai.features.embeddings.enabled')`, resolves `registry->get(registry->activeKey())`, catches `InvalidArgumentException` (log warning, `null`). `AIServiceProvider` binds it with `singleton(IVectorProfileProvider::class, ...)`, which overrides Core's `singletonIf`. The seeder adds `features.embeddings.serving_model` next to `features.embeddings.model` with `managed: true`.
- [x] **Step 4: Run** the tests; expect pass. Pint. Commit `feat(ai): AI answers the vector profile`.

### Task 4: The switch and the other AI readers (AI)

**Carry-over from Tasks 1 to 3:** `Searchable::isEmbeddable()` needs a profile, so `EmbeddingsGate::handles()` is false while `features.embeddings.enabled` is off unless a profile is pinned with `VectorProfileContext::using()`: the switch must pin the target profile when it discovers models, and repair does nothing with the feature off (accepted loss, spec 4.4). Tests still setting `core.search.vector.*` move to `ai.features.embeddings.enabled` and `serving_model`: `RepairEmbeddingsTest`, `EmbeddingSwitchStartTest`, `EmbeddingsProviderFactoryTest`, `HandleBulkModelIndexingListenerTest`, `EmbeddingPrefixTest`, `PrefixingEmbeddingsProviderTest`, `QueryEmbeddingCacheTest`; the five `EmbeddingSwitch*` tests and `EmbeddingPgvectorIndexHooksTest` fail on the `VectorModelContext` rename until `EmbeddingSwitchIndexes`, `EmbeddingSwitchVerifier` and `RecordingSwitchSearchEngine` use `VectorProfileContext`.

**Files:**
- Modify: `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchActivation.php`, `EmbeddingSwitchStore.php` (remove `suspend()`, `clearSuspension()`, `SUSPENDED_REASON_SETTING`; add `isSwitching(): bool` reading the runtime config copy of `features.embeddings.switch`), `EmbeddingSwitchIndexes.php`, `EmbeddingSwitchVerifier.php`, `Modules/AI/app/Console/EmbeddingsSwitchCommand.php` (calls at lines 241, 268, 373, 385), `Modules/AI/app/Services/EmbeddingVectorSearchAvailability.php`, `Modules/AI/app/Services/SearchOrchestratorAgent.php` (lines 70 and 124)
- Test: `EmbeddingSwitchStoreTest`, `EmbeddingSwitchPhasesTest`, `EmbeddingSwitchResumeTest`, `EmbeddingSwitchStartTest`, `EmbeddingSwitchChunkedIndexesTest`, `EmbeddingSwitchVerifierTest`, `EmbeddingVectorSearchAvailabilityTest`, `SearchOrchestratorAgentTest`, `tests/Stubs/EmbeddingSwitchHarness.php` and `RecordingSwitchSearchEngine.php` (all under `Modules/AI/tests`)

**Interfaces:**
- Consumes: `VectorProfileContext::using()` (Task 1), `features.embeddings.serving_model` (Task 3).

- [x] **Step 1: Failing tests**:
  - Activation writes `features.embeddings.serving_model` and `features.embeddings.model` to the target and writes nothing under `search.vector.*`; the previous model's rows are deleted as today.
  - `EmbeddingSwitchStore` has no suspension methods; **the state is `running` before the first re-embed job is dispatched** (order assertion in `EmbeddingSwitchPhasesTest`).
  - `EmbeddingVectorSearchAvailability`: `suspended` while the state is `running` or `failed`; asks the inner guard when `idle`; runs no database query.
  - Indexes are built inside `VectorProfileContext::using(new VectorProfile($target->key, $target->dimensions, $target->similarity), ...)`: the mapping carries the target's dimensions while the serving profile is still the old one; no config key is overridden.
  - Verifier: the smoke vector runs when `EmbeddingsGate::enabled()`; `SearchOrchestratorAgent`: `use_vector` is false when the provider returns `null`.
- [x] **Step 2: Run** them; expect failure.
- [x] **Step 3: Implement.** Read each caller of `suspend()` / `clearSuspension()` first and keep the order (state before work); `VectorSearchAvailability::forget()` stays in activation.
- [x] **Step 4: Run** `php artisan test --compact Modules/AI/tests/Feature Modules/AI/tests/Unit/Embeddings Modules/AI/tests/Unit/Services Modules/AI/tests/Integration`; expect pass. Pint. Commit `refactor(ai): the switch writes AI settings only`.

### Task 5: Remove the Core settings

**Files:**
- Modify: `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (the five `search.vector.*` rows), `Modules/Core/tests/Feature/Seeding/VectorSettingsSeedTest.php`, `Modules/Core/tests/Feature/Models/SettingManagedTest.php` (use a managed row the test creates, not a vector key), `Modules/Core/tests/Unit/Settings/ApplicationSettingDefinitionsTest.php`

- [x] **Step 1: Failing tests**: the Core definitions contain no `search.vector.*` name; `SettingManagedTest` and the seed tests pass without them.
- [x] **Step 2: Implement** the removal; then the gate: `grep -rnE "search\.vector\.(enabled|model|dimensions|similarity|suspended_reason)" Modules/*/app Modules/*/database Modules/*/config` prints nothing, and no `config('core.search.vector` remains in `tests/` of any module.
- [x] **Step 3: Run** the Core, AI, CMS and SAO suites that touch search (`Modules/*/tests/*/Search`), expect pass. Commit `chore(core): drop the search.vector settings`.
- [x] **Step 4 (owner-gated): delete the rows from the development database**, after the PRs are merged and the code is pulled, with the owner's go-ahead: `DELETE FROM core_settings WHERE name IN ('search.vector.enabled','search.vector.model','search.vector.dimensions','search.vector.similarity','search.vector.suspended_reason');`, then `php artisan db:seed` for the AI module (it seeds `features.embeddings.serving_model`) and flush the settings cache. *Done by the owner's `migrate:fresh` (2026-10-31), which recreates the settings from the seeders, so no manual delete is needed.*

### Task 6: Documentation, specs, plans and closing

**Files:** the lists of section 8 of the spec. Modify only what names the old keys.

- [ ] **Step 1: Module and root documents**, rewritten for the present state: AI `README.md`, `CHANGELOG.md`, `docs/DESIGN_DECISIONS.md`, `docs/SEARCH_AND_TRANSLATION.md`, `docs/rag/MODULE.md`, `docs/rag/MODERATION_AND_SEARCH.md`; Core `README.md`, `CHANGELOG.md`, `docs/EVENT_ORCHESTRATION.md`, `docs/rag/EVENT_ORCHESTRATION.md`, `docs/rag/MODULE.md`, `docs/rag/SEARCH_MATCHING_DEVELOPER.md`, `docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `docs/rag/SETTING_ACTIONS_DEVELOPER.md`; root `README.md`, `docs/ricerca-spiegazione-semplice.md`. Say that the AI switch is the single switch, name `features.embeddings.serving_model`, and give the upgrade note with the SQL of Task 5.
- [ ] **Step 2: Superseded notes** (one line each, pointing to the spec, no rewrite): specs `2026-10-05-embedding-model-switch-design.md` (decision 2, sections 4.2 and 4.5), `2026-09-12-es-multilingual-index-and-multimodel-embeddings-design.md`, `2026-10-22-ai-standard-servers-design.md`; plans `2026-10-05-embedding-model-switch.md`, `2026-10-06-embedding-switch-chunked-indexes.md`, `2026-09-12-es-multilingual-index-and-multimodel-embeddings.md`, `2026-10-06-ai-module-neuron-review.md`, `2026-10-07-ai-module-review-decisions.md`, `2026-10-21-palette-documentation-search.md`. Set the spec's status to `Implemented` with the divergences found.
- [ ] **Step 3: Indexes and closing.** Add this plan to `plans/INDEX.md` (name and what it covers, no status); when every box is ticked add `status: completed` to the front matter, a `## Delivery status (date): ...` section and a `**Documented in:**` line naming the module docs, as `ClosedPlansPointToDocumentationTest` requires.
- [ ] **Step 4: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` and `bash plan-status --open` from the stack root. Commit `docs: vector search owned by the AI module`.
