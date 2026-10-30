# ES Multilingual Index & Multi-Model Embeddings — Design

**Status:** Implemented 2026-09-15; see the delivery status of `docs/superpowers/plans/2026-09-12-es-multilingual-index-and-multimodel-embeddings.md`. Was: Draft (for review).
**Date:** 2026-09-12
**Author:** swolley + Claude
**Related:**
- R3 specs (currently at the stack root `docs/superpowers/specs/`, pending their own move): `2026-09-09-r3-retrieval-quality-baseline-design.md`, `2026-09-10-r3-phase2-per-strategy-breakdown-design.md`
- Memories: `es-index-multilingual-review`, `vector-search-knn-broken`, `rag-assistant-program`

## Goal

Refactor how Laraplate reads and writes multilingual content in Elasticsearch, and
make the embedding layer aware of *which* model produced each vector. Deliver a
single refactor split into two ordered parts:

1. **Index refactoring** — per-language text mapping (analyzers), locale-keyed field
   objects, indexing of all content regardless of default locale.
2. **Embeddings & multi-model** — per-translation embeddings searched by a single
   cross-lingual kNN, a model-profile registry, query/passage prefixes, and a model
   stamp on every stored vector.

Both parts share one clean reindex (cutover), so they ship together.

## Context: what exists today

Search is engine-abstracted. A model uses `Modules\Core\Search\Traits\Searchable`,
declares its ES shape via a `SchemaDefinition` of `FieldDefinition`s
(`FieldType` + `IndexType[]` + options), and `SchemaManager::translateForEngine`
renders it per engine (`ElasticsearchTranslator`). Vector search does **not** go
through Scout: `ElasticsearchEngine::performVectorSearch` builds a raw `knn` body and
sends it via the raw ES client, so we fully control the DSL.

Indexed models today:

| Model | Module | Multilingual | Embeddable today |
|-------|--------|--------------|------------------|
| `Content` | CMS | yes (`HasTranslatedDynamicContents` → `ContentTranslation`) | **yes** (only model with `$embed`) |
| `Ticket` | SAO | no | no |
| `Location` | CMS | no | no |

Taxonomy/people (`Contributor`, `Category`, `Tag`) are **not** separate indices — they
are embedded as sub-objects inside the `Content` document, so they are searchable
through `Content`.

### Problems this refactor fixes

- **P1 — No per-language analysis.** `title`, `title_it`, `title_en` are all
  `type: text` with the default/standard analyzer (no Italian/English stemming).
  Worse, `ElasticsearchEngine::createIndex` only pushes the `embedding` field mapping
  explicitly; every text field is left to ES **dynamic mapping**, so per-language
  analyzers cannot exist today even in principle.
- **P2 — Locale-coupled/flat text fields.** The primary `title` holds the
  *current-locale* translation at index time; per-locale data lives in flat suffixed
  keys `title_it` / `title_en`. Cross-lingual lexical recall is weak.
- **P3 — Mono-language content is excluded from the index.** `LocaleScope` (global
  scope on translated models) filters `Content::query()` to rows having the
  default-locale translation. `scout:import` uses that query, so English-only content
  (HackerNews / Spaceflight) is never indexed even though it is valid and embeddable.
- **P4 — One concatenated multilingual embedding per Content.**
  `Searchable::prepareDataToEmbed` glues *all* locales into one string, so the single
  stored vector is a blurry average of two languages and risks token truncation
  (`max_length: 512`).
- **P5 — Laraplate is model-agnostic.** It knows only a *provider*
  (`ai.features.embeddings.default_provider = sentence_transformers`) and a
  *dimension* (`search.vector.dimensions` / `search.vector_search.dimension`). It does
  **not** know the model name, does **not** apply `query:` / `passage:` prefixes, and
  `ModelEmbedding` does **not** record which model produced a vector. The real model
  (`all-MiniLM-L6-v2`) lives only in the external service.

## Scope

**In scope**

- `Content` (CMS): both parts fully (per-language analyzers + per-translation nested
  embeddings). Primary target.
- `Ticket` (SAO): gains embeddings as the **mono-lingual, single-vector exemplar** —
  proves the non-translated embeddable path end-to-end.
- Generic Core infrastructure: schema/translator, engine kNN, `ModelEmbedding`,
  embedding services, model-profile config.
- Switch active embedding model to `intfloat/multilingual-e5-small` (384-dim).

**Out of scope**

- `Location`: stays keyword-only (no long free text worth embedding).
- New indexed entities (ERP/MES, Contributor/Category standalone indices).
- Runtime coexistence of two models serving simultaneously (blue/green). The model
  stamp *enables* future clean re-embeds; we do a clean cutover now.
- Chunking strategy changes (current behavior preserved; nested shape naturally
  tolerates >1 vector per locale).

## Design decisions (locked)

1. **Locale-keyed objects for text; single cross-lingual vector space for embeddings.**
   The two retrieval modes have different access patterns, so they get different
   shapes on purpose:
   - Lexical search is language-specific (analyzer, stemming) and queried by named
     field → `title: { it: <text, analyzer italian>, en: <text, analyzer english> }`
     (an ES **object**, not `nested`).
   - Semantic search is cross-lingual by construction (e5 shared space) → a single
     kNN over all vectors, with language as an optional document-level filter.

2. **Per-translation embeddings stored as an ES `nested` array of *language-agnostic*
   vectors.** An ES `dense_vector` holds one vector; multiple clean per-language
   vectors per document require `nested`. Shape:
   `embeddings: [ { vector: <dense_vector> }, ... ]` — **no per-vector locale**. The
   vectors are semantically language-agnostic (e5 shared space), so the kNN does not
   need to know a vector's language. A single kNN targets `embeddings.vector`; ES
   returns each document scored by its best-matching entry. This is the ES-documented
   "multiple vectors per document" pattern.

3. **Language filtering is a document-level concern, not a per-vector one.** To
   restrict results to a language, filter the *document* by an
   `locales: [it, en]` keyword array (derived from the translations present), applied
   as a `knn.filter`. Filtering at the vector level would be wrong: a bilingual
   Content that best-matches via its English vector must still be returned for an
   "Italian only" search (it exists in Italian and is shown in Italian). So the vector
   entries stay agnostic and `locales` carries availability.

4. **Locale lives on the DB row for lifecycle only, never in ES.** `ModelEmbedding`
   carries a nullable `locale` used exclusively to **re-embed a single translation
   incrementally** (regenerate only the changed language). It is not sent to
   Elasticsearch. "Which translation produced a vector" for read/search purposes is
   answered by the `translations` relation and the document `locales` field, not by
   stamping the ES vector.

5. **Unified embedding shape for all embeddable models.** Both translated and
   non-translated models use the same nested `embeddings` field, so the engine has a
   **single** kNN code path. A mono-lingual model (Ticket) simply has one entry
   (its DB row has `locale = null`). No per-model branching in the query builder.

6. **Embeddings stay morphed to the searchable model (contract preserved).** The
   Core `IEmbeddableModel::embeddings()` morphMany points at the searchable model
   (Content/Ticket), and `DatabaseEngine` looks vectors up by
   `model_type = <searchable model>::class`. We keep this. The rejected alternative —
   a second polymorphic relation to the translation row (`translatable_type` +
   `translatable_id`) so locale comes "for free" — is *harder* to keep consistent, not
   easier: translation tables are per-model (no single `translations` table), Laravel
   morphs enforce no real DB foreign key, and it doubles the relations to keep in
   sync while risking the `DatabaseEngine` lookup. A single nullable `locale`
   discriminator delivers the same incremental capability with one column.

7. **Model-profile registry drives dimensions, prefixes, and the stamp.** One config
   registry of embedding models; the active profile is the single source of truth for
   ES `dense_vector.dims`, the pgvector column dimension, the `query:`/`passage:`
   prefixes, and the `model_key` stamped on each `ModelEmbedding`.

8. **The external service stays dumb.** Prefixes are applied Laraplate-side. Switching
   the model = changing the model string on the service. `/health` (already returns the
   loaded model name) becomes a cross-check.

9. **Clean cutover.** Dev corpus (~260 records). Reindex from scratch; no dual-vector
   coexistence. The `model_key` stamp makes future swaps clean.

---

## Part 1 — Index refactoring

### 1.1 Locale-keyed text objects with per-language analyzers

Replace the current `title` (locale-current) + flat `title_it` / `title_en` with a
single object field per translated text field:

```
title:  { it: {type: text, analyzer: italian}, en: {type: text, analyzer: english} }
slug:   { it: {type: keyword}, en: {type: keyword} }          # no analyzer needed
<component/body fields>: { it: {type: text, analyzer: italian}, en: {...english} }
```

`italian` and `english` are **built-in** ES analyzers, so no custom `settings` block
is required. (If custom stopwords are ever needed, a `settings` section is added then;
out of scope now.)

`Content::toSearchableArray` (`Modules/CMS/app/Models/Content.php:334`) changes from
emitting `title`, `title_it`, `title_en`, `slug_it`, `slug_en` to emitting nested
objects `title: {it, en}`, `slug: {it, en}`, and per-locale component objects, built
by iterating `LocaleContext::getAvailable()` over the loaded `translations`.

`Content::getSearchMapping` (`:395`) declares these as `FieldType::Object` fields whose
`options['properties']` carry per-locale sub-fields with an `analyzer` option.

### 1.2 Schema abstraction changes (Core)

The abstraction already supports most of this: `ElasticsearchTranslator` reads
`options['analyzer']` for `FieldType::Text` and handles `FieldType::Object`. Required
extensions:

- **Per-locale object sub-fields with analyzers.** Allow an `Object` field whose
  `properties` are themselves `FieldDefinition`-like text entries carrying an
  `analyzer`. Extend `translateField`/`translateNestedProperties` so an object's
  sub-field can specify `type: text` + `analyzer` (today nested property translation
  flattens options and drops analyzer).
- **A small helper** to expand a locale list into `{locale: {type, analyzer}}` sub-field
  maps, so `Content` (and any future translated model) declares
  `localeTextField('title')` rather than hand-writing the object.

`FieldType`/`IndexType` enums are sufficient as-is (`Object`, `Text`, `Keyword`,
`Vector`, plus `Searchable`/`Filterable`/`Fuzzy`/`Prefix`).

### 1.3 `createIndex` must push the full mapping

Today `ElasticsearchEngine::createIndex` (`Modules/Core/app/Search/Engines/ElasticsearchEngine.php:~129`)
explicitly applies **only** the `embedding` property and lets ES dynamically map the
rest. To get analyzers and the nested vector field, `createIndex` must apply the
**full** translated mapping (`getSearchMapping()` output) at index creation.

Because analyzers and `nested`/`dense_vector` typing are **not** mutable on an existing
field, index changes require **recreate from scratch** (`scout:delete-index` →
`scout:index` → `scout:import`) — which is the agreed cutover.

### 1.4 Remove `LocaleScope` from the indexing query

`scout:import` must index **all** `Content`, including mono-language (non-default)
rows. The indexing query drops the `LocaleScope` global scope
(`Modules/Core/app/Overrides/LocaleScope.php`) via
`withoutGlobalScope(LocaleScope::class)` on the Scout import query
(`makeAllSearchableUsing` / the searchable index query). `shouldBeSearchable` must not
depend on the presence of the default-locale translation. Runtime application behavior
(read paths, `forLocale`) is unchanged — the scope is stripped only for indexing.

### 1.5 Keyword / full-text over locale sub-fields

Lexical search targets the per-locale sub-fields so a query matches in whichever
language it is written, each analyzed by its own analyzer:

```
multi_match: { query: <q>, fields: ["title.it^2", "title.en^2", "<body>.it", "<body>.en", ...] }
```

Lands in `ElasticsearchEngine::buildTextMatchQuery` /
`TextMatchOptionsResolver`. The current single-`title` match is replaced by a
multi-field match across the locale sub-fields (boosts tunable later).

---

## Part 2 — Embeddings & multi-model

### 2.1 Model-profile registry (config)

Add an embedding-model registry as new keys **directly under the existing**
`ai.features.embeddings` block in `Modules/AI/config/config.php` (siblings of the
current `default_provider` / `retry_until_minutes`), derived by
`Modules/Core/config/search.php` for the vector dimension. Shape (the two new keys):

```php
// config('ai.features.embeddings.active')  and  config('ai.features.embeddings.models')
'active' => env('AI_EMBEDDINGS_MODEL', 'multilingual-e5-small'),
'models' => [
    'multilingual-e5-small' => [
        'provider'      => 'sentence_transformers',
        'service_model' => 'intfloat/multilingual-e5-small', // must match the service /health model
        'dimensions'    => 384,
        'query_prefix'  => 'query: ',
        'passage_prefix'=> 'passage: ',
        'normalize'     => true,
        'similarity'    => 'cosine',
    ],
    'all-MiniLM-L6-v2' => [
        'provider'      => 'sentence_transformers',
        'service_model' => 'all-MiniLM-L6-v2',
        'dimensions'    => 384,
        'query_prefix'  => '',
        'passage_prefix'=> '',
        'normalize'     => true,
        'similarity'    => 'cosine',
    ],
],
```

The existing `default_provider` stays (it selects the transport provider); the active
*model* is a new, orthogonal axis. The active profile's `provider` and the top-level
`default_provider` must agree; the registry is the authority for model-specific
attributes (dims, prefixes, similarity).

The active profile is the single source of truth for:
- ES `dense_vector.dims` (via `search.vector.dimensions` resolving to the active
  profile's `dimensions`).
- The pgvector column dimension in the `model_embeddings` migration.
- Prefixes and `model_key`.

Note: e5-small is 384-dim like MiniLM, so the pgvector `vector(384)` column and ES
`dims: 384` are unchanged — no dimension migration needed for this switch. A future
model with different dims will require a column migration; the profile makes that
explicit.

### 2.2 Query vs passage prefixes

e5 requires asymmetric prefixes. Laraplate already has two seams:
- **Query side:** `ITextEmbedder::embed` (via `SearchEmbedder` →
  `EmbeddingService::embedText`) → prepend the active profile's `query_prefix`.
- **Document side:** `IEmbeddingService::embedDocument` (used by
  `GenerateEmbeddingsJob`) → prepend `passage_prefix`.

Prefixes are applied in the Laraplate-side services, driven by the profile — the
NeuronAI `SentenceTransformersEmbeddingsProvider` stays transport-only and model-dumb.

### 2.3 Per-locale embedding generation

`Searchable::prepareDataToEmbed` (`Modules/Core/app/Search/Traits/Searchable.php:174`)
today returns one concatenated string. Introduce a per-locale form:

- `prepareDataToEmbedByLocale(?string $locale = null): array<string, string>` — map of
  `locale => text` for translated models (single entry keyed by the app default for
  non-translated models). When `$locale` is passed, the map is restricted to that one
  locale.
- `GenerateEmbeddingsJob` takes an optional `?string $locale`:
  - `null` (full re-embed): delete **all** rows for the model, recreate per locale.
    This is today's behavior generalized to per-locale rows.
  - set (incremental re-embed): delete only `where('locale', $locale)` rows, recreate
    only that locale.
  - Each created row is stamped `locale` + `model_key`; passages are prefixed with the
    active profile's `passage_prefix`.

Chunking (multiple `Document`s per text) is preserved and yields multiple rows for the
same locale — the nested ES shape tolerates this naturally.

**Incremental re-embed triggers (consistency).** The point of `locale` on the row is to
regenerate only the language that changed:

- On `ContentTranslation` save where an embeddable field changed → dispatch the embed
  job scoped to that translation's locale.
- On a new `ContentTranslation` → generate that locale.
- On `ContentTranslation` delete → delete that locale's rows (observer), then reindex
  the parent so the ES document and its `locales` field are refreshed.
- Structural changes on the parent model, a model swap, or `ai:embeddings:repair` →
  full re-embed (all locales) + `model_key` refresh.

After any of these, the parent searchable model is re-indexed so the ES `embeddings`
array and `locales` field reflect the current rows.

Optional convenience: a derived `embeddings()` relation on `ContentTranslation` that
reads `ModelEmbedding` by `(model = parent Content, locale = this.locale)` — built on
the `locale` column, no extra schema. Read-side ergonomics only.

### 2.4 `ModelEmbedding` schema

Add two columns to `model_embeddings`
(`Modules/Core/database/migrations/2024_11_05_233754_create_model_embeddings_table.php`
via a new migration; do not edit the original):

- `locale` (string, nullable) — the translation this vector represents; `null` for
  non-translated models.
- `model_key` (string) — the active model profile key that produced the vector.

`ModelEmbedding` (`Modules/Core/app/Models/ModelEmbedding.php`) adds both to
`$fillable`. A scope `producedBy(string $modelKey)` (and/or `forLocale`) supports clean
stale detection / re-embeds. `ai:embeddings:repair` can then target rows not matching
the active `model_key`.

### 2.5 ES agnostic nested embeddings + single kNN (engine)

`Content`/`Ticket` `toSearchableArray` emits **language-agnostic** vectors plus a
document-level `locales` array:

```json
"embeddings": [ {"vector": [ ... ]}, {"vector": [ ... ]} ],
"locales": ["it", "en"]
```

- `embeddings` is built by mapping the model's `ModelEmbedding` rows to
  `{vector: row.embedding}` — the row's `locale` is **not** carried into ES.
- `locales` is built from the `translations` relation (the languages the document
  exists in); for a non-translated model it is the document's single/effective
  language or omitted.

Mapping: `embeddings` is a `FieldType::Array` with a single nested `vector` sub-field of
`FieldType::Vector` (`dimensions` from the active profile). The translator already emits
`type: nested` for Array-with-properties; it needs to carry vector options
(`dims`, `similarity`) into the nested `vector` property. `locales` is a `Keyword`
(filterable) field.

`ElasticsearchEngine::performVectorSearch` (`:893`) changes:
- `resolveVectorField` → `embeddings.vector`.
- The `knn` block targets the nested vector field; ES handles nested kNN with a
  **single** search over all vectors regardless of language.
- Optional language filter is applied at the **document** level: when the caller
  requests a language, add `{terms: {locales: [<locale>]}}` to `knn.filter` — it
  restricts candidate documents to those available in that language without discarding
  cross-lingual vector matches.

The hybrid path (top-level `knn` + text `query`) is preserved.

### 2.6 Ticket — mono-lingual exemplar

`Ticket` (`Modules/SAO/app/Models/Ticket.php`) declares
`protected $embed = ['title', 'description'];`. Being non-translated, it produces one
`ModelEmbedding` row (`locale = null`) and one `embeddings` entry, exercising the same
generic pipeline and the same nested field with a single element. No analyzer/object
work (Ticket text fields stay single-language).

Data-handling note: tickets may contain sensitive text; generating embeddings sends
that text to the internal `:8000` service. Acceptable for the internal test
environment; recorded as a conscious choice.

### 2.7 External service change + `/health` cross-check

The service (`/opt/sentence-api.py`) switches its loaded model to
`intfloat/multilingual-e5-small`; `/health` reports it. Laraplate treats config as the
source of truth and cross-checks `/health.model` against the active profile's
`service_model` at reindex time (a warning on mismatch, e.g. in `ai:embeddings:repair`
or `scout:import` preflight). The service change lives on the server, not in this repo.

---

## Data flow

**Index time (`scout:import`, or per-model on write):**
1. Indexing query strips `LocaleScope` → all Content in scope.
2. Embeddings pre-processing: per locale, `passage_prefix` + text → service →
   `ModelEmbedding` rows stamped `{locale, model_key}`.
3. `toSearchableArray` builds locale-keyed text objects (`title.it/.en`, …) + the
   nested `embeddings` array.
4. `createIndex` applied the full mapping (analyzers + nested `dense_vector`) at index
   creation.

**Query time (`EnsembleSearchService`):**
1. Query embedded once with `query_prefix` (query side).
2. `keyword` leg: `multi_match` across `title.*` and body sub-fields.
3. `vector` leg: single kNN over the agnostic `embeddings.vector` (optional
   document-level `locales` filter via `knn.filter`).
4. `hybrid`: `knn` + text `query` combined; RRF fusion + reranker unchanged.

## Migration & cutover

1. Deploy code (Parts 1 + 2) with active profile = `multilingual-e5-small`.
2. Run new migration (adds `locale`, `model_key`; pgvector dims unchanged at 384).
3. Switch the service model to e5-small; verify `/health`.
4. Regenerate embeddings per locale for `Content` and `Ticket`
   (`ai:embeddings:repair` extended to (re)generate per locale + stamp).
5. `scout:delete-index` → `scout:index` (recreates full mapping) → `scout:import`
   (embeddings must be complete before import; see `vector-search-knn-broken` memory).
6. Re-run the R3 evaluation (`test:ai:evaluate-retrieval-strategies`) to confirm gains,
   especially `en_*` slices (now indexed) and lexical slices (now analyzer-aware).

## Testing strategy

- **Schema/translator (Core, unit):** locale-keyed object → per-language analyzer
  mapping; nested `embeddings` → `type: nested` with a `dense_vector` `vector`
  sub-field carrying `dims`/`similarity`.
- **`createIndex` (Core, ES-gated integration):** created index has the analyzers and
  the nested vector mapping (extends `ElasticsearchVectorIndexTest`).
- **kNN routing (Core, ES-gated):** paginated + `search()` vector query orders by
  similarity over the nested agnostic field; a document-level `locales` filter
  restricts results without dropping cross-lingual matches (extends the existing
  regression test).
- **LocaleScope exclusion (CMS, feature):** the indexing query includes mono-language
  content; runtime `Content::query()` still scoped.
- **Per-locale generation (AI, feature):** a bilingual model yields one stamped
  `ModelEmbedding` per locale; a mono-lingual model yields one `locale = null` row;
  prefixes applied (assert the outbound payload text). ES `embeddings` entries carry
  no locale; the document `locales` field lists the translations present.
- **Incremental re-embed (AI, feature):** changing one translation's embeddable text
  regenerates only that locale's rows (others untouched); deleting a translation
  removes only its rows and refreshes the parent document.
- **Model profile (AI/Core, unit):** active profile drives dims + prefixes +
  `model_key`; `/health` mismatch surfaces a warning.
- **Ticket embeddable (SAO, feature):** `Ticket` produces embeddings and is
  vector-searchable via the generic path.

Follow module test placement rules (module `tests/`, stubs in `tests/Stubs`). ES-gated
tests skip when ES is unreachable.

## Risks & open items

- **Full mapping rejected by ES — cutover blocker (found post-delivery 2026-09-16, RESOLVED 2026-09-17).**
  Pushing the full translated `Content` mapping failed with `mapper_parsing_exception`
  because the translator adds `meta` and `index: true` to `object`/`nested` relation
  fields (tags/contributors/categories/locations), which ES rejects on those types.
  Fixed by sanitising at index-creation time (`ElasticsearchEngine::sanitizeMappingProperty`)
  rather than in the translator, whose output the constraint layer reads back for nested
  filtering. Details and tests in the plan's "Pre-cutover blocker" section.
- **pgvector column is fixed-dimension.** Fine for the 384→384 switch; a future model
  with other dims needs a column migration. The profile makes dims explicit.
- **Nested kNN + hybrid scoring.** Confirm ES combines nested `knn` with a top-level
  text `query` as expected under our ES version (scout-driver-plus 5.1 /
  elastic-adapter 4.1, ES 8.x); validated in ES-gated tests before cutover.
- **Server normalization.** The current `/opt/sentence-api.py` ignores
  `normalize_embeddings`; ES `cosine` normalizes at query time, so this is tolerated,
  but the service should honor it when switched to e5 for DB-side (pgvector) parity.
- **Boost/field weights** for the multilingual `multi_match` are placeholders; tune
  after the first eval.

## Future (not now)

- Runtime dual-model coexistence (blue/green) using `model_key` filtering.
- Locale-aware reranking using `inner_hits`.
- Additional embeddable entities (ERP/MES) once the generic path is proven here.
