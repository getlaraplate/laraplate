# Hierarchies on a closure table

**Status:** Implemented (2026-11-24). Divergences from this record (no hierarchy path, `/tree` payload without `path`, soft-delete purge through `TrashedRowsPurger`) are in the delivery status of `../plans/2026-11-18-closure-table-hierarchies.md`.

**Date:** 2026-11-18

## Intent

The three hierarchies of Laraplate (`Role`, `Taxonomy` with its subclasses, CMS `Comment`) move from
`staudenmeir/laravel-adjacency-list` to Core's own closure table, `HasClosureTable`. The change has to be a real
improvement on three counts, each one checked before the work is called done:

1. **Fewer dependencies to maintain.** Three packages leave the lock file: `staudenmeir/laravel-adjacency-list`,
   `staudenmeir/laravel-cte` and `staudenmeir/eloquent-has-many-deep-contracts`. Today `laravel-cte` refuses any
   database driver outside its list, so it decides which databases Laraplate can run on, and every Laravel major
   waits on its releases (see `2026-09-16-laravel-13-upgrade.md`).
2. **Laraplate can be extended and customised.** A hierarchy becomes a Core feature that any model, in a native
   module or in a project built on Laraplate, adopts with one trait and one table, on any database Laravel
   supports, without a recursive query grammar per driver.
3. **Faster reads.** Reading ancestors is the hot path: role inheritance in every ACL resolution, category paths in
   content URLs and listings, the tree endpoint of the CRUD API. A closure table answers it with one indexed join
   instead of a recursive common table expression. The gain is measured, not assumed (see Performance gate).

This also removes the `staudenmeir` blocker from `2026-11-17-db2-support-design.md`: no pull requests to those
packages, no Core override of their grammar.

## What exists today

- `staudenmeir/laravel-adjacency-list` `^1.26.2` is required by `Modules/Core/composer.json`. `laravel-cte` and
  `eloquent-has-many-deep-contracts` arrive only through it; nothing else in the lock file requires them.
- `Modules\Core\Models\Concerns\HasTypedRecursiveRelationships` wraps the package trait and redeclares twelve
  relations with return types, because `RelationGuard` calls a requested relation only when its declaration says it
  returns a relation. Used by `Role`, `Taxonomy` and `Comment`.
- Callers of the hierarchy:
  - `Role::getAllPermissions()`, `Role::hasPermission()` and `AclResolverService` walk `ancestors`, closest first.
  - `Taxonomy::buildAncestorChain()` (`ids`, `full_name`), the `withAncestorsForPath` scope,
    `Content` eager loading `categories.ancestors.translations` for URLs, `CategoriesTable` indenting by
    `ancestors->count()`.
  - `CrudService::tree()` eager loads `bloodline`, `ancestorsAndSelf` or `descendantsAndSelf` and narrows the nodes
    to the keys the caller's ACL can see.
  - `QueryBuilder::RELATION_BLACK_LIST` holds eleven of the twelve names (`parent` is not there); `Taxonomy::$hidden`
    hides six of them.
  - `CrudService::useRecursiveRelationships()` detects a hierarchy by the package trait.
- The package also overrides `newCollection()` and `newEloquentBuilder()` on the three models. No caller uses what
  they add (`toTree()` and the tree scopes); a PHPStan baseline entry refers to the package collection.
- Ancestor order today: the package adds none. `Taxonomy` has a `global_ordered` scope (`order_column`, then
  `valid_from`) that the outer query applies, so category ancestors come back in sibling-position order and
  `buildAncestorChain()` (`ids`, `full_name`, URL paths) is right only when that order happens to follow depth.
  `Role` ancestors come in whatever order the recursive query returns. This is a defect the switch fixes.
- Scopes today: the recursive query walks without scopes and the outer query applies them, so a trashed, inactive or
  expired node in the middle of a chain hides only itself; the nodes above it still appear.
- Tree payload today: every node `CrudService::tree()` returns carries a `depth` column (negative for ancestors,
  `0` for self, positive for descendants) and a `path` column of keys such as `3.2.1`.
- `HasClosureTable` and `TreeCollection` exist in Core, tested only through `ClosureTreeStubModel`. No real model uses
  them. The trait has defects to fix before any model relies on it (see Closure maintenance).
- Parent columns: all three tables use `parent_id` with a self foreign key `nullOnDelete`. `Role` uses soft deletes
  and versioning; `Comment` is never versioned.
- Writes that bypass Eloquent events on these tables:
  - database cascades from their owners: `cms_comments.user_id` and `cms_comments.content_id`,
    `taxonomies.entity_id` and `taxonomies.presettable_id` are `cascadeOnDelete`;
  - `model:soft-deletes-remove` purges trashed rows with the query builder;
  - tests: `CoverPreloadTest` and `CategoryFacetParentTest` set `parent_id` with a query-builder `update()`.
  Application code has no `insert`, `upsert`, `saveQuietly` or `withoutEvents` on them.

## Goals

- `Role`, `Taxonomy` (and so `Category`, `Activity`, `ContactRole`, `OpportunityStage`) and `Comment` use
  `HasClosureTable`; every caller listed above behaves as before, proven by characterization tests written before
  the switch, except the ancestor order, which this work fixes on purpose.
- The three `staudenmeir` packages are gone from `Modules/Core/composer.json` and from the lock file.
- A developer gives any model a hierarchy with the trait plus one migration call, documented in Core.
- Reads of the hot paths are at least as fast as today on every measured case and faster on the ancestor paths
  (Performance gate).

## Non-goals

- `Counterparty` (CRM) and `Account` (ERP): they have a parent but walk no tree today. They may adopt the trait
  later; this work does not touch them.
- MES lot genealogy (`mes_lot_lineages`): a graph with several parents per lot, not a tree.
- Nested sets, materialized paths or any second hierarchy strategy.
- Db2 itself. This work only removes one of its blockers.

## Anti-goals

- A closure table that can drift from `parent_id` without anyone noticing. Every write path that changes the tree
  keeps the closure in step, in the same transaction, and a test proves incremental maintenance equals a rebuild.
- A change in who can do what. Role inheritance is authorization: a soft-deleted role in the middle of a chain must
  grant exactly what it grants today.
- A compatibility layer reproducing the package's API beyond what Laraplate calls.
- Shipping the switch on the strength of the design alone, without the performance numbers.

## Constraints

- Portable SQL only, per `2026-07-09-database-guidelines-design.md`: Eloquent and the query builder
  (`insertUsing`, joins, subqueries), no recursive queries, no driver branches.
- Pre-stable schema: closure tables go into the create migrations of their model tables, no alter migrations;
  existing installs rebuild with `migrate:fresh`.
- Table names follow the model table, so module prefixes carry over: `{model_table}_closure`.
- Relation names visible to clients do not change: requests, the `QueryBuilder` black-list and `Taxonomy::$hidden`
  keep working unchanged.

## Approaches

1. **Keep the adjacency list, add a Db2 grammar to the packages.** Smallest change now. Keeps three packages, a
   recursive query on every ancestor read, and Laraplate's database list in someone else's hands. Rejected: it
   fails all three counts of the intent.
2. **Our own adjacency-list trait with recursive CTEs.** Drops the packages but rewrites what they do, keeps the
   recursive query and a grammar per driver. Rejected: the cost of approach 1 without its maturity.
3. **Closure table in Core (chosen by the user).** One extra table per hierarchy and writes that touch
   `depth + 1` rows, in exchange for join-only reads, no driver-specific SQL and no external package.

## Design

### Closure table

One table per hierarchy, `{model_table}_closure`:

| Column | Type | Notes |
|---|---|---|
| `ancestor_id` | same type as the model key | foreign key to the model table, cascade on delete |
| `descendant_id` | same type as the model key | foreign key to the model table, cascade on delete |
| `depth` | unsigned small integer | `0` for the self row |

Primary key `(ancestor_id, descendant_id)`; index `(descendant_id, depth)`. No timestamps: the rows are derived data.
A helper in `MigrateUtils` creates it from the model table name (my call), so adopting the trait is one line in a
migration. Created in the create migrations of the roles table, `taxonomies` and `cms_comments`.

### Relations

`HasClosureTable` exposes the twelve names `HasTypedRecursiveRelationships` declares today, each with a declared
return type and each eager-loadable with `with()`, so `RelationGuard` and `CrudService::tree()` keep working:
`parent`, `children`, `ancestors`, `ancestorsAndSelf`, `descendants`, `descendantsAndSelf`, `bloodline`,
`siblings`, `siblingsAndSelf`, `rootAncestor`, `parentAndSelf`, `childrenAndSelf`.

Semantics:

- `ancestors` and `ancestorsAndSelf` are ordered by depth, closest first; `descendants` and `descendantsAndSelf`
  by depth, nearest first. Depth order comes before any ordering a global scope adds. This is a deliberate change
  (my call): it fixes the scrambled category paths described above, so `ids`, `full_name` and content URLs can
  change on data where `order_column` did not follow depth. `buildAncestorChain()` and `AclResolverService` keep
  their logic. The current `HasClosureTable` orders root first; that changes too.
- `bloodline` is a relation, not a collection as in the current trait: `CrudService::tree()` eager loads it and
  adds a `whereIn` on the related key.
- Global scopes of the related model apply to the nodes returned, not to the walk: a trashed, inactive or expired
  node in a chain hides only itself, as today. For roles this keeps authorization unchanged.
- Tree payload kept (my call): `/tree` is a public CRUD endpoint, so each node still carries a signed `depth` and a
  `path` of keys, computed from the closure rows (in SQL or after loading, the builder's choice). The pivot does not
  leak into the payload.
- Client reachability unchanged (my call): the relations may be `BelongsToMany` underneath, so the facet path
  (`resolveManyRelation`, `assertFacetResolvable`), which today accepts any `BelongsToMany`, also checks
  `QueryBuilder::RELATION_BLACK_LIST`. `parent` stays reachable as today.
- `CrudService::useRecursiveRelationships()` detects `HasClosureTable`.

`HasTypedRecursiveRelationships` is deleted; the three models use `HasClosureTable`. With the package go its
collection and builder overrides; nothing calls them, and the PHPStan baseline entry that names them is removed.

### Closure maintenance

All in the trait, through model events, in a transaction the trait opens itself (a plain `save()` opens none):

- **Create:** the self row plus one row per ancestor of the parent, in one `insertUsing` statement.
- **Parent change on any save** (not only `moveTo()`): the whole subtree moves. Delete the links between the subtree
  and the old ancestors, insert the cross product of the new parent's ancestors and the subtree. A new parent inside
  the node's own subtree is refused before saving. Today only the node's own rows are rewritten and descendants
  keep stale links.
- **Hard delete:** the foreign key sets the children's `parent_id` to null without events, so the trait detaches the
  subtree of each child from the deleted node's ancestors before the delete; the node's own rows go with the
  cascade.
- **Soft delete and restore:** closure rows stay; nothing to do.
- **Deletes that bypass events** (my call; the foreign keys and what happens to replies stay as they are):
  - the owners whose rows cascade into a hierarchy at database level (`User` and `Content` for comments, `Entity`
    and the presettable for taxonomies) delete those hierarchy rows through Eloquent before their own delete, so
    the trait runs; the builder finds the delete paths (`HardDeleteCoordinator` among them);
  - `model:soft-deletes-remove` calls `rebuildClosure()` after purging a model that uses the trait;
  - a test lists every foreign key that cascades into a hierarchical table and fails when one has no handled
    delete path, so a new cascade cannot add silent drift.
- **Rebuild:** `rebuildClosure()` stays, for repair and for any future bulk writer that bypasses events. It is
  rewritten level by level with set-based statements instead of one query per node.
- The 24-hour depth cache goes (my call): it is invalidated only for the moved node, so descendants read stale depth
  after a move, and depth is available from the closure row.
- The column names `parent_id` and `id` are read from overridable methods, not hard-coded.
- The trait stops overriding `newCollection()` (my call): a hierarchy should not impose its collection class on the
  models that adopt it. `TreeCollection` stays available to call explicitly.

### Callers

- `CrudService::tree()`: comments and key qualification that refer to the recursive expression are rewritten for
  the closure relations.
- `Taxonomy`, `Role`, `AclResolverService`, `Content`, `CategoriesTable`: no change expected beyond the trait swap,
  proven by their tests.

## Performance gate

Measured before and after, same machine, on SQLite and on one server database (MySQL or PostgreSQL), with a seeded
dataset large enough to matter (the builder sets it; order of magnitude: thousands of taxonomies six levels deep,
tens of thousands of comments, a role chain of five):

- ACL resolution for a user whose roles have ancestors.
- A content listing that builds URLs from category ancestors.
- `CrudService::tree()` with parents, children and both.
- Writes: creating a node, moving a subtree, deleting a middle node.

Reads must be no slower in any case and faster on the ancestor paths; query counts must not grow. Writes may cost
more, and the numbers are recorded. If the gate fails, the switch does not merge and the result comes back to the
user.

## Testing

1. Characterization tests on today's behaviour, before any change: role inheritance (including a trashed middle
   role), the three tree modes with ACL narrowing and their node payload, comment threads. Ancestor chains
   (`ids`, `full_name`, content URLs) are written against the depth order this design sets, on data where
   `order_column` does not follow depth, so the fix is proven; they fail before the switch by design.
2. Closure invariants: create, move a subtree, hard delete a middle node, delete through an owner's cascade,
   soft delete and restore, and incremental maintenance equal to `rebuildClosure()` on the same tree.
3. Existing tests are adapted, none deleted (my call): the anonymous models using the package trait in
   `CrudServiceTest` and `CrudServiceRequestScenariosTest` move to stubs with closure tables, as the project rules
   require; `CategoryTest` asserts the new trait; tests that set `parent_id` with a query-builder `update()` save
   through Eloquent instead, so the trees they read are real.
4. The facet path refuses the tree relations.

## Documentation

- Core developer docs: how to give a model a hierarchy (trait, migration helper, relations, maintenance rules,
  `rebuildClosure()` after bulk writes).
- `Modules/Core/README.md` dependency list, `Modules/Core/docs/CRUD_SYSTEM.md` example, the `depth_cache` row of
  `Modules/Core/docs/rag/PERFORMANCE_TOOLKIT.md`.
- `2026-11-17-db2-support-design.md`: the vendor allowlists section drops `staudenmeir` and points here.

## Left to the builder

- The relation classes behind `bloodline`, `rootAncestor`, `parentAndSelf` and `childrenAndSelf`, as long as they
  are real relations that eager load in a bounded number of queries.
- The dataset sizes and the benchmark harness of the performance gate.
- The order of migrating the three models.
