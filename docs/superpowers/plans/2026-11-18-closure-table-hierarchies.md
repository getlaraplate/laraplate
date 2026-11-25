---
created_on: 2026-11-18
module: core, cms
status: completed
---
# Hierarchies on a closure table. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** `Role`, `Taxonomy` (and its subclasses) and CMS `Comment` read and maintain their hierarchy through Core's `HasClosureTable`; the three `staudenmeir` packages leave the dependencies; reads are no slower anywhere and faster on the ancestor paths, measured.

**Architecture:** A plain Core class, `ClosureTableMaintainer`, owns every set-based statement on a closure table (insert a node, move a subtree, detach subtrees before a delete, rebuild). `HasClosureTable` declares the twelve hierarchy relations and calls the maintainer from model events. The three models switch to the trait in one task, with their closure tables in their create migrations. Owners whose rows cascade into a hierarchy at database level detach the subtrees first.

**Tech Stack:** PHP 8.5, Laravel 13, Pest. No new dependency; three removed.

**Spec:** `../specs/2026-11-18-closure-table-hierarchies-design.md`

## Delivery status (2026-11-24): shipped

**Documented in:** `Modules/Core/docs/rag/HIERARCHIES_DEVELOPER.md` (the model-facing guide: trait, migration helper, the twelve relations, bulk writes, the cascade guard, no hierarchy path), `Modules/Core/docs/rag/MODULE.md`, `Modules/Core/README.md`, `Modules/Core/docs/CRUD_SYSTEM.md` (*HasClosureTable (Tree)*), `Modules/CMS/docs/rag/MODULE.md` (*Category trees and comment threads (closure tables)*, with the `scout:reindex` note).

`Role`, `Taxonomy` (and its subclasses) and CMS `Comment` read and maintain their hierarchy through Core's `HasClosureTable` on closure tables, and the three `staudenmeir` packages are out of the dependencies and the lock. The performance gate was approved by the user on 2026-11-24 with the MySQL "crud tree: parents" median at +2.0 % (p95 -14.4 %, branch spread inside the baseline's); every other read case is faster on both drivers, with no more queries than the baseline. The numbers are in the *After* tables of Task 9 below (both drivers, r7, Xdebug off). Divergences from the plan and the spec:

- **No hierarchy path at all (user decision, 2026-11-24).** Laraplate stays headless: the dotted key `path` of each `/tree` node (`ClosurePaths`), the slug path of entities and taxonomies (`HasPath`, with `ids` and `full_name` on `Taxonomy`, `appendPaths`, `withAncestorsForPath`, `Place::countryPathSegment`) and `TreeCollection::withPaths()` were deleted, and the search document and mapping of a content lost their `path` fields. This changes the spec's "tree payload unchanged" constraint: nodes keep their signed `depth` but carry no `path`. An existing content index must be rebuilt after the upgrade with `scout:reindex` (not `scout:rebuild`, which skips indexes it judges in sync); the database search engine needs nothing. The Filament categories list lost its path column.
- **Batch category paths built, then removed.** On 2026-11-23 the indexer computed category paths in batch through `ClosurePaths` and the benchmark case followed it; the decision above deleted both, so the content case no longer measures that.
- **The content benchmark case is "50 contents indexed"** through the search indexer, not the plan's content page of URLs. The baseline column is the old code with its paths, so that case compares an indexer that builds paths with one that builds none: the real before and after, not a like-for-like engine comparison.
- **First measurements were replaced.** They ran with Xdebug on (`develop,debug,coverage`, `start_with_request=yes`); the After tables come from runs with `XDEBUG_MODE=off`, baseline and branch alternating 3 + 3, round r7.
- **MySQL 8.0.21 was measured on a dedicated throwaway database** (`laraplate_closure_bench`), never on an application database; a fresh install (`migrate:fresh` and the seeds) was proven on MySQL on both sides.
- **Tree loading skips the per-row select check** (Core 43cb65e8) and relies on `ensurePermission`; the review found it equivalent (that check never filters rows or fields).
- **`model:soft-deletes-remove` purges through master's `TrashedRowsPurger`**, not the plan's raw delete followed by `rebuildClosure()`: the purger force deletes each row through its model, so the model events keep the closure and `HierarchyOwnerObserver` cascades in step. The command no longer depends on `OwnerCascadeRegistry`; the two closure purge tests moved to `TrashedRowsPurgerTest`. The spec, a dated record, still describes the earlier design.
- **Shipped separately:** the test-environment hardening (`phpunit.xml` pinned away from any server database, guard reading `.env`) as getlaraplate/laraplate#71, and the batch-seeder test without Redis as getlaraplate/laracore#56 (the branch's duplicate was dropped at the master merge).
- **Writes are slower by design,** no gate: a subtree move rewrites the closure rows of the whole subtree (17 queries against 10; +72.3 % SQLite, +168.1 % MySQL for 200 nodes), a leaf insert adds its closure rows (+1 query).
- **Tests deleted with the path removal (user decision, 2026-11-24).** The constraint "tests are adapted, never deleted" gave way only for tests whose whole subject was removed: Core `tests/Integration/Helpers/HasPathTest.php` and its fixture `tests/Fixtures/HasPathStubModel.php` (the `HasPath` trait), Core `tests/Integration/Support/Hierarchy/ClosurePathsTest.php` (the dotted key paths of tree nodes) and CMS `tests/Feature/Search/ContentCategoryPathIndexingTest.php` (the category paths in the search document). The path cases inside other tests (model, tree, search document and mapping tests) were adapted to assert that no `path` is present, not deleted.
- **Upgrading an existing install.** The closure tables are created inside the create migrations of `vend_roles`, `core_taxonomies` and `cms_comments`, so `php artisan migrate` creates nothing on an install that already ran them and role, category and comment writes fail until the tables exist. Pre-stable, the supported path is `php artisan migrate:fresh --seed`; to keep data, create the tables with `MigrateUtils::closureTable()` and call `rebuildClosure()` on `Role`, `Category` and `Comment`. The exact code is in Core `docs/rag/HIERARCHIES_DEVELOPER.md`, section "Upgrading an existing install". The breaking changes of the branch (closure tables, `HasTypedRecursiveRelationships` removed, `staudenmeir` no longer pulled in by Core, `HasClosureTable` API removals) are in the `BREAKING CHANGE:` footer of the final fix commit of Core and of CMS.
- **Merge order.** Core, then CMS, then root; the root PR carries the bump of the Core and CMS pointers to the merged module commits and merges last, because the root lock no longer holds the `staudenmeir` packages that master Core and CMS still need.
- **Step 5 is not done here:** the three PRs (Core, CMS, root) open after the final whole-branch review, with the user's go-ahead.
- **`Modules/Core/docs/rag/PERFORMANCE_TOOLKIT.md`** (named by Task 9) needed only the removal of the `depth_cache` row, so it describes no hierarchy and is not a pointer above.

## Global Constraints

- Branch `refactor/closure-table-hierarchies` in `Modules/Core`, `Modules/CMS` and the root `laraplate` repo (lock file, PHPStan baseline, this plan). One PR per repo, merged in the order Core, CMS, root. Submodule pointers are bumped by the owner, never by an agent. No direct commit to `master`.
- Portable SQL only: Eloquent and the query builder, no recursive queries, no driver branches. MySQL rejects a `DELETE` whose subquery reads the table being deleted from (error 1093): select the pairs to delete first, then delete them by key in chunks.
- Closure table name: `{model_table}_closure` (`vend_roles_closure`, `core_taxonomies_closure`, `cms_comments_closure`). Columns `ancestor_id`, `descendant_id`, `depth` (unsigned small integer, `0` on the self row); primary key `(ancestor_id, descendant_id)`; index `(descendant_id, depth)`; both keys foreign keys to the model table, cascade on delete; no timestamps.
- Pre-stable schema: closure tables go into the existing create migrations; no alter migrations.
- Relation names visible to clients do not change. `QueryBuilder::RELATION_BLACK_LIST` and `Taxonomy::$hidden` stay as they are.
- A trashed, inactive or expired node in a chain hides only itself (today's behaviour). Role inheritance must not change.
- Tests are adapted, never deleted. Classes used by tests live in `tests/Stubs/` of their module, never inline.
- Every PHP file: `declare(strict_types=1);`. Pint from the laraplate root on an explicit file list: `vendor/bin/pint --format agent <files>`.
- Never run tests with a cached config. The benchmark on a server database uses a dedicated throwaway database, never the development one: `RefreshDatabase` wipes it.

## Review Focus

1. Moving a node under one of its own descendants: refused with an exception, nothing saved, closure unchanged (Task 2, Task 4).
2. Deleting a middle node whose children survive (`nullOnDelete`): the children become roots and their subtrees keep their internal links (Task 2, Task 4).
3. Eager loading a hierarchy relation on many parents at once (`with('siblings')`, `with('bloodline')`): each parent gets its own nodes, not the first parent's (Task 4).
4. A user hard-deleted from the bin whose comments have replies by other users: the replies become roots, closure equal to a rebuild (Task 7).
5. A trashed role between a user's role and its grandparent: the grandparent's permissions still flow, as today (Task 1, Task 5).

---

### Task 1: Baseline: benchmark and characterization tests

Written and run on today's code, before anything changes. Everything here passes now.

**Files:**
- Create: `Modules/CMS/tests/Integration/Performance/HierarchyBenchmarkTest.php`
- Create: `Modules/CMS/tests/Feature/Hierarchy/HierarchyCharacterizationTest.php`
- Modify: `Modules/Core/tests/Feature/Models/RoleTest.php`
- Modify: this plan (baseline numbers)

**Interfaces:**
- Consumes: `Modules\Core\Performance\BenchmarkRunner::run(callable $operation, int $iterations = 30, int $warmup = 3): BenchmarkReport` (duration and query stats).
- Produces: the baseline table in this plan, read by Task 9; the characterization tests, which must pass unchanged after Task 5 except where Task 5 says otherwise.

- [x] **Step 1: Write the benchmark.** Skipped unless `HIERARCHY_BENCHMARK=1`. Seeds: 5,000 categories six levels deep with `order_column` deliberately not following depth; 20,000 comments in threads up to five levels; a role chain of five with permissions at each level and a user holding the leaf role. Cases, each through `BenchmarkRunner::run()`: ACL resolution for that user (`AclResolverService`); a page of 50 contents with their category URLs; `CrudService::tree()` on a category with parents, children and both; creating a leaf comment; moving a category subtree of 200 nodes; deleting a middle category. Prints one line per case: case name, p50 and p95 duration, queries.
- [x] **Step 2: Run it on SQLite and on one server database (dedicated throwaway database).** Run: `HIERARCHY_BENCHMARK=1 php artisan test --compact Modules/CMS/tests/Integration/Performance/HierarchyBenchmarkTest.php` (second run with `DB_CONNECTION`/`DB_DATABASE` pointing at the throwaway database). Copy the numbers into the Baseline table below. (SQLite; server database deferred, see line under the table)
- [x] **Step 3: Write the characterization tests.**
  - `RoleTest`: `it('inherits permissions through a trashed middle role')`: roles A ← B ← C, B soft-deleted, permission only on A; `C->hasPermission(...)` and `getAllPermissions()` give the same results as without B trashed (record what today returns and assert it).
  - `HierarchyCharacterizationTest` on comments (a thread root, two children, a grandchild): `CrudService::tree()` with parents, children and both returns the same node ids, in the same order, each with today's `depth` (signed) and `path` values, asserted literally; with an ACL hiding one node, that node is absent and the others are unchanged.
  - Same file: a comment thread loaded with `with('descendants')` on two roots returns each root's own replies.
- [x] **Step 4: Run them; all pass on today's code.** Run: `php artisan test --compact Modules/Core/tests/Feature/Models/RoleTest.php Modules/CMS/tests/Feature/Hierarchy`
- [x] **Step 5: Commit** (Core: `test(core): characterize role inheritance through a trashed role`; CMS: `test(cms): baseline benchmark and characterization of hierarchies`; root: the plan with the baseline).

**Baseline (filled by Step 2):**

Re-measured with Xdebug off from 0623a60 (`XDEBUG_MODE=off`, 20 iterations, runs alternating with the branch, runs during which another test process ran repeated; median of the run p50s and p95s, spread of the run p50s in brackets). Last measured in the round "r7" of 2026-11-24, three runs per side on each driver (raw files `bench/r7sqlite-*`, `bench/r7mysql-*` of the task folder). MySQL 8.0.21 on a remote server (a dedicated throwaway database, `phpunit.bench-mysql.xml`): every query there is a network round trip of 4-6 ms with jitter of the same size, so the spreads are wide. The content case, "50 contents indexed" (formerly "page of 50 contents + category urls", then "50 contents indexed with category paths"), prepares 50 contents through the search indexer (`makeAllSearchableUsing()`, `makeSearchableUsing()`, `toSearchableArray()`); this checkout runs it as a local, uncommitted edit, through its own indexer, which still builds each category's slug path from its eager-loaded ancestors. The earlier rounds (r4 SQLite, r4 + r5 MySQL) are kept in the task folder.

| Case | Driver | p50 ms | p95 ms | Queries |
|---|---|---|---|---|
| acl resolution (5 roles) | sqlite | 1.35 [1.33-1.37] | 1.41 | 3 |
| 50 contents indexed | sqlite | 119.31 [108.60-122.88] | 127.66 | 72 |
| crud tree: parents | sqlite | 162.01 [153.46-168.97] | 169.45 | 14 |
| crud tree: children | sqlite | 148.47 [147.27-157.45] | 162.33 | 14 |
| crud tree: parents + children | sqlite | 189.28 [186.20-190.31] | 201.83 | 14 |
| create a leaf comment | sqlite | 3.07 [2.87-3.13] | 3.28 | 11 |
| move a 200-node category subtree | sqlite | 3.54 [3.26-3.64] | 3.71 | 10 |
| delete a middle category | sqlite | 2.40 [2.22-2.47] | 2.45 | 6 |
| acl resolution (5 roles) | mysql | 21.15 [20.49-21.20] | 26.62 | 3 |
| 50 contents indexed | mysql | 570.98 [561.57-656.18] | 829.15 | 72 |
| crud tree: parents | mysql | 242.56 [240.57-283.46] | 329.54 | 14 |
| crud tree: children | mysql | 272.20 [246.37-333.64] | 319.90 | 14 |
| crud tree: parents + children | mysql | 426.79 [421.70-474.44] | 526.86 | 14 |
| create a leaf comment | mysql | 67.13 [64.29-72.09] | 92.34 | 11 |
| move a 200-node category subtree | mysql | 62.32 [60.82-65.49] | 83.00 | 10 |
| delete a middle category | mysql | 36.28 [35.69-38.53] | 53.75 | 6 |

First measurement, Xdebug on (`develop,debug,coverage`, `start_with_request=yes`), not comparable (the content case under its old name and code):

| Case | Driver | p50 ms | p95 ms | Queries |
|---|---|---|---|---|
| acl resolution (5 roles) | sqlite | 6.77 | 6.96 | 3 |
| page of 50 contents + category urls | sqlite | 380.24 | 471.57 | 17 |
| crud tree: parents | sqlite | 1468.34 | 1744.19 | 14 |
| crud tree: children | sqlite | 1305.07 | 1666.05 | 14 |
| crud tree: parents + children | sqlite | 1717.87 | 1978.58 | 14 |
| create a leaf comment | sqlite | 11.48 | 12.15 | 11 |
| move a 200-node category subtree | sqlite | 17.75 | 18.59 | 10 |
| delete a middle category | sqlite | 11.58 | 14.59 | 6 |

Server database: measured on MySQL 8.0.21 from this commit, see the MySQL rows of the re-measured table above.

### Task 2: `ClosureTableMaintainer` and the migration helper

**Files:**
- Create: `Modules/Core/app/Support/Hierarchy/ClosureTableMaintainer.php`
- Modify: `Modules/Core/app/Helpers/MigrateUtils.php`
- Create: `Modules/Core/tests/Integration/Support/Hierarchy/ClosureTableMaintainerTest.php`

**Interfaces:**
- Produces:
  - `MigrateUtils::closureTable(string $modelTable, ?ConnectionInterface $connection = null): void`: creates `{modelTable}_closure` per Global Constraints.
  - `final readonly class ClosureTableMaintainer`, constructor `(ConnectionInterface $connection, string $table, string $closureTable, string $keyColumn = 'id', string $parentColumn = 'parent_id')`, and `static forModel(Model $model): self` (reads the model's connection, table, `getClosureTable()`, key and parent column names).
  - `insertNode(int|string $id, int|string|null $parentId): void`: self row plus one row per ancestor of the parent, one `insertUsing`.
  - `moveSubtree(int|string $id, int|string|null $newParentId): void`: throws `Modules\Core\Exceptions\HierarchyCycleException` when the new parent is the node or one of its descendants; otherwise unlinks the subtree from its old ancestors and inserts the cross product of the new parent's ancestors-and-self with the subtree.
  - `detachSubtreesOf(Closure $doomedKeys): void`: `$doomedKeys` adds to a query builder the selection of the keys of nodes about to be deleted. Removes every `(a, x)` where some doomed `d` has `(a, d)` in the closure and `(d, x)` with depth > 0.
  - `rebuild(): void`: truncates and refills level by level, set-based (roots, then each depth from the previous one), no query per node.
  - Every method works on the tables through the query builder, ignoring model scopes: trashed, inactive and expired rows stay in the closure.

- [x] **Step 1: Write the failing tests** on a temporary table pair created with the helper: `it('creates the closure table with its keys')` (columns, primary key, index via `Schema::getIndexes`/`getForeignKeys`); `it('inserts a node under its parent ancestors')`; `it('moves a subtree with all its descendants')` (rows of every descendant point at the new ancestors, depths correct); `it('refuses to move a node under its own descendant')` (exception, closure unchanged); `it('detaches the subtrees of nested doomed nodes without touching surviving links')` (tree `r → d1 → a → x`, `a → d2`, doomed `d1` and `d2`: `(a, x)` survives, `(r, x)` and `(r, a)` are gone); `it('rebuilds to the same rows as incremental maintenance')` (build a random tree of 300 nodes with inserts and moves, snapshot, rebuild, compare).
- [x] **Step 2: Run them; they fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Support/Hierarchy/ClosureTableMaintainerTest.php`
- [x] **Step 3: Implement the helper, the exception (sibling of the other Core exceptions) and the maintainer.** For the deletes in `moveSubtree` and `detachSubtreesOf`, select the pairs first, then delete in chunks (Global Constraints, MySQL 1093).
- [x] **Step 4: Run them; they pass.** Same command. Also run once on the throwaway server database from Task 1. (server database run deferred by controller ruling; first server run happens with the benchmark)
- [x] **Step 5: Pint and commit** (`feat(core): closure table maintainer and migration helper`).

### Task 3: The facet path refuses black-listed relations

**Files:**
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`resolveManyRelation`, around line 2250)
- Create: `Modules/Core/tests/Stubs/Crud/BlackListedManyRelationStubModel.php`
- Test: `Modules/Core/tests/Integration/Services/Crud/FacetBlackListTest.php`

**Interfaces:**
- Produces: `resolveManyRelation()` returns `null` when `QueryBuilder::isBlackListedPath($relation)` is true, so all three facet callers refuse it with their existing errors.

- [x] **Step 1: Write the failing test.** The stub declares `descendants(): BelongsToMany`. `it('refuses a facet on a black-listed relation')`: a facet with `relation = 'descendants'` is refused with the existing "is not a BelongsToMany/MorphToMany relation" `InvalidArgumentException`; a facet on a non-black-listed `BelongsToMany` of the same stub still works.
- [x] **Step 2: Run it; it fails.** Run: `php artisan test --compact Modules/Core/tests/Integration/Services/Crud/FacetBlackListTest.php`
- [x] **Step 3: Add the check in `resolveManyRelation()`.**
- [x] **Step 4: Run it and the existing facet tests; they pass.** Run: `php artisan test --compact --filter=facet Modules/Core/tests`
- [x] **Step 5: Pint and commit** (`fix(core): facets refuse black-listed relations`).

### Task 4: `HasClosureTable` rewritten

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasClosureTable.php`
- Create: relation classes under `Modules/Core/app/Models/Relations/` for the relations that are not `BelongsToMany`/`HasMany`/`BelongsTo` (builder's choice, see spec)
- Modify: `Modules/Core/tests/Integration/Helpers/HasClosureTableTest.php` (its tables created with `MigrateUtils::closureTable()`, no timestamps)
- Modify: `Modules/Core/tests/Stubs/ClosureTreeStubModel.php`

**Interfaces:**
- Consumes: `ClosureTableMaintainer` (Task 2).
- Produces, on any model using the trait, each with that declared return type:
  - `parent(): BelongsTo`, `children(): HasMany`;
  - `ancestors(): BelongsToMany`, `ancestorsAndSelf(): BelongsToMany` (depth ascending, closest first);
  - `descendants(): BelongsToMany`, `descendantsAndSelf(): BelongsToMany` (depth ascending);
  - `siblings()`, `siblingsAndSelf()`, `bloodline()`, `rootAncestor()`, `parentAndSelf()`, `childrenAndSelf()`: real relations, eager-loadable;
  - depth order applies before any order a global scope adds;
  - each node loaded through them exposes `depth` signed as in Task 1 (negative above, positive below, `0` self) and no `pivot` key in `toArray()`;
  - overridable `getParentKeyName(): string` (default `parent_id`) and `getClosureTable(): string` (default `{table}_closure`);
  - `static rebuildClosure(): void` (delegates to `rebuild()`);
  - `isRoot()`, `isLeaf()`, `isDescendantOf()`, `isAncestorOf()`, `isSiblingOf()`, `getDepth()` (no cache), `moveTo()` kept.
- Removed: `newCollection()` override, the depth cache, `bloodlineAndSelf()`, the `closure()` relation and its scopes if nothing uses them (grep; a `- [-]` with the reason if one stays).

- [x] **Step 1: Write the failing tests** in `HasClosureTableTest`: ancestors closest first under a global scope ordering by another column; a trashed middle node hides only itself; `with()` of each of the twelve relations on three parents returns each parent's own nodes; `depth` signed and no `pivot` in `toArray()`; saving with a new `parent_id` (plain `save()`, not `moveTo()`) moves the whole subtree; saving a parent inside the own subtree throws `HierarchyCycleException` and saves nothing; hard-deleting a middle node leaves its children as roots with their subtrees intact; the whole sequence ends equal to `rebuildClosure()`; the existing connection-affinity tests keep passing.
- [x] **Step 2: Run them; they fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Helpers/HasClosureTableTest.php`
- [x] **Step 3: Implement.** Events: `created` → `insertNode`; `updating` with a dirty parent → cycle check before saving; `updated` with a changed parent → `moveSubtree`; `deleting` (or `forceDeleting` on a soft-deletable model) → `detachSubtreesOf` the node itself. Each event body runs in a transaction the trait opens on the model connection. Soft delete and restore touch nothing.
- [x] **Step 4: Run them; they pass.** Same command, plus `Modules/Core/tests/Integration/Helpers/TreeCollectionTest.php`.
- [x] **Step 5: Pint and commit** (`refactor(core): closure table trait with the full hierarchy relations`).

### Task 5: Switch the three hierarchies

One task: the models, their tables and `CrudService::tree()` change together, or the tree endpoint breaks in between.

**Files:**
- Modify: `Modules/Core/database/migrations/2024_03_15_224941_create_permission_tables.php`, `Modules/Core/database/migrations/2024_11_28_225853_create_taxonomies_table.php`, `Modules/CMS/database/migrations/2026_05_15_100000_create_cms_comments_table.php` (closure table after the model table; dropped first in `down()`)
- Modify: `Modules/Core/app/Enums/CoreTables.php` (`RolesClosure = 'vend_roles_closure'`, `TaxonomiesClosure = 'core_taxonomies_closure'`), `CMSTables` (`CommentsClosure = 'cms_comments_closure'`)
- Modify: `Modules/Core/app/Models/Role.php`, `Modules/Core/app/Models/Taxonomy.php`, `Modules/CMS/app/Models/Comment.php` (`HasClosureTable` instead of `HasTypedRecursiveRelationships`)
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`tree()` around lines 785-850, `useRecursiveRelationships()` around line 2969: detects `HasClosureTable`)
- Modify tests: `Modules/Core/tests/Integration/Services/CrudServiceTest.php`, `Modules/Core/tests/Integration/Services/CrudServiceRequestScenariosTest.php` (anonymous tree models move to `Modules/Core/tests/Stubs/Crud/` with closure tables), `Modules/CMS/tests/Unit/Models/CategoryTest.php` (asserts `HasClosureTable`), `Modules/CMS/tests/Feature/Controllers/CoverPreloadTest.php` and `CategoryFacetParentTest.php` (set `parent_id` through Eloquent saves)
- Test: `Modules/CMS/tests/Feature/Models/CategoryTest.php` (chains), `Modules/CMS/tests/Integration/Models/ContentTest.php` (URLs)

**Interfaces:**
- Consumes: Task 4 relations; Task 1 characterization values.
- Produces: `/tree` payload identical to Task 1 for every mode.

- [x] **Step 1: Write the failing chain tests.** Categories root → child → grandchild with `order_column` values 3, 1, 2 (not following depth), loaded from the database (no `setRelation`): `full_name` is `root > child > grandchild`, `ids` is `root.child.grandchild` by key, and the content URL built from the grandchild has the segments in that order. (3, 1, 2 sorts the grandchild's ancestors closest first under the scope order, so it passes before the switch too; a second data row, 1, 3, 2, sorts them root first and is the one that fails before it)
- [x] **Step 2: Run them; they fail on today's code.** Run: `php artisan test --compact Modules/CMS/tests/Feature/Models/CategoryTest.php Modules/CMS/tests/Integration/Models/ContentTest.php`
- [x] **Step 3: Add the closure tables to the three create migrations with `MigrateUtils::closureTable()`, and the enum cases.**
- [x] **Step 4: Switch the three models to `HasClosureTable`.**
- [x] **Step 5: Rewrite `CrudService::tree()` for the closure relations.** Same `$tree_relation_type` names; the visible-keys `whereIn` qualifies the related key; `path` computed to the values pinned in Task 1; comments mentioning the recursive expression rewritten. `useRecursiveRelationships()` detects `HasClosureTable`.
- [x] **Step 6: Adapt the existing tests listed under Files.**
- [x] **Step 7: Run the suites; all pass, Task 1 characterization included.** Run: `php artisan test --compact Modules/Core/tests Modules/CMS/tests`, then `php artisan test --compact Modules/CRM/tests Modules/ERP/tests` (taxonomy subclasses). (`AclResolverServiceTest` set role parents with `saveQuietly()`, which bypasses the closure: saved with `save()` instead)
- [x] **Step 8: Check a fresh install.** Run: `php artisan migrate:fresh --seed` on the throwaway database; it completes, and `SELECT COUNT(*)` on each closure table is at least the row count of its model table. (run on a throwaway SQLite file: no local server database; roles 7 rows / 7 closure rows, taxonomies and comments seed none)
- [x] **Step 9: Pint and commit** (Core: `refactor(core): roles and taxonomies on the closure table`; CMS: `refactor(cms): comments on the closure table`).

### Task 6: `model:soft-deletes-remove` rebuilds the closure

**Files:**
- Modify: `Modules/Core/app/SoftDeletes/Console/ModelSoftDeletesRemoveCommand.php` (after the purge around line 65)
- Test: the command's existing test file (find it with `rg -l ModelSoftDeletesRemoveCommand Modules/Core/tests`)

- [x] **Step 1: Write the failing test** `it('rebuilds the closure after purging a hierarchical model')`: a stub model with `HasClosureTable` and soft deletes, a trashed middle node with a live child; after the command, closure equals `rebuildClosure()` on the remaining rows and the child has no link to the purged node's ancestors.
- [x] **Step 2: Run it; it fails.**
- [x] **Step 3: Call `rebuildClosure()` when the purged model uses `HasClosureTable`.**
- [x] **Step 4: Run it; it passes.**
- [x] **Step 5: Pint and commit** (`fix(core): purging soft deletes keeps the closure in step`).

### Task 7: Owners that cascade into a hierarchy

> **Scope change (2026-11-18, controller ruling):** the investigation found cascade chains the text below misses. Entity, Preset, Presettable and Template reach comments through the contents they take along, and Template reaches taxonomies through presets. Shop's product contents sit on Shop's own entity, which CMS cannot observe. `model:soft-deletes-remove` purges owner tables with no events. Writing in `deleting` is the pattern Task 4's review rejected. As built: `deleting` only reads the surviving children (`ClosureTableMaintainer::childrenLeftBy()`). `deleted` roots them (`rootParentless()`, via `moveSubtree($id, null)`). The cascades live in a Core `OwnerCascadeRegistry`, reached by `HierarchyOwnerObserver`, which is attached with `#[ObservedBy]` to User, Entity, Preset, Presettable and Template. Core registers taxonomies, CMS registers comments and attaches the observer to Content. The purge command rebuilds the registered closures. The guards derive the transitive chains from the schema.

**Files:**
- Modify: `Modules/CMS/app/Providers/CMSServiceProvider.php` (listeners on `User` and `Content` delete events)
- Modify: `Modules/Core/app/Models/Entity.php`, `Modules/Core/app/Models/Preset.php`, `Modules/Core/app/Models/Pivot/Presettable.php` (or the services that delete them: find every delete path with `rg -n "delete\(|detach\(" ` on them)
- Create: `Modules/Core/tests/Integration/Hierarchy/HierarchyCascadeGuardTest.php`, `Modules/CMS/tests/Integration/Hierarchy/CommentCascadeTest.php`

**Interfaces:**
- Consumes: `ClosureTableMaintainer::forModel()` and `detachSubtreesOf()` (Task 2).
- Produces: every owner delete path detaches the doomed hierarchy rows before the row goes: `User` (`forceDeleting`, it is soft-deletable) and `Content` (`deleting`) for comments by `user_id` / `content_id`; `Entity`, `Preset` and `Presettable` delete paths for taxonomies by `entity_id` / `presettable_id`. A path with no Eloquent event (a pivot `detach`) calls the maintainer explicitly where it deletes.

- [x] **Step 1: Write the failing tests.**
  - Guard, one per module for its own tables: for each hierarchy table, `Schema::getForeignKeys()` lists the keys with `on_delete = cascade` pointing at another table; the set must equal the handled set declared in the test (`core_taxonomies.entity_id`, `core_taxonomies.presettable_id`; `cms_comments.user_id`, `cms_comments.content_id`), so a new cascade fails the test until it is handled.
  - Behaviour, one per handled key: a thread whose root belongs to the owner and whose replies belong to others; force-delete the owner through its normal path (for a user, `HardDeleteCoordinator::purge()`); the replies are roots and the closure equals `rebuildClosure()`.
- [x] **Step 2: Run them; behaviour tests fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Hierarchy Modules/CMS/tests/Integration/Hierarchy`
- [x] **Step 3: Add the handlers.**
- [x] **Step 4: Run them; they pass.**
- [x] **Step 5: Pint and commit** (Core: `fix(core): taxonomy owners keep the closure in step`; CMS: `fix(cms): comment owners keep the closure in step`).

### Task 8: Remove the packages

**Files:**
- Modify: `Modules/Core/composer.json` (drop `staudenmeir/laravel-adjacency-list`)
- Delete: `Modules/Core/app/Models/Concerns/HasTypedRecursiveRelationships.php`
- Modify: `composer.lock`, `phpstan-baseline.neon` (entry naming `Staudenmeir\LaravelAdjacencyList\Eloquent\Collection`, around line 7174)

- [x] **Step 1: Remove the requirement and the trait; update the lock.** Run from the laraplate root: `composer update staudenmeir/laravel-adjacency-list staudenmeir/laravel-cte staudenmeir/eloquent-has-many-deep-contracts`
- [x] **Step 2: Check nothing is left.** Run: `/usr/bin/rg -n -i staudenmeir --glob '!vendor/**' --glob '!docs/superpowers/**' .` Expected: only the README line Task 9 replaces. `composer show | grep staudenmeir` prints nothing.
- [x] **Step 3: Remove the stale baseline entry and run static analysis.** Run: `vendor/bin/phpstan analyse --memory-limit=2G`. Expected: no new error; fix code, never add ignores.
- [x] **Step 4: Run the full Core and CMS suites.** Run: `php artisan test --compact Modules/Core/tests Modules/CMS/tests`
- [x] **Step 5: Commit** (Core: `chore(core): drop staudenmeir/laravel-adjacency-list`; root: `chore: lock without the staudenmeir packages`).

### Task 9: Performance gate, documentation, close

**Files:**
- Modify: this plan (After table, delivery status)
- Create: `Modules/Core/docs/rag/HIERARCHIES_DEVELOPER.md`
- Modify: `Modules/Core/README.md` (dependency list line 727), `Modules/Core/docs/CRUD_SYSTEM.md` (example around line 785), `Modules/Core/docs/rag/PERFORMANCE_TOOLKIT.md` (`depth_cache` row), `Modules/Core/docs/rag/MODULE.md` and `Modules/CMS/docs/rag/MODULE.md` where they describe role inheritance, category paths or comment threads
- Modify: `docs/superpowers/specs/2026-11-18-closure-table-hierarchies-design.md` (status), `docs/superpowers/plans/INDEX.md`

- [x] **Step 1: Rerun the Task 1 benchmark on both databases and fill the After table.** (SQLite and MySQL 8.0.21 rows are in, round r7 without hierarchy paths)
- [x] **Step 2: Apply the gate.** Every read case: p50 and p95 no higher than baseline beyond noise (5 %), queries no more; ACL resolution, content URLs and tree with parents faster. Writes recorded, no gate. If a read case fails, stop and report to the user with both tables; do not merge. (Gate approved by the user on 2026-11-24, accepting the MySQL "crud tree: parents" median at +2.0 %.)
- [x] **Step 3: Write the documentation.** `HIERARCHIES_DEVELOPER.md`: giving a model a hierarchy (trait, `MigrateUtils::closureTable()`, overridable column names), the twelve relations and their order, what the trait maintains, what a bulk writer must do (`rebuildClosure()` or `ClosureTableMaintainer::detachSubtreesOf()`), the cascade guard. The other files as listed.
- [x] **Step 4: Close the plan.** All boxes resolved; `status: completed`; `## Delivery status (date): ...` with divergences and `**Documented in:**` naming the docs above. Spec status `Implemented`. Run: `vendor/bin/pest --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`
- [ ] **Step 5: Commit and open the three PRs** (Core, CMS, root), bodies listing the gate numbers.

**After (filled by Step 1):**

Branch at Core 0e819769 and CMS b762fc6, which compute no hierarchy path (user decision of 2026-11-24: Laraplate stays headless; see below), measured in the same alternating r7 runs as the baseline above (Xdebug off; median of the run p50s and p95s, spread of the run p50s in brackets):

| Case | Driver | p50 ms | p95 ms | Queries |
|---|---|---|---|---|
| acl resolution (5 roles) | sqlite | 1.29 [1.27-1.31] | 1.40 | 3 |
| 50 contents indexed | sqlite | 66.58 [66.29-67.18] | 69.88 | 66 |
| crud tree: parents | sqlite | 134.77 [134.13-135.67] | 139.49 | 14 |
| crud tree: children | sqlite | 135.05 [134.47-144.41] | 139.63 | 14 |
| crud tree: parents + children | sqlite | 166.14 [165.16-178.47] | 173.99 | 14 |
| create a leaf comment | sqlite | 3.14 [3.09-3.24] | 3.25 | 12 |
| move a 200-node category subtree | sqlite | 6.10 [5.96-6.41] | 6.44 | 17 |
| delete a middle category | sqlite | 1.95 [1.95-2.07] | 1.98 | 6 |
| acl resolution (5 roles) | mysql | 19.95 [18.49-20.55] | 26.78 | 3 |
| 50 contents indexed | mysql | 489.06 [480.51-522.89] | 589.62 | 66 |
| crud tree: parents | mysql | 247.33 [235.12-265.20] | 282.04 | 14 |
| crud tree: children | mysql | 255.52 [242.26-287.32] | 314.20 | 14 |
| crud tree: parents + children | mysql | 322.55 [299.03-372.94] | 419.12 | 14 |
| create a leaf comment | mysql | 81.97 [74.47-83.74] | 103.95 | 12 |
| move a 200-node category subtree | mysql | 167.05 [142.95-183.04] | 291.82 | 17 |
| delete a middle category | mysql | 39.70 [38.53-41.05] | 55.91 | 6 |

Median deltas against the baseline of the same runs:

| Case | Driver | Baseline p50 [spread] | Branch p50 [spread] | p50 delta | p95 delta | Queries baseline / branch |
|---|---|---|---|---|---|---|
| acl resolution (5 roles) | sqlite | 1.35 [1.33-1.37] | 1.29 [1.27-1.31] | -4.4 % | -0.7 % | 3 / 3 |
| 50 contents indexed | sqlite | 119.31 [108.60-122.88] | 66.58 [66.29-67.18] | -44.2 % | -45.3 % | 72 / 66 |
| crud tree: parents | sqlite | 162.01 [153.46-168.97] | 134.77 [134.13-135.67] | -16.8 % | -17.7 % | 14 / 14 |
| crud tree: children | sqlite | 148.47 [147.27-157.45] | 135.05 [134.47-144.41] | -9.0 % | -14.0 % | 14 / 14 |
| crud tree: parents + children | sqlite | 189.28 [186.20-190.31] | 166.14 [165.16-178.47] | -12.2 % | -13.8 % | 14 / 14 |
| create a leaf comment | sqlite | 3.07 [2.87-3.13] | 3.14 [3.09-3.24] | +2.3 % | -0.9 % | 11 / 12 |
| move a 200-node category subtree | sqlite | 3.54 [3.26-3.64] | 6.10 [5.96-6.41] | +72.3 % | +73.6 % | 10 / 17 |
| delete a middle category | sqlite | 2.40 [2.22-2.47] | 1.95 [1.95-2.07] | -18.8 % | -19.2 % | 6 / 6 |
| acl resolution (5 roles) | mysql | 21.15 [20.49-21.20] | 19.95 [18.49-20.55] | -5.7 % | +0.6 % | 3 / 3 |
| 50 contents indexed | mysql | 570.98 [561.57-656.18] | 489.06 [480.51-522.89] | -14.3 % | -28.9 % | 72 / 66 |
| crud tree: parents | mysql | 242.56 [240.57-283.46] | 247.33 [235.12-265.20] | +2.0 % | -14.4 % | 14 / 14 |
| crud tree: children | mysql | 272.20 [246.37-333.64] | 255.52 [242.26-287.32] | -6.1 % | -1.8 % | 14 / 14 |
| crud tree: parents + children | mysql | 426.79 [421.70-474.44] | 322.55 [299.03-372.94] | -24.4 % | -20.4 % | 14 / 14 |
| create a leaf comment | mysql | 67.13 [64.29-72.09] | 81.97 [74.47-83.74] | +22.1 % | +12.6 % | 11 / 12 |
| move a 200-node category subtree | mysql | 62.32 [60.82-65.49] | 167.05 [142.95-183.04] | +168.1 % | +251.6 % | 10 / 17 |
| delete a middle category | mysql | 36.28 [35.69-38.53] | 39.70 [38.53-41.05] | +9.4 % | +4.0 % | 6 / 6 |

The branch no longer computes paths. On 2026-11-24 the user decided that Laraplate stays headless: the dotted key `path` of each tree node (`ClosurePaths`) and the slug path of entities and taxonomies (`HasPath`, with the batch `Taxonomy::assignPaths()` the indexer used, and the `ids` and `full_name` accessors) were deleted, and the search document of a content and its mapping lost their `path` fields, so an existing content index must be rebuilt after the upgrade (CMS `docs/rag/MODULE.md`). This diverges from the spec's "tree payload unchanged": nodes keep their signed `depth` but carry no `path`. The baseline column is today's code with its paths, so the content case compares an indexer that builds category paths with one that builds none: that is the real before/after for the user, not a like-for-like engine comparison.

SQLite: every read case is faster on its median: ACL resolution -4.4 %, indexing -44.2 %, tree with parents -16.8 %, with children -9.0 %, parents + children -12.2 %; every branch spread is disjoint from the baseline's (ACL resolution 1.27-1.31 against 1.33-1.37, tree with children 134.47-144.41 against 147.27-157.45, parents + children 165.16-178.47 against 186.20-190.31). MySQL: ACL resolution -5.7 % (byte-identical queries on both sides, so this is the noise of the round trips), indexing -14.3 %, tree with children -6.1 %, parents + children -24.4 %; the tree with parents is +2.0 % on the median p50 (247.33 against 242.56 ms) with its p95 -14.4 % and the branch spread (235.12-265.20) inside the baseline's (240.57-283.46). By the 5 % wording of Step 2 that case passes (+2.0 % < 5 %) but is not "faster" as the gate asks for the tree with parents; ACL resolution on MySQL is faster by 5.7 % only within a spread as wide as the difference. Queries: every read case at or below the baseline: ACL 3/3, the three trees 14/14 (the trees with children no longer issue the extra closure read of the previous round, which fed the dotted paths), indexing 66 against 72 (no ancestor rows, no closure rows). Writes, no gate: the subtree move rewrites 200 x 3 closure rows (+72.3 % SQLite, +168.1 % MySQL, 17 queries against 10, inherent), the leaf comment inserts its closure rows (+1 query; +2.3 % SQLite, +22.1 % MySQL), the delete -18.8 % SQLite, +9.4 % MySQL (spreads 38.53-41.05 against 35.69-38.53, touching), same 6 queries. Step 2 was decided by the user on 2026-11-24, who approved the gate with the MySQL tree with parents at +2.0 %. The gate's literal wording (5 %, ACL, content URLs and tree with parents "faster") and the later rule of the user (no read case slower beyond the measured noise) give the same verdict on every case except the MySQL tree with parents, which is +2.0 % on the median, within both noise definitions, and not faster.
