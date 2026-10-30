# Vector search owned by the AI module: design

**Status:** Proposed (2026-10-30), awaiting review. Plan: `docs/superpowers/plans/2026-10-30-vector-search-owned-by-ai.md`.
**Related:** `2026-10-05-embedding-model-switch-design.md` (decisions 2 and 4, sections 4.2 and 4.5 are superseded in part by this spec, see section 3), `2026-09-12-es-multilingual-index-and-multimodel-embeddings-design.md` (the profile registry and the `model_key` stamp), `2026-10-22-ai-standard-servers-design.md` (the servers the profiles point at).

## 1. Purpose

Turning on vector search needs two switches today, one in Core (`search.vector.enabled`) and one in AI (`features.embeddings.enabled`), and both must agree with three more Core keys (`model`, `dimensions`, `similarity`) that describe a model only the AI module knows. The operator turned one on and forgot the other more than once; with only the AI switch on, documents are not embedded at all, with no message saying why.

The goal is one switch and one place that knows. Search stays in Core: engines, query building, fusion, fallback. The AI module decides whether vectors are used and how they are produced, and tells Core through a contract. Core keeps no setting about vectors and works without the AI module as keyword search.

## 2. What exists today

Facts read from the code on 2026-10-30.

- Core settings `search.vector.enabled`, `.model`, `.dimensions`, `.similarity`, `.suspended_reason` (seeded by `CoreDatabaseSeeder`). AI writes the last four itself: `EmbeddingSwitchActivation` writes model, dimensions and similarity at the end of a switch, `EmbeddingSwitchStore` writes `suspended_reason` (`switching`) at the start and clears it at the end.
- AI settings `features.embeddings.enabled` (the pipeline: `EmbeddingsGate::enabled()`), `features.embeddings.model` (the target the operator chose), `features.embeddings.switch` (the state of a switch).
- Core contracts that already point the right way: `ITextEmbedder` (bound by AI to `SearchEmbedder`) and `IVectorSearchAvailability` (Core binds `VectorSearchAvailability`, AI decorates it with `EmbeddingVectorSearchAvailability`, which adds `no_vectors`).
- Core code reading the keys directly:
  - `Searchable` (the dimensions of the `embedding` schema field, default 384 hard-coded, and its private `vectorSearchEnabled()`);
  - `ElasticsearchEngine` (index mapping: enabled, dimensions with default 384, similarity with default `cosine`);
  - `TypesenseEngine` (enabled);
  - `ElasticsearchTranslator` (dimensions and similarity, defaults 384 and `cosine`);
  - `DatabaseEngine` (PostgreSQL: dimensions, similarity, model key);
  - `VectorSearchAvailability` (enabled, suspended, configured dimensions);
  - `AdvancedSearchService::relaxedPlan()` and `FallbackSearchPlanner` (enabled);
  - `VectorModelContext` (model key).
- AI code reading them: `SearchOrchestratorAgent` (enabled, twice), `EmbeddingSwitchVerifier` (enabled), `EmbeddingSwitchIndexes` (reads and temporarily overrides dimensions and similarity to build the target's indexes), `EmbeddingModelRegistry`.
- The profile (`EmbeddingModelProfile`) already holds `key`, `dimensions` and `similarity`; `ai:embeddings:probe` measures the real length and `ai:servers:check` prints it.
- `EmbeddingsGate::allows()` requires the AI switch, the module allowlist and a model that is `isEmbeddable()`, which in turn requires Core's `vectorSearchEnabled()`. With only the AI switch on, nothing is embedded.

## 3. Decisions

1. **Search stays in Core, the answer to "vectors, and with what" comes from the AI module.** Core asks a contract; it has no setting about vectors. Without the AI module the default answer is "none" and Core runs keyword search, as today with the flag off.
2. **One switch: `features.embeddings.enabled`** (AI). It means the feature is active, generation and search together. A state with vectors generated and search off, or the reverse, no longer exists. This supersedes decision 2 of the switch spec, which kept `core.search.vector.*` in Core and had AI write them.
3. **`features.embeddings.model` stays the operator's choice (the target).** The serving model is a second AI value, `features.embeddings.serving_model`, a command-managed setting written only by the switch at activation: seeded `managed`, shown read-only in the settings form, never edited by the operator. The name differs from the removed `features.embeddings.active` on purpose, so it does not read as a synonym of `features.embeddings.enabled` and cannot collide with a stale row of the old key. The switch spec removed a setting of that name (ruling R17) because it duplicated Core's `search.vector.model` and could drift; with Core's key gone there is one copy, and the reason for the ruling no longer applies. Decision 4 of the switch spec (the dropdown never changes the serving model) stands.
4. **Dimensions and similarity are properties of the profile, not settings.** They are a constraint of the model and the service (the metric a model was trained for; the length it produces, verified by the probe), and the engine fixes both in the index mapping. Chosen by picking a profile.
5. **No hard-coded fallback in Core.** The defaults 384 and `cosine` go away: without a profile Core does not build vector fields.
6. **The suspension of a switch is derived from its state**, not stored a second time. AI answers `suspended` while `features.embeddings.switch` is not idle. `search.vector.suspended_reason` goes away.
7. **Accepted loss:** generation off with search on. Nothing needs it in steady state. An outage of the embedding server is covered by `GenerateEmbeddingsJob` retries (`retry_until_minutes`) and by the hourly `ai:embeddings:repair --all --if-idle`. (`suspended_reason` never covered it: it stops the use of vectors in search, not their generation.)
8. **No migration.** The five Core settings are deleted from the database by hand and the seeder stops creating them (section 5).

## 4. Components

### 4.1 The contract (Core)

```php
final readonly class VectorProfile
{
    public function __construct(
        public string $modelKey,    // provider:model, the model_key stamped on every vector
        public int $dimensions,
        public string $similarity,  // cosine | l2 | ip
    ) {}
}

interface IVectorProfileProvider
{
    public function current(): ?VectorProfile;
}
```

`current()` is the profile whose vectors Core indexes and queries, or `null` when there are none to use. The default binding (`NullVectorProfileProvider`) returns `null`. Everything that read `core.search.vector.enabled` asks `current() !== null`; everything that read model, dimensions or similarity reads the profile.

`VectorModelContext` becomes `VectorProfileContext`: `get()` returns the override or `current()`, and `using(VectorProfile, Closure)` overrides the whole profile, not only the key. The switch builds the target's indexes inside it, so `EmbeddingSwitchIndexes` stops overriding the config keys.

`IVectorSearchAvailability` keeps its role (a guard per model: may this query use vectors) and the reasons `disabled` (`current()` is null), `dimension_mismatch` (index mapping against `current()->dimensions`). `suspended` and `no_vectors` are answered by the AI decorator. `ITextEmbedder` is unchanged.

### 4.2 The AI implementation

- `ActiveEmbeddingProfileProvider` (bound over the null one): returns the profile named by `features.embeddings.serving_model` when `features.embeddings.enabled` is on and the profile resolves, otherwise `null`. The profile comes from `EmbeddingModelRegistry`, which already builds it from `ai.features.embeddings.models`.
- `EmbeddingVectorSearchAvailability` answers `suspended` first while a switch is not idle, then asks the inner guard, then `no_vectors` as today.
- `EmbeddingsGate::enabled()` stays on `features.embeddings.enabled`; `isEmbeddable()` is true for a searchable model with a non-empty `$embed` list when the provider has a profile. Turning the switch on is enough to start embedding.
- Switch procedure (`EmbeddingSwitch*`): activation writes `features.embeddings.serving_model` only; the store no longer writes `suspended_reason`; the verifier and the index builder use `VectorProfileContext::using($targetProfile)`.
- `SearchOrchestratorAgent` asks the provider instead of the flag.

### 4.3 Core call sites

`Searchable` (schema field and `vectorSearchEnabled()`), `ElasticsearchEngine` and `ElasticsearchTranslator` (mapping dimensions and similarity), `TypesenseEngine`, `DatabaseEngine` (PostgreSQL cast, operator and `model_key` filter), `AdvancedSearchService::relaxedPlan()` and `FallbackSearchPlanner` read the profile through `VectorProfileContext::get()`. The plan's `use_vector` stays false when it is `null`.

### 4.4 Behaviour table

| `features.embeddings.enabled` | Before | After |
|---|---|---|
| off | vectors off if `search.vector.enabled` is off; if it is on, search expects vectors nobody generates | keyword only, nothing embedded |
| on | nothing embedded unless `search.vector.enabled` is also on | documents embedded, hybrid search available once the first vectors exist (`no_vectors` until then) |
| on, switch running | `suspended_reason = switching` | AI answers `suspended`, keyword only |

## 5. Data and migrations

No migration, by decision. On an existing database:

```sql
DELETE FROM core_settings WHERE name IN (
  'search.vector.enabled', 'search.vector.model', 'search.vector.dimensions',
  'search.vector.similarity', 'search.vector.suspended_reason');
```

then flush the settings cache. `features.embeddings.serving_model` is seeded by the AI seeder; an existing database receives it from the next seed or from the first switch (open point 2). `CoreDatabaseSeeder` stops creating the five keys. The `managed` column is untouched (`features.embeddings.switch` and the new `active` use it).

## 6. Error handling

A profile in `features.embeddings.serving_model` that no longer exists in config resolves to `null` and the log names the key: vector search is off instead of throwing in every engine. `dimension_mismatch` keeps its meaning: the index of an engine holds another length than the profile's.

## 7. Testing

- Core: an absent provider means no vector field in mappings and `use_vector` false; a provider with a profile builds the mapping with its dimensions and similarity; `VectorProfileContext::using()` overrides the whole profile and restores it, also on an exception.
- AI: the provider is `null` with the switch off, with the active key unknown, and returns the profile otherwise; `suspended` while a switch runs; the switch activation writes `features.embeddings.serving_model` and nothing in Core; with the feature on and no other setting, a model is embedded.
- The roughly forty existing tests that set `core.search.vector.*` (engines, planner, availability, CMS and SAO stubs) move to a Core test stub provider in `tests/Stubs/` registered by a helper on the test case; none is deleted.
- `EnvExampleCoversConfigTest` and the seed tests (`VectorSettingsSeedTest`, `EmbeddingSettingsSeedTest`, `SettingManagedTest`, `ApplicationSettingDefinitionsTest`) follow the seeders.

## 8. Documentation

The plan updates the following in the same work, per `AGENTS.md` (nothing ships documented only in a spec).

Module and root documents, rewritten for the present state:
- AI: `README.md`, `CHANGELOG.md`, `docs/DESIGN_DECISIONS.md`, `docs/SEARCH_AND_TRANSLATION.md`, `docs/rag/MODULE.md`, `docs/rag/MODERATION_AND_SEARCH.md`.
- Core: `README.md`, `docs/EVENT_ORCHESTRATION.md`, `docs/rag/EVENT_ORCHESTRATION.md`, `docs/rag/MODULE.md`, `docs/rag/SEARCH_MATCHING_DEVELOPER.md`, `docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `docs/rag/SETTING_ACTIONS_DEVELOPER.md`, `CHANGELOG.md`.
- Stack: `README.md`, `docs/ricerca-spiegazione-semplice.md`.

Specs and plans are dated decisions and stay true about their moment. They get a "superseded in part by this spec" note pointing here instead of a rewrite:
- Specs: `2026-10-05-embedding-model-switch-design.md` (decision 2, sections 4.2 and 4.5), `2026-09-12-es-multilingual-index-and-multimodel-embeddings-design.md`, `2026-10-22-ai-standard-servers-design.md` (the keys it names).
- Plans: `2026-10-05-embedding-model-switch.md`, `2026-10-06-embedding-switch-chunked-indexes.md`, `2026-09-12-es-multilingual-index-and-multimodel-embeddings.md`, and the three plans of 2026-10-06, 2026-10-07 and 2026-10-21 that name the keys.
- `docs/superpowers/specs/INDEX.md` and `plans/INDEX.md` get one line each for this spec and its plan.

## 9. Out of scope

Moving engines or query building to the AI module; pausing generation separately from search; a zero-gap switch; changing `ITextEmbedder`; the choice of the similarity by the operator.

## 10. Open points

1. **Shape of the contract.** A separate provider (this spec) against extending `IVectorSearchAvailability::check()` to return the profile. Chosen separate because indexing needs the profile with no model at hand and the guard needs a model; the plan can still merge them if the call sites turn out to be one.
2. **Seed of `features.embeddings.serving_model` on an existing database**, and how `features.embeddings.model` is seeded today (the plan reads it and mirrors it).
3. **Typesense.** Whether its `float[]` field needs the dimension; read in the plan.
4. **The settings page.** Whether the AI group shows the active profile's dimensions and similarity read-only. Nice to have, not required.
