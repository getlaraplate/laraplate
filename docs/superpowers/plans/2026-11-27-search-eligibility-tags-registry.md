---
created_on: 2026-11-27
module: core, cms
status: completed
---
# Search eligibility, searchable tags and the search registry. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** Every search path returns only what the model allows, tag names help a text search in their own language, tag types carry an icon from one extensible registry, and the application search starts recording, off by default, what people search and how much they find.

**Architecture:** Phases P0, P3 and P1 of the spec. P0 moves the default search filters into the one place every orchestrated strategy builds its Scout query, and makes Elasticsearch express "field is null". P3 adds a locale-keyed `tag_names` object to the `Content` search document and a code registry of tag types read by Filament and by the clients. P1 adds a daily aggregate table in Core, written after the response of the `/search` route for guests and readers only.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Pest, Laravel Scout with the Core engines. No new dependency.

**Spec:** `../specs/2026-11-27-search-variants-and-content-findability-design.md` (decisions 1, 6 to 9, parts P0, P1, P3).


## Delivery status (2026-11-27): shipped, registry off pending privacy approval

**Documented in:** `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `Modules/Core/docs/rag/search-registry-privacy-review.md`, `Modules/CMS/docs/rag/MODULE.md`, `Modules/CMS/docs/CONTENT_EXTENSION.md`.

Tests: the eleven plan test files pass together (78 tests). The full run of the touched Core and CMS folders passes except four failures of this container, not of the code: `AggregateEngineBucketsTest` (two, failing on the base commit too), `ContributorsTableTest` (Imagick missing), `TranslatedRecordFormsTest` (CRM module absent). PHPStan level 9 on the changed files adds no error.

Divergences from the plan:
- P0 (Tasks 1 and 2, and the Typesense and default-filter fixes of the final review) landed on `master` independently while this branch was open (Core `362a6af`, `eb2c9ac`, `db46870`, with the extended-entity search of `2026-11-17-extended-entity-search-design.md`). Merging `master` kept its implementation: the default search filters are merged ahead of the request filters by `Modules\Core\Search\Support\DefaultSearchFilters`, a host of extenders gets `extended_type IS NULL` from `IExtendableModel` instead of `Content::defaultSearchFilters()`, Elasticsearch reads a null equality as "field missing" and Typesense indexes and filters null through a placeholder. This branch's own P0 code, tests and doc section were dropped in the merge.
- Task 3: `tags.translations` is not eager loaded, because the Tag `LocaleScope` drops tags without a translation in the active locale. A dedicated unscoped relation, `tagsAcrossLocales`, is preloaded with its translations. After the final review, a change to a tag translation re-indexes the contents carrying the tag through the queued indexing path, chunked.
- Task 3, Step 5: the Typesense and database translators ignore `locale_properties`, so `tag_names` is not searchable per locale there.
- Task 4: Slovenian labels were added, since `lang/sl/cms.php` exists and the parity test requires them; they need a native review.
- Task 5: an untyped tag shows the generic tag icon and the label "No type" (`cms.fields.no_type`); the stored type stays null.
- Task 7: the recorder call is guarded in `CrudController::search()` and never fails the search; the decision runs before the response, the write after it.

Open, not built here:
- The search registry stays off until the owner approves `search-registry-privacy-review.md`.
- Deleting a tag whose pivot rows are already gone does not re-index the contents that carried it.

## Global Constraints

- Every search path applies the same eligibility constraints; paths differ in recall and ranking only (spec decision 1).
- Tag names are indexed per locale from explicit translations only, no fallback (P3).
- Tag names stay out of the embedding text: `Content::embedHeader()` is not changed (P3).
- The type of a tag stays optional; `null` is never replaced by a default (decision 8).
- Standard types, in this order: `search`, `people`, `locations`, `organizations`, `topics`, `events` (decision 9).
- Icons: `search` → `heroicon-o-magnifying-glass` / `Search`; `people` → `heroicon-o-users` / `Users`; `locations` → `heroicon-o-map-pin` / `MapPin`; `organizations` → `heroicon-o-building-office` / `Building2`; `topics` → `heroicon-o-hashtag` / `Hash`; `events` → `heroicon-o-calendar` / `Calendar`; no type → `heroicon-o-tag` / `Tag`; any other type → its first letter, upper case.
- The registry records no user, no IP, no session, and never a query holding an email, a UUID, a structured identifier or a number (P1).
- The registry is off by default and is turned on only after the owner approves its privacy review (spec section 9).
- Tests live in the owning module's `tests/`; classes for tests go in `tests/Stubs/`. Run Pint from the laraplate root on explicit files: `vendor/bin/pint --format agent <files>`.
- Commits go to each module repository on branch `ccr-2b8b8caa-1igreo`.

## Review Focus

1. A tag translated only in Italian, on a tag model with translation fallback on, must not appear under `tag_names.en` (Task 3).
2. A query mixing words and an email, such as `mario rossi mario@example.com`, is not recorded at all (Task 6).
3. A person who may update the searched model is not recorded on `/api/v1` either; a guest on `/app` is (Task 7).
4. A request filter on the same field as a default filter (`extended_type = 'shop.product'` on `Content`) gives the same result on Basic, Orchestrated and Auto, and does not throw (Task 2).
5. Typing `People` or `pe` in the Filament type select offers `people` once, even when no tag uses it yet (Task 5).

---

### Task 1: Elasticsearch expresses "field is null"

`CMS Content::defaultSearchFilters()` returns `['extended_type' => null]`. `ElasticsearchEngine::buildSearchFilters()` turns a scalar into `term`, and a `term` on `null` is not a valid Elasticsearch query, so the Basic path cannot filter extended contents out on Elasticsearch today.

**Files:**
- Modify: `Modules/Core/app/Search/Engines/ElasticsearchEngine.php` (the plain-value branch of `buildSearchFilters()`)
- Test: `Modules/Core/tests/Integration/Search/SearchEngineContractTest.php`

**Interfaces:**
- Produces: `ElasticsearchEngine::buildSearchFilters(['field' => null])` returns `[['bool' => ['must_not' => [['exists' => ['field' => 'field']]]]]]`.

- [x] **Step 1: Write the failing test** `it('filters a null equality as a missing field on Elasticsearch')`: build the engine with `(new ReflectionClass(ElasticsearchEngine::class))->newInstanceWithoutConstructor()` as the tests beside it do; `buildSearchFilters(['extended_type' => null, 'type' => 'news'])` equals `[['bool' => ['must_not' => [['exists' => ['field' => 'extended_type']]]]], ['term' => ['type' => 'news']]]`.
- [x] **Step 2: Run it and see it fail:** `php artisan test --compact Modules/Core/tests/Integration/Search/SearchEngineContractTest.php --filter="missing field"`. Expected: FAIL, the first clause is a `term` on `null`.
- [x] **Step 3: Implement** the `null` case before the plain `term` fallback.
- [x] **Step 4: Run the file again.** Expected: PASS, the other tests of the file still pass.
- [x] **Step 5: Typesense.** `TypesenseEngine::buildFilter()` writes `field:=` for `null`. Do not change it in this plan; record "Typesense cannot filter a null equality, extended contents are not excluded there" in the delivery status.
- [x] **Step 6: Pint and commit** in `Modules/Core`: `fix(search): express a null equality as a missing field on Elasticsearch`.

### Task 2: Default search filters on every search path

**Files:**
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (`executeScoutSearch()`)
- Create: `Modules/Core/tests/Stubs/Search/DefaultFilteredLicenseSearchStub.php`
- Create: `Modules/Core/tests/Integration/Search/SearchPathEligibilityTest.php`
- Modify: `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`

**Interfaces:**
- Consumes: `Modules\Core\Contracts\ProvidesDefaultSearchFilters::defaultSearchFilters(): array<string, scalar|null>`.
- Produces: every Scout builder created by `EnsembleSearchService` (keyword, vector, hybrid) carries `where($field, $value)` for each default filter, before the request constraints. Callers of `AdvancedSearchService` (the CRUD orchestrated path and `CmsApplicationContentRetrievalProvider`) inherit it with no change of their own.

Applying the filters at the builder rather than as a `FiltersGroup` in `AdvancedSearchService` keeps the semantics of `CrudService::searchWithScout()` exactly: AND equality on the engine query, then the request filters.

- [x] **Step 1: Create the stub** `DefaultFilteredLicenseSearchStub`, modelled on `ScopedLicenseSearchStub`: table `CoreTables::Licenses`, `DatabaseEngine`, `toSearchableArray()` returns `id`, `uuid`, `valid_to`; implements `ProvidesDefaultSearchFilters` with `['valid_to' => null]`.
- [x] **Step 2: Write the failing test file.** A helper `eligibility_search(SearchMode $mode, ?FiltersGroup $filters): list<int>` built like `dbscope_search()` in `DatabaseEngineScopeFilterSearchTest.php`, on the new stub. Three licenses whose uuid starts with `aaaaaaaa`: `open` (`valid_to` null), `scheduled` (`valid_from` next week, `valid_to` null), `expired` (`valid_to` yesterday).
  - `it('applies the default search filters on every search path')` with `[SearchMode::Basic, SearchMode::Orchestrated, SearchMode::Auto]`: `eligibility_search($mode, null)` is `[open, scheduled]`.
  - `it('ANDs a request filter on a defaulted field the same way on every path')` with the same dataset: a `Filter('valid_to', <expired valid_to>, FilterOperator::Equals)` returns `[]` and `$result->error` is null.
- [x] **Step 3: Run it and see Orchestrated and Auto fail:** `php artisan test --compact Modules/Core/tests/Integration/Search/SearchPathEligibilityTest.php`. Expected: Basic passes, Orchestrated and Auto return the expired license too.
- [x] **Step 4: Implement** in `executeScoutSearch()`: when `$model instanceof ProvidesDefaultSearchFilters`, call `$builder->where($field, $value)` for each entry, right after the vector `where` and before `applyRequestedLocale()`.
- [x] **Step 5: Run the new file, then the search suites:** `php artisan test --compact Modules/Core/tests/Integration/Search Modules/Core/tests/Integration/Services`. Expected: PASS.
- [x] **Step 6: Document** in `SEARCH_RETRIEVAL_PIPELINE.md` a short "Eligibility on every path" section: default filters apply to Basic and to every orchestrated strategy; a null equality is "field missing" on Elasticsearch and `IS NULL` on the database engine; Typesense gap.
- [x] **Step 7: Pint and commit** in `Modules/Core`: `fix(search): apply default search filters on every search path`.

### Task 3: Tag names searchable per locale

**Files:**
- Modify: `Modules/CMS/app/Models/Content.php` (`toSearchableWith()` relation list, `toSearchableArray()`, the mapping beside `title`/`slug`)
- Test: `Modules/CMS/tests/Feature/Search/ContentSearchableArrayTest.php`
- Modify: `Modules/CMS/docs/rag/MODULE.md`

**Interfaces:**
- Produces: the `Content` search document holds `tag_names`: `array<locale, list<string>>`, one key per locale that has at least one name. The mapping declares `tag_names` as `FieldType::Object` with `[IndexType::Searchable, IndexType::FullText]` and `['locale_properties' => $localeText]`, like the component fields.

- [x] **Step 1: Write the failing tests** in `ContentSearchableArrayTest.php`, reusing `createBilingualSearchableContent()`:
  - `it('indexes tag names per locale from explicit translations only')`: tag `cinema` named `Cinema` in `it` and `en`; tag `bici` named `Bici elettrica` in `it` only (delete its `en` translation after the factory). `toSearchableArray()['tag_names']` is `['it' => ['Cinema', 'Bici elettrica'], 'en' => ['Cinema']]` (order of `$content->tags`).
  - `it('declares tag_names as a locale-keyed text object that the keyword query reaches')`: `getSearchMapping()['mappings']['properties']['tag_names']['properties']` has `it` and `en`; `ElasticsearchLocaleTextFields::fromProperties($properties, ['it'], ['it', 'en'])` contains `tag_names.it` and not `tag_names.en`.
  - `it('keeps tag names out of the embedding header')`: `embedHeader('it')` does not contain `Bici elettrica`.
- [x] **Step 2: Run them and see the first two fail:** `php artisan test --compact Modules/CMS/tests/Feature/Search/ContentSearchableArrayTest.php`.
- [x] **Step 3: Implement:** eager load `tags.translations` instead of `tags`; in the locale loop of `toSearchableArray()` collect, for each tag, `$tag->getTranslation($locale, false)?->name` when non-empty; add the mapping field.
- [x] **Step 4: Run the file, then the CMS search folder:** `php artisan test --compact Modules/CMS/tests/Feature/Search`. Expected: PASS.
- [x] **Step 5: Other engines.** Read how `TypesenseTranslator` and `DatabaseTranslator` map an object with `locale_properties`. Change nothing in them in this plan; record in the delivery status whether `tag_names` is searchable there.
- [x] **Step 6: Document** in `MODULE.md`, where the search document is described: `tag_names` per locale, explicit translations only, keyword search only, an index rebuild is required (the mapping comparator flags it). Correct the outdated sentence there that speaks of a Typesense schema with flat `title_{locale}` keys.
- [x] **Step 7: Pint and commit** in `Modules/CMS`: `feat(search): index tag names per locale`.

### Task 4: Tag type registry and its read route

**Files:**
- Create: `Modules/CMS/app/Services/TagTypeDefinition.php`
- Create: `Modules/CMS/app/Services/TagTypeRegistry.php`
- Modify: `Modules/CMS/app/Providers/CMSServiceProvider.php` (singleton in `register()`, the six CMS types in `boot()`)
- Create: `Modules/CMS/app/Http/Controllers/TagTypesController.php`
- Modify: `Modules/CMS/routes/web.php`
- Modify: `Modules/CMS/lang/{en,it,de,es}/cms.php`
- Test: `Modules/CMS/tests/Unit/Services/TagTypeRegistryTest.php`, `Modules/CMS/tests/Feature/Controllers/TagTypesControllerTest.php`

**Interfaces:**
- Produces:
  - `final readonly class TagTypeDefinition { __construct(public string $type, public string $labelKey, public string $heroicon, public string $lucide) }`. `$type` is stored normalized with `Tag::normalizeType()`.
  - `final class TagTypeRegistry`: `register(TagTypeDefinition $definition): void` (a later registration of the same type replaces the earlier one, as `ContentExtenderRegistry` does); `all(): list<TagTypeDefinition>` in registration order; `find(?string $type): ?TagTypeDefinition` (normalizes first); `heroiconFor(?string $type): ?string` (the definition's icon, `heroicon-o-tag` for `null`, `null` for a type not registered); `letterFor(?string $type): ?string` (upper-case first letter for a type not registered, else `null`).
  - Route `GET cms/tag-types`, middleware `auth`, name `cms.tag-types.index`, response through `ResponseBuilder` with `data`: list of `{type, label, heroicon, lucide}`, `label` translated in the request locale.
  - Lang keys `cms.tag_types.<type>`. Labels: en Search, People, Locations, Organizations, Topics, Events; it Ricerca, Persone, Luoghi, Organizzazioni, Argomenti, Eventi; de Suche, Personen, Orte, Organisationen, Themen, Ereignisse; es Búsqueda, Personas, Lugares, Organizaciones, Temas, Eventos.

- [x] **Step 1: Write the failing unit tests** in `TagTypeRegistryTest.php`:
  - `it('registers the six CMS types in order')`: `app(TagTypeRegistry::class)->all()` maps to `['search', 'people', 'locations', 'organizations', 'topics', 'events']`.
  - `it('lets a module register its own type and replace one')`: registering `brands` adds it last; registering `topics` again with another icon replaces it in place.
  - `it('resolves icons and letters')`: `heroiconFor('Search')` is `heroicon-o-magnifying-glass`; `heroiconFor(null)` is `heroicon-o-tag`; `heroiconFor('musical-instrument')` is null; `letterFor('musical-instrument')` is `M`; `letterFor('people')` and `letterFor(null)` are null.
- [x] **Step 2: Write the failing feature tests** in `TagTypesControllerTest.php`, modelled on `InsightsControllerTest.php`: an authenticated request with locale `it` returns six items, the first `{type: 'search', label: 'Ricerca', heroicon: 'heroicon-o-magnifying-glass', lucide: 'Search'}`; a guest gets 401.
- [x] **Step 3: Run both and see them fail:** `php artisan test --compact Modules/CMS/tests/Unit/Services/TagTypeRegistryTest.php Modules/CMS/tests/Feature/Controllers/TagTypesControllerTest.php`.
- [x] **Step 4: Implement** the definition, the registry, the provider wiring, the lang keys, the controller and the route.
- [x] **Step 5: Run both again.** Expected: PASS. Run `php artisan test --compact --filter=LangKeyParity` if the Core parity test covers module lang files. Expected: PASS.
- [x] **Step 6: Document** in `MODULE.md` a "Tag types" section: the registry, the six CMS types and their icons, how a module registers one, the read route, `null` keeps its meaning, `people`/`locations` tags versus `Contributor`/`Location` records, `search` tags are search aids returned by the API with their type and filtered by the site if it does not want them.
- [x] **Step 7: Pint and commit** in `Modules/CMS`: `feat(tags): add an extensible registry of tag types with icons`.

### Task 5: Filament shows and offers the tag types

**Files:**
- Create: `Modules/CMS/app/Filament/Resources/Tags/Schemas/TagTypeOptions.php`
- Modify: `Modules/CMS/app/Filament/Resources/Tags/Schemas/TagForm.php` (the `type` select uses `TagTypeOptions`; remove its two private helpers)
- Modify: `Modules/CMS/app/Filament/Resources/Tags/Tables/TagsTable.php` (the `type` column)
- Test: `Modules/CMS/tests/Unit/Filament/TagTypeOptionsTest.php`, `Modules/CMS/tests/Feature/Filament/TagsListOrderTest.php` (or a sibling file in the same folder)

**Interfaces:**
- Consumes: `TagTypeRegistry::all()`, `heroiconFor()`, `letterFor()` (Task 4); `Tag::getTypes()`, `Tag::normalizeType()`.
- Produces: `final class TagTypeOptions { public static function all(): array<string, string>; public static function search(string $search): array<string, string> }`. `all()`: the types in use and every registered type, no duplicates, registered types first in registry order, then the other types in use sorted. `search()`: the same list filtered by `str_contains` on the normalized search, then the normalized search itself when it is not already there; an empty normalized search returns `all()`.

- [x] **Step 1: Write the failing tests** in `TagTypeOptionsTest.php`:
  - with one tag of type `genre` in use: `all()` keys are `['search', 'people', 'locations', 'organizations', 'topics', 'events', 'genre']`;
  - `search('pe')` keys are `['people', 'pe']`;
  - `search('People')` keys are `['people']`;
  - `search('Musical Instrument')` keys end with `musical-instrument`.
- [x] **Step 2: Run and see them fail:** `php artisan test --compact Modules/CMS/tests/Unit/Filament/TagTypeOptionsTest.php`.
- [x] **Step 3: Implement** `TagTypeOptions` and switch `TagForm` to it.
- [x] **Step 4: Change the `type` column** of `TagsTable`: icon from `heroiconFor($state)`; for a type with no icon, the state is shown prefixed by `letterFor($state)`. Add a Livewire table test in the Filament test folder: the list of tags renders the `type` column for a `people` tag, a `genre` tag and an untyped tag without error.
- [x] **Step 5: Run the Filament folder:** `php artisan test --compact Modules/CMS/tests/Feature/Filament Modules/CMS/tests/Unit/Filament`. Expected: PASS.
- [x] **Step 6: Pint and commit** in `Modules/CMS`: `feat(filament): show tag type icons and offer the standard types`.

### Task 6: Search registry storage and recorder (Core)

**Files:**
- Modify: `Modules/Core/app/Enums/CoreTables.php` (`case SearchQueryStats = 'core_search_query_stats';`)
- Create: `Modules/Core/database/migrations/2026_11_27_000000_create_search_query_stats_table.php`
- Create: `Modules/Core/app/Models/SearchQueryStat.php`
- Create: `Modules/Core/app/Search/Services/SearchQueryRegistry.php`
- Modify: `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (three settings beside the other `search.*` ones)
- Test: `Modules/Core/tests/Integration/Search/SearchQueryRegistryTest.php`

**Interfaces:**
- Produces:
  - Table `core_search_query_stats`: `id`, `query` string 255, `locale` string 12, `model` string 128 (the searched model's table), `day` date, `searches` unsigned int default 0, `low_result_searches` unsigned int default 0, `result_sum` unsigned big int default 0, timestamps; unique `(query, locale, model, day)`; index `day`.
  - `final class SearchQueryStat` model on that table.
  - `final readonly class SearchQueryRegistry`:
    - `normalize(string $query, string $locale): ?string`: lowercase, trimmed, spaces collapsed, request words removed with `SearchQueryAnalyzer::withoutRequestWords()`; `null` when empty, longer than 255 characters, or when `SearchQueryAnalyzer::analyze()` finds a token of kind `Email`, `Uuid`, `StructuredIdentifier` or `Numeric`.
    - `record(string $model, string $query, string $locale, int $hits, CarbonImmutable $day): void`: adds one search to the row of the day, creating it at zero first; `low_result_searches` grows when `$hits < low_results_threshold`; `result_sum` grows by `$hits`. Use `incrementEach()` on the row so concurrent searches add up.
  - Settings, group `search`: `search.registry.enabled` false (Boolean), `search.registry.retention_days` 90 (Integer), `search.registry.low_results_threshold` 5 (Integer, the `SearchQualityEvaluator::MIN_RESULTS` value). Read as `config('core.search.registry.*')` like `search.deep_per_minute`.

- [x] **Step 1: Write the failing tests:**
  - `normalize('  Bici   Elettrica ', 'it')` is `bici elettrica`; `normalize('articoli su bici elettrica', 'it')` is `bici elettrica`;
  - `normalize('mario rossi mario@example.com', 'it')`, `normalize('ordine 12345', 'it')`, `normalize('ABC-123', 'it')` and a UUID are `null`;
  - `normalize('NATO vertice', 'it')` is `nato vertice` (an acronym is not a protected token here);
  - two `record()` calls on the same day, with 2 and 8 hits and threshold 5, leave one row with `searches` 2, `low_result_searches` 1, `result_sum` 10; a call on the next day creates a second row.
- [x] **Step 2: Run and see them fail:** `php artisan test --compact Modules/Core/tests/Integration/Search/SearchQueryRegistryTest.php`.
- [x] **Step 3: Implement** the enum case, the migration, the model, the service and the seeded settings.
- [x] **Step 4: Run again.** Expected: PASS.
- [x] **Step 5: Pint and commit** in `Modules/Core`: `feat(search): add the search registry storage`.

### Task 7: Record from the search route, guests and readers only (Core)

**Files:**
- Create: `Modules/Core/app/Search/Jobs/RecordSearchQueryJob.php`
- Create: `Modules/Core/app/Search/Services/SearchQueryRecorder.php`
- Modify: `Modules/Core/app/Http/Controllers/CrudController.php` (`search()`)
- Test: `Modules/Core/tests/Integration/Search/SearchQueryRecorderTest.php`, `Modules/Core/tests/Feature/Controllers/CrudSearchRecordingTest.php`

**Interfaces:**
- Consumes: `SearchQueryRegistry::normalize()`, `record()` (Task 6); `AuthorizationService::userHolds(User $user, string $permission_name): bool`; `PermissionName::forModel(Model $model, ActionEnum::Update->value)`; `LocaleContext::get()`.
- Produces:
  - `RecordSearchQueryJob(string $model, string $query, string $locale, int $hits, string $day)`, `$day` as `Y-m-d`: calls `SearchQueryRegistry::record()` with `CarbonImmutable::parse($day)`.
  - `final readonly class SearchQueryRecorder`: `shouldRecord(?User $user, Model $model): bool` (false when `core.search.registry.enabled` is off; true for a guest; false when the user holds the update permission of the model; true otherwise). `recordAfterResponse(SearchRequestData $data, CrudResult $result): void`: when `shouldRecord()` and `$result->error === null` and `normalize()` gives a query, dispatches `RecordSearchQueryJob::dispatchAfterResponse()` with the model table, the normalized query, `LocaleContext::get()`, `$result->meta->totalRecords` and today.
  - `CrudController::search()` calls `recordAfterResponse()` with the result of `CrudService::search()`. No other caller records: not `CrudService`, not the assistant, not the analysis.

- [x] **Step 1: Write the failing recorder tests:** with the setting on, a guest and a user without the update permission are recorded, a user holding it is not; with the setting off nobody is; an errored result and a query that normalizes to `null` dispatch nothing (`Bus::fake()`, `Bus::assertDispatchedAfterResponse()` / `assertNotDispatched()`).
- [x] **Step 2: Write the failing controller test:** bind a `CrudService` mock that returns a successful `CrudResult` with `totalRecords` 3, and a spy `SearchQueryRecorder`; a guest request to the search route of `/api/v1` and one to `/app` both reach `recordAfterResponse()` once. Use the route setup of `ScoutSearchScopeFilterTest.php` (`CrudApiExposure::enable()`).
- [x] **Step 3: Run both and see them fail:** `php artisan test --compact Modules/Core/tests/Integration/Search/SearchQueryRecorderTest.php Modules/Core/tests/Feature/Controllers/CrudSearchRecordingTest.php`.
- [x] **Step 4: Implement** the job, the recorder and the controller call.
- [x] **Step 5: Run both, then the controller and search suites:** `php artisan test --compact Modules/Core/tests/Feature/Controllers Modules/Core/tests/Integration/Search`. Expected: PASS.
- [x] **Step 6: Pint and commit** in `Modules/Core`: `feat(search): record searches of guests and readers after the response`.

### Task 8: Retention, privacy review and documentation (Core)

**Files:**
- Create: `Modules/Core/app/Console/PruneSearchQueryStatsCommand.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php` (`registerCommandSchedules()`)
- Create: `Modules/Core/docs/rag/search-registry-privacy-review.md`
- Modify: `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`
- Test: `Modules/Core/tests/Feature/Console/PruneSearchQueryStatsCommandTest.php`

**Interfaces:**
- Consumes: `SearchQueryStat` (Task 6), `core.search.registry.retention_days`.
- Produces: command `search:prune-registry`, scheduled `->daily()->onOneServer()` beside `PruneMediaDraftsCommand`; deletes rows whose `day` is older than today minus `retention_days`.

- [x] **Step 1: Write the failing test:** with retention 90 and today fixed, rows of day −91 are deleted, rows of day −90 and today stay; the command exits successfully with the setting off too (pruning old rows is always allowed).
- [x] **Step 2: Run and see it fail:** `php artisan test --compact Modules/Core/tests/Feature/Console/PruneSearchQueryStatsCommandTest.php`.
- [x] **Step 3: Implement** the command and the schedule.
- [x] **Step 4: Run again.** Expected: PASS.
- [x] **Step 5: Write the privacy review** on the model of `Modules/AI/docs/rag/query-analytics-privacy-review.md`: the field set of Task 6, what is never stored, who is recorded and why, the three settings with their defaults, retention, no per-person erasure needed since no person is stored. Status line: "Pending owner approval. The registry stays off until approved."
- [x] **Step 6: Document** the registry in `SEARCH_RETRIEVAL_PIPELINE.md`: purpose, who is recorded, normalization and exclusions, settings, retention, a link to the privacy review.
- [x] **Step 7: Pint and commit** in `Modules/Core`: `feat(search): prune the search registry and document it`.

### Task 9: Close the plan

**Files:**
- Modify: this plan (laraplate)

- [x] **Step 1:** Run every test file named in this plan in one call and record the result.
- [x] **Step 2:** Write `## Delivery status (date): ...` with the Typesense gap of Task 1, the pending privacy approval, any divergence, and `**Documented in:** Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md, Modules/Core/docs/rag/search-registry-privacy-review.md, Modules/CMS/docs/rag/MODULE.md`; set `status: completed`.
- [x] **Step 3:** Commit the plan in laraplate, with the submodule pointers of `Modules/Core` and `Modules/CMS` moved to their new commits.
