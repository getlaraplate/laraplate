---
created_on: 2026-11-18
module: core, cms
status: open
---
# Cache membership labels. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** CRUD read responses are served from cache without querying, labelled with everything they depend on, and invalidated only by the writes that can affect them, on every cache driver.

**Architecture:** One contract, `ICacheLabelIndex`, with a Redis membership index (ZSET per label, Lua), an in-memory twin for `array`, a version-counter fallback for every other driver, a null index, and a failover wrapper that stops caching while Redis is down. A resolver turns a CRUD request into labels before the query and row labels after it; `CrudResponseCache` looks before computing; one listener on model events, the Core pivot relations, Spatie events and the ACL observer flush labels after commit.

**Tech Stack:** PHP 8.5, Laravel 13, Pest, Redis (phpredis or predis through Laravel). No new dependency.

**Spec:** `../specs/2026-11-18-cache-membership-labels-design.md`

## Prerequisites

- getlaraplate/laracore#45 merged. Every Core task branches from Core `master` after it.

## Global Constraints

- Label strings: `table:{table}`, `row:{table}:{id}`, `user:{id}`, `role:{id}`, `crud` (every CRUD response), `acl` (ACL resolutions). Built only through `CacheLabel`.
- No label carries an app namespace: the Redis `cache` connection prefix (`{app}:cache:`) separates applications.
- Nothing stored through the index is `forever`. CRUD responses keep the current default TTL (`Repository::resolveDefaultCacheSeconds()`, `config('cache.duration')`); this plan raises no TTL.
- Every flush triggered by a write runs after the owning model's transaction commits (`$model->getConnection()->afterCommit()`; immediate outside a transaction). No `DB::` facade.
- On a cache hit the permission check of the operation still runs (`ensurePermission` and relation authorization).
- While Redis is unreachable under `failover`, CRUD responses are neither read from nor written to cache; the first successful Redis call after a failure flushes `crud` before anything else.
- Version counters: key `labelv:{label}`, missing reads as 0; a row label flush increments its table label.
- Tests that need Redis skip when `Redis::connection('cache')->ping()` throws, and carry `->group('redis')`.
- Pint from the laraplate root on explicit files (`.ai/rules/modules.md`).

## Review Focus

1. A new row on a paginated list: every cached page of that entity misses afterwards, not only page 1 (Task 8).
2. A guest and an authenticated user, or two guests with different `Accept-Language`, hitting the same URL never share a response (Task 7).
3. A response with a non-200 status (403, 422, 500) is never stored (Task 7).
4. Deleting, soft-deleting or restoring a record embedded through `belongsTo` invalidates the responses that embed it (Task 10).
5. A transaction that rolls back flushes nothing, one that commits flushes once (Task 8).

---

### Task 1: Labels vocabulary, contract, array and counter indexes

**Files:**
- Create: `Modules/Core/app/Cache/Labels/CacheLabel.php`, `CacheDependencies.php`, `CacheLookup.php`, `ArrayLabelIndex.php`, `VersionCounterLabelIndex.php`, `NullLabelIndex.php`
- Create: `Modules/Core/app/Contracts/ICacheLabelIndex.php`
- Test: `Modules/Core/tests/Integration/Cache/Labels/LabelIndexContractTest.php`, `CacheLabelTest.php`

**Interfaces:**
- Produces:
  - `final class CacheLabel`: `static table(string $table): string`, `row(string $table, int|string $id): string`, `user(int|string $id): string`, `role(int|string $id): string`, `all(): string` (`crud`), `acl(): string`, `isRow(string $label): bool`, `tableOfRow(string $label): string` (returns `table:{t}`).
  - `final readonly class CacheDependencies(list<string> $labels, list<string> $row_tables = [], bool $complete = true)`; `withLabels(string ...$labels): self`.
  - `final readonly class CacheLookup(string $key, bool $hit, mixed $value, int $started_at, CacheDependencies $dependencies)`; `$started_at` in microseconds.
  - `interface ICacheLabelIndex`: `lookup(string $key, CacheDependencies $dependencies): CacheLookup`; `store(CacheLookup $lookup, mixed $value, int $ttl, array $row_labels = []): bool`; `forget(CacheLookup $lookup): void`; `flush(string ...$labels): void`; `pruneStale(): int`; `supportsRowLabels(): bool`.

- [x] **Step 1: Write the failing tests.** `CacheLabelTest` pins the five string forms and `tableOfRow('row:core_users:5') === 'table:core_users'`. `LabelIndexContractTest` runs every case on `dataset('label indexes')` = `array` (`new ArrayLabelIndex()`) and `counters` (`new VersionCounterLabelIndex(new Repository(new ArrayStore()))`):
  - `it('misses, stores, then hits')`
  - `it('misses after one of its labels is flushed')`
  - `it('keeps the entry when an unrelated label is flushed')`
  - `it('stores nothing usable when a label was flushed after the lookup')`: lookup, flush one label, store, next lookup misses
  - `it('misses after its row label is flushed')`: dependencies `row_tables: ['core_users']`, stored with row label `row:core_users:5`, `flush('row:core_users:5')`, miss
  - `it('misses once the ttl passed')` with `$this->travel(61)->seconds()` on a 60 s entry
  - membership only (`->skip(fn () => ! $index->supportsRowLabels())`): `it('keeps the entry when an unrelated row is flushed')` (`row:core_users:7`) and `it('prunes expired memberships')` (`pruneStale()` returns 1)
  - `NullLabelIndex`: `it('never hits')`
- [x] **Step 2: Run** `vendor/bin/pest --compact Modules/Core/tests/Integration/Cache/Labels`. Expected: FAIL, classes missing.
- [x] **Step 3: Implement the vocabulary and the contract.**
- [x] **Step 4: Implement `ArrayLabelIndex`.** Values, memberships (`label => [key => expires_at]`) and last-flush times (`label => microseconds`) in arrays; time from `Carbon::now()`. `store()` returns false when any label of the lookup or any row label was flushed after `started_at`. Row labels are memberships like any other.
- [x] **Step 5: Implement `VersionCounterLabelIndex(Repository $cache)`.** `lookup()` reads `labelv:*` of `labels` plus `table:{t}` of every `row_tables` entry in one `many()`, sorts them, appends `|label=version` pairs to the key, reads it; `store()` puts under that resolved key with the TTL and ignores `$row_labels`; `flush()` increments each label's counter, a row label's table counter instead of its own; `supportsRowLabels()` false; `pruneStale()` returns 0. Store the resolved key in `CacheLookup::$key`.
- [x] **Step 6: Run** the same command. Expected: PASS.
- [x] **Step 7: Commit** `feat(cache): label index contract with in-memory and version-counter indexes`.

### Task 2: Redis membership index

**Files:**
- Create: `Modules/Core/app/Cache/Labels/RedisLabelIndex.php`
- Test: add `redis` to `dataset('label indexes')` in `LabelIndexContractTest.php`, conditional on Redis being reachable; `Modules/Core/tests/Integration/Cache/Labels/RedisLabelIndexTest.php`

**Interfaces:**
- Consumes: `ICacheLabelIndex`, `CacheLookup`, `CacheDependencies`, `CacheLabel` (Task 1).
- Produces: `final class RedisLabelIndex(RedisStore $store) implements ICacheLabelIndex`.

- [x] **Step 1: Write the failing tests.** Contract cases on the `redis` dataset entry (a store on the `cache` connection with a per-test prefix, flushed in `afterEach`), plus `it('flushes a label with more members than one chunk')` with 2500 members and `->group('redis')`.
- [x] **Step 2: Run** `vendor/bin/pest --compact Modules/Core/tests/Integration/Cache/Labels --group=redis`. Expected: FAIL (or SKIPPED without Redis: run it where Redis is up before ticking).
- [x] **Step 3: Implement.** Four Lua scripts, all keys under the store prefix:
  - lookup: `GET key` plus `TIME`, returns value and microseconds;
  - store: `HMGET labelflush <labels>`; if any is greater than `started_at`, return 0; else `SET key value EX ttl` and `ZADD label:{l} expires_at key` for each label;
  - flush: per label, `HSET labelflush l <TIME>`, `ZRANGE label:{l}` in chunks of 1000, `DEL` the members, `DEL label:{l}`;
  - prune: `SCAN` `label:*`, `ZREMRANGEBYSCORE -inf <TIME>`, and `HDEL labelflush` fields older than the longest TTL in use (`config('cache.duration')` max).
  Values are serialized as `RedisStore` does (`serialize`, numeric left raw).
- [x] **Step 4: Run** the command with Redis up. Expected: PASS.
- [x] **Step 5: Commit** `feat(cache): Redis membership label index`.

### Task 3: Index selection, failover degradation, measurement, commands

**Files:**
- Create: `Modules/Core/app/Cache/Labels/CacheLabelIndexFactory.php`, `FailoverLabelIndex.php`, `MeasuredLabelIndex.php`
- Create: `Modules/Core/app/Console/PruneCacheLabelsCommand.php`, `CacheLabelStatsCommand.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php` (singleton binding of `ICacheLabelIndex`, commands, schedule next to `ModelLockSweepCommand` at line 377)
- Test: `Modules/Core/tests/Integration/Cache/Labels/CacheLabelIndexFactoryTest.php`, `FailoverLabelIndexTest.php`, `Modules/Core/tests/Feature/Console/CacheLabelCommandsTest.php`

**Interfaces:**
- Consumes: Tasks 1-2.
- Produces: `CacheLabelIndexFactory::forStore(Repository $cache): ICacheLabelIndex`; `FailoverLabelIndex(ICacheLabelIndex $primary)`; `MeasuredLabelIndex(ICacheLabelIndex $inner, Repository $stats)`; `app(ICacheLabelIndex::class)` is the measured index of the default store; commands `cache:prune-labels`, `cache:label-stats`.

- [x] **Step 1: Write the failing tests.**
  - factory: `array` store → `ArrayLabelIndex`; `redis` → `RedisLabelIndex`; `file`, `database` → `VersionCounterLabelIndex`; `null` → `NullLabelIndex`; `failover` → `FailoverLabelIndex` around the index of its first store (`config('cache.stores.failover.stores')[0]`).
  - failover, with a primary that throws on demand: `it('misses and stores nothing while the primary throws')`; `it('flushes crud on the primary before the first call after recovery')` (assert the primary received `flush('crud')` before `lookup`).
  - commands: `cache:prune-labels` prints the pruned count; `cache:label-stats` lists, per label, flushes, and per first `table:` label, hits and misses, after a scripted sequence.
- [x] **Step 2: Run** `vendor/bin/pest --compact Modules/Core/tests/Integration/Cache/Labels Modules/Core/tests/Feature/Console/CacheLabelCommandsTest.php`. Expected: FAIL.
- [x] **Step 3: Implement.** `FailoverLabelIndex` catches `Throwable` from the primary, sets a degraded flag, returns a miss / `false` / no-op; the next call that succeeds runs `flush(CacheLabel::all())` first and clears the flag. `MeasuredLabelIndex` increments `labelstats:{metric}:{label}` on the stats repository (any driver) and keeps the label list under `labelstats:labels`. Schedule `cache:prune-labels` `->hourly()->onOneServer()`.
- [x] **Step 4: Run** the command. Expected: PASS.
- [x] **Step 5: Commit** `feat(cache): label index selection, failover degradation, prune and stats commands`.

### Task 4: Relation paths by use

**Files:**
- Modify: `Modules/Core/app/Services/Crud/QueryBuilder.php` (`requestedRelationPaths()` at line 195)
- Test: `Modules/Core/tests/Integration/Services/Crud/RequestedRelationPathsByUseTest.php`

**Interfaces:**
- Produces: `QueryBuilder::requestedRelationPathsByUse(Model $model, SelectRequestData $request_data, bool $lenient_filters = false, bool $include_columns = true): array{display: list<string>, membership: list<string>}`. `display`: `relations` and the relation part of plain dotted columns. `membership`: aggregates, computed columns, group by, sorts, filters. `requestedRelationPaths()` returns the deduplicated merge, unchanged for its callers.

- [x] **Step 1: Write the failing test** on `ACL` and `Role` requests: `relations=role` → `display: ['role']`; `filters` on `role.name` → `membership: ['role']`; both → `role` in both; the merge equals today's `requestedRelationPaths()` for the same requests.
- [x] **Step 2: Run** it. Expected: FAIL.
- [x] **Step 3: Implement** by splitting the existing collection in `requestedRelationPaths()`; guards and black-list handling unchanged.
- [x] **Step 4: Run** it and `vendor/bin/pest --compact Modules/Core/tests --filter=QueryBuilder`. Expected: PASS.
- [x] **Step 5: Commit** `refactor(crud): split requested relation paths into display and membership`.

### Task 5: Dependency resolver

**Files:**
- Create: `Modules/Core/app/Services/Crud/Cache/CrudCacheDependencyResolver.php`, `Modules/Core/app/Contracts/IDeclaresCacheDependencies.php`
- Create: `Modules/Core/tests/Support/Cache/AssertsCacheDependencies.php` (trait, namespace `Modules\Core\Tests\Support\Cache`)
- Test: `Modules/Core/tests/Integration/Services/Crud/CrudCacheDependencyResolverTest.php`, stubs under `Modules/Core/tests/Stubs/Cache/` (check `git ls-files Modules/Core/tests/Stubs/Cache` first)

**Interfaces:**
- Consumes: `requestedRelationPathsByUse()` (Task 4), `CacheLabel`, `CacheDependencies` (Task 1).
- Produces: `forRequest(Model $model, SelectRequestData $request_data, ?User $user, bool $single_record): CacheDependencies`; `rowLabels(Model $model, CacheDependencies $dependencies, mixed $data): list<string>`; `interface IDeclaresCacheDependencies { public function cacheDependencyTables(): array; }` (list of tables).

- [x] **Step 1: Write the failing tests.**
  - list of `ACL` with `relations=role`: labels contain `table:core_acls`, `crud`, `user:{id}`, `role:{id}` of the caller; `row_tables` = `['core_roles']`; `complete` true.
  - list of `Role` with `relations=permissions`: labels contain `table:core_permissions` and `table:` of the pivot (`$role->permissions()->getTable()`).
  - filter on `role.name`: `table:core_roles` in labels, not in `row_tables`.
  - detail of `ACL` 7: `table:core_acls` not in labels, `core_acls` in `row_tables`; `rowLabels()` on the loaded model returns `row:core_acls:7` and `row:core_roles:{role_id}`.
  - a translated stub adds its translations table; a stub implementing `IDeclaresCacheDependencies` adds its tables; a path through `morphTo` gives `complete: false`.
  - `hasOne` and `hasMany` segments give set labels, `belongsTo` gives a row table; a segment after a to-many segment keeps its own kind.
  - `AssertsCacheDependencies::assertCacheDependenciesCover(Closure $call, CacheDependencies $dependencies)` records the tables the call queries (`listen()` on the model's connection, table names parsed from `from`/`join` of the SQL) and fails naming each table with no `table:` label and no row table; its own test feeds it a call that reads an undeclared table and expects the failure. Every model opted into the cache by this plan's tests is checked with it once.
- [x] **Step 2: Run** it. Expected: FAIL.
- [x] **Step 3: Implement.** Walk each path with the relation instance of each segment (`$model->{$name}()`, names already guarded): `BelongsTo` → row table (display) or set label (membership); `MorphTo` → stop, `complete: false`, row labels after the query; `BelongsToMany`/`MorphToMany` → set labels of related and pivot table; `HasOneOrMany`, `HasOneOrManyThrough` → set labels of related (and through parent). Translations through `HasTranslations::translations()`. Scope labels from the user and its roles as `Repository::getKeyPartsFromUser()` collects them (guests are the anonymous user). `rowLabels()` walks the Eloquent models in `$data` (model, collection, paginator) and their loaded `BelongsTo`/`MorphTo` relations recursively; `CrudResult::$data` for list, detail, tree and history holds models: confirm, and if a method returns arrays, collect the models in `CrudService` before serialization.
- [x] **Step 4: Run** it. Expected: PASS.
- [x] **Step 5: Commit** `feat(crud): resolve cache labels of a CRUD read`.

### Task 6: Invalidator, model-event listener and pivot flushes

**Files:**
- Create: `Modules/Core/app/Cache/Labels/CacheLabelInvalidator.php`, `Modules/Core/app/Listeners/FlushCacheLabelsOnModelWrite.php`, `Modules/Core/app/Overrides/Relations/FlushesCacheLabelsOnMutation.php`
- Modify: `Modules/Core/app/Overrides/Relations/BelongsToMany.php`, `MorphToMany.php`, `Modules/Core/app/Providers/EventServiceProvider.php`, `Modules/Core/app/Cache/HasCache.php` (keeps `usesCache()`, `getCacheKey()`; `invalidateCache()` calls `flushTable`; boot hooks removed)
- Test: `Modules/Core/tests/Integration/Cache/Labels/CacheLabelInvalidatorTest.php`

**Interfaces:**
- Consumes: `ICacheLabelIndex`, `CacheLabel`.
- Produces: `CacheLabelInvalidator`: `flushTable(string $table, ?Model $owner = null): void`, `flushRecord(Model $model, bool $with_table = true): void`, `flushPivot(BelongsToMany $relation): void`, `flushUser(int|string $id, ?Model $owner = null): void`, `flushRole(int|string $id, ?Model $owner = null): void`, `flushLabels(?Model $owner, string ...$labels): void`. Every method defers to `$owner->getConnection()->afterCommit()` when an owner is given.

- [x] **Step 1: Write the failing tests** on a recording index: created → `table:`; updated, deleted, restored, forceDeleted → `table:` and `row:`; inside `$model->getConnection()->transaction()` nothing before commit, one flush after; rollback → none; `attach`, `detach`, `sync`, `toggle`, `updateExistingPivot` on `Role::permissions()` → `table:` of pivot, `core_roles`, `core_permissions`.
- [x] **Step 2: Run** it. Expected: FAIL.
- [x] **Step 3: Implement.** Listener on `eloquent.created: *`, `eloquent.updated: *`, `eloquent.deleted: *`, `eloquent.restored: *`, `eloquent.forceDeleted: *` (wildcard handler `handle(string $event, array $payload)`). In the relation classes alias the revision trait's mutators (`use OpensRevisionOnMutation { attach as attachWithinRevision; ... }`) and flush after each. `Cache::clearByEntity()` delegates to `flushTable`.
- [x] **Step 4: Run** it and `vendor/bin/pest --compact Modules/Core/tests --filter=Versioned`. Expected: PASS.
- [x] **Step 5: Commit** `feat(cache): flush labels on model events and pivot writes after commit`.

### Task 7: CRUD read path

**Files:**
- Create: `Modules/Core/app/Services/Crud/Cache/CrudResponseCache.php`
- Modify: `Modules/Core/app/Http/Controllers/CrudController.php` (`handleServiceCall()` at line 570 and the read actions calling it), `Modules/Core/app/Services/Crud/CrudService.php` (add `authorizeCachedRead()`), `Modules/Core/app/Cache/Repository.php` (remove `tryByRequest()`, `clearByRequest()`; move key building to `CrudResponseCache`)
- Test: `Modules/Core/tests/Feature/Crud/CrudResponseCacheTest.php`; adapt `Modules/Core/tests/Integration/Cache/RepositoryTest.php`

**Interfaces:**
- Consumes: Tasks 3, 5, 6.
- Produces: `CrudResponseCache::remember(CrudRequestData $request_data, Closure $compute, Closure $render, Closure $authorize): Response`, where `$compute(): CrudResult`, `$render(CrudResult): Response`, `$authorize(): void`; `CrudResponseCache::keyFor(Request $request): string` (path, sorted query, user, roles, token, `App::getLocale()`); `CrudService::authorizeCachedRead(CrudRequestData $requestData): void` (the `ensurePermission(..., 'select', ...)` and `authorizeRequestedRelations()` each read method runs first).

- [x] **Step 1: Write the failing feature tests** on a `HasCache` entity through its `/app/crud` routes:
  - second identical request runs no query on its table (`DB::listen` count through the model's connection)
  - permission removed straight in the pivot table (no event) → the cached URL answers 403
  - two guests with `Accept-Language: it` and `en` get their own responses; a guest and a user on one URL get their own
  - a 403/422 response is not stored; `no_cache=1` bypasses
  - a model without `HasCache` is never stored; with `complete: false` on a counter index it is not stored
- [x] **Step 2: Run** `vendor/bin/pest --compact Modules/Core/tests/Feature/Crud/CrudResponseCacheTest.php`. Expected: FAIL.
- [x] **Step 3: Implement.** Search is not routed through `CrudResponseCache` (author's call on the point the spec left open: its rows come from an index reindexed asynchronously, so a flush on write would let the cache refill from the old index); add a test that a second identical search still calls the service. Hit: `$authorize()`, return value. Miss: `$compute()`, `$render()`, store only status 200 with `rowLabels()` and the TTL of today. Failover degraded or `NullLabelIndex`: compute and render only.
- [x] **Step 4: Run** it and `vendor/bin/pest --compact Modules/Core/tests/Feature/Crud Modules/Core/tests/Integration/Cache`. Expected: PASS.
- [x] **Step 5: Commit** `feat(crud): look up the response cache before computing a read`.

### Task 8: Invalidation by entity, end to end

**Files:**
- Test: `Modules/Core/tests/Feature/Crud/CrudResponseCacheInvalidationTest.php`

- [ ] **Step 1: Write the tests**, each on `array` and on a `file` store (counters): creating a row → every cached page of the list misses (Review Focus 1); updating a row → its list and detail miss; writing another entity's table → still a hit; a committed transaction flushes once, a rolled-back one leaves the hit (Review Focus 5); attaching a permission to a role → the roles list with `relations=permissions` misses.
- [ ] **Step 2: Run** them. Expected: PASS (they pin Tasks 6-7); fix the owning task's code if not.
- [ ] **Step 3: Commit** `test(crud): response cache invalidation by entity on both index kinds`.

### Task 9: Permission and ACL triggers

**Files:**
- Create: `Modules/Core/app/Listeners/FlushCacheLabelsOnPermissionChange.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php` (set `permission.events_enabled` true in `register()`), `Modules/Core/app/Providers/EventServiceProvider.php`, `Modules/Core/app/Observers/AclObserver.php`, `Modules/Core/app/Services/AclResolverService.php` (resolutions stored through the index with label `acl`; `flushCache()` = `flush('acl')`; the `Cache::flush()` fallbacks removed)
- Test: `Modules/Core/tests/Feature/Crud/CrudResponseCachePermissionTest.php`

- [ ] **Step 1: Write the failing tests:** `givePermissionTo`/`revokePermissionTo`/`syncPermissions` on a role → `role:{id}` flushed; on a user → `user:{id}`; `assignRole`/`removeRole` → `user:{id}`; an ACL with `role_id` → `role:{role_id}` and `acl`; with `role_id` null → `table:` of its permission's entity and `acl`; no test path calls `Cache::flush()` (spy).
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3: Implement**, listening to `Spatie\Permission\Events\{RoleAttachedEvent, RoleDetachedEvent, PermissionAttachedEvent, PermissionDetachedEvent}`; read their payload from the installed package.
- [ ] **Step 4: Run** it and `vendor/bin/pest --compact Modules/Core/tests --filter=Acl`. Expected: PASS.
- [ ] **Step 5: Commit** `feat(cache): flush user, role and ACL labels on permission changes`.

### Task 10: Row labels end to end

**Files:**
- Test: `Modules/Core/tests/Feature/Crud/CrudResponseCacheRowLabelTest.php`

- [ ] **Step 1: Write the tests** on a cached list of `ACL` with `relations=role`: on `array`, updating a role not embedded → hit, updating the embedded role → miss; deleting, soft-deleting, restoring the embedded role → miss (Review Focus 4); on a `file` store, updating the role not embedded → miss (documented over-invalidation).
- [ ] **Step 2: Run** them. Expected: PASS; fix Task 5 or 6 if not.
- [ ] **Step 3: Commit** `test(crud): row-level invalidation of embedded records`.

### Task 11: Mass writes in CMS imports (laracms)

**Files:**
- Modify: `Modules/CMS/app/Import/Pipeline/ImportPipeline.php`
- Test: `Modules/CMS/tests/Integration/Import/ImportPipelineCacheFlushTest.php`

- [ ] **Step 1: Write the failing test:** one import run calls `CacheLabelInvalidator::flushTable()` once per table it wrote (contents, categories, contributors, tags, locations and their pivots), after the run.
- [ ] **Step 2: Run** it. Expected: FAIL.
- [ ] **Step 3: Implement**, collecting the tables during the run and flushing at its end.
- [ ] **Step 4: Run** it. Expected: PASS.
- [ ] **Step 5: Commit** in laracms `feat(import): flush cache labels of the tables an import wrote`.

### Task 12: Existing tag users on labels

**Files:**
- Modify: `Modules/Core/app/Http/Controllers/DocsController.php`, `SettingController.php`, `Modules/Core/app/Inspector/Inspect.php`, `Modules/Core/app/Console/{WarmCacheCommand,TranslationsCheckCommand,SwaggerGenerateCommand}.php`, `Modules/Core/app/Providers/{CoreServiceProvider,CommandListenerProvider}.php`, `Modules/Core/app/Services/Geocoding/AbstractGeocodingService.php`, `Modules/Core/app/Cache/Repository.php` (remove `getCacheTags()` once unused)
- Test: one case per caller in its existing test file

- [ ] **Step 1: Write the failing tests:** each value is served on its second read and gone after its invalidation: docs after `SwaggerGenerateCommand`, translations after `TranslationsCheckCommand`, inspector after a migration, the cron list after saving a `CronJob` (label `table:core_cron_jobs`), site configs after saving a `Setting` (label `table:core_settings`).
- [ ] **Step 2: Run** them. Expected: FAIL.
- [ ] **Step 3: Implement** with `lookup`/`store`/`flush` and labels (`table:` of the source table, or a named label `docs`, `translations`, `inspector`); TTL `config('cache.duration.long')` instead of `forever`.
- [ ] **Step 4: Run** them and the files of Step 1. Expected: PASS.
- [ ] **Step 5: Commit** `refactor(cache): move remaining tag users to labels`.

### Task 13: Documentation and closing

**Files:**
- Modify: `Modules/Core/docs/CRUD_SYSTEM.md` (rewrite "Response cache"), `Modules/Core/docs/rag/MODULE.md` (cache paragraph)
- Create: `Modules/Core/docs/rag/CACHE_LABELS_DEVELOPER.md`: labels, drivers table, what a module writing outside Eloquent must flush, the commands
- Modify: this plan (ticks, `status: completed`, `## Delivery status (date): ...`, `**Documented in:**`), `docs/superpowers/plans/INDEX.md`

- [ ] **Step 1: Write the docs** from the code as merged.
- [ ] **Step 2: Run** `vendor/bin/pest --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`. Expected: PASS.
- [ ] **Step 3: Run** the full Core suite `vendor/bin/pest --compact --parallel Modules/Core/tests` and the CMS import tests. Expected: PASS.
- [ ] **Step 4: Commit** `docs(cache): membership labels and the response cache`.
