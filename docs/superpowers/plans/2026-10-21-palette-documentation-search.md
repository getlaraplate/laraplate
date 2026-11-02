---
status: in-progress
---
# Palette Documentation Search Implementation Plan

> **Superseded in part (2026-11-02):** The `core.search.vector.model` setting it names no longer exists; the cache is keyed by `features.embeddings.serving_model`. Superseded by `../specs/2026-10-30-vector-search-owned-by-ai-design.md`: the AI module owns the vector profile and Core keeps no `search.vector.*` setting. This plan stays true about its moment.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the command palette a documentation-passages group through `GET /app/crud/search/{module}/documentation`, and make the palette's parallel search requests compute the query embedding once.

**Architecture:** A cache with a lock inside `EmbeddingService::embedText()` serves every caller (Core search through `SearchEmbedder`, documentation retrieval through `EmbedsDocumentationQuery`) without Core knowing it. A new retrieval-only route reuses `DocumentationService::retrieveForInApp()` with the access context and the module scope the assistant already uses. No embedding route, no change to Core.

**Tech Stack:** PHP 8.5, Laravel 12, `nwidart/laravel-modules`, Redis cache (atomic locks), Pest 4.

**Documented in:** `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` (section 6, query embedding cache and documentation search route), `Modules/AI/README.md` (configuration, `features.faq.search.rate_per_minute`).

**Spec:** `docs/superpowers/specs/2026-10-21-palette-documentation-search-design.md`. Consumer: `docs/superpowers/plans/2026-06-17-ai-assist.md` task 7, in the stack root repository.

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; classes `final`; explicit types; constructor promotion; no new dependency, no new base folder.
- Work is in `Modules/AI` (a nested repository): commit module files with `git -C Modules/AI`, docs and indexes with `git` from the laraplate root. Run tests from the laraplate root: `php artisan test --compact <path>`. Format with `vendor/bin/pint --format agent <explicit file list>` from the laraplate root, never inside `Modules/AI`.
- Core is not modified. The route is not exposed under `/api/v1`.
- Route: `GET /app/crud/search/{module}/documentation?q=`; `documentation` is a reserved entity name. `q` is trimmed, 3 to 200 characters.
- Gate: `AssistantCapabilities::help()` (`features.faq.enabled`). The chat switch (`features.chat.enabled`) and the chat provider do not gate the route.
- Query embedding cache: TTL 300 seconds, lock expiry 30 seconds, wait at most 5 seconds (`Lock::block()`, retry every 250 ms). Only the vector is cached, under a hashed key; the text is never stored. Cache or lock failure computes the vector as today.
- Rate limit: 60 requests per minute per signed-in user, named limiter `documentation-search`, key `ai.features.faq.search.rate_per_minute`.

## Review Focus

- A `q` of only whitespace, or one longer than 200 characters, answers 422 and computes no embedding (Task 4).
- A module path segment with uppercase letters, a dot, or an extra path segment (`CMS`, `cms.x`, `search/cms/documentation/extra`) never reaches the scope and answers 404 (Task 4).
- Two users with different permissions asking the same text share one cached vector but each gets only the passages their own access context allows (Tasks 1 and 3).
- The embedding provider or the documentation index is down: the route answers 503, not 500, and no lock is left held (Tasks 1 and 4).
- The same text typed with different spacing shares one cache key; text that differs only in case does not (Task 1).

---

### Task 1: Query embedding cache with lock

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/QueryEmbeddingCache.php`
- Modify: `Modules/AI/app/Services/EmbeddingService.php` (`embedText`, constructor)
- Test: `Modules/AI/tests/Unit/Embeddings/QueryEmbeddingCacheTest.php`

**Interfaces:**
- Produces: `QueryEmbeddingCache::__construct(?EmbeddingModelRegistry $registry = null, int $waitSeconds = 5)`; `QueryEmbeddingCache::remember(string $text, Closure $compute): array` where `$compute` is `Closure(): list<float>` and the result is `list<float>`. `EmbeddingService::__construct(?Closure $providerFactory = null, ?SplitterInterface $splitter = null, ?QueryEmbeddingCache $queryCache = null)`.

- [x] **Step 1: Write the failing tests** in `QueryEmbeddingCacheTest.php`, using `Sleep::fake()`:
  - `it('returns the cached vector without computing')`: a second `remember` with the same text does not call `$compute`.
  - `it('shares the key across spacing and not across case')`: `"ordini  aperti "` and `"ordini aperti"` hit one entry; `"Ordini aperti"` computes again.
  - `it('computes once for a caller that waits on the lock')`: hold `Cache::lock` on the key from the test, register `Sleep::whenFakingSleep` to store the vector in the cache, call `remember`; `$compute` is never called and the stored vector is returned.
  - `it('computes without the cache when the wait times out')`: lock held for the whole wait, nothing stored; `remember` returns the computed vector and does not throw.
  - `it('keys by the active embedding profile')`: with two keys of `ai.features.embeddings.models`, switching `core.search.vector.model` makes the next call compute again.
  - `it('stores only the vector')`: with the `array` cache store, no cached value or key contains the text.
  - `it('computes when the cache store throws')`: a repository whose `get` and `lock` throw; the vector is still returned.

- [x] **Step 2: Run to verify failure.** Run: `php artisan test --compact Modules/AI/tests/Unit/Embeddings/QueryEmbeddingCacheTest.php`. Expected: FAIL, class not found.

- [x] **Step 3: Implement `QueryEmbeddingCache`.** Normalize the text by trimming and collapsing whitespace (`/\s+/u` to one space), no case change. Key = `ai:query-embedding:` plus `sha256` of the JSON of `[profile->key, provider, serviceModel, dimensions, queryPrefix, normalized text]`, the profile from `EmbeddingModelRegistry::active()` (so a `withActive` override is respected). Flow: `Cache::get`; on miss `Cache::lock(key . ':lock', 30)->block($waitSeconds)`; read the cache again inside the lock before computing; store with TTL 300; release in `finally`. Catch `LockTimeoutException` and any cache exception and return `$compute()` uncached. Constants `TTL_SECONDS = 300`, `LOCK_SECONDS = 30`, `WAIT_SECONDS = 5`.

- [x] **Step 4: Run to verify pass.** Same command. Expected: PASS.

- [x] **Step 5: Write the failing `EmbeddingService` tests** in the same file's sibling `Modules/AI/tests/Unit/Embeddings/EmbeddingServiceQueryCacheTest.php`:
  - `it('embeds a query once for repeated calls')`: the provider closure counts calls; two `embedText('x')` calls count one.
  - `it('does not cache when a provider factory is injected')`: an injected factory is called on every `embedText`, because the active profile does not identify an injected provider.
  - `it('does not cache document embeddings')`: `embedDocument` calls the provider each time.

- [x] **Step 6: Wire `EmbeddingService::embedText`.** Delegate to `QueryEmbeddingCache::remember` only when `$this->providerFactory === null`; resolve the cache lazily with `app(QueryEmbeddingCache::class)` when none was injected. Leave `embedDocument` and `embedDocumentsBatch` untouched. Run both test files; expected PASS.

- [x] **Step 7: Commit.**

```bash
vendor/bin/pint --format agent Modules/AI/app/Ai/Embeddings/QueryEmbeddingCache.php Modules/AI/app/Services/EmbeddingService.php Modules/AI/tests/Unit/Embeddings/QueryEmbeddingCacheTest.php Modules/AI/tests/Unit/Embeddings/EmbeddingServiceQueryCacheTest.php
git -C Modules/AI add app/Ai/Embeddings/QueryEmbeddingCache.php app/Services/EmbeddingService.php tests/Unit/Embeddings
git -C Modules/AI commit -m "feat(ai): cache query embeddings with a lock so parallel searches embed once"
```

### Task 2: Access context for a search

**Files:**
- Modify: `Modules/AI/app/Services/Assistance/AssistantAccessContext.php` (`assertInAppContext`)
- Modify: `Modules/AI/app/Services/Assistance/AssistantAccessContextFactory.php`
- Test: `Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php`

**Interfaces:**
- Produces: `AssistantAccessContextFactory::forDocumentationSearch(User $authenticated_user): AssistantAccessContext`, profile `InAppAssistance`, `conversationId` null, tenant, locale and permissions resolved as `forInApp()` does. Throws `AuthorizationException` for a guest or an unresolvable tenant.

- [x] **Step 1: Write the failing tests** in the existing file:
  - `it('builds a search context with no conversation')`: tenant, locale and permissions come from the user, `conversationId` is null, profile is `InAppAssistance`.
  - `it('refuses the guest for a search context')`: `AuthorizationException`.
  - `it('still requires an owned conversation for the assistant')`: `forInApp()` with another user's conversation throws, as today.
  - `it('rejects a blank conversation id')`: constructing the in-app `AssistantAccessContext` with `''` throws `InvalidArgumentException`; the developer context still rejects any conversation id.

- [x] **Step 2: Run to verify failure.** Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php`. Expected: the new tests FAIL.

- [x] **Step 3: Implement.** In `assertInAppContext`, accept a null `conversationId` and keep rejecting a blank string. Extract the tenant, permissions and locale resolution of `forInApp()` into a private method shared by both factory methods; `forInApp()` keeps its ownership checks.

- [x] **Step 4: Run to verify pass.** Same command; also run `Modules/AI/tests/Unit/Ai/Rag/Retrieval/InAppDocumentationRetrievalTest.php`. Expected: PASS.

- [x] **Step 5: Commit** (`vendor/bin/pint --format agent` on the touched files first): `git -C Modules/AI commit -m "feat(ai): access context for a documentation search without a conversation"`.

### Task 3: Documentation search service

**Files:**
- Create: `Modules/AI/app/Services/Documentation/Search/DocumentationPassage.php`
- Create: `Modules/AI/app/Services/Documentation/Search/DocumentationSearch.php`
- Test: `Modules/AI/tests/Unit/Services/Documentation/DocumentationSearchTest.php`

**Interfaces:**
- Consumes: `AssistantAccessContextFactory::forDocumentationSearch(User): AssistantAccessContext` (Task 2); `AssistantScopeResolver::resolve(AssistantProfile, ?string): AssistantScope`; `DocumentationService::retrieveForInApp(string, AssistantAccessContext, ?AssistantScope): list<Document>`.
- Produces: `DocumentationPassage` (`label`, `reference`, `excerpt`, `?headingBreadcrumb`, `?module`; `static fromDocument(Document): ?self`; `toArray(): array<string, string>` omitting nulls). `DocumentationSearch::__construct(DocumentationService, AssistantAccessContextFactory, AssistantScopeResolver)` and `search(User $user, string $module, string $query): list<DocumentationPassage>`; a retrieval failure surfaces as `RuntimeException`.

- [x] **Step 1: Write the failing tests**, building `DocumentationService` with the existing `in_app_retrieval` seam as `InAppDocumentationRetrievalTest` does:
  - `it('maps a document to a passage')`: `label` is the source name, `reference` equals `label`, `excerpt` is cut at 300 characters, `heading_breadcrumb` and `module` come from the document metadata when present and are absent otherwise.
  - `it('skips a document with a blank source name')`.
  - `it('asks for the module scope')`: the scope handed to retrieval has `moduleKey` `cms` and `DocScope::Module`.
  - `it('uses each user's own access context')`: two users with different permissions reach retrieval with different `effectivePermissions`.
  - `it('lets a retrieval failure through as RuntimeException')`.

- [x] **Step 2: Run to verify failure.** Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/DocumentationSearchTest.php`. Expected: FAIL, class not found.

- [x] **Step 3: Implement both classes.** The mapping follows the documentation citation in `InAppAssistanceService::promptContext()` (source name as label, `Str::limit` 300). `search()` builds the context with `forDocumentationSearch`, the scope with `resolve(AssistantProfile::InAppAssistance, $module)`, calls `retrieveForInApp`, maps with `fromDocument` and drops nulls.

- [x] **Step 4: Run to verify pass.** Same command. Expected: PASS.

- [x] **Step 5: Commit** (Pint on the touched files first): `git -C Modules/AI commit -m "feat(ai): documentation search service returning citation-shaped passages"`.

### Task 4: The route

**Files:**
- Create: `Modules/AI/app/Http/Controllers/DocumentationSearchController.php`
- Create: `Modules/AI/app/Http/Requests/DocumentationSearchRequest.php`
- Modify: `Modules/AI/routes/web.php` (inside the `crud` group)
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php` (limiter `documentation-search`)
- Modify: `Modules/AI/config/config.php` (`features.faq.search.rate_per_minute` = 60)
- Test: `Modules/AI/tests/Feature/DocumentationSearchRouteTest.php`

**Interfaces:**
- Consumes: `DocumentationSearch::search(User, string, string): list<DocumentationPassage>` (Task 3); `AssistantCapabilities::help(): bool`.
- Produces: named route `ai.crud.documentation.search`, `GET /app/crud/search/{module}/documentation`, middleware `auth` and `throttle:documentation-search`, registered before Core's `search/{module}/{entity?}`.

- [x] **Step 1: Write the failing tests** (Feature, `RefreshDatabase`):
  - `it('refuses an anonymous call and the guest')`: 401 and 403, as `CapabilitiesController` does.
  - `it('answers as a disabled feature when help is off')`: copy the disabled-feature response the assistant routes give (`grep -rn FEATURE_DISABLED Modules/AI/app` for the code and status).
  - `it('answers 404 for an unknown or disabled module')`: `nosuch`, and a module present on disk but disabled.
  - `it('answers 404 for a malformed module')`: `CMS`, `cms.x`; `search/cms/documentation/extra` is not matched.
  - `it('answers 422 for a short, blank or overlong q and computes no embedding')`: spy on `EmbeddingService`, assert no `embedText` call.
  - `it('returns the passages in the response envelope')`: a fake `DocumentationSearch` bound in the container; body `data` is the `toArray()` list.
  - `it('passes the path module as the scope')`.
  - `it('answers 503 when retrieval is unavailable')`: `RuntimeException` from the service.
  - `it('is rate limited per user')`: set `ai.features.faq.search.rate_per_minute` to 2; the third call answers 429.
  - `it('resolves search/{module}/documentation to the AI controller and leaves the others to Core')`: `Route::getRoutes()->match(Request::create('/app/crud/search/cms/documentation'))` has this controller; `/app/crud/search/cms/contents` still resolves to `CrudController@search`.
  - `it('reserves the name documentation')`: for every enabled module, `DynamicEntity::tryResolveModel('documentation', null, $module)` is null.
  - `it('is not exposed under api/v1')`: no registered route whose URI starts `api/v1` ends in `documentation`.

- [x] **Step 2: Run to verify failure.** Run: `php artisan test --compact Modules/AI/tests/Feature/DocumentationSearchRouteTest.php`. Expected: FAIL, route not found.

- [x] **Step 3: Implement the request, controller, route and limiter.** The request trims `q` and validates `required|string|min:3|max:200`. The controller checks, in order: authenticated user, not guest, `help()`, the module (`preg_match('/^[a-z][a-z0-9_]{0,63}$/')` then `Module::isEnabled(Str::studly($module))`, as Core's `ApplicationContentRetrievalService` does); then calls `DocumentationSearch::search`, answers through `ResponseBuilder`, and maps `RuntimeException` to 503. The route is `Route::get('search/{module}/documentation', ...)->name('documentation.search')->middleware(['auth', 'throttle:documentation-search'])` with a `where` of `[a-z][a-z0-9_]{0,63}` on `module`, declared before any generic `search` route of the group. The limiter follows `RateLimiter::for('aggregate', …)` in `CoreServiceProvider`: per signed-in user, ceiling read from config when a request arrives. *(Note: the `where()` on `{module}` was not applied; the controller's pattern answers 404. See Delivery status.)*

- [x] **Step 4: Run to verify pass.** Same command. Expected: PASS. Then `php artisan route:list --path=app/crud/search --sort=definition` and confirm the AI route is listed before `search/{module}/{entity?}`.

- [x] **Step 5: Commit** (Pint on every touched file first): `git -C Modules/AI commit -m "feat(ai): documentation search route for the command palette"`.

### Task 5: Documentation, indexes and handoff

**Files:**
- Modify: `Modules/AI/docs/SEARCH_AND_TRANSLATION.md` (query embedding cache and the route)
- Modify: `Modules/AI/README.md` (config key `ai.features.faq.search.rate_per_minute`)
- Modify: `docs/superpowers/specs/2026-10-21-palette-documentation-search-design.md` (status), `docs/superpowers/specs/INDEX.md`, `docs/superpowers/plans/INDEX.md`, this plan (delivery status and `**Documented in:**`)
- Modify (stack root repository): `docs/superpowers/plans/2026-06-17-ai-assist.md` (dependency row of the palette endpoint, Task 7 note on `features.help` and the real route)

- [x] **Step 1: Document the behavior** in the two module docs: the route, its gate, the module scope, the 60 per minute limit, the cache (TTL, lock, what is stored), and that `documentation` is a reserved entity name.

- [ ] **Step 2: Measure the saving.** With Elasticsearch and the embedding service running, send three parallel requests with the same `q` to `search/cms/contents`, `search/core/media` and `search/cms/documentation`; count the `/embed` requests in the embedding service log. Expected: one. Record the result in the delivery status.

- [x] **Step 3: Close the records.** Add the `## Delivery status` section and the `**Documented in:**` line to this plan, set the spec status to Delivered, add this plan to `plans/INDEX.md`, and update the spec's INDEX row.

- [x] **Step 4: Hand off to the stack plan.** In the stack repository, replace the "not specified" palette endpoint dependency in `2026-06-17-ai-assist.md` with the route above and add `help` to the `features` type of the UI client (`laraplate-ui/packages/core/src/types/ai.ts`) as a follow-up for its Task 7.

- [x] **Step 5: Run the AI module tests touched by this plan and `tests/Unit/ClosedPlansPointToDocumentationTest.php`.** Expected: PASS. Commit docs in the laraplate repository and the handoff in the stack repository.

## Progress (2026-10-21): built, one step open

Built in `Modules/AI` (commits on its master):

- Task 1: `QueryEmbeddingCache` (TTL 300 s, lock 30 s, wait 5 s, vector only under a sha256 key, failures compute uncached) used by `EmbeddingService::embedText()` when no provider factory is injected.
- Task 2: `AssistantAccessContextFactory::forDocumentationSearch()`; the in-app context accepts a null conversation id.
- Task 3: `DocumentationSearch` and `DocumentationPassage`.
- Task 4: `GET /app/crud/search/{module}/documentation?q=`, `DocumentationSearchController`, `DocumentationSearchRequest`, limiter `documentation-search`, config `ai.features.faq.search.rate_per_minute` = 60.
- Task 5: module docs (steps 1, 3, 4, 5).

Divergences from the plan:

- No `where()` constraint on `{module}`: the controller's pattern answers 404 for a malformed module. The route therefore takes any segment before Core's `search/{module}/{entity?}`.
- GET only: a POST to the same path still reaches Core. Resolved afterwards (2026-10-21): the route is now `Route::match(['get', 'post'], ...)`, same middleware, name and position, and `q` is read with `input('q')`.
- Added afterwards (2026-10-21): the query embedding cache key includes the signed-in user (shared partition when none).
- The gate is `AssistantCapabilities::help()` (`features.faq.enabled`); a disabled feature answers `403 FEATURE_DISABLED`, as the assistant routes do.
- The limiter is route middleware that runs after `auth` and before the controller, so every authenticated call counts against the 60 per minute whatever its outcome (403, `FEATURE_DISABLED`, 404, 422, 503, 200), not only a call with an invalid `q`. The controller checks guest, feature and module before validating `q`.
- Plan Task 5 step 3 said the spec would be set to Delivered; it is set to "Built 2026-10-21 (one measurement pending)" because the measurement is still open.

Pending, owner (Task 5 step 2, left `- [ ]`): measure the embedding saving. With Elasticsearch and the embedding service running and a signed-in session, note the number of `/embed` lines in the embedding service log, send three parallel requests with the same `q` (3 or more characters, not used in the last 5 minutes) to `GET /app/crud/search/cms/contents`, `GET /app/crud/search/core/media` and `GET /app/crud/search/cms/documentation`, then count the new `/embed` lines. Expected: one. Record the count here and tick the step; the plan then closes with `status: completed`.
