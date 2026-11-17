---
created_on: 2026-11-17
module: core, cms, shop
status: open
---
# Extended entity search and list. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** `/search` and `/select` of an extended entity (`shop/products`) work through the index of its host under the extender's own permissions; `with_extended=true` on `/search` and `/select` of a host (`cms/contents`) adds the extended rows the user may read, each upcast and checked against its own ACL; the generic parts of `Content` (host side of the seam, indexed relations, Editor plain text) live in Core.

**Architecture:** Two Core contracts, host and extender. A Core trait implements the host side of the content-extension seam for any host (hiding scope, `withExtended()`, a registry per host, the batched upcast, the `extension` document and mapping). `CrudService` recognises an extender and searches its host index, translating filters and ACL by one rule; on a host it replaces the exclusion of extended rows with the type condition of the mixed flows when `with_extended` is set. CMS `Content` uses the trait; Shop registers in the Core registry and seeds the guest ACL of its products.

**Tech Stack:** PHP 8.5, Laravel 13, Scout, Pest. No new dependency.

**Spec:** `../specs/2026-11-17-extended-entity-search-design.md` (decisions XS1 to XS17).

## Prerequisites

All merged on 2026-11-17:

- getlaraplate/laracore#43: default search filters on every search path, locale on the basic path, typed sub-fields of an Elasticsearch `object` (needed by `extension.*` filters).
- getlaraplate/laracms#22.
- getlaraplate/larashop#4: shared primary key of `Product` (XS7). The flows of Task 9 reload products by the hit ids.
- getlaraplate/laracore#44: a pending deletion under the `Hide` strategy is hidden from an anonymous HTTP visitor. Not required by the plan; it was found while verifying XS9.

## Global Constraints

- Branch `feat/extended-entity-search` in `Modules/Core`, `Modules/CMS` and `Modules/Shop`; the spec and this plan are in the root `laraplate` repo. One PR per repo, merged in the order Core, CMS, Shop; submodule pointers are bumped by the owner, never by an agent.
- Core never names CMS or Shop: every Core class reaches a host or an extender through the contracts of Task 5.
- No migration. No change to the `contents` index beyond `extension.company_id` (Task 7); the owner rebuilds the index after the Shop PR.
- Refactors (Tasks 2, 4, 6) do not change output: the search document, the mapping, reading statistics and evidence excerpts of `Content` are compared before and after.
- No test is deleted. A test of a CMS class that moves to Core moves with it and keeps its cases; removing the old file needs the owner's approval in the PR.
- Tests that declare classes use the module's `tests/Stubs/`, never the test file.
- Run `php artisan test --compact <path>` on narrow paths while working; each module suite passes before its PR. Pint from the root with an explicit file list. `config:clear` first if `bootstrap/cache/config.php` exists.
- Every decision enforced in code (XS16, XS17) names the decision and the spec in the docblock of its check, and its error message says which rule broke and how to fix it (spec §8).

## Review Focus

1. **Fail-closed ACL.** An extender ACL condition that cannot be translated to the host index is applied at reload, never dropped (XS10). A mixed list never shows an extended row the user's ACL on its own entity excludes (XS5).
2. **Replaced, not joined.** With `with_extended`, the exclusion `extended_type IS NULL` is replaced by the type condition; joined, the two select nothing (XS11).
3. **No leak through the type.** An alias whose table the user may not `select` is absent from the mixed list, silently (XS4).
4. **Core stays generic.** No `Modules\CMS` or `Modules\Shop` reference in Core.
5. **Unchanged output of the refactors.** Same document keys, same mapping, same plain text.

---

### Task 1: Editor plain text in Core (XS15)

**Files:**
- Create: `Modules/Core/app/Support/EditorPlainText.php`.
- Test: `Modules/Core/tests/Unit/Support/EditorPlainTextTest.php`.

- [x] **Step 1: Test first.** An Editor.js document (array or object with `blocks`) yields the same plain text as `Modules\CMS\Casts\ReadingStatistics::plainText()` does today for the same blocks (paragraph, header, list, nested `items`, HTML tags and entities, find marks); a plain string passes through without control characters; anything else is returned as it is.
- [x] **Step 2: Implement** a `final readonly` class with static methods, no I/O. The block walking and HTML stripping move from `ReadingStatistics` (`normalizeBlocksList()`, `collectMetricSegments()`, `stripFindMarksFromHtmlString()`), next to `FieldType::Editor`.
- [x] **Step 3:** tests PASS, Pint, commit: `feat(core): plain text of an Editor field`. Committed on `feat/extended-entity-search` in `Modules/Core`. Pest and Pint were not run in the authoring environment (PHP 8.3, no host app): every case of the test, and parity with `ReadingStatistics::plainText()` on the same blocks, ran through a standalone script. Run both in the host app before the Core PR merges.

---

### Task 2: CMS readers of Editor content use the Core service (XS15)

**Files:**
- Modify: `Modules/CMS/app/Casts/ReadingStatistics.php`, `Modules/CMS/app/Models/Content.php` (`searchableComponentValue()`), `Modules/CMS/app/ApplicationContent/CmsContentEvidenceProjector.php`.
- Test: the existing reading statistics, content search data and evidence projector tests; a new case per reader if none covers an Editor document.

- [x] **Step 1:** pin the current output of each reader on an Editor document with nested blocks (tests that pass before the change).
- [x] **Step 2:** `ReadingStatistics::plainText()` and `Content::searchableComponentValue()` delegate to `EditorPlainText`. The evidence projector uses it where its output is the same; where it must differ (its excerpt keeps a structure of its own), it keeps its own step on top of the service. Record which in the delivery status. Done: `ReadingStatistics` reads blocks, segments and find marks through `EditorPlainText` (its `plainText()` and `stripFindMarksFromHtmlString()` delegate); `Content` calls `EditorPlainText::fromValue()` and drops `searchableComponentValue()`. `CmsContentEvidenceProjector` keeps its own walk, unchanged: `collectText()` is a generic walk over any component shape (it reads `title`, `label`, `description`, `value` and skips string list items and table cells, and `strip_tags()` leaves no space at tag boundaries), so the Editor reader would change its excerpts. Converging it is a behaviour change with its own test, not part of this refactor.
- [x] **Step 3:** the pinned tests still PASS, Pint, commit: `refactor(cms): Editor plain text from Core`. Committed on `feat/extended-entity-search` in `Modules/CMS`; pinned in `tests/Feature/Models/ContentTest.php` (Editor component in the reranker text and the search document). Pest and Pint not run in the authoring environment: the plain text, the reading metrics and the find-mark stripping of `ReadingStatistics` were compared before and after through a standalone script, identical. Run both in the host app.

---

### Task 3: Indexed relation helper in Core (XS14)

**Files:**
- Create: `Modules/Core/app/Search/Schema/IndexedRelation.php`.
- Test: `Modules/Core/tests/Unit/Search/IndexedRelationTest.php`.

- [ ] **Step 1: Test first.** A declaration with a relation name and the default shape yields a `FieldDefinition` of type `Array` with `relation` set and properties `name` (text), `id` (integer, filterable), `slug` and `path` (keyword, filterable); extra properties add to the shape and can override it; the document entry of a collection of related models holds exactly the declared keys for each model, in order; field and document are built from the same declaration, so their keys match.
- [ ] **Step 2: Implement** a `final readonly` value object: constructor (relation, field name, extra properties, index types), `field(): FieldDefinition`, `document(iterable $related): array`.
- [ ] **Step 3:** tests PASS, Pint, commit: `feat(core): declare an indexed relation once for document and mapping`.

---

### Task 4: `Content` declares its indexed relations with the helper (XS14)

**Files:**
- Modify: `Modules/CMS/app/Models/Content.php` (`toSearchableArray()`, `getSearchMapping()`).
- Test: `Modules/CMS/tests/Feature/ContentExtension/ContentExtensionMappingTest.php`, `ContentExtensionSearchDataTest.php`, or a new `ContentSearchDocumentTest.php`.

- [ ] **Step 1:** pin the current mapping of `contributors`, `categories`, `tags`, `locations` and their document entries (tests that pass before the change).
- [ ] **Step 2:** replace the four `only([...])` calls and the four `FieldDefinition` blocks with four `IndexedRelation` declarations (the `locations` one adds the address properties, `tags.name` is a keyword).
- [ ] **Step 3:** the pinned tests still PASS, Pint, commit: `refactor(cms): indexed relations of Content through the Core helper`.

---

### Task 5: Host and extender contracts, and the generic host trait (XS11, XS13, XS16)

**Files:**
- Create: `Modules/Core/app/Contracts/IExtendableModel.php` (host), `Modules/Core/app/Contracts/IExtendsModel.php` (extender), `Modules/Core/app/Models/Concerns/HasExtensions.php` (host trait), `Modules/Core/app/Search/Extensions/ExtensionRegistry.php`, `Modules/Core/app/Search/Extensions/ExtensionResolver.php`, `Modules/Core/app/Search/Extensions/HidesExtendedRows.php` (global scope), `Modules/Core/app/Search/Extensions/ExtensionFieldConflict.php` (exception).
- Test: `Modules/Core/tests/Feature/Extensions/` with stubs in `Modules/Core/tests/Stubs/Extensions/` (a host on its own table, two extenders).

- [ ] **Step 1: Test first.** Host: its type column; extended rows hidden by default and returned by `withExtended()`; the registry is keyed by host class (an alias registered for one host is unknown to another); the upcast of a page swaps extended rows for their extenders, re-attaches the host, keeps the order and costs one query per alias; the bulk import query includes extended rows; the `extension` document holds `type` and the extender's `searchableExtension()`; the composed mapping unions the extenders' fields. XS16: two extenders declaring a field with the same type and flags compose into one entry; different definitions throw `ExtensionFieldConflict` naming the field and both aliases.
- [ ] **Step 2: Implement.** The extender contract: host class, alias, host key, host relation, own columns, `searchableExtension()`, `searchableExtensionMapping()`. The host contract: type column, extenders, `withExtended()`, upcast. The trait registers the hiding scope and implements the contract from the registry and resolver. The scope, registry, resolver and composition are generalised from `Modules/CMS/app/Scopes/HidesExtendedContent.php`, `Services/ContentExtenderRegistry.php`, `Services/ContentExtensionResolver.php` and `Content::extensionSearchDocument()` / `composeExtensionMapping()`.
- [ ] **Step 3:** the exclusion comes from the host contract: `DefaultSearchFilters::merge()` (or its caller) adds `<type column> IS NULL` for a host, alongside any `ProvidesDefaultSearchFilters` filters. Test: a stub host with no `defaultSearchFilters()` is still excluded on Basic, Orchestrated and Auto.
- [ ] **Step 4:** tests PASS, Pint, commit: `feat(core): host and extender contracts with a generic host implementation`.

---

### Task 6: `Content` and `ExtendsContentTrait` on the Core contracts (XS11, XS13)

**Files:**
- Modify: `Modules/CMS/app/Models/Content.php`, `Modules/CMS/app/Contracts/ExtendsContent.php` (extends `IExtendsModel`), `Modules/CMS/app/Models/Concerns/ExtendsContentTrait.php`, `Modules/CMS/app/Observers/ContentExtensionObserver.php`, `Modules/CMS/app/Observers/ContentReferenceObserver.php`, `Modules/CMS/app/Providers/CMSServiceProvider.php`.
- Remove (replaced by Task 5): `Modules/CMS/app/Scopes/HidesExtendedContent.php`, `Modules/CMS/app/Services/ContentExtenderRegistry.php`, `Modules/CMS/app/Services/ContentExtensionResolver.php`.
- Test: `Modules/CMS/tests/Unit/ContentExtension/ContentExtenderRegistryTest.php` and `Modules/CMS/tests/Feature/ContentExtension/*`, adapted to the Core classes; `Modules/CMS/tests/Stubs/ContentExtension/FakeContentExtender.php`.

- [ ] **Step 1:** `Content` uses `HasExtensions`, declares `extended_type`, and drops `withExtended()`, `extensionSearchDocument()`, `composeExtensionMapping()`, the registration of `HidesExtendedContent` in `booted()` and its exclusion in `makeAllSearchableUsing()`. `defaultSearchFilters()` loses `extended_type => null`; if nothing is left, `Content` stops implementing `ProvidesDefaultSearchFilters`.
- [ ] **Step 2:** `ExtendsContent` extends `IExtendsModel`; `ExtendsContentTrait` implements the new methods (host `Content`, host key `content_id`, relation `content`, own columns from the model) and reads the registry and the upcast from Core. The lifecycle cascade (`ContentExtensionCascade`) does not change.
- [ ] **Step 3:** the seam tests pass against the Core classes; the generic content search still excludes extended contents; `withExtended()` still opens them; `ContentExtensionMappingTest` and `ContentExtensionSearchDataTest` give the same mapping and document. A CMS test composes the `extension` mapping in the full application with every registered extender (XS16).
- [ ] **Step 4:** tests PASS, Pint, commit: `refactor(cms): Content is a host of the Core extension contracts`.

---

### Task 7: Shop on the Core registry, and its indexed ACL fields (XS13, XS17, §5 Shop)

**Files:**
- Modify: `Modules/Shop/app/Providers/ShopServiceProvider.php`, `Modules/Shop/app/Models/Product.php` (`searchableExtension()`, `searchableExtensionMapping()`).
- Test: `Modules/Shop/tests/Feature/ProductExtensionTest.php`, a new `Modules/Shop/tests/Feature/ProductColumnNamesTest.php`.

- [ ] **Step 1:** `shop.product` is registered in the Core registry for `Content` (both the resolving and the already-resolved paths of today's provider).
- [ ] **Step 2:** `extension` gains `company_id` (integer, filterable) next to `is_published_in_shop`.
- [ ] **Step 3: XS17 test.** The own columns of `Product`, less `id`, timestamps and soft-delete columns, share no name with the columns of `cms_contents` nor the fields of `ContentTranslation`. The failure message names the colliding names and XS17.
- [ ] **Step 4:** tests PASS, Pint, commit: `feat(shop): products in the Core extension registry, with company and publication indexed`.

---

### Task 8: A preset field never shadows an extender column (XS17)

**Files:**
- Modify: `Modules/Core/app/Models/Pivot/Fieldable.php` (attach), `Modules/Core/app/Models/Field.php` (rename), the extender contract of Task 5 (the entity its host rows use).
- Test: `Modules/Core/tests/Feature/Extensions/PresetFieldShadowingTest.php`.

- [ ] **Step 1: Test first.** Attaching a field named like an own column of an extender to a preset of that extender's entity is refused with a validation error naming the field, the extender and XS17; renaming a field attached to such a preset to such a name is refused; other names, and presets of other entities, are accepted.
- [ ] **Step 2: Implement** the check through the registry: which extender owns the preset's entity, and its own columns. Docblock and message per the Global Constraints.
- [ ] **Step 3:** tests PASS, Pint, commit: `feat(core): refuse a preset field that would shadow an extender column`.

---

### Task 9: Search and list of an extender through its host (XS1, XS2, XS10)

**Files:**
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`search()`), `Modules/Core/app/Search/Services/AdvancedSearchService.php` (the host exclusion replaced by the alias condition).
- Create: `Modules/Core/app/Search/Extensions/ExtenderFilterTranslator.php`.
- Test: `Modules/Core/tests/Integration/Search/ExtenderSearchTest.php` with the Task 5 stubs on the database engine.

- [ ] **Step 1: Test first.** `/search` of an extender: permission and ACL of the extender; the engine runs on the host index with `<type column> = <alias>` in place of the exclusion; hits reload as extenders by key under the extender ACL, in engine order; a filter on a mounted attribute reaches the host field, on an indexed own column `extension.<column>`, on a non-indexed own column answers 400; an ACL condition that cannot be translated still holds at reload. Basic, Orchestrated and Auto agree. `/select` of an extender is unchanged.
- [ ] **Step 2: Implement.** `CrudService::search()` branches on `IExtendsModel` before the `Searchable` check; the translator maps filters and ACL by XS10.
- [ ] **Step 3:** tests PASS, Pint, commit: `feat(core): search an extender through the index of its host`.

---

### Task 10: `with_extended` on `/select` and `/search` of a host (XS3 to XS6, XS12)

**Files:**
- Modify: `Modules/Core/app/Http/Requests/ListRequest.php` (rule `with_extended` boolean), `Modules/Core/app/Casts/ListRequestData.php` (property), `Modules/Core/app/Services/Crud/CrudService.php` (`list()`, `search()`), `Modules/Core/app/Services/Authorization/AuthorizationService.php` if the per-alias ACL needs a helper.
- Test: `Modules/Core/tests/Integration/Search/MixedHostListTest.php` with the Task 5 stubs.

- [ ] **Step 1: Test first.** On a host, `with_extended=true` adds the rows of the aliases whose table the user may `select`, and none other; an extended row also passes the ACL of its own entity (a user restricted on the extender sees only those rows); rows are upcast and carry `extended_type`; requested columns and relations apply where the row's model has them; the exclusion is replaced, not joined. The flag is ignored on a model that is not a host and on an extender. Same rows for `/select` (SQL) and `/search` (engine), on Basic, Orchestrated and Auto.
- [ ] **Step 2: Implement.** `/select`: `withExtended()` plus `type IS NULL OR (type = alias AND EXISTS extender row under its ACL)` per allowed alias, the host ACL on every row, then the upcast. `/search`: the same type condition as an engine filter, each alias branch carrying the translated extender ACL (Task 9), reload under both ACLs, then the upcast.
- [ ] **Step 3:** extend `Modules/Core/tests/Integration/Search/SearchPathEligibilityContractTest.php` with a host and an extender: no path returns a row another path excludes.
- [ ] **Step 4:** tests PASS, Pint, commit: `feat(core): with_extended opens a host list to the extended rows the user may read`.

---

### Task 11: Public product visibility (XS9)

**Files:**
- Modify: `Modules/Shop/database/seeders/ShopDatabaseSeeder.php` (guest role grant and ACL on `shop_products`, following the guest grants of `Modules/CMS/database/seeders/CMSDatabaseSeeder.php`).
- Test: `Modules/Shop/tests/Feature/ProductPublicVisibilityTest.php`.

- [ ] **Step 1: Test first.** As the anonymous account: `/select` and `/search` of `shop/products`, and `cms/contents?with_extended=true`, return a product only when its content is in its validity window and `is_published_in_shop` is true; a draft, scheduled or expired content hides it whatever the flag, and `false` hides it whatever the content. A user restricted to one company sees only that company's products, in the product routes and in the mixed list.
- [ ] **Step 2: Implement** the grant and the ACL as column conditions (`valid_from`, `valid_to`, `is_published_in_shop`), never the `valid` scope. No approval condition (verified in the spec, XS9).
- [ ] **Step 3:** tests PASS, Pint, commit: `feat(shop): the public sees online products only`.

---

### Task 12: Documentation (spec §8)

**Files:**
- Modify: `Modules/CMS/docs/CONTENT_EXTENSION.md` ("Becoming a consumer", "Search", "Generic search excludes extended contents"), `Modules/Shop/docs/rag/MODULE.md` (search and list of products, `with_extended`, the XS17 test, the guest ACL), `Modules/Core/docs/rag/MODULE.md` (host and extender contracts, preset field refusal), `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` (constraints table: the host exclusion and `with_extended`), `Modules/Core/docs/CRUD_SYSTEM.md` or its RAG counterpart (the `with_extended` parameter).

- [ ] **Step 1:** document XS16 and XS17 with their reasons where they are enforced, per spec §8.
- [ ] **Step 2:** document the request parameter, the mixed list (`extended_type` per row, columns applied where present) and the extender search.
- [ ] **Step 3:** commit per repo: `docs(<module>): extended entity search and list`.
- [ ] **Step 4: Close.** A `## Delivery status (date)` section with its `**Documented in:**` line naming the module docs of Steps 1 and 2, every box resolved (`- [x]`, or `- [-]` with its reason), `status: completed` in the front matter.
