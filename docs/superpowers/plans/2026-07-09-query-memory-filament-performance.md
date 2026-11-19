---
status: completed
---
# Query memory and Filament performance implementation plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close remaining P1/P2 items from `2026-07-09-large-dataset-query-patterns-design.md` for Core queries and Filament widgets.

**Architecture:** Apply Laravel iteration primitives (`lazy`, `chunk`, `cursor`) where full `get()` is unsafe; add eager loading and short cache where Filament lists/widgets repeat work. No new dependencies.

**Tech Stack:** Laravel 12, Eloquent, Filament 5, Pest

**Spec:** `docs/superpowers/specs/2026-07-09-large-dataset-query-patterns-design.md`

---

### Task 1: DatabaseEngine SQLite vector search — lazy iteration

**Files:**
- Modify: `Modules/Core/app/Search/Engines/DatabaseEngine.php` (`performSQLiteVectorSearch`)
- Test: `Modules/Core/tests/Feature/Search/DatabaseEngineSQLiteVectorSearchTest.php` (create)

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\Core\Models\ModelEmbedding;
use Modules\Core\Search\Engines\DatabaseEngine;
use Modules\Core\Tests\TestCase;

it('limits sqlite vector search without loading all embeddings into memory', function (): void {
    // Seed > limit embeddings; assert result count <= limit and method completes.
})->skip(fn (): bool => config('database.default') !== 'sqlite', 'SQLite only');
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/Core/tests/Feature/Search/DatabaseEngineSQLiteVectorSearchTest.php`
Expected: FAIL (full `get()` or missing early termination)

- [x] **Step 3: Replace `get()` with `lazy()` in `performSQLiteVectorSearch`**

Iterate with `lazy(100)`, compute similarity per row, collect top matches up to `$builder->limit ?? 10`, stop early when enough results above threshold.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact Modules/Core/tests/Feature/Search/DatabaseEngineSQLiteVectorSearchTest.php`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git add Modules/Core/app/Search/Engines/DatabaseEngine.php Modules/Core/tests/Feature/Search/DatabaseEngineSQLiteVectorSearchTest.php
git commit -m "perf(core): lazy sqlite vector search in DatabaseEngine"
```

---

### Task 2: HandleLicensesCommand listLicenses — lazy with eager load

**Files:**
- Modify: `Modules/Core/app/Console/HandleLicensesCommand.php` (`listLicenses`, ~line 118)
- Test: `Modules/Core/tests/Feature/Console/HandleLicensesCommandTest.php` (extend or create)

- [x] **Step 1: Write the failing test**

Assert `list` action completes with many licenses without loading all at once (mock or seed factory count + assert command exit 0).

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=HandleLicenses`
Expected: FAIL if still using `get()`

- [x] **Step 3: Change `listLicenses` to use `lazy(100)`**

```php
$licenses = License::with('user')->lazy(100);
```

Iterate for table output instead of mapping a full Collection.

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=HandleLicenses`
Expected: PASS

- [x] **Step 5: Commit**

```bash
git commit -m "perf(core): lazy listLicenses in HandleLicensesCommand"
```

---

### Task 3: HasClosureTable rebuild — chunk processing

**Files:**
- Modify: `Modules/Core/app/Helpers/HasClosureTable.php` (`rebuild`)
- Test: `Modules/Core/tests/Feature/Helpers/HasClosureTableRebuildTest.php` (create)

- [x] **Step 1: Confirm trait exists in Core checkout**

If absent, skip task and note in spec as not yet landed.

- [x] **Step 2: Write failing test** with hierarchical fixture > chunk size; assert rebuild completes.

- [x] **Step 3: Replace `get()->keyBy()` with `chunk(100)` or `lazy(100)`**

Process `insertClosures` per model without holding full tree in memory. Remove `with('children')` if not required per row.

- [x] **Step 4: Run test and commit**

```bash
git commit -m "perf(core): chunk HasClosureTable rebuild"
```

---

### Task 4: User permissions — cache hot path

**Files:**
- Modify: `Modules/Core/app/Models/User.php` (~line 276)
- Test: `Modules/Core/tests/Feature/Models/UserPermissionsTest.php` (extend or create)

- [x] **Step 1: Write test** asserting permissions resolved without repeated full-table queries when called twice.

- [x] **Step 2: Wrap `Permission::query()->get()->sort()` in `Cache::remember`** Done differently (2026-10-01): the superadmin branch reads Spatie's `PermissionRegistrar::getPermissions()` cache (flushed by Spatie on permission/role writes) sorted by name, instead of a new `Cache::remember` key.

Key e.g. `core.permissions.all`; TTL from config or 3600s; invalidate on permission CRUD if observers exist.

- [x] **Step 3: Run test and commit**

```bash
git commit -m "perf(core): cache all permissions for User accessor"
```

---

### Task 5: DynamicContentsService cache warm-up

**Files:**
- Modify: `Modules/Cms/app/Services/DynamicContentsService.php`
- Test: `Modules/Cms/tests/Feature/Services/DynamicContentsServiceTest.php` (extend or create)

- [-] **Step 1: Identify `rememberForever` blocks using `get()` on large queries** Superseded by `2026-07-13-global-performance-optimization.md` Task 4 (per-type memo buckets + generation-key invalidation in Core `DynamicContentsService`).

- [-] **Step 2: Use `lazy()` inside cache closure** for first warm-up when result set can exceed ~5k rows. Superseded by `2026-07-13-global-performance-optimization.md` Task 4 (per-type memo buckets + generation-key invalidation in Core `DynamicContentsService`).

- [-] **Step 3: Evaluate TTL** — replace `rememberForever` with bounded TTL if data changes often. Superseded by `2026-07-13-global-performance-optimization.md` Task 4 (per-type memo buckets + generation-key invalidation in Core `DynamicContentsService`).

- [-] **Step 4: Test warm-up + cache hit; commit** Superseded by `2026-07-13-global-performance-optimization.md` Task 4 (per-type memo buckets + generation-key invalidation in Core `DynamicContentsService`).

```bash
git commit -m "perf(cms): lazy load on DynamicContentsService cache warm-up"
```

---

### Task 6: CoreStatsWidget — lazy load and cache

**Files:**
- Modify: `Modules/Core/app/Filament/Widgets/CoreStatsWidget.php`
- Test: `Modules/Core/tests/Feature/Filament/CoreStatsWidgetTest.php` (create)

- [x] **Step 1: Write test** for widget stats array structure and cached second call.

- [x] **Step 2: Add lazy widget if supported**

```php
protected static bool $isLazy = true;
```

- [x] **Step 3: Cache `getStats()` body** — key `filament_dashboard_core_stats`, TTL 60s.

- [x] **Step 4: Run test and commit**

```bash
git commit -m "perf(core): lazy and cache CoreStatsWidget stats"
```

---

### Task 7: Verify Contents Resource eager loading

**Files:**
- Modify: `Modules/Cms/app/Filament/Resources/Contents/ContentResource.php` (if missing `with`)
- Test: existing Contents list test or new Filament feature test

- [x] **Step 1: Confirm `modifyQueryUsing` includes `with(['entity', 'preset', 'media'])`**

- [x] **Step 2: Add if missing; assert query count on list page ≤ 5 for 25 rows** Relations were already eager loaded (`presettable.preset`, `presettable.entity`, `media`, plus model defaults). The assertion is written as "the list query count does not grow from 1 to 25 rows" (`ContentResourceTest`), not "<= 5": a Livewire `loadTable` round trip spends about 14 queries on auth, hydration and settings whatever the row count.

- [x] **Step 3: Commit if changed**

```bash
git commit -m "perf(cms): eager load Contents Filament table relations"
```

---

### Task 8: Optional — ListModifications tab count cache

**Files:**
- Modify: `Modules/Core/app/Filament/Resources/Modifications/Pages/ListModifications.php`

- [x] **Step 1: Uncomment or add `Cache::remember` around grouped count query**

Use `config('core.filament.tabs_counts_ttl_seconds', 300)`.

- [x] **Step 2: Test tab badges still correct after cache**

- [x] **Step 3: Commit**

```bash
git commit -m "perf(core): cache modification tab badge counts"
```

---

## Already completed (no task)

- `CrudService` bulk update/delete/lock: `lazy(100)`
- `AfterLoginListener`: `first()` for available license
- `HandleLicensesCommand` license groups: `lazy(100)`
- `ListModifications::getTabs()`: single `groupBy` query
- `SearchEngineHealthTableWidget`: 300s cache on `getViewData()`
- Multiple Filament Resources: eager loads per `docs/filament-performance-recommendations.md`

## Final verification

- [x] Run `vendor/bin/pint --dirty`
- [x] Run `php artisan test --compact` on touched module tests
- [-] Manual: load Dashboard, Modifications, Contents — check query count / TTFB Manual TTFB check not run; the query-count guards in `WidgetsTest`, `ModificationResourceTest` and `ContentResourceTest` replace it.

## Delivery status (2026-10-01): shipped

**Documented in:** `Modules/Core/docs/ACL_SYSTEM.md` (superadmin permission list from the registrar cache), `Modules/Core/docs/rag/PERFORMANCE_TOOLKIT.md` (how to measure the query and memory work this plan did).

Most tasks landed with `2026-07-13-global-performance-optimization.md` and were never ticked here; they were checked against the code on 2026-10-01.

- Task 1: `DatabaseEngine::performSQLiteVectorSearch()` iterates `lazy(100)`; test lives at `Modules/Core/tests/Integration/Search/DatabaseEngineSQLiteVectorSearchTest.php`, not under `Feature/`.
- Task 2: `HandleLicensesCommand::listLicenses()` uses `License::with('user')->lazy(100)`.
- Task 3: the trait is `Modules/Core/app/Models/Concerns/HasClosureTable.php` (not `Helpers/`); `rebuild()` walks roots with `chunkById(100)` and no recursive eager load. Test in `Modules/Core/tests/Integration/Helpers/HasClosureTableTest.php`.
- Task 4 (done 2026-10-01): `User::getPermissionsViaRoles()` superadmin branch ran `Permission::query()->get()` on every call and `UserInfoResponse` ran `Permission::all()`. Both now use Spatie's `PermissionRegistrar::getPermissions()` cache, `sortBy('name')->values()`. No new cache key: Spatie already invalidates it on permission and role writes. Test in `Modules/Core/tests/Feature/Models/UserTest.php`.
- Task 5: dropped, superseded by the other plan's Task 4 (per-type memo buckets with generation invalidation).
- Task 6: `CoreStatsWidget` is lazy and caches its stats for 60s; tests are in `Modules/Core/tests/Feature/Filament/WidgetsTest.php`, not a new `CoreStatsWidgetTest.php`.
- Task 7: eager loads were already in place in `ListContents::getTableQuery()` and `ContentResource::table()`. The "<= 5 queries" target was replaced by a non-scaling assertion (1 row vs 25 rows, same query count). Removing all eager loads makes it fail (14 -> 86 queries).
- Task 8 (done 2026-10-01): the `Cache::remember` in `ListModifications::getTabs()` was commented out. It is back, with key `filament_core_modifications_tabs_{model}` and TTL `core.filament.tabs_counts_ttl_seconds`, the same as the CMS `HasRecords` tabs.

Regression found during the same audit and fixed here: `HorizonStatsWidget` read `getRecent()['pending'|'failed']`, keys Horizon never returns, so both stats were always 0. It now uses `JobRepository::countPending()` and `countRecentlyFailed()`, the same failed count as the Horizon dashboard.

Still open, not part of this plan: `CrudService` bulk `update()`, `delete()` and lock/unlock (`Modules/Core/app/Services/Crud/CrudService.php`, the three `->lazy(100)` calls) page with OFFSET over a query their own loop changes. A bulk delete of more than 100 keys removes page one, then the second page's OFFSET 100 skips the survivors. A bulk update or lock that changes a filtered column (ACL filter, lock or soft-delete scope) can skip rows the same way. `lazyById()` fixes it for single-key models; composite-key models need a decision first, so the code is unchanged.

Resolved 2026-11-19, no code change: the skip is not reachable. Every caller names exactly one record by its whole primary key. `ModifyRequest` refuses a list of keys (422: the key carries its model's scalar rules, and every key column is required), the AI `bulk_update`/`bulk_delete` tools call the service once per id, and a direct caller passing an array still reaches `Builder::where()`, which binds only the first value. The query matches at most one row, so no second page exists for an OFFSET to skip, for single and composite keys alike. `Modules/Core/tests/Feature/Controllers/CrudBulkModifyPagingTest.php` holds the invariant on the delete, update and lock routes: a change that lets a list of keys through fails it and has to replace `lazy(100)` with a key snapshot first.
