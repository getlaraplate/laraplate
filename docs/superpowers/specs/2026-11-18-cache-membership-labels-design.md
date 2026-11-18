# Cache membership labels for targeted invalidation

**Status:** Proposed

**Date:** 2026-11-18

**Module:** Core (`Modules/Core/app/Cache`, `Modules/Core/app/Http/Controllers/CrudController.php`, `Modules/Core/app/Services/Crud`)

**Builds on:** getlaraplate/laracore#45 (tagged CRUD responses, namespaced and sorted tags). That PR fixes today's tag mechanism and merges first; the plan branches from Core master after it. This design replaces it for CRUD responses, and its sorted tags keep serving the `Cache::tags()` users until the last slice.

## Why

The goal is the most targeted cache invalidation the data allows, so the read APIs can answer from cache without touching the database, and a write invalidates only the responses that really depend on it.

Laravel cache tags cannot get there. The tags of an entry are part of its key (`sha1(tag ids):key`), so:

- reading, writing or forgetting one entry needs every tag it was stored with, in the same order;
- a tag cannot be added after the value is computed, so a label naming the records a query actually returned (`core_users:5`) is impossible: its ids are known only after the query, while the key is needed before it;
- with the `failover` store Laravel uses the generic `TagSet`, whose flush only rotates the tag id: the entries stay in Redis, unreachable, and the `forever` ones never leave.

The owner already moved another project from "tags form the key" to "tags are memberships": the key stays plain and a label is a set listing the keys that carry it. This design does the same for Laraplate.

## What exists today

- `Modules\Core\Cache\Repository::tryByRequest()` caches a CRUD read response under a key built from path, query, user, roles and token, tagged (after #45) with the main table and the user and role tags.
- Only five models use `HasCache` (`Permission`, `Setting`, `Role`, `Preset`, `Entity`): every other entity is never cached. `HasCache` both opts a model into the response cache and flushes its table tag on `saved` and `deleted`.
- `CrudController::handleServiceCall()` runs the service call **before** consulting the cache: a hit saves only the serialization, and returns the cached response although a fresh one was just computed.
- Only the main table is a dependency. Relations loaded in the response, relations used by filters and sorts, translations and pivots are not, so editing an embedded author leaves every response embedding it stale until the TTL.
- Writes that fire no model event invalidate nothing: `attach`, `detach` and `sync` on pivots, query-builder `update`/`upsert`/`insert`, importers.
- A guest's locale comes from `Accept-Language` (`LocalizationMiddleware`) and is not part of the key, so two languages can share one cached response.
- `AclResolverService` answers an ACL change with `Cache::flush()`, which empties the whole store.

`QueryBuilder::requestedRelationPaths()` already lists every relation path a request names (`relations`, dotted, aggregate and computed columns, sorts, filters, group by), each checked by `RelationGuard`. It is the natural source of the dependencies.

The production store is `failover` over `redis` then `array`. The `cache` Redis connection already prefixes every key with the app slug (`{app}:cache:`).

## Goals

1. A label is a membership: a value is stored and read by its plain key, and labels are attached to it, before or after it is computed.
2. A CRUD read answers from cache without running the query on a hit.
3. A response carries a label for everything it depends on: the main table, every table along the relation paths it names, pivot and translation tables, the user and role it was computed for.
4. A write flushes only the labels it can affect, so a response that merely embeds a record is invalidated when that record changes, not when any record of its table changes.
5. A flush deletes the entries. Nothing is left orphaned.
6. Flush and hit rates are measurable per label, so further narrowing is decided on numbers.

## Non-goals

- HTTP caching (ETag, `Cache-Control`). The `freshness` endpoint stays as is.
- Changing Laravel's `Cache::tags()`. It keeps working for code that uses it.
- Caching Domain Actions, aggregates or writes.

## Anti-goals

These would make the work a failure even if it technically works:

- **A stale response after a write the caller made.** When in doubt, a write over-invalidates.
- **A response served to someone it was not computed for.** The key keeps every part that changes the answer: user, roles, token, locale, path, query.
- **A silent miss.** A write path that cannot invalidate precisely must invalidate broadly, never skip.

## Approaches considered

1. **Table labels computed from the query, on Laravel tags.** Simple, reuses #45. Coarse: any write to `core_users` empties every response that embeds users. Cannot label records. Keeps the key/tag coupling and the failover orphans.
2. **Membership labels (chosen).** Plain keys plus label sets. Labels can be added after the query, including record labels, and a flush really deletes. Costs our own index code, cleanup of expired members, and an in-memory twin for the `array` fallback.
3. **Version counters per table in the key.** Same reach as 1 with one more read per response. Dropped.

The owner picked 2 because it removes the limitation that started this work and is the only one that can reach record-level invalidation.

## Design

### Labels

A label is a string. Three kinds:

| Kind | Form | Meaning | Flushed by |
|---|---|---|---|
| set | `{table}` | the response depends on *which* rows of the table exist or match | any create, update, delete or restore on the table, any pivot or mass write on it |
| row | `{table}:{id}` | the response embeds that row's content only | an update, delete or restore of that row |
| scope | `user:{id}`, `role:{id}` | the response was computed for that user or role | `clearByUser()`, `clearByGroup()`, an ACL or role-membership change |

An update flushes both the set label and the row label of its table: an update can move a row into or out of a filtered list.

The gain comes from responses that depend on a table only through row labels. A list of contents embedding their author carries the set label `cms_contents` and the row labels of the authors it loaded, so editing a user nobody embeds leaves it cached.

### Label index

A Core contract with one implementation per kind of store:

- `put($key, $value, $ttl, array $labels)` and `label($key, array $labels, $ttl)`: write the value, then add the key to each label's set with the entry's expiry as score. On Redis the value and the memberships go in one `MULTI`.
- `get($key)` and `forget($key)`: plain, no labels needed.
- `flush(string ...$labels)`: for each label, record the flush time, read its members, delete them and the set, in chunks (as `RedisTaggedCache::flush()` does in Lua).
- **No stale write-back** (owner's decision). A reader that computed from old rows must not store its result after a writer flushed. Every label keeps the time of its last flush; `put()` and `label()` take the time the computation started and store nothing when any of the labels was flushed after it. This covers labels known only after the query too, at the cost of one read of the flush times per store.
- `pruneStale()`: remove expired members from every set (`ZREMRANGEBYSCORE -inf now`), scheduled like Laravel's `cache:prune-stale-tags`.

Implementations: Redis (ZSET per label, key `label:{name}` under the cache connection prefix), and in-memory for `array`. With `failover`, the index follows the store that is serving: a value and its memberships always live in the same store.

Label names carry no app namespace: the Redis connection prefix already separates applications.

**Degraded mode** (owner's decision). While `failover` serves from `array` because Redis is down, CRUD responses are not cached at all: an in-process cache is worth little and its invalidations would never reach Redis. When Redis serves again, every CRUD response entry is flushed (one label carried by all of them, e.g. `crud`) before the first one is stored, so nothing written before or during the outage is served.

### Driver compatibility

The membership index needs sets with scores and atomic multi-commands, which only Redis has. Every other driver keeps a working, invalidated response cache through a version-counter fallback (owner's decision, 2026-11-18):

| Store | Mechanism | Set and scope labels | Row labels |
|---|---|---|---|
| `redis`, `failover` serving from Redis | membership index | yes | yes |
| `array` | in-memory membership index | yes | yes |
| `database`, `file`, `memcached`, `dynamodb`, `octane` | version counters | yes | no: row dependencies fall back to the set label of their table |
| `null` | nothing is cached | n/a | n/a |

**Version counters.** Each label has a counter entry (`labelv:{name}`, missing reads as 0). The read fetches the counters of the response's labels in one `many()` and appends them to the key; a flush is an `increment()` of each counter. An entry whose labels moved on is never read again and expires by its TTL, so the fallback never stores `forever`. Only labels known before the query can be in the key, which is why row labels are unavailable there and a row dependency is recorded as its table's set label instead: over-invalidation, never staleness. The "no stale write-back" rule holds by construction: a reader that computed during a flush stores under the old counters, a key nobody reads again.

Both mechanisms implement one contract, chosen from the store at hand, so the read and write paths never branch on the driver.

### Read path

`CrudController::handleServiceCall()` becomes "look, then compute":

1. Build the key from path, query, user, roles, token and the resolved locale.
2. On a hit, still run the permission check the service would run for that operation (`ensurePermission` and the cached ACL resolution), then return the response without querying. This is defence in depth (owner's decision): an invalidation trigger missed somewhere never opens data to a caller who lost the permission. The row-level effect of an ACL change still relies on the flushes below.
3. On a miss, run the service, build the response, then store it with its labels.

The service reports the dependencies of what it returned, through `CrudResult` or a companion object:

- **set labels**, known from the request plan: the main table of a list, search, tree or history; the table of every relation used by a filter, a sort, a group by or an aggregate; the pivot table of every to-many relation along a path; the translations table of a translated model;
- **row labels**, known from the result: the main record of a detail; the records of every `belongsTo` and `morphTo` relation that was loaded. Only these: their foreign key sits on the row that embeds them, so a change of which record is embedded is an update of a row the response already depends on;
- `hasOne`, `morphOne` and `ofMany` relations are **set labels** on the related table, like to-many relations: their foreign key sits on the related table, so creating the related row or moving "the one" to another row would never reach a row label (builder check, author's call);
- **to-many relations** loaded for display (`hasMany`, `belongsToMany`) are set labels on the related table and on the pivot in the first slice. Scoped labels such as `cms_comments:post_id=42` would narrow them later and are left out until the measurements ask for them;
- **scope labels**: `user:{id}` and `role:{id}` of the caller.

Models opt into the response cache as today (`HasCache::usesCache()`). Opting more models in is a decision per entity, after this lands.

Dependencies the request does not name are declared by the model when it opts in (author's call, from the builder check): the tables read by its global scopes, its default `$with`, its appended accessors and computed methods, and the relations its ACL filters may name. The five models cached today have none. A test helper records the tables a cached call actually queries (`DB::listen`) and fails when one carries no label, so a missing declaration is caught in the model's tests, not in production.

### Time

A response can change with the clock and no write: the CMS guest ACL filters contents by `valid_from <= @now`. Such responses keep the default short TTL as their staleness bound; longer TTLs are allowed only for responses whose filters resolve no time placeholder (author's call, from the builder check).

### Write path

Invalidation no longer depends on a trait on each model:

- one listener on `eloquent.created`, `updated`, `deleted`, `restored` and `forceDeleted` for every model flushes the labels of its table (set always, row for update, delete and restore);
- every pivot write Core performs (`CrudService` relation sync, `HasTags`, other `sync`/`attach`/`detach` call sites) flushes the set labels of the pivot and of both sides' tables;
- mass writes and importers call `flush()` on the tables they touched once per batch or run. A module that writes outside Eloquent events must do the same, and the developer documentation says so;
- an ACL or role change flushes the affected `role:{id}` labels instead of `Cache::flush()`. The triggers to hook and test: ACL rows (role and user scoped), role-permission grants through Spatie (`givePermissionTo`, `syncPermissions`, which fire no model event), permissions granted directly to a user, role membership of a user, and the guest role, whose responses carry its `role:{id}` label like any other;
- every flush runs after the surrounding transaction commits (`DB::afterCommit` semantics), so a reader can never re-cache old rows between the flush and the commit.

### Measurement

Count hits, misses and flushes per label (Redis `HINCRBY` on a stats hash), and expose them through an Artisan command. This decides whether scoped labels or more cached entities are worth it.

### Existing tag users

Docs, translations, inspector, settings site configs and the cron list keep `Cache::tags()` in the first slices. Moving them to labels removes the failover orphans of their `forever` entries and is the last slice.

## Slices

1. Label index (contract, Redis, in-memory, version-counter fallback, failover), prune command, stats.
2. CRUD read path on labels: look before compute, locale in the key, set and scope labels from the request plan, global model-event listener, pivot and mass-write flushes, ACL flush by role.
3. Row labels from the result for detail and to-one relations.
4. Existing `Cache::tags()` users moved to labels.

## Left to the builder

- Names of the contract, classes, command and of the dependency object on `CrudResult`.
- How the index finds the serving store inside `failover` (subclassing `FailoverStore` is acceptable).
- How to enumerate pivot call sites and keep new ones from being missed (a helper every sync goes through is preferred to a list).
- Whether search, whose rows come from Elasticsearch and are reindexed asynchronously, is cached at all: if it is, its labels must be flushed again when the reindex job finishes, or the cache refills from the old index.
- Chunk size and Lua script of the flush.
