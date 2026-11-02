---
status: completed
---
# Embedding Model Switch Implementation Plan

> **Superseded in part (2026-11-02):** The Core settings `search.vector.*`, `suspended_reason`, `VectorModelContext` and `features.embeddings.active` it names no longer exist. Superseded by `../specs/2026-10-30-vector-search-owned-by-ai-design.md`: the AI module owns the vector profile and Core keeps no `search.vector.*` setting. This plan stays true about its moment.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An operator changes the embedding model from a Settings dropdown, is told what it costs, and a guided procedure rebuilds vectors and indexes and activates the model only when verified, with vector search off meanwhile and a guard that disables it on any mismatch.

**Architecture:** The AI module owns the profile (which now declares its dimensions), the probe that verifies them, the switch procedure and its state. Core keeps reading its own `core.search.vector.*` settings and gains a vector-availability guard, command-managed settings and a generic setting-change confirmation hook; the AI module writes the Core settings at activation. PostgreSQL moves to a plain `vector` column with one partial index per profile.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Livewire 4, Pest, NeuronAI 3.17, nwidart modules. `Modules/Core` is the `laraplate-core` repository, `Modules/AI` is `laraplate-ai`, both submodules of `laraplate`.

**Spec:** `docs/superpowers/specs/2026-10-05-embedding-model-switch-design.md`

## Global Constraints

- During a switch vector search is off and search continues by keywords.
- A profile declares `dimensions`; the probe measures the length of a real vector; a mismatch rejects the profile (it cannot be a target or be activated).
- The dropdown records a target (`features.embeddings.model`); the serving model (`features.embeddings.active`) changes only at activation.
- A model is named `provider:model` (2026-09-29 convention, split on the first colon): setting values, profile keys, `search.vector.model` and `core_model_embeddings.model_key` are that one string. Rows stamped with the old alias keys are not migrated; they are stale and re-created (`ai:embeddings:repair --all --stale` or `migrate:fresh`).
- At activation the rows of the previous model are deleted; going back is a new switch that re-embeds everything.
- A change of model needs a full re-embed even when the dimensions are equal; only a change of dimensions also rebuilds the index mapping.
- Guard reasons, exact strings: `suspended`, `dimension_mismatch`, `disabled`, `no_vectors`; a search result carries `meta['vector_disabled']` with the reason.
- Setting names, exact: AI `features.embeddings.model`, `features.embeddings.active`, `features.embeddings.switch`; Core `search.vector.dimensions`, `search.vector.similarity`, `search.vector.suspended_reason`, `search.vector.model` (the last is a plan addition, see below).
- `AI_EMBEDDINGS_MODEL` and `AI_EMBEDDINGS_PROVIDER` are removed; defaults live in code (2026-09-29 spec).
- Project rules: `declare(strict_types=1)`, Pest, tests in the owning module's `tests/`, stubs in `tests/Stubs/` with PSR-4 namespaces, no new dependencies, code and docs in English, `vendor/bin/pint --format agent` on the touched files, narrowest tests with `php artisan test --compact`. Never run tests with a cached config. Schema changes to our own tables go into the create migration (pre-stable; `migrate:fresh` is fine).

**Decisions made while planning** (all now in the spec): a model is named `provider:model` everywhere, and the Core gets a managed setting `search.vector.model` with that value, because `DatabaseEngine` must filter by the active model and cannot read the AI registry; the PostgreSQL change is made in the create migration, not as a migration of existing installations; the confirmation is a generic Core hook (`ISettingChangeConfirmation`) that the AI module registers for its setting.

**Ruling R17 (2026-10-05, user, in chat):** remove `features.embeddings.active`, no duplicates. The setting held the same value as Core's `search.vector.model`, written by the same procedure at the same moment, so it was a duplicate that could drift. The AI reads the serving model from `config('core.search.vector.model')` (AI depends on Core, so the direction is allowed); `features.embeddings.model` (the target) and `features.embeddings.switch` stay. The fresh-install default stays Core's fixed `sentence_transformers:intfloat/multilingual-e5-small`; the AI seeder defaults `features.embeddings.model` to the stored Core value. The Global Constraints above still name `active` because Tasks 1-12 were built against them; Task 13 removed it.

## Review Focus

1. The target service is unreachable, reports another model, or measures other dimensions: the switch does not start and nothing changed. Task 8.
2. A model with equal dimensions but another vector space: everything is re-embedded, the index mapping is not rebuilt. Task 9.
3. A record created or edited while a switch runs is embedded with the target. Task 8.
4. A second switch while one runs is refused. Task 8.
5. A search while a switch runs returns keyword results with `meta['vector_disabled'] = 'suspended'` and no engine error. Task 5.
6. The worker dies mid-phase, or the activation write fails: `--resume` continues and repeating activation is harmless. Task 9.

## File Structure

| Area | Files |
|---|---|
| Profile and probe (AI) | `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php`, `EmbeddingsProviderFactory.php`, new `EmbeddingDimensionProbe.php`, `EmbeddingDimensionMismatch.php`; `Modules/AI/app/Console/EmbeddingsProbeCommand.php` |
| Switching (AI) | new `Modules/AI/app/Ai/Embeddings/Switching/` (`EmbeddingSwitchState`, `EmbeddingSwitchStore`, `EmbeddingSwitchPreview`, `EmbeddingSwitchOrchestrator`); `Modules/AI/app/Jobs/SwitchEmbeddingModelJob.php`; commands `EmbeddingsSwitchCommand`, `EmbeddingsStatusCommand`, `EmbeddingsPruneCommand` |
| Guard (Core, AI) | `Modules/Core/app/Search/Contracts/IVectorSearchAvailability.php`, `IReportsVectorDimensions.php`; `Modules/Core/app/Search/DTOs/VectorAvailability.php`; `Modules/Core/app/Search/Services/VectorSearchAvailability.php`; `Modules/AI/app/Services/EmbeddingVectorSearchAvailability.php` |
| Settings (Core) | `Modules/Core/app/Models/Setting.php`, `Filament/Resources/Settings/Schemas/SettingForm.php`, `Pages/EditSetting.php`, new `Modules/Core/app/Contracts/ISettingChangeConfirmation.php`, `Modules/Core/app/Services/SettingChangeConfirmations.php`, the create migration of `core_settings`, `CoreDatabaseSeeder.php` |
| PostgreSQL (Core) | `Modules/Core/database/migrations/2024_11_05_233754_create_model_embeddings_table.php`, `Modules/Core/app/Search/Engines/DatabaseEngine.php`, new `Modules/Core/app/Search/Support/PgvectorProfileIndex.php` |

---

### Task 1: Dimensions come from the profile, and the active profile can be overridden for a call

**Files:**
- Modify: `Modules/AI/config/config.php` (each profile is keyed by `provider:service_model`, loses its `provider` and `service_model` fields and gains `'dimensions' => 384`), `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php`, `Modules/AI/app/Ai/Embeddings/EmbeddingsProviderFactory.php`
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingModelRegistryTest.php`

**Interfaces:**
- Produces: `EmbeddingModelRegistry::keys(): list<string>`; `::withActive(string $key, Closure $callback): mixed` (while the closure runs, `active()` returns that profile, and the previous override is restored even if it throws); `get()` takes `dimensions` and `similarity` (default `'cosine'`) from the profile block and throws `InvalidArgumentException` when `dimensions` is missing or below 1. A profile key is `provider:service_model`; `EmbeddingModelProfile::$provider` and `$serviceModel` are the two parts split at the first colon; the two existing profiles become `sentence_transformers:intfloat/multilingual-e5-small` and `sentence_transformers:all-MiniLM-L6-v2`.
- Produces: `EmbeddingsProviderFactory::make(?string $provider = null)` and its per-request service model resolve the active profile through `EmbeddingModelRegistry`, not through `config('ai.features.embeddings.active')`; `make()` with no argument uses `active()->provider`.

- [x] **Step 1: Write the failing tests.** `it('takes dimensions and similarity from the profile, not from Core')` (set `core.search.vector.dimensions` to 999, expect the profile's 384); `it('rejects a profile with no dimensions')`; `it('lists the configured profile keys')`; `it('derives provider and service model from the key, splitting on the first colon')` (keys `sentence_transformers:intfloat/multilingual-e5-small` and `ollama:nomic-embed-text:latest`); `it('returns the overridden profile while a callback runs and restores the previous one after, also when it throws')`; `it('resolves the provider and service model of the overridden profile in the factory')` (override to `all-MiniLM-L6-v2`, expect its `service_model` in the request the sentence-transformers provider builds).
- [x] **Step 2: Run** `php artisan test --compact Modules/AI/tests/Unit/Embeddings/EmbeddingModelRegistryTest.php` and see them fail.
- [x] **Step 3: Implement** the signatures above. The override is a private nullable key on the registry, which is a singleton; read `activeServiceModel()` in the factory from `app(EmbeddingModelRegistry::class)->active()->serviceModel`.
- [x] **Step 4: Run** the new file and `Modules/AI/tests/Unit/Embeddings Modules/AI/tests/Integration/EmbeddingsProviderFactoryTest.php`; all pass. Pint the touched files.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): embedding profiles declare their dimensions and can be overridden for one call`.

### Task 2: The dimension probe and `ai:embeddings:probe`

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/EmbeddingDimensionProbe.php`, `Modules/AI/app/Ai/Embeddings/EmbeddingDimensionMismatch.php`, `Modules/AI/app/Console/EmbeddingsProbeCommand.php`
- Modify: `Modules/AI/app/Console/RepairMissingEmbeddingsCommand.php` (its `probeEmbedding()` uses the probe), `Modules/AI/app/Providers/AIServiceProvider.php` (register the command)
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingDimensionProbeTest.php`, `Modules/AI/tests/Feature/EmbeddingsProbeCommandTest.php`; keep `RepairMissingEmbeddingsCommandTest` passing

**Interfaces:**
- Consumes: Task 1 (`withActive`, factory).
- Produces: `EmbeddingDimensionProbe::measure(EmbeddingModelProfile $profile): int` embeds the fixed text `embedding service probe` with that profile's provider and service model and returns the vector length; `::verify(EmbeddingModelProfile $profile): int` returns it and throws `EmbeddingDimensionMismatch` (extends `RuntimeException`, message names profile, declared and measured) when it differs from `$profile->dimensions`. An empty or missing vector throws too.
- Produces: `php artisan ai:embeddings:probe {profile}` prints `profile, model, measured dimensions` and exits `FAILURE` with the mismatch message when declared and measured differ.

- [x] **Step 1: Write the failing tests.** Probe: returns the length of a fake provider's vector; throws `EmbeddingDimensionMismatch` for 768 against a declared 384; throws on an empty vector; uses the overridden profile's service model (assert on the faked HTTP request body). Command: prints the measured value; fails with the mismatch text; unknown profile fails.
- [x] **Step 2: Run** the two files and see them fail.
- [x] **Step 3: Implement** the probe over `withActive`, and the command; replace the inline length check in the repair command by `verify()` and keep its messages and the model-identity check.
- [x] **Step 4: Run** the two new files plus `Modules/AI/tests/Integration/RepairMissingEmbeddingsCommandTest.php` and `Modules/AI/tests/Feature/Console/RepairEmbeddingsTest.php`; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): measure a profile's vector length with a probe, and expose it as ai:embeddings:probe`.

### Task 3: Stored vectors are checked, and the embedding job takes a profile

**Files:**
- Modify: `Modules/AI/app/Services/ModelEmbeddingSynchronizer.php`, `Modules/AI/app/Jobs/GenerateEmbeddingsJob.php`
- Test: `Modules/AI/tests/Feature/GenerateEmbeddingsPerLocaleTest.php` (extend), new `Modules/AI/tests/Feature/GenerateEmbeddingsForProfileTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: `GenerateEmbeddingsJob::__construct(Model $model, ?string $locale = null, ?string $profile = null)`; with a profile, `handle()` runs the synchronizer inside `registry->withActive($profile, ...)`, so rows are stamped with that key. `ModelEmbeddingSynchronizer` throws `EmbeddingDimensionMismatch` instead of storing a vector whose length differs from the profile in use.

- [x] **Step 1: Write the failing tests.** Job with `profile: 'sentence_transformers:all-MiniLM-L6-v2'` while the active is `sentence_transformers:intfloat/multilingual-e5-small` stamps `model_key = 'sentence_transformers:all-MiniLM-L6-v2'` and leaves the active profile's rows untouched; without a profile it stamps the active one (existing behavior); a fake provider returning 5 floats for a 384 profile makes the sync throw and stores no row; a row whose `content_hash` and `model_key` already match is skipped.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement** the argument and the length check in `writePlan()`. Pass `$profile` through the job's serialization.
- [x] **Step 4: Run** the three embedding test files above and `Modules/AI/tests/Unit/Services/ModelEmbeddingSynchronizerTest.php` if present; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): embed with an explicit profile, and refuse to store a vector of the wrong length`.

### Task 4: Command-managed settings (Core)

**Files:**
- Modify: the create migration of `core_settings` (add `managed` boolean, default false), `Modules/Core/app/Models/Setting.php`, `Modules/Core/app/Filament/Resources/Settings/Schemas/SettingForm.php`, `Modules/Core/database/seeders/CoreDatabaseSeeder.php`
- Test: `Modules/Core/tests/Feature/Filament/ManagedSettingFormTest.php`, `Modules/Core/tests/Feature/Models/SettingManagedTest.php`, `Modules/Core/tests/Feature/Search/RerankerDefaultConfigTest.php`-style seed test `VectorSettingsSeedTest.php`

**Interfaces:**
- Produces: `Setting::$managed` (bool cast, not mass-assignable); `Setting::writeManaged(string $name, mixed $value): void` updates the row's value without creating a pending approval and refreshes the settings overlay, and throws `InvalidArgumentException` for a setting that is not managed. The form shows a managed setting's value read-only.
- Produces: seeded managed settings `search.vector.dimensions` (384), `search.vector.similarity` (`cosine`), `search.vector.model` (`sentence_transformers:intfloat/multilingual-e5-small`), and `search.vector.suspended_reason` (null, string), group `search`.

- [x] **Step 1: Write the failing tests.** `writeManaged` changes the value and `config('core.search.vector.dimensions')` follows after the overlay refresh; it throws for an unmanaged setting; it does not create a pending approval (copy how `RefreshAiModelsCommand` writes `choices` and assert the same); the edit form disables `value` for a managed row and not for an unmanaged one; the four settings are seeded with `managed = true`.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement** the column in the create migration, the cast and `writeManaged()`, the disabled field, and the seed definitions (extend `setting()` with a `managed` argument). Remove `search.vector.dimensions` and `search.vector.similarity` from the user-editable seed block they live in now.
- [x] **Step 4: Run** the three new files and `Modules/Core/tests/Feature/Filament`. All pass. Pint.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(settings): a setting can be managed by a command and read-only in the form`.

### Task 5: The vector availability guard (Core)

**Files:**
- Create: `Modules/Core/app/Search/Contracts/IVectorSearchAvailability.php`, `Modules/Core/app/Search/Contracts/IReportsVectorDimensions.php`, `Modules/Core/app/Search/DTOs/VectorAvailability.php`, `Modules/Core/app/Search/Services/VectorSearchAvailability.php`
- Modify: `Modules/Core/app/Search/Engines/ElasticsearchEngine.php`, `Modules/Core/app/Search/Services/AdvancedSearchService.php` (`resolveVector()` and the result meta), `Modules/Core/app/Providers/SearchServiceProvider.php` (`singletonIf`)
- Test: `Modules/Core/tests/Unit/Search/VectorSearchAvailabilityTest.php`, `Modules/Core/tests/Integration/Search/AdvancedSearchVectorGuardTest.php`, ES-gated `Modules/Core/tests/Integration/Search/ElasticsearchVectorDimensionsTest.php`

**Interfaces:**
- Produces: `VectorAvailability` (readonly: `bool $available`, `?string $reason`, `static yes(): self`, `static no(string $reason): self`); `IVectorSearchAvailability::check(Model $model): VectorAvailability`; `IReportsVectorDimensions::indexedVectorDimensions(Model $model): ?int` implemented by `ElasticsearchEngine` (reads `embeddings.properties.vector.dims` of the model's index mapping, null when the index or field is absent).
- Produces: `VectorSearchAvailability` answers `disabled` when `core.search.vector.enabled` is false, `suspended` when `core.search.vector.suspended_reason` is non-empty, `dimension_mismatch` when the engine reports dimensions different from `core.search.vector.dimensions` (cached 60 seconds per model class; skipped for an engine that does not implement the interface), else `yes()`. `AdvancedSearchService` asks it before embedding the query: when unavailable, the vector is `null` and the result's `meta['vector_disabled']` is the reason.

- [x] **Step 1: Write the failing tests.** Unit: one test per reason with config set accordingly, plus the cache (engine called once for two checks within the window) and the order (`disabled` before `suspended` before `dimension_mismatch`). Integration: with `suspended_reason` set and a plan asking for vectors, the search returns keyword hits, `meta['vector_disabled'] === 'suspended'`, the embedder is never called and no engine error is raised; with a dimension mismatch the same with `dimension_mismatch`. ES-gated: an index created with 384 reports 384, a missing index reports null.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement.** Read the mapping through the engine's existing client in the style of `checkIndexStructure()`.
- [x] **Step 4: Run** the unit and integration files and `Modules/Core/tests/Integration/Search`; all pass; the gated file is skipped without Elasticsearch. Pint.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(search): vector search is guarded: suspended, mismatching or disabled searches fall back to keywords with a reason`.

### Task 6: The embedding settings, the active profile from a setting, and the AI side of the guard

**Files:**
- Modify: `Modules/AI/app/Ai/Providers/Models/ProviderConfiguration.php` (`isConfigured()` learns `voyageai`: API key set, and `sentence_transformers`: URL set), `Modules/AI/database/seeders/AIDatabaseSeeder.php`, `Modules/AI/config/config.php` (remove the `AI_EMBEDDINGS_MODEL` and `AI_EMBEDDINGS_PROVIDER` env reads and the `active` and `default_provider` defaults), `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php`, `EmbeddingsProviderFactory.php`, `Modules/AI/app/Providers/AIServiceProvider.php`
- Create: `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchState.php`, `Switching/EmbeddingSwitchStore.php`, `Modules/AI/app/Services/EmbeddingVectorSearchAvailability.php`
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingSwitchStateTest.php`, `Modules/AI/tests/Feature/EmbeddingSettingsSeedTest.php`, `Modules/AI/tests/Unit/Services/EmbeddingVectorSearchAvailabilityTest.php`; adapt the tests that set `ai.features.embeddings.active` or `default_provider` (they keep working through the setting key `ai.features.embeddings.active`)

**Interfaces:**
- Produces: seeded AI settings, group `ai`: `features.embeddings.model` (string, a dropdown whose choices are the profile keys), `features.embeddings.active` (managed), `features.embeddings.switch` (managed, JSON string). Defaults in code: both profile settings default to the first configured profile key.
- Produces: `EmbeddingSwitchState` (readonly): `string $status` (`idle|running|failed`), `?string $phase` (`preflight|embeddings|indexes|verify|activate`), `?string $target`, `?string $previous`, `int $total`, `int $done`, `?string $error`, `?string $startedAt`; `toJson(): string`, `static fromJson(?string $json): self` (null or invalid gives `idle`). `EmbeddingSwitchStore::get(): EmbeddingSwitchState`, `::put(EmbeddingSwitchState $state): void` (through `Setting::writeManaged`), `::lock(): ?Closure` (atomic cache lock named `embeddings:switch`, returns a release closure, or null when held).
- Produces: `EmbeddingVectorSearchAvailability implements IVectorSearchAvailability` decorating Core's: delegates first, then answers `no_vectors` when no `core_model_embeddings` row stamped with the active key exists. Bound over Core's with `singleton`, as `IReranker` is.
- Consumes: Task 4 (`writeManaged`), Task 5 (the interface).

- [x] **Step 1: Write the failing tests.** State round-trips JSON and degrades to idle on null and on garbage; the store writes through the managed setting and the lock refuses a second holder and can be released; seeding creates the three settings with the right `managed` flags and choices equal to the profile keys; the registry reads `active` from `config('ai.features.embeddings.active')` fed by the setting; the AI guard returns `no_vectors` for an empty table and delegates otherwise; a profile whose provider has no key or URL is not among the choices.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement.** The AI settings seeder follows `runtimeSettingDefinitions()`; the choices of `features.embeddings.model` are written at seed time from `EmbeddingModelRegistry::keys()`, leaving out the profiles whose `provider` is not configured (`ProviderConfiguration::isConfigured()`), and the active profile is always among them.
- [x] **Step 4: Run** the new files and `Modules/AI/tests/Unit/Embeddings Modules/AI/tests/Integration/EmbeddingsProviderFactoryTest.php Modules/AI/tests/Integration/EmbeddingServiceTest.php`; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): the embedding model is a setting, with a managed active profile and switch state`.

### Task 7: The setting-change confirmation hook (Core)

**Files:**
- Create: `Modules/Core/app/Contracts/ISettingChangeConfirmation.php`, `Modules/Core/app/Data/SettingChangeWarning.php`, `Modules/Core/app/Services/SettingChangeConfirmations.php`
- Modify: `Modules/Core/app/Filament/Resources/Settings/Pages/EditSetting.php`, `Modules/Core/app/Filament/Resources/Settings/Schemas/SettingForm.php`, the Core service provider (bind the registry as a singleton)
- Test: `Modules/Core/tests/Feature/Filament/SettingChangeConfirmationTest.php`

**Interfaces:**
- Produces: `SettingChangeWarning` (readonly: `string $title`, `list<string> $lines`); `ISettingChangeConfirmation`: `supports(string $settingName): bool`, `warn(Setting $setting, mixed $newValue): ?SettingChangeWarning` (null means no confirmation needed), `confirmed(Setting $setting, mixed $newValue): void` (runs after the value is saved), `lockedReason(Setting $setting): ?string` (a non-null reason disables the field and shows the reason); `SettingChangeConfirmations::register(ISettingChangeConfirmation $confirmation): void` and `::for(string $settingName): ?ISettingChangeConfirmation`.

- [x] **Step 1: Write the failing tests** with a stub confirmation in `Modules/Core/tests/Stubs/`: saving a changed value of a supported setting does not save and opens a confirmation showing the title and lines; confirming saves and calls `confirmed()` once; cancelling saves nothing; an unchanged value saves without a confirmation; a setting with no registered confirmation saves as before; a non-null `lockedReason` disables the field and shows the reason.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement** the interception in `EditSetting` with Filament's confirmation modal (read the `filament-development` skill for the Filament 5 API before writing it).
- [x] **Step 4: Run** the new file and `Modules/Core/tests/Feature/Filament`; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(settings): a module can ask for a confirmation, or lock a setting, when its value changes`.

### Task 8: The alert, the switch start and its preflight

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchPreview.php`, `Switching/EmbeddingSwitchOrchestrator.php`, `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingModelSettingConfirmation.php`, `Modules/AI/app/Jobs/SwitchEmbeddingModelJob.php`, `Modules/AI/app/Console/EmbeddingsSwitchCommand.php`, `Modules/AI/app/Console/EmbeddingsStatusCommand.php`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php` (register the commands and the confirmation)
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingSwitchPreviewTest.php`, `Modules/AI/tests/Feature/EmbeddingSwitchStartTest.php`, `Modules/AI/tests/Feature/EmbeddingModelSettingConfirmationTest.php`

**Interfaces:**
- Consumes: Tasks 2, 3, 4, 6, 7.
- Produces: `EmbeddingSwitchPreview::for(EmbeddingModelProfile $target): SwitchPreview` with readonly `SwitchPreview` fields `currentModel`, `targetModel`, `currentDimensions`, `targetDimensions` (the declared one), `bool dimensionsDiffer`, `int recordsToEmbed` (embeddable searchable records times their translations, from `IEmbeddableModels`), `?int estimatedSeconds` (probe latency times batch count, null when it cannot be measured).
- Produces: `EmbeddingModelSettingConfirmation implements ISettingChangeConfirmation` for `features.embeddings.model`: `warn()` returns the title, the two models, the two dimensions, whether the change rebuilds the mapping or only re-embeds, the number of records, the estimate marked as such, that vector search is off meanwhile and that going back costs the same; null when the value equals the active profile; `confirmed()` calls `ai:embeddings:switch` queued; `lockedReason()` returns the phase text while the state is `running` or `failed`.
- Produces: `ai:embeddings:switch {profile}`: refuses when the lock is held, when the profile is unknown, equals the active one, or the probe cannot verify it, and in each case changes nothing; otherwise stores `running`/`preflight`, sets `search.vector.suspended_reason = 'switching'` and dispatches `SwitchEmbeddingModelJob`. `ai:embeddings:status` prints the state and counts.
- The preflight reuses the model-identity check of `ai:embeddings:repair` (`/health` and the model named by the probe answer): another model reported means the switch does not start.

- [x] **Step 1: Write the failing tests.** Preview: equal dimensions give `dimensionsDiffer = false`; the counts match seeded translated records. Start: an unreachable service, a service reporting another model, and a probe measuring other dimensions each leave state `idle`, `suspended_reason` empty and no job dispatched; a held lock refuses a second start; a valid start records state, sets `suspended_reason`, dispatches the job. Confirmation: not shown for the active profile, shows both dimensions and the record count for another, locks the field while running, cancel changes nothing, confirm starts the switch. Records created during the embeddings phase get the target key: dispatch a `GenerateEmbeddingsJob` for a new record while the state is `running` and expect `model_key` equal to the target.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement.** For the last test, `GenerateEmbeddingsJob` resolves its profile from the store when state is `running` and no profile was given.
- [x] **Step 4: Run** the three files; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): changing the embedding model asks for confirmation and starts a guarded switch`.

### Task 9: Embeddings, indexes, verification and activation

**Files:**
- Modify: `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchOrchestrator.php`, `Modules/AI/app/Jobs/SwitchEmbeddingModelJob.php`, `Modules/AI/app/Console/EmbeddingsSwitchCommand.php` (`--resume`, `--abandon`), `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php` and `Modules/AI/app/Console/CreateRagElasticsearchIndexCommand.php` (dimensions from the active profile instead of `faq.elasticsearch.embedding_dims`)
- Create: `Modules/AI/app/Console/EmbeddingsPruneCommand.php`
- Test: `Modules/AI/tests/Feature/EmbeddingSwitchPhasesTest.php`, `Modules/AI/tests/Feature/EmbeddingSwitchResumeTest.php`, `Modules/AI/tests/Feature/EmbeddingsPruneCommandTest.php`

**Interfaces:**
- Consumes: Task 8.
- Produces: `EmbeddingSwitchOrchestrator::advance(): EmbeddingSwitchState` runs the current phase and moves to the next, persisting state after each. Phases, in order: `embeddings` (dispatch `GenerateEmbeddingsJob` with the target for every embeddable record, record `total` and `done`, finish when every embeddable record has a row with the target `model_key` for each of its locales), `indexes` (run inside `VectorModelContext::using($targetKey, ...)` of Task 12, so the documents carry only the target's vectors; for each searchable embeddable model: when the dimensions differ recreate the index with the target dimensions through the engine's `createIndex(..., force: true)`, in every case import; recreate the RAG indexes with the target dimensions), `verify` (per index: document count equals the searchable record count, every record has a target-stamped row, the mapping reports the target dimensions, a smoke vector query of the embedded text `test` succeeds), `activate` (one step: `writeManaged` for `features.embeddings.active`, `search.vector.dimensions`, `search.vector.similarity`, `search.vector.model`, then clear `suspended_reason`, set state `idle`, invalidate the settings and guard caches, then delete the `core_model_embeddings` rows of the previous model and, on PostgreSQL, drop its partial index).
- Failure: the failing phase and error are stored, state `failed`, `active` unchanged, `suspended_reason` stays. `--resume` continues from the stored phase; repeating `activate` after a failed settings write is harmless (same values). `--abandon` runs the switch with `previous` as the target. `ai:embeddings:prune --model-key=<key>` deletes the `core_model_embeddings` rows of that key and refuses the active key; it is a manual cleanup for rows nobody uses, since activation already removes the previous model's.

- [x] **Step 1: Write the failing tests.** With fake provider and the database engine: a full switch from 384 to 384 (equal dimensions) re-embeds every record, does not call `createIndex` with force, verifies and activates, and afterwards only target-stamped rows remain; a switch to 768 recreates the index with 768 (assert on the engine call), then activates and `core.search.vector.dimensions` reads 768; verify fails when one record has no target row, stays in `failed/verify` with `active` unchanged, the previous model's rows still present and vector search still suspended; `--resume` after fixing it completes; an activation whose settings write throws once leaves state `activate` and `--resume` completes it; `--abandon` returns to the previous model; prune removes only the named key and refuses the active one.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement.** `SwitchEmbeddingModelJob` calls `advance()` and re-dispatches itself with a delay while the state is `running`, releasing the lock on idle or failed. Index recreation and import use the engine and `scout:import` programmatically, not by shelling out.
- [x] **Step 4: Run** the three files and `Modules/AI/tests/Feature/CreateRagElasticsearchIndex*` tests if present; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/AI`: `feat(ai): ai:embeddings:switch re-embeds, rebuilds the indexes, verifies and activates the new model`.

### Task 10: PostgreSQL with pgvector

**Files:**
- Modify: `Modules/Core/database/migrations/2024_11_05_233754_create_model_embeddings_table.php`, `Modules/Core/app/Search/Engines/DatabaseEngine.php` (the query near `embedding <=> ?::vector`)
- Create: `Modules/Core/app/Search/Support/PgvectorProfileIndex.php`
- Modify: `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchOrchestrator.php` (create the target's index in the `indexes` phase on a pgvector connection), `Modules/AI/app/Console/EmbeddingsPruneCommand.php` (drop the pruned key's index), and the activation step of the orchestrator (drop the previous key's index), `Modules/AI/app/Services/ModelEmbeddingSynchronizer.php` (ensure the index of the key once per process before the first write)
- Test: `Modules/Core/tests/Unit/Search/PgvectorProfileIndexTest.php` (SQL generation, runs everywhere), PostgreSQL-gated `Modules/Core/tests/Integration/Search/PgvectorDatabaseEngineTest.php`

**Interfaces:**
- Produces: the `embedding` column is created as plain `vector` (no dimension) on pgvector, with no index at migration time; `PgvectorProfileIndex::ensure(Connection $connection, string $modelKey, int $dimensions, string $similarity): void` creates `USING hnsw ((embedding::vector(N)) <ops>) WHERE model_key = '<key>'` named from the key (idempotent), `::drop(Connection $connection, string $modelKey): void`, and `::statements(string $table, string $modelKey, int $dimensions, string $similarity): list<string>` returns the SQL for tests. `DatabaseEngine` orders by `embedding::vector(N) <=> ?::vector` filtered by `model_key = core.search.vector.model`, N from `core.search.vector.dimensions`.

- [x] **Step 1: Write the failing tests.** Unit: the generated SQL has the cast, the ops class for each similarity (`cosine`, `l2`, `ip`), the partial `WHERE`, a name that differs per key and quotes a key with unusual characters; `drop` targets the same name. Gated: with pgvector, two models of different lengths in the table, the engine query returns only the active model's rows and uses the index (`EXPLAIN` mentions it).
- [x] **Step 2: Run** and see the unit file fail; the gated file is skipped without pgvector.
- [x] **Step 3: Implement** the three changes. Confirm the `Blueprint::vector()` call without a dimension in the Laravel version installed; if it does not accept `null`, create the column with a raw statement.
- [x] **Step 4: Run** the unit file and `Modules/Core/tests/Integration/Search`; pass. On a PostgreSQL with pgvector, run the gated file; if none is available, say so in the commit message and leave Open point 1 of the spec standing. Pint.
- [x] **Step 5: Commit** in `Modules/Core` and `Modules/AI`: `feat(search): PostgreSQL stores vectors of any length, with one partial index per embedding profile`.

### Task 11: Documentation, spec amendments and closing

**Files:**
- Modify: `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/README.md` (remove the two variables), `Modules/AI/docs/SEARCH_AND_TRANSLATION.md`, `docs/SENTENCE_TRANSFORMERS_INSTALLATION.md of the AI module (replaced on 2026-10-23 by STANDARD_SERVERS_INSTALLATION.md)`, `Modules/Core/README.md`, `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` (the guard and its reasons), `docs/superpowers/specs/2026-10-05-embedding-model-switch-design.md`, `docs/superpowers/specs/INDEX.md`, `docs/superpowers/plans/INDEX.md`, this plan
- Test: `tests/Unit/ClosedPlansPointToDocumentationTest.php`

- [x] **Step 1: Write** the user and developer pages: the settings, the confirmation, `ai:embeddings:probe|switch|status|prune`, the guard reasons and `meta.vector_disabled`, the PostgreSQL partial indexes, and how to add a profile (run the probe, declare the number).
- [x] **Step 2: Update the spec:** status becomes **Implemented** with the date, and any divergence found while building is recorded in it.
- [x] **Step 3: Close this plan:** tick every box, add a `## Delivery status (date): ...` section with a `**Documented in:**` line naming the pages above and the divergences, update both indexes.
- [x] **Step 4: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` and `bash plan-status` from the stack root; the plan shows complete.
- [x] **Step 5: Commit** in `laraplate`, then the submodule references in `laraplate` and the stack root: `docs: embedding model switch delivered; update the AI and Core references`.

### Task 12: Index documents carry the vectors of one model (Core)

**Added during execution** (review of Task 3): rows of two models coexist until activation, and `Searchable::toSearchableArray()` serializes every embedding row of a model, so a document would carry vectors of two lengths while a switch runs. **Execution order: right after Task 5; Task 9 consumes it.**

**Files:**
- Create: `Modules/Core/app/Search/Support/VectorModelContext.php`
- Modify: `Modules/Core/app/Search/Traits/Searchable.php` (the embeddings serialization, around lines 267-272)
- Test: `Modules/Core/tests/Unit/Search/VectorModelContextTest.php`, `Modules/Core/tests/Feature/Search/SearchableEmbeddingsModelKeyTest.php`

**Interfaces:**
- Consumes: `search.vector.model` (Task 4).
- Produces: `VectorModelContext::get(): ?string` (the override while inside `using`, else `config('core.search.vector.model')`, null when empty); `VectorModelContext::using(string $modelKey, Closure $callback): mixed` (restores the previous override, also when the callback throws). `Searchable::toSearchableArray()` includes only the embedding rows whose `model_key` equals `VectorModelContext::get()`; when that is null every row is included, as today.

- [x] **Step 1: Write the failing tests.** Context: returns the configured model; the override inside `using`; nested overrides; restores after a throw; null when nothing is configured. Searchable: a model with rows of two `model_key`s serializes only the configured one; inside `using($other, ...)` only the other; with no configured model and no override all rows are serialized.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement** the context as a small static holder and filter the rows in the trait.
- [x] **Step 4: Run** the two files and `Modules/Core/tests/Feature/Search Modules/Core/tests/Integration/Search`; all pass. Pint.
- [x] **Step 5: Commit** in `Modules/Core`: `feat(search): an index document carries the vectors of one embedding model`.

### Task 13: The serving model is Core's `search.vector.model` only (AI)

**Added during execution** (Ruling R17, confirmed by the user on 2026-10-05). It ran after Task 11, since Tasks 10 and 11 had run while R17 waited for confirmation; the planned order was right after Task 9. Task 9 was built as planned (activation wrote `active` together with the Core settings); this task removed the AI copy. Open point settled: the serving model of a fresh installation is Core's seeded default; the AI seeder reads the stored `search.vector.model` (the seed reconciler upserts without model events, so the runtime config does not hold it yet) and defaults `features.embeddings.model` to it, adding it to the choices when its provider is not configured. It does not write `search.vector.model`.

**Files:**
- Modify: `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php` (`activeKey()` reads `config('core.search.vector.model')`, keeping the fallback to the first configured profile, then the first declared one), `Modules/AI/database/seeders/AIDatabaseSeeder.php` (drop the `features.embeddings.active` definition), `Modules/AI/app/Ai/Embeddings/Switching/EmbeddingSwitchOrchestrator.php` (activation no longer writes `features.embeddings.active`), `Modules/AI/config/config.php` (the comment naming `active`), every docblock that names the setting.
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingModelRegistryTest.php`, `Modules/AI/tests/Feature/EmbeddingSettingsSeedTest.php`; replace `config()->set('ai.features.embeddings.active', ...)` with `core.search.vector.model` in every AI test that sets it (`rg "embeddings.active" Modules/AI/tests`), including the `for('features.embeddings.active')` assertion of `EmbeddingModelSettingConfirmationTest`.
- Docs: `Modules/AI/README.md`, `docs/SENTENCE_TRANSFORMERS_INSTALLATION.md of the AI module (replaced on 2026-10-23 by STANDARD_SERVERS_INSTALLATION.md)`, `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` (each line naming `ai.features.embeddings.active`).

**Interfaces:**
- Consumes: `search.vector.model` (Task 4), the activation phase (Task 9).
- Produces: no setting `features.embeddings.active`; `EmbeddingModelRegistry::activeKey()` returns `core.search.vector.model` when non-empty.

**Open point to settle in the task:** the Core seeds `search.vector.model` with a fixed `sentence_transformers:intfloat/multilingual-e5-small`, while the AI seeded `active` from the first *configured* profile. Decide how a fresh installation whose first configured profile differs gets a coherent serving model (for example the AI seeder writing `search.vector.model` through `Setting::writeManaged()` when the Core default is not a configured profile), record the choice in the delivery status, and cover it with a seed test. Existing rows of `features.embeddings.active` are not migrated (pre-stable, `migrate:fresh`).

- [x] **Step 1: Write the failing tests.** The registry returns `core.search.vector.model` when set and falls back to the first configured profile when empty; seeding no longer creates `features.embeddings.active`; a full switch leaves `search.vector.model` on the target and creates no `features.embeddings.active` row; the fresh-install case of the open point.
- [x] **Step 2: Run** and see them fail.
- [x] **Step 3: Implement** the changes above and update the tests that set the old key.
- [x] **Step 4: Run** `Modules/AI/tests/Unit/Embeddings`, `Modules/AI/tests/Feature/Embedding*`, the switch tests of Task 9 and every file touched by the key rename; all pass. Pint on the touched files.
- [x] **Step 5: Commit** in `Modules/AI`: `refactor(ai): the serving embedding model is Core's search.vector.model; drop features.embeddings.active`.

## Delivery status (2026-10-05): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/README.md`, `Modules/AI/docs/SEARCH_AND_TRANSLATION.md`, `Modules/AI/docs/STANDARD_SERVERS_INSTALLATION.md`, `Modules/Core/README.md`, `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `Modules/Core/docs/rag/SETTING_ACTIONS_DEVELOPER.md`, `Modules/Core/docs/rag/SETTING_ACTIONS_USER.md`.

Divergences from the plan, all recorded in the spec (sections 4, 10 and 11):
- Ruling R17 (user, in chat): `features.embeddings.active` was removed (Task 13); the serving model is Core's `search.vector.model` only. The write that the plan placed in the orchestrator lived in `EmbeddingSwitchActivation`. Existing rows of the removed setting are not deleted.
- The DTO lives in `app/Data`, not a new `app/DTOs` folder; settings and profile keys are `provider:model` strings.
- The final review added a dedicated `embeddings-switch` queue with its own Horizon supervisor, resumed a failed `indexes` or `verify` at `embeddings`, passed the profile's service model to hosted providers, and made `--abandon` check the return model. Not built, left as open points in the spec: abandoning while the `embeddings` supervisor is down, and starting a switch from a change that went through approval.

Not verified on a real server: every PostgreSQL branch (pgvector column, partial indexes, `vector_dims` guard, non-concurrent `CREATE INDEX` blocking writes) and the Elasticsearch branches (document count, `--force` RAG index, forced `createIndex`, bulk update, `flush`). The gated pgvector test has never run. Redis `retry_after` must be at least 1000 s for whoever runs a switch.
