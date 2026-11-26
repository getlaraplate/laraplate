# IBM Db2 as a primary database

**Status:** Approved by the user on 2026-11-26; implemented by `../plans/2026-11-26-db2-support.md` (phase 1)

**Date:** 2026-11-17

## Intent

Laraplate runs entirely on IBM Db2: migrations, seeders, Eloquent models, queues, the module flows and the vendor packages they rely on, on the same footing as MySQL and PostgreSQL. Oracle is not the baseline to copy: `2024_03_15_224941_create_permission_tables.php` throws `Unsupported database driver` on Oracle, so `migrate:fresh` does not pass there today. No customer asks for it today. The reason is positioning: a product that runs on Db2 reads as an enterprise product. That reason sets the bar. A Db2 claim that breaks at a prospect's first trial does more harm than no claim, so support is announced only for what a test run proves.

Two variants, in order:

1. **Db2 for i** (IBM i, AS/400) first. It is the Db2 of Italian manufacturing, the natural market of ERP and MES, and every available Laravel driver targets it.
2. **Db2 for Linux, Unix and Windows (LUW)** is the destination. It runs in Docker, so it can join CI and keep the claim verified on every commit.

## Goals

- `php artisan migrate:fresh --seed` completes on Db2 for i for every native module.
- The application's own query paths (Eloquent, query builder, the driver-branched raw SQL) work on Db2 for i, proven by a Db2 test run.
- The design leaves LUW as a second variant of the same driver layer, not a rewrite.
- LUW support runs in CI against a Db2 Community Edition container.

## Non-goals

- Db2 as an external data source read by importers (the "Laraplate connects to your Db2" scenario). It is cheaper and may come later; it is not this work.
- Db2 for z/OS.
- Full-text indexes, spatial columns and vector search on Db2. They follow the Oracle precedent: omitted, with the gap documented, and search falls back to the existing keyword path.
- The `vms_rms_gateway` driver of the upstream package.

## Anti-goals

- Announcing Db2 support (README, website, docs) before the matching phase gate below is met.
- IBM proprietary binaries (ODBC driver, CLI driver) committed to any repository.
- Laraplate-specific behaviour pushed into the driver fork. The fork stays a generic Laravel Db2 driver that upstream could accept.

## Constraints

- Backend is AGPL; the upstream package and the fork are MIT. Code ported between MIT packages keeps its attribution.
- The dependency is declared in `Modules/Core/composer.json`. Connection configuration lives in `config/database.php` of laraplate.
- Registration follows the Oracle precedent: the package is in Core's `extra.laravel.dont-discover`, and `CoreServiceProvider` registers its provider only when the PHP extension it needs is loaded (`pdo_odbc` for IBM i through ODBC).
- `docs/superpowers/specs/2026-07-09-database-guidelines-design.md` requires schema and SQL to stay portable across the supported drivers, with driver branches where they cannot. Db2 joins that rule; SQLite stays the default test database.
- `tests/bootstrap.php` (`TestEnvironmentGuard`) refuses a non-local test database. A remote Db2 for i test run uses `ALLOW_REMOTE_TEST_DATABASE=1` knowingly, in a dedicated invocation, never by default.
- Db2 for i has no expression-based generated columns: `GENERATED ALWAYS AS` accepts only identity, row change timestamp, row begin/end, transaction start id and non-deterministic expressions (data change operation, special registers, built-in global variables). Verified on the IBM i 7.5 `CREATE TABLE` reference. Db2 LUW does support expression-based generated columns.
- Db2 for i JSON functions: `JSON_VALUE`, `JSON_QUERY` and `JSON_TABLE` exist since 7.3 PTF groups; `JSON_EXISTS` is listed as new in 7.4. The minimum supported IBM i release is 7.4 or later; the builder confirms the floor against IBM's support lifecycle.

## Library choice

Five Laravel Db2 drivers were compared by reading their source (2026-11-17):

| Package | Laravel 13 | State | Variants |
|---|---|---|---|
| `easi-power/laravel-db2` | yes (v14.4.0) | active, monthly releases | IBM i (ODBC, PDO_IBM), z/OS, LUW (thin) |
| `bwicompanies/db2-driver` | no (to 12) | archived, abandoned on Packagist | IBM i |
| `cooperl/laravel-db2` | no (9 only) | idle since 2023 | IBM i, z/OS, LUW |
| `idl-bnl-dev/db2-driver` | yes | active, fork of BWI | IBM i |
| `emkcloud/laravel-iseries-db2` | yes | active, rebuilt for Laravel 12 | IBM i, query builder only |

`easi-power/laravel-db2` is the base: the only maintained one on Laravel 13 with upsert, locks and a queue driver, and the only one with a path toward LUW. `emkcloud/laravel-iseries-db2` is the only one with schema introspection for IBM i; that part is ported from it with attribution.

Gaps found in `easi-power/laravel-db2` that this work must close:

1. `wrapValue` never quotes identifiers, so a column named after a reserved word (`order`, `type`, `value`, `position`) breaks the statement.
2. `typeEnum` emits MySQL syntax `enum('a', 'b')`, invalid on Db2. Laraplate has 74 `enum` columns in 62 migration files.
3. No schema introspection: `compileTables`, `compileViews`, `compileColumns`, `compileIndexes`, `compileForeignKeys` and their processors are missing. Core uses them in `MigrateUtils`, `DatabaseTranslator` and `Inspect`.
4. `Schema\Builder::dropAllTables` drops tables in catalog order without foreign keys, so `migrate:fresh` fails as soon as one exists; views are not dropped.
5. No JSON path queries (`where('col->key')`, `whereJsonContains`, `whereJsonLength`). JSON columns are `CLOB`. Seven application files use JSON paths (five ERP posting services, two AI media classes).
6. No `compileInsertOrIgnore` in the Db2 query grammar (only the out-of-scope gateway grammar has one), so Laravel's base method throws. Laraplate calls `insertOrIgnore` in five application files, including `ModuleDatabaseActivator`.
7. `Schema\Grammars\DB2Grammar::getDefaultValue()` returns a raw PHP bool, so `->default(false)` compiles to `default ` with no value. Migrations use `default(false)` 33 times.
8. Smaller gaps: no `typeSet` (one use), `compileRandom` inherits `RANDOM()`, `truncate` uses the base `truncate table`, which LUW needs as `truncate table ... immediate`.
9. The LUW grammar (`DB2ExpressCGrammar`) is 32 lines over the IBM i one. Its two overrides read `syspublic.all_tables` and `syspublic.all_ind_columns`, the Oracle-compatibility views of LUW, and `compileColumnExists` reads index columns, so `hasColumn` finds only indexed columns.

## Approaches

1. **Subclasses inside Core.** Core extends the package's connection and grammars and registers its own resolver. Rejected: gaps 1 to 6 are generic Db2 work, not Laraplate's, and keeping them in Core means maintaining a second Db2 driver hooked to protected internals of a single-maintainer package.
2. **Fork, then pull requests upstream.** Chosen. The fork lives at `github.com/swolley/laravel-db2` (created on 2026-11-25 from `easi-power/laravel-db2`, default branch `master`). Generic driver work happens there on topic branches, each one ready to be offered upstream as a pull request. Whether and when to open them upstream is the user's decision, still open (2026-11-25). Core depends on the fork until upstream has merged what Laraplate needs, then moves back to `easi-power/laravel-db2`.
3. **Upstream pull requests only.** Rejected as the plan, kept as the outcome: timing and acceptance depend on one maintainer.

## Design

### Split of responsibilities

| Fork (`swolley/laravel-db2`, generic) | Core (Laraplate-specific) |
|---|---|
| Identifier quoting, enum, introspection, `dropAllTables`, JSON paths, LUW grammar | Conditional registration, connection config, Db2 arms in driver branches, triggers for flag columns, documented exclusions, Db2 test run |

### Fork work, in pull-request order

Each item is one branch and one upstream pull request, with unit tests in the package's own suite.

1. **Schema and query bug fixes.** `typeEnum` emits `varchar(n)` plus a `CHECK (col IN (...))` constraint; boolean defaults compile to `0`/`1`; `compileInsertOrIgnore` is added (through `MERGE ... WHEN NOT MATCHED`, or the form the builder finds equivalent); `typeSet` and `compileRandom` are completed. Bug fixes, no option.
2. **`dropAllTables`.** Drop foreign keys first, then tables; add `dropAllViews`. Bug fix.
3. **Schema introspection** for IBM i (`QSYS2` catalog), ported from `emkcloud/laravel-iseries-db2` with attribution, completed with the matching processor methods so `Schema::getTables/getColumns/getIndexes/getForeignKeys` return Laravel's standard shapes.
4. **Identifier quoting, opt-in.** A connection option (name left to the builder) turns quoting on. Default off keeps upstream behaviour. Laraplate turns it on. Quoting mode, my call: quote only identifiers that are Db2 reserved words, upper-cased (`"ORDER"`), so they match the catalog's folded form; every other identifier stays unquoted. Reason: quoting everything in lower case would make every name case-sensitive and break the 161 raw SQL statements in 65 files that write identifiers unquoted, which Db2 folds to upper case. With PDO `ATTR_CASE` set to lower, result keys stay lower case either way. The reserved-word list covers both Db2 for i and LUW.
5. **JSON paths.** `compileJsonSelector` through `JSON_VALUE(col, 'lax $.path')`, `compileJsonContains` through `JSON_EXISTS`, `compileJsonLength` through `JSON_TABLE` or the equivalent array-length form; boolean and numeric extraction handled with `RETURNING`.
6. **LUW variant**, phase 2: the `db2_expressc_odbc` grammar becomes a full LUW grammar with `SYSCAT` introspection; connection through the IBM Data Server Driver (ODBC or `pdo_ibm`, builder's call after a spike). Expression-based generated columns (`modifyStoredAs`/`modifyVirtualAs`) are implemented for LUW only.

### Core work

- `Modules/Core/composer.json` requires the fork; Composer reads `repositories` from the root `composer.json` (precedent: the `swolley/license-compliance-checker` VCS entry). The builder verifies whether the merge plugin carries a `repositories` block from Core; if it does not, the VCS entry goes in the root file.
- Distribution, decided by the user on 2026-11-25: a VCS entry pointing at the fork for now, not Packagist, while the upstream pull request question is open. Limit accepted with it: Composer ignores `repositories` declared by a dependency, so the VCS entry serves installs built from the laraplate repository only. Before Core ships as a standalone Composer package the question comes back: publish the fork on Packagist, wait for upstream, or make the driver an optional dependency (`suggest`).
- `CoreServiceProvider` registers the package provider when `pdo_odbc` is loaded and the class exists, next to the Oracle block.
- `config/database.php` gains a `db2` connection for IBM i with `env()` keys in the existing style; `.env.example` and the Core README document them.
- Driver names: the package reports `db2_ibmi_odbc`, `db2_ibmi_ibm`, `db2_expressc_odbc` as the driver name. Core adds one place that names the Db2 family (constant or helper, builder's call) and every driver branch uses it, so a branch never lists the driver names by hand.
- Every file that branches on the driver gets a Db2 arm or a documented exclusion. Known today: `MigrateUtils`, `ERPMigrateUtils`, `DayTruncation`, `DatabaseEngine`, `IdempotentWriter`, `HasBenchmark`, `ResourceSizer`, `PeriodPlan`, `BucketSeries`, `PgvectorProfileIndex`, `Place`/`HasPlace`, `helpers.php`, and the migrations of places, presettables, permission tables, taxonomies, model embeddings, users and the ERP lock guard triggers. The builder re-runs the search; this list is a starting point.
- Columns derived from other columns use triggers on Db2, one path for both variants in phase 1; LUW may move to generated columns later. Today `is_deleted` is a trigger on Oracle and a generated column on SQLite; permission name parsing is a trigger on SQLite and has no Oracle branch. The Db2 triggers are modelled on the Oracle `is_deleted` triggers and the SQLite permission triggers. There is no `is_locked` column: lock state is computed (`MigrateUtils::locked()`); the database guidelines spec is stale on that point and is corrected with the Db2 section.
- `MigrateUtils::liveUniqueIndex` needs a Db2 branch: its fallback uses `storedAs('IF(...)')`, and Db2 unique indexes treat NULLs as equal. IBM i uses `CREATE UNIQUE WHERE NOT NULL INDEX`, LUW `EXCLUDE NULL KEYS`.
- The 161 raw SQL statements are reviewed for Db2 portability; non-portable ones get a branch.

### Vendor packages with driver allowlists

The claim covers the whole application, so the phase gate covers the vendor query paths Laraplate uses (Filament backoffice, spatie permission and medialibrary, the Scout database engine), not only Laraplate's own code.

- `staudenmeir/laravel-cte` throws `This database is not supported.` for any driver outside its list (`BuildsExpressionQueries::getQueryGrammar`). `Role`, `Taxonomy` and the CMS `Comment` use `HasRecursiveRelationships`, so every query on them throws on Db2, including a plain `find`, and seeding fails. `staudenmeir/laravel-adjacency-list` has the same kind of list for recursive queries.
- Fix, decided by the user on 2026-11-18: the three hierarchies move to Core's closure table and the `staudenmeir` packages leave the dependencies, see `2026-11-18-closure-table-hierarchies-design.md`. That work is a prerequisite of the Db2 work; no pull requests to `staudenmeir` and no grammar override in Core. Shipped on 2026-11-25 (Core v2.0.0, CMS v1.50.0, laraplate v1.16.0): no `staudenmeir` package is left in the dependencies.
- The first task of the Laraplate work is an audit of `vendor/` for other driver allowlists (`getDriverName()` matches that throw by default) among the packages Laraplate uses. Each one found is fixed in the package that owns it: a generic Db2 change offered upstream as a pull request, with a Core override for the Db2 family only until it is merged.

### Transactions on IBM i

Laraplate depends on real transactions (`DB::transaction`, the queue's locking pop, the ERP lock-guard triggers, test isolation). On IBM i that needs journaled tables and a commit mode of at least `*CHG`; the package's sample configuration defaults to no commit.

- Install requirement, my call: the Laraplate schema is a library created with `CREATE SCHEMA` (journaled automatically) and the connection runs under commitment control. The connection config sets the commit mode; the Core README states the requirement.
- The PUB400 spike proves more than one query: create a table, commit, roll back, and check the rollback held. PUB400 hands out libraries that already exist, so their journaling is unknown until tried.
- `docs/superpowers/specs/2026-07-09-database-guidelines-design.md` gains Db2 in the supported table and its own patterns section, once phase 1 passes.

### Testing

- Fork: unit tests on the compiled SQL in the package suite, per pull request.
- Laraplate, phase 1: a Pest group (name left to the builder) that runs `migrate:fresh --seed` and a chosen set of feature tests against the Db2 connection. It is skipped unless the Db2 environment is configured, and it runs with `ALLOW_REMOTE_TEST_DATABASE=1` in its own invocation. The default suite stays on SQLite.
- IBM i test system: PUB400 (IBM i 7.5, free, 1 GB, two libraries, limit on connections per IP, no uptime guarantee) is the candidate. Its page calls it a playground and forbids running commercial environments; development testing of an open-source driver is the intended use. On 2026-11-26 its host server ports (449, 8471, 8476) answered over TCP from the user's work network; an ODBC login has not been tried. The builder confirms ODBC access with the spike before building on it. If PUB400 does not work, the fallback is a paid IBM i instance, which is the user's decision.
- Db2 for i has no container: IBM i is an operating system on Power hardware, and the Db2 Community Edition image is LUW only. Phase 1 therefore tests against a remote system, and a local Docker Db2 helps only from phase 2.
- A machine that runs the Db2 group needs unixODBC, the IBM i Access ODBC driver for Linux (an IBM download behind an IBM ID, installed by the user, never committed) and the PHP `pdo_odbc` extension. The `ibm_db2` extension is not enough: it is not PDO, and Laravel connections are.
- Day to day the work does not need Db2: fork changes are proven by compiled-SQL unit tests, Laraplate's driver arms by tests on the SQL they build. The remote run is the phase-gate proof, repeated at the end of each stage, not the inner loop.
- Phase 2: a CI job with the Db2 Community Edition container runs the same Pest group on every pull request.

### Phase gates for the claim

| Phase | Gate | What may be announced |
|---|---|---|
| 1 | `migrate:fresh --seed` and the Db2 group green on Db2 for i | "Runs on IBM Db2 for i" |
| 2 | Db2 group green in CI on LUW | "Runs on IBM Db2 (for i and LUW), tested in CI" |

## Open decisions

- **When to announce "Runs on IBM Db2 for i".** Db2 for i cannot run in CI, so after phase 1 any commit can break it unnoticed while the claim stays online. (a) announce after phase 1 and repeat the Db2 for i run before every Laraplate release, recording the result; (b) announce nothing until phase 2 runs in CI on LUW. Recommended: (a). The user's call; the phase gates below hold either way.
- **Upstream pull requests** to `easi-power/laravel-db2`: whether and when, see Approaches.

## Left to the builder

- Names: the quoting option, the Db2 family constant or helper, the Pest group, the fork's version tags.
- Which feature tests form the Db2 group, as long as every module with driver branches is covered.
- The minimum IBM i release, confirmed against IBM's support lifecycle (7.4 or later).
- ODBC versus `pdo_ibm` for LUW, after a spike.
- Whether a Docker image installs the IBM ODBC driver at build time, checked against IBM's license before any image is published.
