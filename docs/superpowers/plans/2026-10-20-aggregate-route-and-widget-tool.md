---
status: completed
created_on: 2026-10-20
module: core, ai
---
# Aggregate route and widget tool (backend) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add the `aggregate` CRUD route (sums, averages, time series, top-N with "Others", previous-period comparison) under the caller's permissions, make `summarize` use the same engine, and let the in-app assistant emit validated `kpi`/`chart`/`graph` objects through a `show_widget` tool.

**Architecture:** An `AggregateEngine` in Core, fed by a `CrudService::aggregate()` that does the permission, relation and readable-column checks and hands the engine closures over the ACL-scoped base query (the pattern of facets). Time buckets are computed by SQL per day and rolled up in PHP, so one portable expression per driver is enough. The AI module gains a policy capability `widget_objects`, a `WidgetObjectCollector` and a `ShowWidgetTool` (the sibling of the proposal tools), and publishes `objects` in the message metadata and in the capabilities.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, SQLite in tests, `Brick\Math\BigDecimal` (already used by ERP `Decimal`), NeuronAI tools.

**Spec:** `docs/superpowers/specs/2026-10-20-aggregate-route-and-widget-tool-design.md`. The contract (request, result, objects) is `docs/superpowers/specs/2026-10-20-widget-requests-and-objects-contract.md` at the stack root, outside this repository; where this plan needs a value from it, the value is written here.

## Global Constraints

- Every PHP file `declare(strict_types=1);`; braces always; explicit param and return types; constructor property promotion; `final` and `readonly` where siblings are; `#[Override]` on overrides; PHPDoc over inline comments; enum cases in TitleCase; `config()` outside config files, `env()` only inside `Modules/Core/config/config.php`.
- Tests are Pest, on SQLite. No class, trait, interface or enum declared in a test file: stubs go in the module's `tests/Stubs/` (Core: namespace `Modules\Core\Tests\`, mapped to `tests/`), helpers in `tests/Support/`.
- Portable SQL only: identifiers come from resolved model columns and are wrapped with the connection grammar, never concatenated from request text; functions and buckets are closed lists. The only driver-specific SQL is `DayTruncation`.
- Repositories: `Modules/Core` and `Modules/AI` are separate git repositories (submodules of `laraplate`). Core tasks commit inside `Modules/Core`, AI tasks inside `Modules/AI`, docs and plans inside `laraplate`. Stage only the task's files. **Commit per task; do not push, and do not update the submodule pointers in `laraplate`** until the owner says so after the final review.
- Commit messages: Conventional Commits in English. No `Co-Authored-By` trailer and no "Generated with" line (the owner's rule).
- Run from the `laraplate` root: `php artisan test --compact <path or --filter>`; before each commit `vendor/bin/pint --format agent <explicit file list>`. If a run aborts on `TestEnvironmentGuard` (cached config), run `php artisan config:clear`.
- Limits (`core.aggregate.*`): `max_measures` 3, `max_groups` 50, `max_points` 400, `max_period_days` 1096, `max_objects_per_message` 6, `rate_per_minute` 60. Functions: `count`, `sum`, `avg`, `min`, `max`. Buckets: `day`, `week` (starts Monday), `month`, `quarter`, `year`. Error codes: `INVALID_FIELD`, `UNREADABLE_FIELD`, `NOT_MEASURABLE`, `UNSUPPORTED_FUNCTION`, `TOO_MANY_GROUPS`, `PERIOD_TOO_LONG`, `TIMEOUT` (reserved, never emitted), warning `MIXED_CURRENCY`.
- Specs and plans are not documentation: the shipped behavior is documented in `Modules/Core/docs/` and `Modules/AI/docs/` (Tasks 9 and 12).

## Rulings made while planning

| # | Ruling | Why | Cost if wrong |
|---|---|---|---|
| R1 | Decimal-safe roll-up uses `Brick\Math\BigDecimal`, as `Modules\ERP\Support\Decimal` does. `composer.json` is not changed. | It is installed and already used by ERP; Core has no decimal helper. | One line to declare it explicitly in `require`. |
| R2 | `AggregatePeriod::previous()`: a period that starts on the first day of a month and ends on the last day of a month shifts by that many calendar months; any other period shifts by its own length in days. | Spec 4.4 says "calendar units when expressed in them"; a partial `last 12 months` ending today has no clean calendar reading. | Refines spec 4.4 only for partial periods. |
| R3 | `AggregateRequest` removes `group_by`, `sort`, `limit`, `period`, `measures`, `other`, `compare` from what it hands to `ListRequestData`, because three of those names mean something else in the list vocabulary. | `ListRequest` already owns `group_by` (a list of strings), `sort` (a list of `{property, direction}`) and `limit`. | None; internal. |
| R4 | `group_by.label` (a to-one relation column labelling a foreign-key group) is part of the request, resolved through `resolveFacetLabels`. | Spec section 4.7 as corrected on 2026-10-20; facets key a relation column by its own value. | None. |
| R5 | `CrudService::aggregate()` gives the engine an `AggregateScope` of closures over its private helpers. | The helpers (`relationParentIds`, `visibleRelatedKeys`, `resolveToOneColumn`, `resolveFacetLabels`) are private to a 3,600-line class; the spec wants a separate engine. | A later extraction of the helpers into a shared service. |
| R6 | A bucketed `group_by` requires a `period` (`INVALID_FIELD` on `period`). | Without one the number of buckets is unbounded. | Relax later by defaulting to `max_period_days`. |
| R7 | The route does not use `handleServiceCall`: its `QueryException` and `Throwable` arms answer with the exception message (SQL text). The action maps its own exceptions. | Spec 4.8: no SQL text in a 500. | None. |
| R8 | Result numbers are `int|float|null`: a numeric string `s` becomes `0 + s`. | JSON numbers for the client; money fits a double at scale 4. | Switch to strings if a client needs exact decimals. |
| R9 | `show_widget` is offered for an entity when `CrudToolProvider::offeredOperations()` lists `summarize` for it. | Spec 5.2: `summarize` in `ai.features.tools.crud.entities` is the operator's opt-in to aggregation. | None. |
| R10 | `show_widget` has no `delta` parameter in v1; a `kpi` object carries `ref.request`, `ref.variant` absent, `ref.format?`. | The UI treats a missing `delta` as neutral. | Add the parameter later. |

## Review Focus

- **A row-level ACL or a list filter must bound every sum, count and average**, in totals, groups, buckets and `other` (Task 5 and Task 6 tests `acl narrows the totals` and `acl narrows the groups`).
- **A field the caller cannot read, in `measures`, `group_by`, `sort`, `period` or a filter through a relation, is `UNREADABLE_FIELD`, never a different number** (Task 5 and Task 6).
- **A week or year boundary must put each day in the right bucket, and `avg` over buckets must be weighted by the days' counts, not an average of averages** (Task 7 tests `rolls days into Monday weeks` and `weights the average by count`).
- **A sum over several currencies must be `null` with a warning, not a number** (Task 8 test `sums over two currencies give null and a warning`).
- **The model must not get a way to put a number or an unvalidated request in an object**: an entity not offered, a type the page did not declare, a duplicate, the cap, an invalid request and a model-written title with markup are all refused (Task 11 tests).
- **A database failure must not echo SQL text to the client** (Task 9 test `answers a query failure with a generic message`).
- **A `decimal(15,4)` column on SQLite is measurable and a primary key is not** (Task 2).

---

### Task 1: Error codes, exception, limits, config

**Files:**
- Create: `Modules/Core/app/Enums/AggregateErrorCode.php`
- Create: `Modules/Core/app/Exceptions/AggregateException.php`
- Create: `Modules/Core/app/Services/Crud/Aggregate/AggregateLimits.php`
- Modify: `Modules/Core/config/config.php` (add the `aggregate` block after the `api` block)
- Test: `Modules/Core/tests/Unit/Services/Crud/AggregateLimitsTest.php`

**Interfaces:**
- Produces:
  - `enum AggregateErrorCode: string` with cases `InvalidField = 'INVALID_FIELD'`, `UnreadableField`, `NotMeasurable`, `UnsupportedFunction`, `TooManyGroups`, `PeriodTooLong`, `Timeout`, `MixedCurrency`, each backed by the SCREAMING_SNAKE_CASE code of the constraints above.
  - `final class AggregateException extends RuntimeException`: `__construct(public readonly AggregateErrorCode $errorCode, string $message, public readonly ?string $field = null)`; `toArray(): array{code: string, message: string, field?: string}` (`field` omitted when null).
  - `final readonly class AggregateLimits`: public properties `maxMeasures`, `maxGroups`, `maxPoints`, `maxPeriodDays`, `maxObjectsPerMessage`, `ratePerMinute` (all `int`); `static fromConfig(): self`; `toArray(): array{max_measures: int, max_groups: int, max_points: int, max_period_days: int}`.

- [x] **Step 1: Write the failing tests**

`AggregateLimitsTest`: `reads the defaults from the Core config` (the six values of the constraints); `reads an override` (`config(['core.aggregate.max_groups' => 10])` gives 10); `falls back to the default for a value below 1 or not numeric` (`0`, `'x'`); `publishes the four limits of the capabilities` (`toArray()` has exactly `max_measures`, `max_groups`, `max_points`, `max_period_days`); `an AggregateException serializes its code, message and field` and `omits field when it is null`.

- [x] **Step 2: Run to verify they fail** (`php artisan test --compact Modules/Core/tests/Unit/Services/Crud/AggregateLimitsTest.php`). Expected: FAIL, classes not found.

- [x] **Step 3: Implement** the three classes and the config block:

```php
'aggregate' => [
    'max_measures' => env('CORE_AGGREGATE_MAX_MEASURES', 3),
    'max_groups' => env('CORE_AGGREGATE_MAX_GROUPS', 50),
    'max_points' => env('CORE_AGGREGATE_MAX_POINTS', 400),
    'max_period_days' => env('CORE_AGGREGATE_MAX_PERIOD_DAYS', 1096),
    'max_objects_per_message' => env('CORE_AGGREGATE_MAX_OBJECTS_PER_MESSAGE', 6),
    'rate_per_minute' => env('CORE_AGGREGATE_RATE_PER_MINUTE', 60),
],
```

`fromConfig()` casts each with `(int)` and uses the default when the cast is below 1 (env values are strings). Follow the sibling `force_https` line for the phpstan ignore comment on `env()`.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate error codes, limits and config`.

---

### Task 2: `ColumnKinds` (what is measurable) and the test kit

**Files:**
- Create: `Modules/Core/app/Enums/ColumnKind.php`
- Create: `Modules/Core/app/Support/ColumnKinds.php`
- Create: `Modules/Core/tests/Stubs/Crud/AggregateOrderStubModel.php`, `AggregateCustomerStubModel.php`
- Create: `Modules/Core/tests/Support/AggregateTestKit.php`
- Test: `Modules/Core/tests/Integration/Support/ColumnKindsTest.php`

**Interfaces:**
- Produces:
  - `enum ColumnKind: string { case Numeric = 'numeric'; case Temporal = 'temporal'; case Other = 'other'; }`
  - `final class ColumnKinds`: `static exists(Model $model, string $column): bool` (a real column of the model's table, through the schema builder); `static of(Model $model, string $column): ColumnKind`; `static isKey(Model $model, string $column): bool`.
  - Stub tables (created by `AggregateTestKit::createTables()`): `aggregate_customers(id, name, country)` and `aggregate_orders(id, customer_id foreign key to customers, status string, total decimal(15,4) nullable, quantity integer, currency char(3) nullable, ordered_at datetime, is_paid boolean)`. `AggregateOrderStubModel` casts `total` to `decimal:4`, `quantity` to `integer`, `ordered_at` to `datetime`, `is_paid` to `boolean`, and has `customer(): BelongsTo`; `AggregateCustomerStubModel` has `orders(): HasMany`. Model the stubs on a `tests/Stubs/Crud/*StubModel` that an integration test already runs through `CrudService` (for example `ActivationStubModel`), so they work with the CRUD layer.
  - `AggregateTestKit`: `static createTables(): void`; `static asSuperadmin(): User` (creates a user with the `superadmin` role as `CrudFacetsTest` does and logs in); `static listData(Model $model, ?FiltersGroup $filters = null): ListRequestData` (the reflection builder of `facet_values_list_data` in `CrudServiceFacetValuesTest`, moved here so later tasks share it); `static seedOrders(array $rows): void`.

- [x] **Step 1: Write the failing tests** (`ColumnKindsTest`, with `AggregateTestKit::createTables()` in `beforeEach`): `a cast decimal column is numeric even though SQLite reports decimal(15,4)` (`total`); `an integer column is numeric` (`quantity`); `a datetime cast is temporal` (`ordered_at`); `a string and a boolean are other` (`status`, `is_paid`); `the primary key and a foreign key are keys` (`id`, `customer_id` are `isKey`); `a numeric key still reports numeric from of() but isKey is true`; `a column with no cast falls back to the schema type` (add a `float` column `weight` with no cast to the table: numeric); `exists is false for a name that is not a column and for an accessor`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement** `ColumnKinds`. `of()`: the model cast first (`integer`, `int`, `float`, `double`, `real`, `decimal` and `decimal:N` are numeric; `date`, `datetime`, `immutable_date`, `immutable_datetime`, `timestamp` and the `date:`/`datetime:` forms are temporal); otherwise the raw schema type of the column from `SchemaInspector` (read it where `Column::type` is built from, `Inspect.php`; the attribute `type_name` carries the raw string), lower-cased, with the precision `(…)` and `unsigned` removed: `int`, `integer`, `bigint`, `smallint`, `mediumint`, `decimal`, `numeric`, `float`, `double`, `real`, `double precision`, `money` are numeric; `date`, `datetime`, `timestamp`, `timestamptz`, `datetimetz`, `timestamp with time zone`, `timestamp without time zone` are temporal; `tinyint(1)` and `boolean` are other. `isKey()`: the model's `getKeyName()` (and each name of a composite key) or a local column of a foreign key of the table (`SchemaInspector::foreignKeys`, `ForeignKey::localColumnNames()`).

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): ColumnKinds and aggregate test kit`.

---

### Task 3: Time buckets, day truncation, periods

**Files:**
- Create: `Modules/Core/app/Enums/TimeBucket.php`
- Create: `Modules/Core/app/Support/DayTruncation.php`
- Create: `Modules/Core/app/Services/Crud/Aggregate/AggregatePeriod.php`
- Test: `Modules/Core/tests/Unit/Support/TimeBucketTest.php`, `DayTruncationTest.php`, `Modules/Core/tests/Unit/Services/Crud/AggregatePeriodTest.php`

**Interfaces:**
- Produces:
  - `enum TimeBucket: string` cases `Day`, `Week`, `Month`, `Quarter`, `Year` (values `day`, `week`, `month`, `quarter`, `year`); `static fromInput(string $value): ?self`; `start(CarbonImmutable $day): CarbonImmutable` (first day of the bucket that holds `$day`; a week starts on Monday); `next(CarbonImmutable $start): CarbonImmutable`; `key(CarbonImmutable $start): string` (`Y-m-d`).
  - `final class DayTruncation`: `static expression(string $driver, string $wrappedColumn): string`: `sqlite` gives `date(<col>)`, `mysql` and `mariadb` `DATE(<col>)`, `pgsql` and `sqlsrv` `CAST(<col> AS date)`, `oracle` `TRUNC(<col>)`; any other driver throws `AggregateException(UnsupportedFunction, ..., 'group_by.bucket')`.
  - `final readonly class AggregatePeriod`: `__construct(public string $field, public CarbonImmutable $from, public CarbonImmutable $to)` (both at the start of their day, `to` inclusive); `static resolve(array $spec, CarbonImmutable $now): self` where `$spec` is `['field' => string]` plus exactly one of `last => ['n' => int, 'unit' => string]`, `preset => string`, or `from`/`to` (`Y-m-d`); `days(): int`; `previous(): self` (ruling R2); `buckets(TimeBucket $bucket): list<CarbonImmutable>` (the bucket starts from the bucket of `from` through the bucket of `to`); `toMeta(): array{field: string, from: string, to: string, timezone: string}` with the application time zone.

- [x] **Step 1: Write the failing tests.**
  - `TimeBucketTest`: Monday-start weeks (`2026-10-20`, a Tuesday, starts `2026-10-19`); month, quarter (`2026-08-15` starts `2026-07-01`) and year starts; `next` crosses a year; `key` is `Y-m-d`; `fromInput('fortnight')` is null.
  - `DayTruncationTest`: the exact string of each driver for a wrapped column `"orders"."ordered_at"`; an unknown driver throws with code `UNSUPPORTED_FUNCTION` and field `group_by.bucket`.
  - `AggregatePeriodTest` (a fixed `now` of `2026-10-20`): `last 12 months` starts `2025-11-01` and ends `2026-10-20`; `last 7 days` starts `2026-10-14`; `last 3 weeks` starts the Monday two weeks before the current one; presets `today`, `yesterday`, `this_week`, `last_week`, `this_month` (ends `2026-10-31`), `last_month`, `this_quarter` (`2026-10-01` to `2026-12-31`), `last_quarter`, `this_year`, `last_year`; a custom `from`/`to`; `from` after `to` throws `INVALID_FIELD`; an unknown preset or unit throws `INVALID_FIELD`; `previous()` of `this_month` is September; of `this_quarter` is Q3; of a partial `last 12 months` is the 365 days before it; `days()` counts inclusively; `buckets(Month)` over `last 12 months` has 12 entries; `toMeta` carries `config('app.timezone')`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** `AggregatePeriod::resolve` uses `$now->startOfDay()` in the application time zone and throws `AggregateException(InvalidField, ..., 'period')` for any malformed spec.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): time buckets, day truncation and aggregate periods`.

---

### Task 4: `AggregateQuery`, `AggregateRequest`

**Files:**
- Create: `Modules/Core/app/Services/Crud/Aggregate/AggregateMeasure.php`, `AggregateGroup.php`, `AggregateQuery.php`
- Create: `Modules/Core/app/Http/Requests/AggregateRequest.php`
- Test: `Modules/Core/tests/Unit/Services/Crud/AggregateQueryTest.php`

**Interfaces:**
- Consumes: `AggregateLimits`, `AggregateException`, `TimeBucket` (Tasks 1 and 3).
- Produces:
  - `final readonly class AggregateMeasure`: `__construct(public string $fn, public ?string $field)`; `name(): string` (`count` for a field-less `count`, otherwise `<fn>_<field>`, so `count` over a field is `count_<field>`).
  - `final readonly class AggregateGroup`: `__construct(public string $field, public ?TimeBucket $bucket = null, public ?string $label = null)`.
  - `final readonly class AggregateQuery`: `__construct(public array $measures, public ?AggregateGroup $group, public ?array $period, public string $sortBy, public string $sortDir, public ?int $limit, public bool $other, public bool $compare)` (`$measures` is `list<AggregateMeasure>`, `$period` the validated spec array of `AggregatePeriod::resolve`); `static fromArray(array $input, AggregateLimits $limits): self`; `toArray(): array` (the normalized request array, without `kind` and `resource`); `canonical(): string` (JSON with sorted keys, for duplicate detection and for cache keys).
  - `final class AggregateRequest extends ListRequest`: `aggregateQuery(): AggregateQuery`; `parsed(): ListRequestData` overridden per ruling R3.

- [x] **Step 1: Write the failing tests** (`AggregateQueryTest`, direct `AggregateQuery::fromArray`):
  - `accepts a count with no field and a sum with a field`; `names measures` (`count`, `sum_total`, `count_status`).
  - Errors, each asserting `errorCode` and `field`: a function outside the five (`UNSUPPORTED_FUNCTION`, `measures.0.fn`); a missing `field` on `sum` (`INVALID_FIELD`, `measures.0.field`); more measures than `maxMeasures` (`INVALID_FIELD`, `measures`); no measures; a bucket outside the five (`INVALID_FIELD`, `group_by.bucket`); a bucketed group with no `period` (`INVALID_FIELD`, `period`, ruling R6); `compare` with no `period` (`INVALID_FIELD`, `compare`); `other` with no `limit` (`INVALID_FIELD`, `other`); `limit` above `maxGroups` (`TOO_MANY_GROUPS`, `limit`); a `period` with two of `last`/`preset`/`from`; `sort.by` other than `measure` or `key`; `sort.dir` other than `asc`/`desc`.
  - Defaults: `sortBy` `measure`, `sortDir` `desc` for a column group, and `key` `asc` for a bucketed group.
  - `canonical` is stable for the same request with keys in another order and differs for a different measure.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** `AggregateRequest`: rules for `measures` (required array, min 1), `measures.*.fn`, `measures.*.field`, `group_by.field`, `group_by.bucket`, `group_by.label`, `period` (array), `sort.by`, `sort.dir`, `limit`, `other`, `compare`; remove the inherited `group_by.*`, `sort.*.property`, `sort.*.direction` rules; `prepareForValidation()` also decodes JSON strings for `measures`, `group_by`, `period`, `sort` (GET callers); `failedValidation()` throws `AggregateException(InvalidField, <first message>, <first failing key>)`. `parsed()` builds `ListRequestData` from `Arr::except($this->validated(), ['measures', 'group_by', 'sort', 'period', 'limit', 'other', 'compare'])`. `aggregateQuery()` calls `AggregateQuery::fromArray($this->validated(), AggregateLimits::fromConfig())`.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): AggregateQuery and AggregateRequest`.

---

### Task 5: The engine, totals only, and `CrudService::aggregate`

**Files:**
- Create: `Modules/Core/app/Services/Crud/Aggregate/AggregateScope.php`, `AggregateResult.php`, `AggregateEngine.php`
- Create: `Modules/Core/tests/Stubs/Crud/AggregateRestrictedOrderStubModel.php` (the order stub implementing `IRestrictsReadableColumns`, hiding `total`)
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (new public `aggregate`, a nullable `AggregateEngine` constructor argument resolved like `RelationAuthorizer`)
- Test: `Modules/Core/tests/Integration/Services/Crud/AggregateEngineTotalsTest.php`

**Interfaces:**
- Consumes: Tasks 1 to 4.
- Produces:
  - `final readonly class AggregateScope`: `__construct(public Model $model, public Closure $baseQuery, public Closure $filteredIds, public Closure $visibleRelatedKeys, public Closure $resolveToOne, public Closure $resolveLabels)` where `baseQuery: fn(): Illuminate\Database\Eloquent\Builder` (ACL and the list filters applied), `filteredIds: fn(): Illuminate\Database\Query\Builder` (the primary keys of those rows, as `relationParentIds` returns), `visibleRelatedKeys: fn(Model $related, string $ownerKey): ?Illuminate\Database\Query\Builder`, `resolveToOne: fn(string $path): ?array{related: Model, relatedTable: string, foreignKey: string, ownerKey: string, column: string}`, `resolveLabels: fn(string $key, array $pageKeys, string $labelField): array<mixed, mixed>` (group key to label).
  - `final readonly class AggregateResult`: `__construct(public array $series, public array $total, public ?array $other, public ?array $compare, public array $meta)`; `toArray(): array{data: array<string, mixed>, meta: array<string, mixed>}` (`other` and `compare` keys omitted when null). A series entry is `array{key: mixed, label: ?string, values: array<string, int|float|null>}`.
  - `final class AggregateEngine`: `__construct(private readonly AggregateLimits $limits)`; `run(AggregateScope $scope, AggregateQuery $query, CarbonImmutable $now): AggregateResult`.
  - `CrudService::aggregate(ListRequestData $base, AggregateQuery $query): AggregateResult`.
- `CrudService::aggregate` does, in order: `ensurePermission(select)` as `facetCounts` does; `authorizeRequestedRelations($base, include_columns: false)`; for each measure field, the `group_by` field and label, the `period` field and the sort: `ColumnKinds::exists` (else `INVALID_FIELD`), then `ReadableColumnGuard::assertReadablePath` (its `InvalidArgumentException` becomes `UNREADABLE_FIELD`); the measure rules of spec 4.5 (`count` any column or none; `min`/`max` numeric or temporal; `sum`/`avg` numeric and not a key, else `NOT_MEASURABLE`); then builds the `AggregateScope` from `applyAclFiltersToQuery`, `applyFilters`, `relationParentIds`, `visibleRelatedKeys`, `resolveToOneColumn`, `resolveFacetLabels` and runs the engine.

- [x] **Step 1: Write the failing tests** (`AggregateEngineTotalsTest`, `AggregateTestKit::createTables()` and `asSuperadmin()` in `beforeEach`; call `app(CrudService::class)->aggregate(...)`): `counts the rows` (`row_count` and `total.count`); `sums, averages, takes min and max of a decimal column` (values `int|float`, `avg` an average of the non-null rows, a null `total` row is ignored); `count over a field counts only non-null values`; `an empty table gives zero counts and null sum, avg, min, max`; `a list filter narrows the totals`; `acl narrows the totals` (give the user a row-level ACL on the stub as `CrudServiceFacetCountsTest` or the CMS `ContentFacetsAclTest` set one up, and assert the sum equals the visible rows only); `sum over a primary key is NOT_MEASURABLE`; `sum over a string is NOT_MEASURABLE`; `an unknown column is INVALID_FIELD`; `a column the user cannot read is UNREADABLE_FIELD` (use a model that restricts readable columns through `IRestrictsReadableColumns`); `no select permission is refused` (an `AuthorizationException`).

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** The engine builds one grouped-less query on `($scope->baseQuery)()->toBase()->reorder()` selecting one aliased expression per measure (`count(*)`, `count(<col>)`, `sum(<col>)`, `min`, `max`; `avg` as `avg(<col>)`) plus `count(*)` for `meta.row_count`, with columns qualified by table and wrapped by the connection grammar; aliases are `m0`, `m1`, … mapped to measure names. `meta`: `row_count`, `group_count` 0, `truncated` false, `generated_at` (ISO-8601). Values pass through ruling R8.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate engine totals and CrudService::aggregate`.

---

### Task 6: Grouping by column and by relation column, labels, sort, limit, "Others"

**Files:**
- Modify: `Modules/Core/app/Services/Crud/Aggregate/AggregateEngine.php`
- Test: `Modules/Core/tests/Integration/Services/Crud/AggregateEngineGroupsTest.php`

**Interfaces:** Consumes the `AggregateScope` closures of Task 5. Produces the grouped path of `AggregateEngine::run`.

- [x] **Step 1: Write the failing tests** (same setup as Task 5):
  - `groups by an own column` (`status`): series keys, `values` per measure, ordered by the first measure descending; `total` covers all rows.
  - `orders by key ascending when asked` (`sort: {by: key, dir: asc}`).
  - `a null group has key null and no label`.
  - `groups by a to-one relation column` (`customer.country`): the join of `facetRelatedColumnValues`, each order counted once, the key is the country.
  - `a relation group hides related rows the user cannot see` (the related ACL, through `visibleRelatedKeys`).
  - `a foreign key group takes labels through group_by.label` (`customer_id` with label `customer.name`): `label` is the customer's name, the key the id.
  - `limit with other` (`limit: 2, other: true`): `series` holds two entries, `other.groups` is the number of the rest, `other.values` are computed over the rest (an `avg` of the rest equals the average of the other rows, not a difference), `meta.truncated` is true, `group_count` is the total number of groups.
  - `limit without other still reports truncated`.
  - `a limit above max_groups is TOO_MANY_GROUPS`; `more groups than max_groups with no limit is TOO_MANY_GROUPS` (set `core.aggregate.max_groups` to 2).
  - `acl narrows the groups`; `a hidden column in group_by or sort is UNREADABLE_FIELD`; `a multi-hop relation path is INVALID_FIELD`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** Own column: `groupBy` the qualified column on `baseQuery()->toBase()->reorder()`, `orderBy` the alias `m0` (or the group column for `key`), `limit`. Relation column: the query of `facetRelatedColumnValues` (the table joined to the related table on the foreign key and owner key, `whereIn(pk, filteredIds())`, `whereIn` related keys from `visibleRelatedKeys`), measures qualified with the parent table, group by the related column. `other`: the same measures over the base query with `whereNotIn(<group>, <shown keys>)` (and the null group handled explicitly). `group_count`: `count(distinct <group>)`. Labels: for `group_by.label` call `resolveLabels` once for the shown keys.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate grouping by column and relation`.

---

### Task 7: Time buckets, periods, comparison

**Files:**
- Create: `Modules/Core/app/Services/Crud/Aggregate/BucketRollup.php`
- Modify: `Modules/Core/app/Services/Crud/Aggregate/AggregateEngine.php`
- Test: `Modules/Core/tests/Unit/Services/Crud/BucketRollupTest.php`, `Modules/Core/tests/Integration/Services/Crud/AggregateEngineBucketsTest.php`

**Interfaces:**
- Produces `final class BucketRollup`: `static sum(list<?string> $values): ?string` (null when every value is null; `BigDecimal` addition otherwise, returned as a plain decimal string); `static avg(?string $sum, int $count): ?string` (null when `$count` is 0; scale 4, half up); `static min(list<?string> $values): ?string` and `static max(...)` (numeric comparison, nulls ignored).

- [x] **Step 1: Write the failing tests.**
  - `BucketRollupTest`: `adds decimals exactly` (`0.1`, `0.2` give `0.3`; three `0.1000` give `0.3000` or `0.3`, as formatted); `sum is null when all are null`; `avg divides by the count of non-null rows`; `min and max ignore nulls`.
  - `AggregateEngineBucketsTest`: `fills empty buckets` (a month with no orders has `count` 0, `sum` 0 and `avg`, `min`, `max` null) per the contract; `rolls days into Monday weeks` (orders on a Sunday and the next Monday land in different weeks); `rolls days into months, quarters and years` over a period that crosses a year end; `weights the average by count` (a day with 1 order of 10 and a day with 3 orders of 20 in one bucket give `17.5`, not `15`); `min and max over days`; `series are chronological by default and key is the bucket start date`; `the period limits the rows` (orders outside it are not counted); `a period longer than max_period_days is PERIOD_TOO_LONG`; `a bucket count above max_points is PERIOD_TOO_LONG` (day buckets over 500 days with `max_points` 400; the message names a coarser bucket); `a period or bucket field that is not temporal is INVALID_FIELD`; `meta carries resolved_period and bucket`; `compare adds the previous period total` and `compare adds a series aligned by position for a bucketed request`; `compare without a bucket gives only a total`; `acl narrows the buckets`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** In the engine: resolve the period with `AggregatePeriod::resolve($query->period, $now)`; add the half-open range `field >= from 00:00:00 and field < to + 1 day` (`Y-m-d H:i:s`, application time zone) to the base query for every path that has a period; check `days() <= maxPeriodDays` and the bucket count `<= maxPoints` before any query. The bucketed query groups by `DayTruncation::expression($connection->getDriverName(), <wrapped column>)` and selects per measure only the partials it needs (`count(*)` or `count(col)`; `sum` and `count(col)` for `avg`; `sum`; `min`; `max`); PHP maps each day string (first 10 characters, for Oracle's `TRUNC` datetime) to `TimeBucket::start`/`key` and rolls partials up with `BucketRollup`. `compare` runs the same path over `AggregatePeriod::previous()` and aligns series by position.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate time buckets, periods and comparison`.

---

### Task 8: Currency detection and mixed currencies

**Files:**
- Create: `Modules/Core/app/Support/CurrencyColumns.php`
- Modify: `Modules/Core/app/Services/Crud/Aggregate/AggregateEngine.php`
- Test: `Modules/Core/tests/Unit/Support/CurrencyColumnsTest.php` (or Integration if it needs the schema), `Modules/Core/tests/Integration/Services/Crud/AggregateEngineCurrencyTest.php`

**Interfaces:**
- Produces `final class CurrencyColumns`: `static for(Model $model, string $field): ?string`: the column `currency_<suffix>` when the field's last `_` segment is `<suffix>` and that column exists on the table, otherwise `currency` when it exists, otherwise null.
- Result additions: `meta.currency` (string) when the whole result has one currency; `meta.currencies` (list of the currencies met, at most 20) and `meta.warnings` (`list<array{code: 'MIXED_CURRENCY', field: string, message: string}>`, once per affected measure) when a value mixes currencies.

- [x] **Step 1: Write the failing tests.**
  - `CurrencyColumnsTest`: `finds currency beside total`; `pairs amount_doc with currency_doc and amount_local with currency_local`; `returns null with no currency column`.
  - `AggregateEngineCurrencyTest` (the order stub has `currency`): `one currency puts it in meta.currency and formats nothing`; `sums over two currencies give null and a warning` (`total.sum_total` is null, `meta.warnings[0].code` is `MIXED_CURRENCY`, `field` is `sum_total`, `meta.currencies` has both); `grouping by currency splits the result into single-currency values` (no warning, each group's sum is a number); `a bucket with one currency keeps its value while a mixed bucket is null` (bucketed over days, `min(currency)`/`max(currency)` partials rolled up); `count is never affected`; `a table with no currency column adds nothing to meta`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** For each `sum`, `avg`, `min` or `max` measure whose field has a currency column (`CurrencyColumns::for`), add `min(<currency>)` and `max(<currency>)` partials to every query of Tasks 5 to 7; a cell is mixed when the rolled-up minimum differs from the maximum; a mixed cell's value for that measure becomes null. The distinct currencies are read with one extra bounded query only when the total is mixed.

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate currency detection and mixed-currency warnings`.

---

### Task 9: Route, controller action, rate limiter, errors, Core documentation

**Files:**
- Modify: `Modules/Core/routes/crud.php`, `Modules/Core/app/Http/Controllers/CrudController.php`, `Modules/Core/app/Providers/CoreServiceProvider.php`
- Create: `Modules/Core/tests/Feature/Controllers/CrudAggregateTest.php`
- Modify (docs): `Modules/Core/docs/CRUD_SYSTEM.md`, the Core README env-var table, and each `Modules/Core/docs` or `docs/rag` file that describes the facets route (`grep -rn facets Modules/Core/docs`), adding the aggregate route beside it.

**Interfaces:**
- Produces: `Route::match(['get', 'post'], '/aggregate/{module}/{entity}', 'aggregate')->name('aggregate')->middleware('throttle:aggregate');` (name `core.crud.aggregate` under `app/crud`, mounted under `api/v1` like facets); `CrudController::aggregate(AggregateRequest $request): Response`; `RateLimiter::for('aggregate', ...)` limiting per authenticated user (the address for a guest) to `AggregateLimits::fromConfig()->ratePerMinute` a minute.
- Response contract (ruling R7): success `200 {data, meta}` from `AggregateResult::toArray()`; `AggregateException` gives `422 {error: {code, message, field?}}`; `AuthorizationException` gives the status `handleServiceCall` gives it (`authorizationFailureStatus`) through `buildResponse`; `QueryException` and any other `Throwable` are passed to `report()` and answered `500 {error: {message: 'The aggregate could not be computed.'}}` with no exception text.

- [x] **Step 1: Write the failing tests** (`CrudAggregateTest`, entity `core/settings`, a superadmin as in `CrudFacetsTest`): `counts settings by group_name` (`data.series`, `meta.row_count`); `buckets settings by created_at over a period` (`group_by` `{field: created_at, bucket: day}`, `period` `{field: created_at, from, to}`); `accepts the request as a JSON query string` (a GET with `measures` and `period` as JSON strings); `denies without select permission` (403); `refuses a guest`; `answers a shape error with a structured 422` (`error.code` `UNSUPPORTED_FUNCTION`, `error.field` `measures.0.fn`); `answers an unreadable or unknown field with its code`; `answers a query failure with a generic message` (bind a `CrudService` mock whose `aggregate` throws `QueryException` with the text `SQLSTATE secret`; the body does not contain `SQLSTATE` and the status is 500); `limits the rate` (`config(['core.aggregate.rate_per_minute' => 2])`, `RateLimiter::clear` first, the third call is 429).

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement** the route, action and limiter; write the documentation: the request and result shapes (the contract's, with `group_by.label`), the limits and their env variables (`CORE_AGGREGATE_*`), the error codes and statuses, the time-zone and bucket rules, the currency rule, and the driver note (only SQLite is run in CI).

- [x] **Step 4: Run to verify they pass**, then `php artisan route:list --path=aggregate` shows the route under both `app/crud` and `api/v1`.

- [x] **Step 5: Commit** in `Modules/Core`: `feat(crud): aggregate route, limiter and documentation`.

---

### Task 10: `summarize` delegates to the engine

**Files:**
- Modify: `Modules/AI/app/Services/Tools/CrudToolProvider.php`
- Modify: `Modules/AI/tests/Feature/Tools/CrudToolProviderTest.php`

**Interfaces:**
- Consumes: `CrudService::aggregate`, `AggregateQuery::fromArray` (Tasks 4 and 5).
- Produces: `CrudToolProvider::aggregate(string $module, string $entity, mixed $filters, AggregateQuery $query): AggregateResult` (public; builds the `ListRequestData` from the normalized filters exactly as `runSummarize` does today, calls `$this->crud->aggregate(...)`, lets `AggregateException` through). `summarize` keeps its output shape: `request`, `data` (a list of `{group: {<field>: <key>}, count, metrics: {'<fn>(<property>)': value}}`), `meta` `{total_records, aggregated_rows, truncated}` with `total_records` and `aggregated_rows` from `meta.row_count`.
- New optional tool parameters `bucket` (string, enum of the five buckets) and `period` (object `{field, last|preset|from, to}`), applying to the single `group_by` column.

- [x] **Step 1: Write the failing tests** (in `CrudToolProviderTest`): update `summarizes records with group-by count and sum metrics` so the metric is a numeric non-key column (the existing test sums `id`, now `NOT_MEASURABLE`; use a column the test model has, or `count` only if the model has no numeric column, and say which in the commit message); add `summarize rejects more than one group_by column with the tool's error shape`; `summarize reports NOT_MEASURABLE for a sum over a key in the error shape`; `summarize buckets a date column over a period` (`bucket: month`, `period`); `summarize no longer reads rows into PHP` (a test with more than 5000 rows is not needed: assert that `SUMMARIZE_ROW_CAP` no longer exists with `defined`/reflection, or that `CrudService::list` is not called by mocking it to fail).

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** Replace `runSummarize`'s body by the delegation; map `metrics` (`{property, function}`) to `AggregateMeasure`s plus a `count`; map the result back to the old shape; remove `SUMMARIZE_ROW_CAP`, `aggregateRows`, `foldMetric`, `finalizeMetrics`, `bucketKey`, `bucketGroup` and any helper no longer used; update the tool description (one `group_by` column, optional `bucket` and `period`) and set `maxItems` 1 on `group_by`.

- [x] **Step 4: Run to verify they pass** (`Modules/AI/tests/Feature/Tools/CrudToolProviderTest.php` in full).

- [x] **Step 5: Commit** in `Modules/AI`: `feat(assistant): summarize runs on the aggregate engine`.

---

### Task 11: Capability, `WidgetObjectCollector` and `ShowWidgetTool`

**Files:**
- Create: `Modules/AI/app/Data/WidgetObject.php`
- Create: `Modules/AI/app/Services/Assistance/Widgets/RenderableObjectTypes.php`, `WidgetObjectCollector.php`
- Create: `Modules/AI/app/Services/Tools/ShowWidgetTool.php`
- Modify: `Modules/AI/app/Services/Assistance/Policies/AssistantPolicyCatalog.php`, `Modules/AI/app/Services/Assistance/AssistantCapabilities.php` (the constant only in this task)
- Test: `Modules/AI/tests/Feature/Assistance/WidgetObjectsTest.php`

**Interfaces:**
- Consumes: `CrudToolProvider::aggregate`, `offeredOperations()` (Task 10), `AggregateQuery::fromArray`, `AggregateLimits`, `AssistanceGuardrailPipeline` (`validateOutput`, `toolResultContainsInstructions`).
- Produces:
  - `AssistantCapabilities::WIDGETS_CAPABILITY = 'widget_objects'`.
  - `AssistantPolicyCatalog`: a capability `widget_objects` (instruction: an object shows data the application computes; state what it shows and never invent figures; the application renders it) allowing the tool `show_widget`; `show_widget` added to the `in_app_assistance` profile's `allowedTools`.
  - `final readonly class WidgetObject`: `__construct(public string $id, public string $type, public string $title, public array $ref)`; `toArray(): array{id: string, type: string, title: string, ref: array<string, mixed>}`.
  - `final class RenderableObjectTypes`: `const array ALLOWED = ['kpi', 'chart', 'graph']`; `const int MAX = 3`; `static fromPageContext(mixed $objects): self` (a list of at most 3 distinct strings among `ALLOWED`, anything else ignored); `has(string $type): bool`; `isEmpty(): bool`.
  - `final class WidgetObjectCollector`: `__construct(RenderableObjectTypes $types, CrudToolProvider $crud, AssistanceGuardrailPipeline $guardrails, AggregateLimits $limits)`; `show(mixed $type, mixed $title, mixed $request, mixed $variant, mixed $format): string`; `objects(): list<WidgetObject>`; `hasObjects(): bool`. Every refusal is a sentence for the model beginning `Not shown:`, never an exception; success begins `Widget recorded.`
  - `final readonly class ShowWidgetTool`: `NAME = 'show_widget'`; `__construct(WidgetObjectCollector $collector)`; `definition(): ToolDefinition` with parameters `type` (enum `kpi`, `chart`), `title` (string, max 80), `request` (string, JSON text, max 2000), `variant` (string, optional, enum `bar`, `line`, `donut`, `ranking`), `format` (string, optional JSON text, max 200); risk level `low`. (`graph` joins the enum in Task 13.)
- Rules of `show`, in order, each with its own refusal: the type is declared by the page (`types->has`) and is `kpi` or `chart`; `title` is plain text of 1 to 80 characters with no control characters, `<`, `>`, backtick or URL, and passes `guardrails->validateOutput` (the check of `UiProposalCollector::plainReason`, with its own maximum); `request` decodes (depth 8) to an array whose `resource` matches `^[a-z][a-z0-9_]{0,63}/[a-z][a-z0-9_]{0,63}$` and for which `offeredOperations()` lists `summarize` (ruling R9); the count is below `limits->maxObjectsPerMessage`; `AggregateQuery::fromArray` accepts it; shape by type (`kpi`: no `group_by`, or a `group_by` with a bucket; `chart`: a `variant` among the four, a `group_by` required, `line` requires a bucketed `group_by`, `donut` requires a `limit` of at most 8); `format` (if present) is `{style: number|currency|percent, currency?: three capital letters, max_fraction_digits?: 0 to 4, compact?: bool}` and nothing else; no object with the same `type`, `variant` and `AggregateQuery::canonical()` is already collected. Then run once through `crud->aggregate`; an `AggregateException` is a refusal naming its code and field. A recorded object is logged with `Log::info('Assistant widget recorded', ['type' => ..., 'resource' => ..., 'request' => <canonical>])`, never its figures. On success the object is `new WidgetObject((string) Str::uuid(), $type, $title, ['request' => [...$decoded, 'kind' => 'aggregate'], 'variant' => ..., 'format' => ...])` (absent keys omitted), and the sentence handed back is `Widget recorded.` plus a compact JSON of the totals, the first five series entries and the warnings; if `toolResultContainsInstructions` flags that text it is replaced by a sentence saying the figures were withheld but the widget is still shown.

- [x] **Step 1: Write the failing tests** (`WidgetObjectsTest`; use a `CrudToolProvider` built as `makeCrudToolProvider` does in `CrudToolProviderTest`, config `ai.features.tools.crud.entities` with `core.setting` and `summarize`):
  - `RenderableObjectTypes`: keeps the allowed distinct types, caps at three, ignores the rest and non-lists.
  - Refusals: `a type the page did not declare`; `an entity not offered for summarize`; `a request that is not JSON or lacks resource`; `an invalid aggregate request returns its code and field` (a bad `fn`); `a chart without a variant, a line without a bucket, a donut with limit above 8`; `an invalid format` (an extra key, a lower-case currency); `a title with markup or over 80 characters`; `the seventh object` (the cap, set to 2 in config for speed); `a duplicate`.
  - Success: `records a kpi with the request and kind aggregate in ref` (the object `ref.request.kind` is `aggregate`, `type` `kpi`, id a UUID); `records a chart with variant and format`; `the object holds no figures` (no `series`, `total` key in `toArray()`); `hands the model the figures it can comment on` (the returned text contains the group keys); `withholds figures that read like an instruction` (a setting whose `group_name` is an injection phrase: the object is still recorded and the text says withheld).
  - Catalog: `the in-app profile allows show_widget only with the widget_objects capability` (compile with and without `AssistantCapabilities::WIDGETS_CAPABILITY`).
  - Audit: `logs the request of a recorded object and never its figures` (`Log::shouldReceive('info')` with the resource, type and canonical request; the context has no `series` or `total`).

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.**

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/AI`: `feat(assistant): show_widget tool, widget objects collector and policy capability`.

---

### Task 12: Wiring, emission, stream event, capabilities, AI documentation

**Files:**
- Modify: `Modules/AI/app/Services/Assistance/InAppAssistanceService.php`, `Modules/AI/app/Services/AssistAgentService.php`, `Modules/AI/app/Services/Assistance/Stream/AgentEvent.php`, `Modules/AI/app/Services/Assistance/AssistantCapabilities.php`, `Modules/AI/app/Http/Resources/AiCapabilitiesResource.php`
- Test: `Modules/AI/tests/Feature/Assistance/WidgetObjectsFlowTest.php`; update `CapabilitiesEndpointTest.php` and `AgentEndpointTest.php`
- Create: `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md`; modify every file that mentions `propose_view_state` or `ui_proposals` (`grep -rn "propose_view_state" Modules/AI/docs`): `docs/ARCHITECTURE.md`, `docs/GLOSSARY.md`, `docs/rag/GLOSSARY.md`, `docs/rag/MODULE.md`, and the user-facing assistant documentation if it lists what the assistant can show.

**Interfaces:**
- Consumes: Task 11 classes.
- Produces:
  - `InAppAssistanceService`: `WIDGETS_CAPABILITY` added to the capabilities passed to `policy_compiler->compile`; a private `widgetCollector(CompiledAssistantPolicy $policy): ?WidgetObjectCollector` (null unless the policy allows `show_widget`, `RenderableObjectTypes::fromPageContext($this->request->input('context.page.objects'))` is not empty and `ai.features.tools.crud.entities` has an entity with `summarize`; builds the collector with `app(CrudToolProvider::class)`, `$this->guardrails` and `AggregateLimits::fromConfig()`); a private `widgetTools(...)` returning the `show_widget` tool through `tool_registry->getNeuronToolsForDefinitions`; `$metadata['objects']` set to `array_map(fn (WidgetObject $o): array => $o->toArray(), $collector->objects())` when there are any, after `proposals` and `writes`.
  - `AgentEvent::object(array $object): array` shaped like `AgentEvent::proposal` (`type` `ToolCall`, `toolCallId` the object id, `toolCallName` `show_widget`, `args` the object); `AssistAgentService::events` yields one per entry of `$reply->metadata['objects'] ?? []`, after the proposals and before `runFinished`, which is unchanged (only proposals interrupt).
  - `AssistantCapabilities::widgets(): bool` (the compiled in-app policy with `widget_objects` still allows a tool, as `proposals()` does) and `objects(): list<string>` (`[]` when `widgets()` is false or no entity in `ai.features.tools.crud.entities` has `summarize`; otherwise `['kpi', 'chart']`); `toArray()` becomes `{enabled, configured, features: {help, proposals, writes, streaming, objects}, limits: {aggregate: AggregateLimits::fromConfig()->toArray()}}`; update the docblock shapes of `toArray` and of `AiCapabilitiesResource::toArray`.

- [x] **Step 1: Write the failing tests.**
  - `WidgetObjectsFlowTest` (the pattern of `UiProposalsTest`, `ScriptedAssistantFixtures::inAppService`, a request with `context.page.objects` = `['kpi','chart']`): `offers show_widget only when the page declared object types and an entity offers summarize`; `a recorded object lands in message.metadata.objects with id, type, title and ref`; `a run with no object has no objects key`; `the page declaring nothing gets no tool`; `the refusal path keeps its metadata unchanged`.
  - `AgentEndpointTest`: `the run emits a show_widget event for each object before RUN_FINISHED and does not interrupt` (the run outcome stays `success`).
  - `CapabilitiesEndpointTest`: `features.objects lists kpi and chart when an entity offers summarize and the capability is on`; `features.objects is empty when the capability is removed from the catalog or no entity offers summarize`; `limits.aggregate carries the four limits and follows config`; update the existing exact-shape assertions of the endpoint to the new shape.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.** Write `ASSISTANT_WIDGETS_DEVELOPER.md` (the tool, the capability, the page context key `objects`, the metadata shape, the stream event, the limits, the refusal sentences, and that `graph` arrives in the next task); mirror each `propose_view_state` mention in the other docs.

- [x] **Step 4: Run to verify they pass**, then the whole `Modules/AI/tests/Feature/Assistance` directory.

- [x] **Step 5: Commit** in `Modules/AI`: `feat(assistant): emit widget objects in messages, stream and capabilities`.

---

### Task 13: `graph` objects

**Files:**
- Modify: `Modules/AI/app/Services/Assistance/Widgets/WidgetObjectCollector.php`, `Modules/AI/app/Services/Tools/ShowWidgetTool.php`, `Modules/AI/app/Services/Assistance/AssistantCapabilities.php`, `Modules/AI/app/Services/Assistance/InAppAssistanceService.php` (pass the graph permission to the collector)
- Test: extend `Modules/AI/tests/Feature/Assistance/WidgetObjectsTest.php` and `CapabilitiesEndpointTest.php`
- Modify (docs): `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md`

**Interfaces:**
- Consumes: `Modules\Core\Graph\Contracts\GraphToolGatewayInterface::expand(GraphExpandToolInput)`, the bounds of `GraphToolProvider::centerParameters` (relations 1 to 10 paths matching `^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$`, depth 1 to 2, limit at most 25, relation limit at most 10).
- Produces: the `show_widget` `type` enum gains `graph`; for a `graph` the `request` is `{resource: 'module/entity', id: string|int, relations: list<string>, depth?: 1|2, node_detail?: 'minimal'|'summary'}`; the collector validates it with those bounds, requires the policy to allow `graph_expand`, runs `gateway->expand(new GraphExpandToolInput($module, $entity, (string) $id, $relations, $depth ?? 1, 25, 10))` once (any throwable is a refusal), and records `ref.request` as `{...request, kind: 'graph'}`; the text returned to the model is a count of nodes and edges per type, passed through `toolResultContainsInstructions`. `AssistantCapabilities::objects()` adds `'graph'` when the compiled policy with `read_only_graph` allows `graph_expand`.

- [x] **Step 1: Write the failing tests:** `records a graph object for a valid expand request` (with a fake `GraphToolGatewayInterface` bound in the container, asserting the `GraphExpandToolInput` it received); `refuses a graph with no relations, depth 3, a bad relation path or an invalid resource`; `refuses a graph when graph_expand is not allowed by the policy`; `a gateway failure is a refusal`; `a graph shares the cap and the duplicate rule with the other objects`; `features.objects includes graph only when the policy allows graph_expand`.

- [x] **Step 2: Run to verify they fail.**

- [x] **Step 3: Implement.**

- [x] **Step 4: Run to verify they pass.**

- [x] **Step 5: Commit** in `Modules/AI`: `feat(assistant): graph widget objects`.

---

### Task 14: Cross-driver check and delivery

**Files:**
- Modify: `docs/superpowers/plans/2026-10-20-aggregate-route-and-widget-tool.md` (this file: tick the boxes, add the delivery status)
- Modify: `docs/superpowers/plans/INDEX.md` and `docs/superpowers/specs/INDEX.md` (descriptions only, no status: `AGENTS.md`)

- [-] **Step 1:** Run `Modules/Core/tests/Integration/Services/Crud/AggregateEngine*Test.php` and `Modules/Core/tests/Feature/Controllers/CrudAggregateTest.php` against a local MySQL and a local PostgreSQL if available (`TestEnvironmentGuard` accepts local hosts; set `DB_CONNECTION` and the connection variables for the run only). Record the result per driver in the delivery status. A driver that cannot be run here is recorded as not verified, and the route documentation says so (spec section 8, point 2). Not run: no MySQL or PostgreSQL server was available in the work environment, so the route is verified on SQLite only, and `Modules/Core/docs/CRUD_SYSTEM.md` (Aggregate database support) says so.
- [x] **Step 2:** Run the AI and Core suites touched (`Modules/AI/tests/Feature`, `Modules/Core/tests/Feature/Controllers`, `Modules/Core/tests/Integration/Services/Crud`) and `vendor/bin/pint --format agent` over every changed file.
- [x] **Step 3:** Add `## Delivery status (<date>)` to this plan with divergences, the drivers verified, and a `**Documented in:**` line naming `Modules/Core/docs/CRUD_SYSTEM.md` and `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md`; mark any dropped step `- [-]` with its reason; update the two index rows.
- [x] **Step 4:** Commit in `laraplate`: `docs(plans): close the aggregate route and widget tool plan`. Do not push and do not move the submodule pointers; report to the owner what is waiting for their go-ahead (the pushes of `Modules/Core` and `Modules/AI`, the pointer update in `laraplate`, and the stack-root `docs/compatibility.md` entry that belongs to the UI plan).

## Delivery status (2026-11-04): delivered, verified on SQLite only

Tasks 1 to 13 are done. Task 14 Step 1, the run on MySQL and PostgreSQL, was not done (`- [-]`); Steps 3 and 4 are this closing. The decisions below come from the execution ledger `.superpowers/sdd/2026-10-20-aggregate-route-and-widget-tool/progress.md`, which holds every review finding and the reason of each ruling.

**Documented in:** `Modules/Core/docs/CRUD_SYSTEM.md` (section Aggregate Route: request, result, groups, periods, measurable columns, currency, limits, errors, database support), `Modules/Core/README.md` (the `CORE_AGGREGATE_*` variables), `Modules/Core/docs/rag/API_ACCESS_CONTROL.md` (the aggregate rows and section), `Modules/Core/docs/rag/MODULE.md` (the aggregate route paragraph), `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md` (`show_widget`, the objects, the stream event, the capabilities, `summarize`, graph objects), `Modules/AI/docs/rag/ASSISTANT_DATA_TOOLS_USER.md` (what a user sees of summaries, figures, charts and maps).

**Delivered.**
- Core: the `aggregate` route (`GET|POST /app/crud/aggregate/{module}/{entity}`, `core.crud.aggregate`, and `core.api.aggregate` on `/api/v1`), `AggregateRequest`, `AggregateQuery` (the one validator of every caller), `CrudService::aggregate()` over an `AggregateScope` of the facets' helpers, `AggregateEngine` with its collaborators (`PeriodPlan`, `AggregatePeriod`, `PeriodComparison`, `BucketSeries`, `BucketRollup`, `MeasureSql`, `CurrencyCheck`), `ColumnKinds`, `DayTruncation`, `CurrencyColumns`, `AggregateException` and `AggregateErrorCode`, the `core.aggregate.*` limits with their variables, the `aggregate` rate limiter, and the Core documentation.
- AI: `summarize` on the engine (one column, or a time bucket over a period, with `other` and `MIXED_CURRENCY`), the `show_widget` tool of the capability `widget_objects` (`ShowWidgetTool`, `WidgetObjectCollector`, `RenderableObjectTypes`, `GraphWidgetRequest`, `WidgetObject`), the objects in `message.metadata.objects`, one `ToolCall` stream event per object, `features.objects` and `limits.aggregate` in the capabilities, `graph` objects, and the AI documentation.

**Commits** (on `master` of each module repository; commits of other sessions are interleaved and not listed):
- `Modules/Core`: Task 1 `201aac9`; Task 2 `d94963b`; Task 3 `fd9ab63`; Task 4 `38cd4a3` and its follow-up `46d5975`; Task 5 `40db6fe`; Task 6 `3f6429d`, `c2dcb97`; Task 7 `01d8656`, `a6f56c1`; Task 8 `ee4f1b1`, `250fc10`; Task 9 `0360f9f`; minors sweep `19cf3f5`, `f454252`, `d40c97c`, `c616349`, `ea41191`, `9300450`, `82af892`, `d50ebae`, `198bd4e`, `40dbafa`; Task 9 documentation `afff38a`, `68aa4af`; final fix wave `553cd569`, `16d7afa3`, `3ff96782`, `9ba14b96`, `b6d2d6d9`, `4b2d9935`, `834e686d`, `7270bead`; closing documentation `a86afe54`.
- `Modules/AI`: Task 10 `553a686`, `13d4325`, `4445c05`; Task 11 `13fac7a`, `23b0c73`, `c7e8b8d`; Task 12 `0c40b08`, `a1f5168`, `1f7e176`, `d1b663d`; Task 13 `66e8fca`, `49caf09`, `2044aec`, `8204011`; final fix wave `0278cb5`, `2831988`, `4d8b2a2`, `79594b2`; closing documentation `ce38c70`.
- The Core commits up to `68aa4af` and the AI commits up to `23b0c73` are on `origin/master` and inside the submodule pointers `laraplate` records. The 9 later Core commits (`553cd569` to `a86afe54`) and the 14 later AI commits (`c7e8b8d` to `ce38c70`) are not pushed, and the pointers in `laraplate` do not include them.

**Drivers verified:** SQLite only, in the tests and in CI. MySQL, MariaDB, PostgreSQL, SQL Server and Oracle are reasoned, not run: their day expressions are tested as generated strings, and the Core documentation says the route still needs a run on MySQL and PostgreSQL before it is called supported there.

**Tests at the close:** the Core aggregate tests (Unit, Integration, `CrudAggregateTest`, `CrudErrorMappingTest`) `554 passed`; AI `tests/Unit/Services/{Tools,Assistance}` and `tests/Feature/{Assistance,Tools}` `781 passed`; the whole AI suite ran in chunks at Task 12 (`2233 passed`, 7 skipped); Pint ran on every PHP file before its commit. The whole directories named in Task 14 Step 2 were not run again as one after the final fix wave.

**Rulings made while planning, as built.**
- R1 holds: `BucketRollup` adds with `Brick\Math\BigDecimal`; `composer.json` is unchanged.
- R2 holds. The Task 3 test text "the 365 days before it" was an arithmetic slip: the partial `last 12 months` ending 2026-10-20 has 354 days, and its previous period is 2024-11-12 to 2025-10-31 (P6).
- R3 holds; `sort` and `group_by` also left the JSON keys `AggregateRequest` decodes, since `ListRequest` decodes them already (P10).
- R4 holds. A hidden or missing related row, or a `null` label, gives the key as text; no `select` on the related entity is `403`; an unreadable label column is `UNREADABLE_FIELD` on `group_by.label`; `label` with a bucket is `INVALID_FIELD`. A relation-column group is labelled with its own value as text, an own column with `null`.
- R5 holds (`AggregateScope`). R6 holds, and the bucket must group the period's own field (P14). R7 holds: the action maps `AggregateException`, `AuthorizationException`, `ValidationException`, `InvalidArgumentException` and any other `Throwable` itself.
- R8 amended: values are `int|float|string|null`, since `min` and `max` of a date column return the text the database stores.
- R9 holds on every call; the tool itself is built only for a person who is offered `summarize` (Task 12 fix), while `features.objects` stays per instance.
- R10 holds: a `kpi` has no `delta` and no `variant`, and a kpi sent with a variant is refused.

**Rulings made while executing (ledger P1 to P19).**
- P1 and P18: the AI tasks waited for another session's uncommitted edits in `Modules/AI`; meanwhile the capability name lived on `WidgetObjectCollector::CAPABILITY`, and Task 12 added `AssistantCapabilities::WIDGETS_CAPABILITY` referring to it.
- P2: Core Tasks 1 to 9 ran on `feat/aggregate-route`, merged fast-forward into `master` on 2026-10-21 at the owner's instruction; later work went on `master`. P5: model choice per task (process only).
- P3 and P9: the grouped and bucketed paths were refused until Tasks 6 and 7 replaced the refusals. P4: the graph permission and gateway reach the collector as optional trailing constructor parameters.
- P6: the 354-day correction above. P7: `last.n` is an integer from 1 to 100000 (`AggregatePeriod::MAX_UNITS`) before `max_period_days` applies.
- P8: `AggregateQuery::fromArray()` stores the period normalized (digit strings and whole floats become integers) and resolves it once, so a bad period is refused before any query.
- P10, the Task 4 follow-up `46d5975`: a non-UTF-8 string, a `measures` that is not a list or holds more than `max_measures`, a repeated measure, `group_by.label` with a bucket, and `limit` or `other` without a column group are `INVALID_FIELD`; `TimeBucket::names()`.
- P11: `other` is flat, `{<measure names>..., groups}`, as in the contract, not the `{groups, values}` of Task 6 Step 1; it is left out when no group was left out (`c2dcb97`).
- P12: a relation-column group joins inner, like the facets, so `series` plus `other` can add up to less than `total`.
- P13: Task 7 split the engine into collaborators (`BucketSeries`, `PeriodPlan`, `PeriodComparison`, `MeasureSql`, then `CurrencyCheck` in Task 8) instead of growing `AggregateEngine.php`, files the plan did not list; the period applies to every path.
- P14: a bucket must group the period's own field (`INVALID_FIELD` on `group_by.field`).
- P15: the period stays the half-open range in `Y-m-d H:i:s`; on SQLite a `date` column holding bare `Y-m-d` text written outside Eloquent loses the first day of a period (documented).
- P16: `compare.series` is always chronological, whatever `sort` says (`f454252`). The "aligned by position" of Task 7 holds only while `series` is chronological, the default; otherwise a consumer aligns by key. It can be one bucket longer or shorter than `series`.
- P17: the documentation of Task 9 (its Step 3 docs and the docs of its Step 5 commit) moved to a separate documentation task after the minors sweep, so it describes the final behaviour (`afff38a`, `68aa4af`); the Task 9 commit is `feat(crud): aggregate route, limiter and error mapping`, and its steps are ticked for that later work.
- P19: the stored graph request carries `limit: 25` and `relation_limit: 10`, the limits the model's counts came from, so the client's `GET /app/crud/graph/expand/...` draws the map the model described (`2044aec`).

**Other divergences from the plan text.**
- Task 1: `AggregateLimits::fromConfig()` keeps a value only when `is_numeric` and at least 1, instead of an `(int)` cast.
- Task 2: `ColumnKinds` also knows the PostgreSQL names `int2`, `int4`, `int8`, `float4`, `float8` and reads the casts through `getCasts()`; the Oracle and SQL Server names came with C4; a temporal cast on a column the schema stores as a number is `other` (S3). `CurrencyColumnsTest` is an Integration test.
- Task 4: `compare` accepts `"previous_period"` or `true` and is stored as `"previous_period"`; `canonical()` holds neither `resource` nor `filters`, so the duplicate rule of the widget tool compares type, variant, resource, filters and `canonical()`.
- Task 5: the engine re-checks `max_measures`; with S4 it re-checks every invariant whose absence gives a wrong figure (a bucket or `compare` needs a period; `limit` and `other` only with a column group).
- Task 8: `null` and the empty string are not currencies (`nullif(col, '')`, `250fc10`); grouping by the amount's own currency column keeps the group values and leaves `total` and `other` `null`, with no warning and no `meta.currency`.
- Task 9: `AggregateException` implements `Responsable`, because a failed validation is thrown while the framework resolves the request, before the action's `try`. The `ShouldntReport` it had at first was replaced in S7 by `report()` returning `! $reportable`, with `DayTruncation`'s unknown-driver refusal built `reportable: true`, and the action calls `report($exception)` (`198bd4e`). The `403` and `401` body is the list's refusal body (`error` a string, `data: []`) and the `429` body Laravel's `{message}`, both outside the `{error: {...}}` envelope; an `InvalidArgumentException` of the list machinery is `422 INVALID_FIELD` without `field`, logged as a notice. The limiter keys a Core user by id and anyone else (anonymous, no email, another `Authenticatable`) by address (C5).
- Task 10: for a column group `summarize` sends `limit` = `max_groups` and `other: true` and puts the first requested metric first, so the groups shown are ranked by it; with no `group_by` it gives one entry (count 0 on no rows); a metric with no value is `null`; refusals carry `code` and `field`; an unexpected failure is reported and answered with a fixed text, a refused permission with its own message, a refused scope node with its message (A3); `group_by` is strict (a plain string is `INVALID_FIELD`).
- Task 11: `ref.request` is the validated request (`kind`, `resource`, `AggregateQuery::toArray()` with `sort` always written, `filters` when there are some); `request` and `format` must be JSON text; the filters are validated with Core's own rules (`QueryBuilder`, `ClientScopeFilters`), stored and run as validated, their keys whitelisted at every depth (`CrudToolProvider::aggregate()` gained an optional `filtersAreValidated` flag); one `PlainText` helper checks the titles of both collectors; `maxRuns` is `max_objects_per_message` + 2 (8).
- Task 12: the tool is built only when the acting person is offered `summarize` (kpi, chart) or the policy allows `graph_expand` (graph), and the page declared a type the run can use; a scope without data access gets no widget tool; the documentation says the application computes the figures and the title is model text, digits included.
- Task 13: the `type` enum and the descriptions list exactly the types of the run; the graph validation, canonical form and counts live in `GraphWidgetRequest`; a message expands at most 3 graphs (`GraphToolProvider::MAX_RUNS`); the per-message cap is checked before the type branch; relation paths compare case-insensitively, `7` and `"7"` are one id, `0` is an id, an explicit `null` depth or node detail is refused; the model is told `truncated` only, never that records were left out for permissions (the Core gateway drops `filteredByAcl`); with no entity opted into `summarize`, `features.objects` is `["graph"]` when the policy allows `graph_expand`. `Modules/Core/docs/GRAPH_SYSTEM.md` names no widget: the graph object is documented in the AI developer guide.

**Minors sweep (S1 to S7, chosen by the owner on 2026-10-21, Core).**
- S1: a stored value the database cannot read as a day fails the request (`500`, reported) instead of leaving the series while `total` counts it.
- S2: `compare.series` is chronological (P16). S3: a temporal cast on a numeric schema type is not a date column.
- S4: an unsupported bucket driver is refused before any query, and the engine re-checks the invariants above for a query built without the validator.
- S5: an unreadable filter column is `UNREADABLE_FIELD` on `filters.<property>`, an unknown plain one `INVALID_FIELD`.
- S6: the `null` group sorts last in both directions under `sort.by = key`, and after the groups it ties with under `sort.by = measure`.
- S7: the `reportable` flag of `AggregateException` and the notice for an `InvalidArgumentException` (Task 9 above).

**Final fix wave (after the whole-work review, 2026-11-04).**
- C1: an amount whose paired currency column the caller cannot read is `UNREADABLE_FIELD` on `measures.N.field`; by the pairing rule this covers every numeric non-key column paired with it.
- C2: a client `relation` filter node, at any depth, is `INVALID_FIELD` on `filters`: the list parser reads it as a group of the main record (`ListRequestData::$hasRelationFilterNode` records it). The list route is unchanged.
- C3: `max_objects_per_message` is documented as read by `show_widget`. C4: the Oracle (`number`, `binary_double`, `binary_float`) and SQL Server (`smallmoney`, `datetime2`, `smalldatetime`, `datetimeoffset`) type names.
- C5: the limiter keys a user of another kind by address; a relation group on the entity's own table is `INVALID_FIELD` on `group_by.field`; the ranking of a mixed-currency amount under `sort.by = measure` is documented; a spring-forward test; a dead `RateLimiter::clear` removed.
- A1: `show_widget` refuses a `relation` filter node and stores neither `relation` nor `morph_types`. A2: tests send a stored aggregate request (as a body and as a query string) and a stored graph request unchanged to the routes: both answer 200, no stored key needed a fix.
- A3: `summarize` answers a refused scope node with its message, as `show_widget` does. A4: the ranking of a mixed-currency amount and `features.objects` not depending on `enabled` are documented.

**Deferred minors** (each with its finding in the ledger):
- Core request and engine: defaults repeated in the config and the constants; boolean and enum casts fall through to the schema type; a hand-built `AggregatePeriod` may have `from` after `to`; a `limit` of 20 digits is `INVALID_FIELD` through the route and `TOO_MANY_GROUPS` through `fromArray()`.
  `CrudService` grew about 220 lines of aggregate checks; the relation-group join repeats the facets' one; under `sort.by = measure` a `null` first measure follows the driver's null order; a dotted filter path ending in an unknown column is not refused up front, as in the list.
- Core currency: the partials cover every row of a cell, rows with a `null` amount included; `sum(quantity)` on a table with a currency column is an amount; a mix only in the previous period drops `meta.currency` without `meta.currencies`.
  Values are not trimmed (`'EUR '` beside `'EUR'` is two currencies); `nullif` on an integer currency column is not run on PostgreSQL; `MeasureSql` takes a boolean `previous` flag.
- Core route and docs: the `InvalidArgumentException` arm answers any such message; the `AuthorizationException` arm repeats the mapping of `handleServiceCall`; `aggregateRefusal()` builds an exception only to render it; one middleware assertion is weak.
  `docs/rag/API_ACCESS_CONTROL.md` line 69 names only request fields (the paired currency column is in the paragraph below it); no test pins the message of a malformed relation node on the route.
- AI tools: `AggregateLimits::fromConfig()` is read for every operation; the size of an answer is bounded by the config only; `CrudToolProvider.php` (about 1500 lines) and the collector repeat helpers of Core (`sortKeys`, `filters`), and no `WidgetFormat` value object trims the collector.
  Refusals quote Core's validator text with its path; `WidgetObjectsTest` has no positive test of a scope node; objects survive an insufficient-evidence answer (kept, as proposals and writes are); the `DataAccess::None` branch is unreachable through `respond()` and untested; a chart-only run is still described as "a figure (kpi) or a chart".
- Core graph gateway, seen from Task 13: `GraphToolGateway::expand()` swallows every `Throwable`, so a database failure is answered with the domain sentence and reported nowhere.
  Roles given to an `App\Models\User` are not found through `core/users`; `GraphToolGateway::sanitizeGraph()` drops `filteredByAcl`, so the model cannot be told that records were left out for permissions.
- Portability, reasoned and not proven: the session date formats of Oracle and SQL Server, the day strings of SQL Server and the `union` with `limit` of the currencies query off SQLite.

**Open points for the owner.**
- (a) Core's list parser recognizes the request operators `eq`, `ne`, `gt`, `ge`, `lt`, `le` (with `like`, `not like`, `in`, `between`), not the symbols `=`, `!=`, `>`, `>=`, `<`, `<=`: `FilterOperator::tryFromRequestOperator()` matches none of them and `ListRequestData::conformFilterOperators()` falls back to `=`, which is right only for `=` (measured: `!=` counted 1 row where `ne` counted 4). Core's own documentation (`CRUD_SYSTEM.md`, Filter Operators and its examples) and the `filters` description of the AI CRUD tools (`CrudToolProvider.php`) tell clients and the model to use the symbols, so the list, the facets, the aggregate route, `summarize` and `show_widget` can answer figures over the wrong rows. Pre-existing, not fixed here.
- (b) Core parses a client `{relation, filters}` node as a plain group on the main record (`ListRequestData::conformFilterNode()` has no relation branch), in the list, the facets and the search. The aggregate route and `show_widget` refuse it; the list routes still apply it silently.
- (c) `CrudToolProvider::fail()` still answers the other AI CRUD tools (list, search, export and the rest) with the exception message, which for a `QueryException` holds SQL. `summarize` no longer does.
- (d) The run on MySQL and PostgreSQL (Task 14 Step 1) is still to do before the route is called supported there.
- (e) The 2026-11-04 commits of `Modules/Core` and `Modules/AI` listed above are not pushed, and the submodule pointers in `laraplate` are not updated to them.
