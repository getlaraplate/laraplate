---
created_on: 2026-11-18
module: core, cms
status: open
---
# Hierarchies on a closure table. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** `Role`, `Taxonomy` (and its subclasses) and CMS `Comment` read and maintain their hierarchy through Core's `HasClosureTable`; the three `staudenmeir` packages leave the dependencies; reads are no slower anywhere and faster on the ancestor paths, measured.

**Architecture:** A plain Core class, `ClosureTableMaintainer`, owns every set-based statement on a closure table (insert a node, move a subtree, detach subtrees before a delete, rebuild). `HasClosureTable` declares the twelve hierarchy relations and calls the maintainer from model events. The three models switch to the trait in one task, with their closure tables in their create migrations. Owners whose rows cascade into a hierarchy at database level detach the subtrees first.

**Tech Stack:** PHP 8.5, Laravel 13, Pest. No new dependency; three removed.

**Spec:** `../specs/2026-11-18-closure-table-hierarchies-design.md`

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

Server database: pending, measured later from this commit.

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

- [ ] **Step 1: Remove the requirement and the trait; update the lock.** Run from the laraplate root: `composer update staudenmeir/laravel-adjacency-list staudenmeir/laravel-cte staudenmeir/eloquent-has-many-deep-contracts`
- [ ] **Step 2: Check nothing is left.** Run: `/usr/bin/rg -n -i staudenmeir --glob '!vendor/**' --glob '!docs/superpowers/**' .` Expected: only the README line Task 9 replaces. `composer show | grep staudenmeir` prints nothing.
- [ ] **Step 3: Remove the stale baseline entry and run static analysis.** Run: `vendor/bin/phpstan analyse --memory-limit=2G`. Expected: no new error; fix code, never add ignores.
- [ ] **Step 4: Run the full Core and CMS suites.** Run: `php artisan test --compact Modules/Core/tests Modules/CMS/tests`
- [ ] **Step 5: Commit** (Core: `chore(core): drop staudenmeir/laravel-adjacency-list`; root: `chore: lock without the staudenmeir packages`).

### Task 9: Performance gate, documentation, close

**Files:**
- Modify: this plan (After table, delivery status)
- Create: `Modules/Core/docs/rag/HIERARCHIES_DEVELOPER.md`
- Modify: `Modules/Core/README.md` (dependency list line 727), `Modules/Core/docs/CRUD_SYSTEM.md` (example around line 785), `Modules/Core/docs/rag/PERFORMANCE_TOOLKIT.md` (`depth_cache` row), `Modules/Core/docs/rag/MODULE.md` and `Modules/CMS/docs/rag/MODULE.md` where they describe role inheritance, category paths or comment threads
- Modify: `docs/superpowers/specs/2026-11-18-closure-table-hierarchies-design.md` (status), `docs/superpowers/plans/INDEX.md`

- [ ] **Step 1: Rerun the Task 1 benchmark on both databases and fill the After table.**
- [ ] **Step 2: Apply the gate.** Every read case: p50 and p95 no higher than baseline beyond noise (5 %), queries no more; ACL resolution, content URLs and tree with parents faster. Writes recorded, no gate. If a read case fails, stop and report to the user with both tables; do not merge.
- [ ] **Step 3: Write the documentation.** `HIERARCHIES_DEVELOPER.md`: giving a model a hierarchy (trait, `MigrateUtils::closureTable()`, overridable column names), the twelve relations and their order, what the trait maintains, what a bulk writer must do (`rebuildClosure()` or `ClosureTableMaintainer::detachSubtreesOf()`), the cascade guard. The other files as listed.
- [ ] **Step 4: Close the plan.** All boxes resolved; `status: completed`; `## Delivery status (date): ...` with divergences and `**Documented in:**` naming the docs above. Spec status `Implemented`. Run: `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`
- [ ] **Step 5: Commit and open the three PRs** (Core, CMS, root), bodies listing the gate numbers.

**After (filled by Step 1):**

| Case | Driver | p50 ms | p95 ms | Queries |
|---|---|---|---|---|
