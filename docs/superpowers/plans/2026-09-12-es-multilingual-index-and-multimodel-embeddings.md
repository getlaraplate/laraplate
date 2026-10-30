---
status: completed
verified_on: 2026-09-15
verified_by: repo audit (declared files + tests present)
---
# ES Multilingual Index & Multi-Model Embeddings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Refactor Elasticsearch multilingual indexing (per-language analyzers, locale-keyed text objects, index all content) and make embeddings per-translation, model-aware, and searched by a single cross-lingual kNN.

**Architecture:** Two ordered parts sharing one clean reindex. Part 1 reshapes the ES document/mapping via the existing Core schema abstraction (`SchemaDefinition`/`FieldDefinition`/`ElasticsearchTranslator`) and makes `createIndex` push the full mapping. Part 2 adds an embedding-model registry (config + resolver), applies `query:`/`passage:` prefixes, stamps `locale`+`model_key` on `ModelEmbedding`, generates one clean vector per translation, and stores them in ES as an agnostic nested `embeddings` array searched with one kNN.

**Tech Stack:** PHP 8.5, Laravel 12, nwidart modules, `babenkoivan/elastic-scout-driver-plus` 5.1 / `elastic-adapter` 4.1 (ES 8.x), pgvector, NeuronAI embeddings, Pest.

**Spec:** `docs/superpowers/specs/2026-09-12-es-multilingual-index-and-multimodel-embeddings-design.md`

## Global Constraints

- **Modules are git submodules.** `Modules/{Core,CMS,AI,SAO}` are independent submodules on `master`. Commit each module's changes **inside that submodule** on `master`. The spec/plan docs live in the **stack-root** repo. Never commit proprietary code into the AGPL backend.
- **PHP style:** `declare(strict_types=1);` in every file; braces on all control structures; explicit param + return types; constructor property promotion; `#[Override]` when overriding; prefer `final`/`readonly` where consistent with siblings; PHPDoc over inline comments; array-shape PHPDoc.
- **Config:** use `config()` outside config files, never `env()`. New/changed env vars documented in the affected module README.
- **Tests:** Pest; module tests in `Modules/{Module}/tests/`; never declare classes/traits/enums inside test files — put stubs in `Modules/{Module}/tests/Stubs/` with PSR-4 in that module's `composer.json` `autoload-dev`. ES-gated tests must `markTestSkipped` when the engine is not Elasticsearch or is unreachable (mirror `ElasticsearchVectorIndexTest`).
- **Formatting:** run `vendor/bin/pint --dirty --format agent` before finishing each task.
- **Core stays generic:** Core must gain no dependency on AI/CMS/SAO. The AI module reads Core config (allowed: AI→Core); Core must not read `ai.*` config.
- **No new dependencies, no new base folders** without approval.
- **RAG docs:** update the affected module README when behavior/env changes (Core search, AI embeddings).
- **Dimensions today:** e5-small and MiniLM are both 384-dim, so the pgvector `vector(384)` column and ES `dims: 384` do not change in this plan.

---

## File Structure

**Core** (`Modules/Core`)
- Modify: `app/Search/Schema/FieldDefinition.php` — add locale/analyzer + nested-vector option helpers (or keep options-driven; see Task 1).
- Modify: `app/Search/Translators/ElasticsearchTranslator.php` — object sub-fields with analyzers; nested array carrying a `dense_vector` sub-field with dims/similarity.
- Modify: `app/Search/Traits/CommonEngineFunctions.php` — `resolveVectorField` returns nested dotted path.
- Modify: `app/Search/Engines/ElasticsearchEngine.php` — `createIndex` pushes full mapping; `performVectorSearch` targets nested field + document `locales` filter.
- Modify: `config/search.php` — `vector.dimensions` + `vector.similarity` authority.
- Modify: `app/Search/Traits/Searchable.php` — `makeAllSearchableUsing` strips `LocaleScope`; `prepareDataToEmbedByLocale`; agnostic `embeddings` + `locales` in base `toSearchableArray`.
- Modify: `app/Models/ModelEmbedding.php` + new migration — `locale`, `model_key`.
- Test: `tests/Unit/Search/*`, `tests/Integration/Search/ElasticsearchVectorIndexTest.php`, `tests/Feature/*`.

**AI** (`Modules/AI`)
- Create: `config` keys under `ai.features.embeddings` (`active`, `models`).
- Create: `app/Ai/Embeddings/EmbeddingModelRegistry.php` + `EmbeddingModelProfile.php`.
- Modify: `app/Services/SearchEmbedder.php` — query prefix.
- Modify: `app/Services/EmbeddingService.php` — passage prefix on `embedDocument`.
- Modify: `app/Jobs/GenerateEmbeddingsJob.php` — optional `?string $locale`, per-locale generation, stamps.
- Modify: `app/Console/RepairMissingEmbeddingsCommand.php` — per-locale + `model_key` + `/health` cross-check.
- Test: `tests/Unit/*`, `tests/Feature/*`, `tests/Integration/*`.

**CMS** (`Modules/CMS`)
- Modify: `app/Models/Content.php` — locale-keyed objects, `locales`, agnostic embeddings mapping.
- Create: `app/Observers/ContentTranslationObserver.php` (or events) — incremental re-embed triggers.
- Test: `tests/Feature/*`.

**SAO** (`Modules/SAO`)
- Modify: `app/Models/Ticket.php` — `$embed`.
- Test: `tests/Feature/*`.

---

## Part 1 — Index refactoring

### Task 1: Schema translator — locale object sub-fields with analyzers + nested vector

**Files:**
- Modify: `Modules/Core/app/Search/Translators/ElasticsearchTranslator.php`
- Modify: `Modules/Core/config/search.php`
- Test: `Modules/Core/tests/Unit/Search/ElasticsearchTranslatorTest.php` (create if absent)

**Interfaces:**
- Consumes: `FieldDefinition($name, FieldType, IndexType[], $options)`, `FieldType::{Text,Keyword,Object,Array,Vector}`.
- Produces: translator emits, for `FieldType::Object` whose `options['locale_properties']` is a map `locale => ['analyzer' => string]`, an ES object with per-locale `text` sub-fields each with its `analyzer`; and for `FieldType::Array` whose `options['vector']` is set, an ES `nested` with a `vector` `dense_vector` sub-field (`dims`, `index:true`, `similarity`).

- [x] **Step 1: Add config authority for dims/similarity**

In `Modules/Core/config/search.php`, ensure these keys exist (keep the existing `vector_search.dimension` working as a read alias):

```php
'vector' => [
    'dimensions' => (int) env('VECTOR_DIMENSION', 384),
    'similarity' => env('VECTOR_SIMILARITY', 'cosine'),
],
```

- [x] **Step 2: Write failing translator test — locale object with analyzers**

```php
// Modules/Core/tests/Unit/Search/ElasticsearchTranslatorTest.php
use Modules\Core\Search\Schema\{SchemaDefinition, FieldDefinition, FieldType, IndexType};
use Modules\Core\Search\Translators\ElasticsearchTranslator;

it('maps a locale object field to per-language analyzers', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable], [
        'locale_properties' => [
            'it' => ['analyzer' => 'italian'],
            'en' => ['analyzer' => 'english'],
        ],
    ]));

    $mapping = (new ElasticsearchTranslator)->translate($schema);
    $title = $mapping['mappings']['properties']['title'];

    expect($title['type'])->toBe('object')
        ->and($title['properties']['it'])->toMatchArray(['type' => 'text', 'analyzer' => 'italian'])
        ->and($title['properties']['en'])->toMatchArray(['type' => 'text', 'analyzer' => 'english']);
});
```

- [x] **Step 3: Run it — expect FAIL**

Run: `php artisan test --compact Modules/Core/tests/Unit/Search/ElasticsearchTranslatorTest.php`
Expected: FAIL (locale_properties not handled).

- [x] **Step 4: Implement locale-object translation**

In `translateField`, before the generic `match`, handle the object-with-locale case:

```php
if ($field->type === FieldType::Object && is_array($field->options['locale_properties'] ?? null)) {
    $properties = [];
    foreach ($field->options['locale_properties'] as $locale => $definition) {
        $properties[(string) $locale] = [
            'type' => 'text',
            'analyzer' => (string) ($definition['analyzer'] ?? 'standard'),
        ];
    }

    return ['type' => 'object', 'properties' => $properties];
}
```

- [x] **Step 5: Run it — expect PASS**

Run: `php artisan test --compact Modules/Core/tests/Unit/Search/ElasticsearchTranslatorTest.php`

- [x] **Step 6: Write failing test — nested vector array**

```php
it('maps a vector-carrying array field to a nested dense_vector', function (): void {
    $schema = new SchemaDefinition('cms_contents');
    $schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable, IndexType::Vector], [
        'vector' => ['dimensions' => 384, 'similarity' => 'cosine'],
    ]));

    $mapping = (new ElasticsearchTranslator)->translate($schema);
    $field = $mapping['mappings']['properties']['embeddings'];

    expect($field['type'])->toBe('nested')
        ->and($field['properties']['vector'])->toMatchArray([
            'type' => 'dense_vector', 'dims' => 384, 'index' => true, 'similarity' => 'cosine',
        ]);
});
```

- [x] **Step 7: Run it — expect FAIL**

- [x] **Step 8: Implement nested-vector translation**

In `translateField`, handle the vector-array case:

```php
if ($field->type === FieldType::Array && is_array($field->options['vector'] ?? null)) {
    $vector = $field->options['vector'];

    return [
        'type' => 'nested',
        'properties' => [
            'vector' => [
                'type' => 'dense_vector',
                'dims' => (int) ($vector['dimensions'] ?? config('search.vector.dimensions', 384)),
                'index' => true,
                'similarity' => (string) ($vector['similarity'] ?? config('search.vector.similarity', 'cosine')),
            ],
        ],
    ];
}
```

- [x] **Step 9: Run it — expect PASS**

- [x] **Step 10: Run full translator test file + Pint + commit (Core submodule)**

```bash
php artisan test --compact Modules/Core/tests/Unit/Search/ElasticsearchTranslatorTest.php
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): translate locale-object analyzers and nested vector fields"
```

---

### Task 2: `resolveVectorField` returns the nested dotted path

**Files:**
- Modify: `Modules/Core/app/Search/Traits/CommonEngineFunctions.php`
- Test: `Modules/Core/tests/Unit/Search/ResolveVectorFieldTest.php` (create) using a stub model.

**Interfaces:**
- Produces: `resolveVectorField($model)` returns `"embeddings.vector"` when the schema's vector lives inside an Array field carrying `options['vector']`; unchanged (`embedding`) for a top-level `FieldType::Vector`.

- [x] **Step 1: Write failing test**

Use a stub model in `Modules/Core/tests/Stubs/Search/` whose `getSchemaDefinition()` declares an `embeddings` Array field with `options['vector']`. Assert `resolveVectorField` returns `'embeddings.vector'`. (Reuse `VectorMappingStubModel` if it already exposes a public `getSchemaDefinition`; otherwise add a dedicated stub `NestedVectorStubModel`.)

```php
expect($engine->resolveVectorField($model))->toBe('embeddings.vector');
```

- [x] **Step 2: Run — expect FAIL** (returns `'embeddings'` today).

- [x] **Step 3: Implement nested resolution**

In `resolveVectorField`, when scanning schema fields, detect the nested vector:

```php
foreach ($schema->getFields() as $field) {
    if (! $field instanceof FieldDefinition) {
        continue;
    }

    if ($field->type === FieldType::Vector || ($field->type !== FieldType::Array && $field->hasIndexType(IndexType::Vector))) {
        return $field->name;
    }

    if ($field->type === FieldType::Array && is_array($field->options['vector'] ?? null)) {
        return $field->name . '.vector';
    }
}
```

Apply the same nested awareness in `resolveVectorFieldFromMapping` (detect a `nested` property whose `properties.vector.type === 'dense_vector'` and return `"{name}.vector"`).

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): resolve nested embeddings.vector as the kNN field"
```

---

### Task 3: `createIndex` pushes the full mapping

**Files:**
- Modify: `Modules/Core/app/Search/Engines/ElasticsearchEngine.php` (`createIndex`, ~line 120-150)
- Test: `Modules/Core/tests/Integration/Search/ElasticsearchVectorIndexTest.php` (extend)

**Interfaces:**
- Consumes: `$model->getSearchMapping()` (full translated mapping).
- Produces: the created ES index carries the full `properties` (analyzers + nested vector), not only `embedding`.

- [x] **Step 1: Write failing ES-gated test**

Extend `ElasticsearchVectorIndexTest` with a case (skips without ES) that creates the index for a stub whose mapping has a `title` locale-object with `italian`/`english` analyzers and an `embeddings` nested vector, then asserts `getMapping` contains `title.properties.it.analyzer === 'italian'` and `embeddings.type === 'nested'` with a `dense_vector` `vector`.

- [x] **Step 2: Run — expect FAIL/SKIP**

Run: `php artisan test --compact Modules/Core/tests/Integration/Search/ElasticsearchVectorIndexTest.php`
Expected: FAIL when ES is up (only `embedding` is applied today); SKIP when ES down.

- [x] **Step 3: Implement full-mapping push**

Replace the "only embedding field" block in `createIndex` with the full properties:

```php
$properties = is_array($schema['mappings']['properties'] ?? null) ? $schema['mappings']['properties'] : [];

if ($properties !== []) {
    $mapped = [];
    foreach ($properties as $name => $definition) {
        $mapped[$name] = is_array($definition) ? $this->stringifyFieldMeta($definition) : $definition;
    }

    ElasticsearchService::getInstance()->createIndex(
        $collection,
        [],
        ['properties' => $mapped],
    );
}
```

Keep the existing settings/analyzer note: `italian`/`english` are built-in, so no `settings` block is required. Confirm `stringifyFieldMeta` recurses into nested `properties` (extend it if it only stringifies the top level).

- [x] **Step 4: Run — expect PASS (or SKIP without ES)**

- [x] **Step 5: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): apply the full field mapping on index creation"
```

---

### Task 4: Content document — locale-keyed text objects, `locales`, agnostic embeddings

**Files:**
- Modify: `Modules/CMS/app/Models/Content.php` (`toSearchableArray:334`, `getSearchMapping:395`)
- Test: `Modules/CMS/tests/Feature/Search/ContentSearchableArrayTest.php` (create)

**Interfaces:**
- Consumes: `LocaleContext::getAvailable()`, `findContentTranslation($locale)`, `ModelEmbedding` rows via `$this->embeddings`.
- Produces document keys: `title: {<locale>: string}`, `slug: {<locale>: string}`, per-locale component objects `<field>: {<locale>: value}`, `locales: string[]`, `embeddings: [{vector: float[]}]`. Removes flat `title_<locale>` / `slug_<locale>` and the locale-current `title`/`slug`.
- Produces mapping: `title`/component text fields as `FieldType::Object` with `options['locale_properties']` (analyzer per locale via `Task 5` analyzer map); `slug` object of keyword sub-fields; `embeddings` `FieldType::Array` with `options['vector']`; `locales` `FieldType::Keyword` filterable.

- [x] **Step 1: Write failing test for the document shape**

```php
// build a bilingual Content via factory with it + en translations
$doc = $content->toSearchableArray();

expect($doc['title'])->toMatchArray(['it' => $itTitle, 'en' => $enTitle])
    ->and($doc)->not->toHaveKey('title_it')
    ->and($doc['locales'])->toEqualCanonicalizing(['it', 'en'])
    ->and($doc['embeddings'])->each->toHaveKey('vector');
```

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement `toSearchableArray`**

Replace the flat-suffix block with locale objects, `locales`, and agnostic embeddings:

```php
$available = LocaleContext::getAvailable();
$title = [];
$slug = [];
$components = [];
$locales = [];

foreach ($available as $locale) {
    $translation = $this->findContentTranslation($locale);
    if (! $translation instanceof ContentTranslation) {
        continue;
    }
    $locales[] = $locale;
    $title[$locale] = $translation->title;
    $slug[$locale] = $translation->slug;
    foreach (($translation->components ?? []) as $field => $value) {
        $components[$field][$locale] = is_string($value)
            ? Str::replaceMatches('/\\n|\\r|\\t/', '', $value)
            : $value;
    }
}

$document['title'] = $title;
$document['slug'] = $slug;
$document['locales'] = $locales;
foreach ($components as $field => $byLocale) {
    $document[$field] = $byLocale;
}
```

The base `Searchable::toSearchableArray` provides agnostic `embeddings` (Task 11); Content does not set `embedding` itself anymore.

- [x] **Step 4: Implement `getSearchMapping`**

Replace per-locale flat `title_<locale>`/`slug_<locale>` `FieldDefinition`s with objects, and the `embedding` Vector with the `embeddings` Array + `locales` keyword:

```php
$analyzers = ['it' => 'italian', 'en' => 'english']; // Task 5 provides the shared map
$localeText = [];
$localeKeyword = [];
foreach ($available_locales as $locale) {
    $localeText[$locale] = ['analyzer' => $analyzers[$locale] ?? 'standard'];
    $localeKeyword[$locale] = ['analyzer' => 'keyword'];
}

$schema->addField(new FieldDefinition('title', FieldType::Object, [IndexType::Searchable, IndexType::Fuzzy], ['locale_properties' => $localeText]));
$schema->addField(new FieldDefinition('slug', FieldType::Object, [IndexType::Searchable], ['locale_properties' => $localeKeyword]));
$schema->addField(new FieldDefinition('locales', FieldType::Keyword, [IndexType::Searchable, IndexType::Filterable, IndexType::Facetable]));
$schema->addField(new FieldDefinition('embeddings', FieldType::Array, [IndexType::Searchable, IndexType::Vector], [
    'vector' => ['dimensions' => (int) config('search.vector.dimensions', 384), 'similarity' => config('search.vector.similarity', 'cosine')],
]));

foreach ($component_fields as $field) {
    $schema->addField(new FieldDefinition($field, FieldType::Object, [IndexType::Searchable, IndexType::FullText], ['locale_properties' => $localeText]));
}
```

Remove the old `title`, `title_<locale>`, `slug_<locale>`, and `embedding` field declarations.

- [x] **Step 5: Run — expect PASS**

Run: `php artisan test --compact Modules/CMS/tests/Feature/Search/ContentSearchableArrayTest.php`

- [x] **Step 6: Pint + commit (CMS)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/CMS && git add -A && git commit -m "feat(search): index Content as locale-keyed objects with locales and agnostic embeddings"
```

---

### Task 5: Shared locale→analyzer map

**Files:**
- Modify: `Modules/Core/config/search.php` — add `analyzers` map.
- Modify: `Modules/CMS/app/Models/Content.php` — read the map instead of the inline `['it'=>'italian','en'=>'english']`.
- Test: covered by Task 4's mapping assertions; add one assertion that an unknown locale falls back to `standard`.

**Interfaces:**
- Produces: `config('search.analyzers')` → `['it' => 'italian', 'en' => 'english']` with `standard` fallback.

- [x] **Step 1: Add config**

```php
// Modules/Core/config/search.php
'analyzers' => [
    'it' => env('SEARCH_ANALYZER_IT', 'italian'),
    'en' => env('SEARCH_ANALYZER_EN', 'english'),
],
```

- [x] **Step 2: Consume it in Content**

Replace the inline `$analyzers` in `getSearchMapping` with:

```php
$analyzerMap = is_array(config('search.analyzers')) ? config('search.analyzers') : [];
$analyzers = fn (string $locale): string => (string) ($analyzerMap[$locale] ?? 'standard');
// ... $localeText[$locale] = ['analyzer' => $analyzers($locale)];
```

- [x] **Step 3: Run Task 4 test + a fallback assertion — expect PASS**

- [x] **Step 4: Pint + commit (Core, then CMS)**

Commit the config in Core, the consumption in CMS (two submodule commits).

---

### Task 6: Index all content — strip `LocaleScope` from the import query

**Files:**
- Modify: `Modules/Core/app/Search/Traits/Searchable.php` — add `makeAllSearchableUsing`.
- Test: `Modules/CMS/tests/Feature/Search/ContentIndexingScopeTest.php` (create)

**Interfaces:**
- Produces: the Scout bulk-import query for a translated model excludes `LocaleScope` (indexes mono-language content); runtime `Content::query()` remains scoped.

- [x] **Step 1: Write failing feature test**

Create an English-only `Content` (no default-locale translation) + a bilingual one. Assert the import query returns both:

```php
$query = (new Content)->makeAllSearchableUsing(Content::query());
expect($query->pluck('id'))->toContain($englishOnly->id)
    ->and(Content::query()->pluck('id'))->not->toContain($englishOnly->id); // runtime still scoped
```

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement the override**

In `Searchable`, add (confirm the exact hook name/signature against the installed `laravel/scout`; it is `makeAllSearchableUsing`):

```php
/**
 * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
 * @return \Illuminate\Database\Eloquent\Builder<static>
 */
public function makeAllSearchableUsing($query)
{
    return $query->withoutGlobalScope(\Modules\Core\Overrides\LocaleScope::class);
}
```

`withoutGlobalScope` is a no-op for models without `LocaleScope`, so this is safe for `Ticket`/`Location`. Verify `shouldBeSearchable` does not independently exclude mono-language content; if it does, relax it.

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): index all translated content, dropping LocaleScope from the import query"
```

---

### Task 7: Keyword search over locale sub-fields

**Files:**
- Modify: `Modules/Core/app/Search/Engines/ElasticsearchEngine.php` (`buildFreeTextMatchQuery` / field selection) and/or `Modules/Core/app/Search/Services/TextMatchOptionsResolver.php`.
- Test: `Modules/Core/tests/Unit/Search/BuildTextMatchQueryTest.php` (create) asserting the produced DSL targets locale sub-fields.

**Interfaces:**
- Produces: free-text match uses `fields` including `title.*` (all locale sub-fields) and component sub-fields, instead of the single locale-current `title`.

- [x] **Step 1: Write failing test**

Assert `buildTextMatchQuery('festival', $options)` yields a `multi_match` whose `fields` include `title.*` (or `title.it`,`title.en`).

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement field targeting**

In `buildFreeTextMatchQuery`, when the model's searchable fields include locale objects, target `title.*` and the component object sub-fields. Prefer an explicit list built from the model's schema (locale sub-fields) with a boost on `title.*`:

```php
'multi_match' => [
    'query' => $free_query,
    'fields' => $options->fields !== [] ? $options->fields : ['title.*^2', '*'],
    'type' => 'best_fields',
],
```

Have `TextMatchOptionsResolver::forBuilder` populate `fields` from the model's schema locale sub-fields (so `Ticket`/`Location`, which have flat `title`, keep matching via `title`/`*`).

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): match free text across per-locale title/body sub-fields"
```

---

## Part 2 — Embeddings & multi-model

### Task 8: Embedding-model registry (config + resolver)

**Files:**
- Modify: `Modules/AI/config/config.php` — `ai.features.embeddings.{active,models}`.
- Create: `Modules/AI/app/Ai/Embeddings/EmbeddingModelProfile.php` (readonly value object).
- Create: `Modules/AI/app/Ai/Embeddings/EmbeddingModelRegistry.php`.
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingModelRegistryTest.php` (create).

**Interfaces:**
- Produces:
  - `EmbeddingModelProfile { key: string, provider: string, serviceModel: string, dimensions: int, queryPrefix: string, passagePrefix: string, similarity: string, normalize: bool }`.
  - `EmbeddingModelRegistry::active(): EmbeddingModelProfile` and `EmbeddingModelRegistry::get(string $key): EmbeddingModelProfile` (throws on unknown).
  - `dimensions` derives from `config('search.vector.dimensions')` (AI→Core) to avoid drift.

- [x] **Step 1: Add config keys** (siblings of `default_provider`)

```php
'active' => env('AI_EMBEDDINGS_MODEL', 'multilingual-e5-small'),
'models' => [
    'multilingual-e5-small' => [
        'provider' => 'sentence_transformers',
        'service_model' => 'intfloat/multilingual-e5-small',
        'query_prefix' => 'query: ',
        'passage_prefix' => 'passage: ',
        'normalize' => true,
    ],
    'all-MiniLM-L6-v2' => [
        'provider' => 'sentence_transformers',
        'service_model' => 'all-MiniLM-L6-v2',
        'query_prefix' => '',
        'passage_prefix' => '',
        'normalize' => true,
    ],
],
```

- [x] **Step 2: Write failing registry test**

```php
$profile = app(EmbeddingModelRegistry::class)->active();
expect($profile->key)->toBe('multilingual-e5-small')
    ->and($profile->queryPrefix)->toBe('query: ')
    ->and($profile->passagePrefix)->toBe('passage: ')
    ->and($profile->dimensions)->toBe((int) config('search.vector.dimensions'));

expect(fn () => app(EmbeddingModelRegistry::class)->get('nope'))
    ->toThrow(InvalidArgumentException::class);
```

- [x] **Step 3: Run — expect FAIL**

- [x] **Step 4: Implement the value object + registry**

`EmbeddingModelProfile` is a `final readonly` class with promoted constructor properties (types as above). `EmbeddingModelRegistry` reads `config('ai.features.embeddings.models')`, resolves the active key, and builds a profile with `dimensions: (int) config('search.vector.dimensions', 384)` and `similarity: (string) config('search.vector.similarity', 'cosine')`. Throw `InvalidArgumentException` for an unknown key. Register in `AIServiceProvider` as a singleton.

- [x] **Step 5: Run — expect PASS**

- [x] **Step 6: Pint + commit (AI)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/AI && git add -A && git commit -m "feat(embeddings): add embedding-model registry and active profile"
```

---

### Task 9: Query/passage prefixes

**Files:**
- Modify: `Modules/AI/app/Services/SearchEmbedder.php` — prepend `queryPrefix`.
- Modify: `Modules/AI/app/Services/EmbeddingService.php` — prepend `passagePrefix` in `embedDocument`.
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingPrefixTest.php` (create) with a fake provider capturing the text.

**Interfaces:**
- Consumes: `EmbeddingModelRegistry::active()`.
- Produces: query text sent as `"{queryPrefix}{text}"`; document text sent as `"{passagePrefix}{content}"`.

- [x] **Step 1: Write failing test (query side)**

Bind a fake `EmbeddingsProviderInterface` that records the last text; assert `SearchEmbedder::embed('festival')` sends `'query: festival'` when the active profile is e5.

- [x] **Step 2: Write failing test (document side)**

Assert `EmbeddingService::embedDocument('body')` sends chunks prefixed `'passage: '`.

- [x] **Step 3: Run — expect FAIL**

- [x] **Step 4: Implement**

`SearchEmbedder::embed`:

```php
public function embed(string $text): array
{
    $profile = app(EmbeddingModelRegistry::class)->active();

    return $this->embeddingService->embedText($profile->queryPrefix . $text);
}
```

`EmbeddingService::embedDocument`: after building `$content`, prepend the passage prefix before splitting/embedding:

```php
$content = app(EmbeddingModelRegistry::class)->active()->passagePrefix . $content;
```

(Keep `EmbeddingService` free of AI-provider coupling; the registry is an AI service.) Confirm the prefix is applied per chunk if the splitter would otherwise strip it — if chunking splits the body, apply the prefix to each chunk's text instead of once up front.

- [x] **Step 5: Run — expect PASS**

- [x] **Step 6: Pint + commit (AI)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/AI && git add -A && git commit -m "feat(embeddings): apply query/passage prefixes from the active model profile"
```

---

### Task 10: `ModelEmbedding` schema — `locale` + `model_key`

**Files:**
- Modify (clean migration): `Modules/Core/database/migrations/2024_11_05_233754_create_model_embeddings_table.php` — add `locale`, `model_key`, composite index **in the original create migration** (dev uses `migrate:fresh`). ALREADY DONE in this plan's prep commit; verify it is present.
- Modify: `Modules/Core/app/Models/ModelEmbedding.php`
- Test: `Modules/Core/tests/Feature/Models/ModelEmbeddingTest.php` (create)

**Interfaces:**
- Produces: `ModelEmbedding` columns `locale` (string, nullable), `model_key` (string, nullable for legacy, set on write); `$fillable` includes both; scopes `producedBy(string $modelKey)` and `forLocale(?string $locale)`.
- Table: `core_model_embeddings` (pgsql). For an already-migrated live table, the columns are added by hand (see the SQL below) instead of a new migration.

- [x] **Step 1: Write failing model test**

```php
$e = ModelEmbedding::factory()->create(['locale' => 'it', 'model_key' => 'multilingual-e5-small']);
expect(ModelEmbedding::query()->producedBy('multilingual-e5-small')->forLocale('it')->count())->toBe(1);
```

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Confirm the columns exist in the original migration**

The columns are added directly to the original create migration
(`2024_11_05_233754_create_model_embeddings_table.php`): `locale` (nullable string),
`model_key` (nullable string), and index `core_model_embeddings_model_locale_IDX` on
`(model_type, model_id, locale)`. For a live table already migrated, apply by hand:

```sql
ALTER TABLE core_model_embeddings
  ADD COLUMN locale varchar(255) NULL,
  ADD COLUMN model_key varchar(255) NULL;

CREATE INDEX core_model_embeddings_model_locale_IDX
  ON core_model_embeddings (model_type, model_id, locale);
```

- [x] **Step 4: Add fillable + scopes to `ModelEmbedding`**

```php
protected $fillable = ['embedding', 'locale', 'model_key'];

#[Scope]
protected function producedBy(Builder $query, string $modelKey): Builder
{
    return $query->where('model_key', $modelKey);
}

#[Scope]
protected function forLocale(Builder $query, ?string $locale): Builder
{
    return $locale === null ? $query->whereNull('locale') : $query->where('locale', $locale);
}
```

- [x] **Step 5: Run migration + test — expect PASS**

Run: `php artisan migrate --no-interaction && php artisan test --compact Modules/Core/tests/Feature/Models/ModelEmbeddingTest.php`

- [x] **Step 6: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(embeddings): stamp locale and model_key on model embeddings"
```

---

### Task 11: Per-locale generation + agnostic ES embeddings

**Files:**
- Modify: `Modules/Core/app/Search/Traits/Searchable.php` — `prepareDataToEmbedByLocale`, agnostic `embeddings` in base `toSearchableArray`.
- Modify: `Modules/AI/app/Jobs/GenerateEmbeddingsJob.php` — optional `?string $locale`, per-locale rows, stamps.
- Test: `Modules/AI/tests/Feature/GenerateEmbeddingsPerLocaleTest.php` (create).

**Interfaces:**
- Consumes: `EmbeddingModelRegistry::active()->key` for `model_key`.
- Produces:
  - `Searchable::prepareDataToEmbedByLocale(?string $locale = null): array<string,string>` (locale => text; single default-keyed entry for non-translated models).
  - Base `toSearchableArray` sets `embeddings` = `$this->embeddings->map(fn ($e) => ['vector' => $e->embedding])->all()` (no locale in ES).
  - `GenerateEmbeddingsJob(model, ?string $locale = null)`: `$locale === null` → delete all + regenerate every locale; else delete `forLocale($locale)` + regenerate that locale. Each row stamped `locale` + `model_key`.

- [x] **Step 1: Write failing test — full generation stamps per locale**

Bilingual model → 2 rows (one `it`, one `en`), each `model_key = active`. Non-translated stub → 1 row `locale = null`.

- [x] **Step 2: Write failing test — incremental**

Run job with `locale: 'it'` after both exist → only `it` rows replaced; `en` row untouched (assert `updated_at`/id stability of the en row).

- [x] **Step 3: Run — expect FAIL**

- [x] **Step 4: Implement `prepareDataToEmbedByLocale`**

```php
public function prepareDataToEmbedByLocale(?string $locale = null): array
{
    if (! isset($this->embed) || $this->embed === []) {
        return [];
    }

    if (! class_uses_trait($this, HasTranslations::class)) {
        $text = $this->collectEmbedText(fn (string $attr) => $this->{$attr});

        return $text === '' ? [] : [(string) (config('app.locale') ?: 'en') => $text];
    }

    $result = [];
    $locales = $locale !== null ? [$locale] : LocaleContext::getAvailable();
    foreach ($locales as $loc) {
        $translation = $this->getTranslation($loc);
        if (! $translation) {
            continue;
        }
        $text = $this->collectEmbedText(fn (string $attr) => $translation->{$attr} ?? null);
        if ($text !== '') {
            $result[$loc] = $text;
        }
    }

    return $result;
}
```

Extract the current concatenation loop into a private `collectEmbedText(callable $get): string` reused here and by the existing `prepareDataToEmbed` (keep `prepareDataToEmbed` working for callers that still want the concatenated form, or reimplement it as `implode(' ', prepareDataToEmbedByLocale())`).

- [x] **Step 5: Implement agnostic `embeddings` in base `toSearchableArray`**

Replace the current single-embedding block:

```php
if ($this->vectorSearchEnabled() && $engine instanceof ISearchEngine && $engine->supportsVectorSearch() && method_exists($this, 'embeddings')) {
    $vectors = $this->embeddings()->get()
        ->map(static fn ($e): array => ['vector' => $e->embedding])
        ->values()->all();

    if ($vectors !== []) {
        $array['embeddings'] = $vectors;
    }
}
```

- [x] **Step 6: Implement per-locale job**

```php
public function __construct(private readonly Model $model, private readonly ?string $locale = null) { $this->onQueue('embeddings'); }

// in handle():
$byLocale = $model->prepareDataToEmbedByLocale($this->locale);
if ($byLocale === []) { return; }

$modelKey = app(EmbeddingModelRegistry::class)->active()->key;

if ($this->locale === null) {
    $model->embeddings()->delete();
} else {
    $model->embeddings()->forLocale($this->locale)->delete();
}

foreach ($byLocale as $loc => $text) {
    $documents = $embedding_service->embedDocument($text);
    foreach ($documents as $document) {
        $model->embeddings()->create([
            'embedding' => $document->embedding,
            'locale' => $loc === (config('app.locale') ?: 'en') && ! class_uses_trait($model, HasTranslations::class) ? null : $loc,
            'model_key' => $modelKey,
        ]);
    }
}

event(new ModelPreProcessingCompleted($model, 'embeddings'));
```

(For non-translated models the single key maps to `locale = null`.)

- [x] **Step 7: Run — expect PASS**

Run: `php artisan test --compact Modules/AI/tests/Feature/GenerateEmbeddingsPerLocaleTest.php`

- [x] **Step 8: Pint + commit (Core, then AI)**

Two submodule commits: Core (trait), AI (job).

---

### Task 12: Incremental re-embed triggers on `ContentTranslation`

**Files:**
- Create: `Modules/CMS/app/Observers/ContentTranslationObserver.php`
- Modify: `Modules/CMS/app/Providers/*ServiceProvider.php` (register observer)
- Test: `Modules/CMS/tests/Feature/Search/IncrementalReembedTest.php` (create)

**Interfaces:**
- Consumes: `GenerateEmbeddingsJob(model, ?locale)`.
- Produces: on translation `saved` with a changed embeddable field → dispatch `GenerateEmbeddingsJob($content, $translation->locale)`; on `deleted` → delete that locale's rows and re-index the parent.

- [x] **Step 1: Write failing feature test**

Update only the `it` translation's title → only `it` embedding regenerated; the parent Content is re-indexed (assert via a fake engine/spy or that `embeddings()` for `it` changed and `en` did not).

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement observer**

```php
public function saved(ContentTranslation $t): void
{
    $embedFields = $t->content?->getEmbedFields() ?? []; // expose $embed via a public accessor
    if (array_intersect(array_keys($t->getChanges()), $embedFields) === [] && ! $t->wasRecentlyCreated) {
        return;
    }
    GenerateEmbeddingsJob::dispatch($t->content, $t->locale);
}

public function deleted(ContentTranslation $t): void
{
    $t->content?->embeddings()->forLocale($t->locale)->delete();
    $t->content?->searchable();
}
```

Add a public `getEmbedFields(): array` accessor to the model (Core `Searchable` or `IEmbeddableModel`) returning `$this->embed`. Register the observer in the CMS service provider.

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (Core for accessor, then CMS for observer)**

---

### Task 13: Vector search over the nested field + document `locales` filter

**Files:**
- Modify: `Modules/Core/app/Search/Engines/ElasticsearchEngine.php` (`performVectorSearch:893`)
- Test: `Modules/Core/tests/Integration/Search/ElasticsearchVectorIndexTest.php` (extend)

**Interfaces:**
- Consumes: `resolveVectorField` → `embeddings.vector` (Task 2).
- Produces: `knn.field = embeddings.vector`; an optional `{terms: {locales: [...]}}` added to `knn.filter` when the builder carries a `locales`/locale filter.

- [x] **Step 1: Write failing ES-gated test**

Index two docs with nested `embeddings` (one near, one far); assert the paginated vector search orders by similarity over the nested field. Add a second doc set to assert a `locales` filter restricts results to available languages.

- [x] **Step 2: Run — expect FAIL/SKIP**

- [x] **Step 3: Implement**

`performVectorSearch` already calls `resolveVectorField($model)`; with Task 2 it returns `embeddings.vector`, so the existing `knn.field` line needs no change. Add the document-level language filter:

```php
$requestedLocales = $this->extractLocaleFilter($builder); // from $builder->wheres['locales'] or a dedicated where
if ($requestedLocales !== []) {
    $query['knn']['filter']['bool']['must'][] = ['terms' => ['locales' => $requestedLocales]];
}
```

Confirm nested kNN returns document-level hits (ES handles the nested vector automatically for the top-level `knn` option). Keep the hybrid `query` branch intact.

- [x] **Step 4: Run — expect PASS (or SKIP without ES)**

- [x] **Step 5: Pint + commit (Core)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/Core && git add -A && git commit -m "feat(search): kNN over nested embeddings with document-level locale filter"
```

---

### Task 14: Ticket embeddable (mono-lingual exemplar)

**Files:**
- Modify: `Modules/SAO/app/Models/Ticket.php` — `$embed`.
- Test: `Modules/SAO/tests/Feature/TicketEmbeddableTest.php` (create)

**Interfaces:**
- Produces: `Ticket::$embed = ['title', 'description']`; generation yields one `ModelEmbedding` (`locale = null`, `model_key` set); `toSearchableArray` emits a single-element agnostic `embeddings` array.

- [x] **Step 1: Write failing test**

Assert a `Ticket` with title+description produces one embedding row (`locale = null`), and its `toSearchableArray()['embeddings']` has one `{vector}` entry.

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement**

```php
protected array $embed = ['title', 'description'];
```

Confirm `Ticket` uses the Core `Searchable` trait path so generation runs (it does). No analyzer/object work — Ticket text fields stay flat.

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (SAO)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/SAO && git add -A && git commit -m "feat(search): make Ticket embeddable (mono-lingual vector)"
```

---

### Task 15: `ai:embeddings:repair` per-locale + `/health` cross-check

**Files:**
- Modify: `Modules/AI/app/Console/RepairMissingEmbeddingsCommand.php`
- Test: `Modules/AI/tests/Feature/Console/RepairEmbeddingsTest.php` (extend/create)

**Interfaces:**
- Produces: `ai:embeddings:repair {model}` regenerates per locale, stamps `model_key`, and warns when the service `/health.model` differs from the active profile's `service_model`; can target rows whose `model_key` != active (`--stale`).

- [x] **Step 1: Write failing test**

Records with no embedding, or embeddings with a stale `model_key`, get regenerated with the active `model_key`; a `/health` mismatch emits a warning line (fake the HTTP client).

- [x] **Step 2: Run — expect FAIL**

- [x] **Step 3: Implement**

Iterate target records, dispatch/`handle` `GenerateEmbeddingsJob` with `locale = null` (full), stamping the active `model_key`. Add a preflight that GETs `/health` (via the configured `ai.providers.sentence_transformers.url`) and compares `model` to `active()->serviceModel`; on mismatch, `$this->warn(...)` but continue. Add a `--stale` option selecting records whose latest embedding `model_key` != active.

- [x] **Step 4: Run — expect PASS**

- [x] **Step 5: Pint + commit (AI)**

```bash
vendor/bin/pint --dirty --format agent
cd Modules/AI && git add -A && git commit -m "feat(embeddings): repair per-locale with model_key stamp and /health cross-check"
```

---

## Post-implementation: cutover (manual, not a code task)

Run once after all tasks land (dev environment):

1. Switch the service model to `intfloat/multilingual-e5-small`; verify `/health`.
2. `php artisan migrate --no-interaction` (locale + model_key).
3. `php artisan ai:embeddings:repair "Modules\\CMS\\Models\\Content"` and `... "Modules\\SAO\\Models\\Ticket"`; wait until all have embeddings.
4. `php artisan scout:delete-index "Modules\\CMS\\Models\\Content"` → `scout:index` → `scout:import` (Content, Ticket, Location).
5. `php artisan test:ai:evaluate-retrieval-strategies` to confirm gains (especially `en_*` and lexical slices).

## Self-Review notes

- Spec coverage: Part 1 (Tasks 1-7) covers analyzers/objects/full-mapping/LocaleScope/keyword; Part 2 (Tasks 8-15) covers registry/prefixes/schema/per-locale generation/incremental/nested kNN/Ticket/repair. All spec sections mapped.
- Type consistency: `resolveVectorField` returns `embeddings.vector` (Task 2) matching the field emitted by the translator (Task 1) and the mapping declared by Content (Task 4) and the base `embeddings` document key (Task 11).
- Dependency direction: dims/similarity/analyzers authority in Core `search.php`; AI registry reads Core config; Core reads no `ai.*`.
- Cross-module commits: each task commits inside its own submodule; tasks touching two modules (5, 11, 12) make two commits.

## Delivery status (2026-09-15): code complete, cutover outstanding

**Documented in:** `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` and `Modules/Core/README.md`.

All 15 code tasks verified against the repo: locale-object mappings with per-language analyzers, the nested agnostic `embeddings` array and its kNN with a document-level `locales` filter, the embedding-model registry with query/passage prefixes, the `locale` + `model_key` stamps, per-locale generation, the incremental re-embed observer, Ticket as the mono-lingual exemplar, and the repair command.

The post-implementation cutover (switch the service model, migrate, repair embeddings, delete/recreate/import the indexes, then measure) is manual and has **not** been run. Until it is, the index still holds vectors from the previous model.

### Cutover progress (2026-09-18)

Step 1 is done and extended: the embedding service now serves `intfloat/multilingual-e5-small`, and it was made **multi-model + request-driven** — `EmbeddingsProviderFactory` sends the active profile's `service_model` on every `/embed` call, so `/health`↔config drift is structurally eliminated rather than merely reconciled once. The self-hosted service (lazy-load + LRU cache + `EMBEDDING_MODEL` default) is documented in `docs/SENTENCE_TRANSFORMERS_INSTALLATION.md of the AI module (replaced on 2026-10-23 by STANDARD_SERVERS_INSTALLATION.md)`. Two related fixes landed alongside: the embeddings HTTP client's timeout/batch became configurable (a hardcoded 10s/128 aborted indexing on a CPU service), and the RAG retrieval now applies the active profile's `query:` prefix (it embedded queries raw while documents carry `passage:`).

The RAG **documentation** corpus was re-embedded with e5 and measured — tracked in `2026-07-16-rag-retrieval-strategy.md`. Steps 2-5 (migrate, `ai:embeddings:repair` for CMS Content / SAO Ticket, `scout:delete-index`/`index`/`import`, `test:ai:evaluate-retrieval-strategies`) remain outstanding for the **application-content** corpus: those tables are currently empty, so there are no `ModelEmbedding` rows to regenerate yet. Run steps 2-5 once real content exists.

### Pre-cutover blocker: full mapping was rejected by ES — RESOLVED 2026-09-17

Task 3 changed `ElasticsearchEngine::createIndex` to push the **full** translated mapping (not just the `embedding` field, which was the earlier scope in `2026-09-08-es-index-mapping-and-vector-enablement.md`). But `ElasticsearchTranslator` adds `meta` (`relation`, `filterable`) and `index: true` to the `nested`/`object` relation fields (tags/contributors/categories/locations), and ES `object`/`nested` field types accept **neither** parameter; leaf `meta` also accepts only string values, not booleans. `stringifyFieldMeta` only stringified the meta values; it did not remove `meta`/`index` from object/nested, so it did not prevent the failure.

Verified live 2026-09-16 (throwaway index on the real cluster, then deleted): applying the full `Content` mapping returned `mapper_parsing_exception: Mapping definition for [tags] has unsupported parameters: [meta : {filterable=true, relation=tags}] [index : true]` (HTTP 400). The Task 3 ES-gated test skipped in the runner (no ES), so this was not caught.

**Fix delivered (2026-09-17, Core `master`):** the sanitisation was placed at **index-creation time**, not in the translator. `ElasticsearchTranslator` still emits `meta`/`index` on relation fields **on purpose**: the in-app constraint layer (`ScoutSearchConstraintApplier`) reads them back from `getSearchMapping()` to know which nested fields are filterable and their relation path (the model's `getSchemaDefinition()` is private, so the applier uses the mapping, not the schema). Removing them from the translator would have broken nested filtering. Instead, `ElasticsearchEngine::createIndex` now runs each property through `sanitizeMappingProperty()` (replacing `stringifyFieldMeta`), which recursively:
- strips `meta`, `index` and `doc_values` from `object`/`nested` types (ES rejects them there);
- stringifies boolean `meta` values on leaf types, keeping their valid `index`/`doc_values` (including a `dense_vector`'s `index: true`).

Tests:
- `Modules/Core/tests/Unit/Search/SanitizeMappingPropertyTest.php` — deterministic, no ES: feeds the **real** translator output through the sanitiser and asserts the strip/stringify rules (and that the translator itself still emits meta/index, which the applier needs).
- `Modules/Core/tests/Integration/Search/ElasticsearchVectorIndexTest.php` (`applies the full field mapping…`) — ES-gated: `FullMappingStubModel` now carries a `tags` nested relation field with `meta`/`index`; the test creates the index against a live cluster and asserts success plus a clean applied `tags` mapping. Verified against the live cluster (`SCOUT_DRIVER=elasticsearch`): it 400s with the sanitiser bypassed and passes with it. Note: `phpunit.xml` forces `SCOUT_DRIVER=collection`, so ES-gated tests skip in the default suite — run them with `SCOUT_DRIVER=elasticsearch` (and ES reachable) before the cutover.

With this fixed, step 4 of the cutover (`scout:delete-index` → `scout:index`) can recreate the index with the full mapping. See `2026-09-08-es-index-mapping-and-vector-enablement.md` (Delivery status) for the full history: that plan deliberately scoped `createIndex` to embedding-only to avoid this exact 400.
