# Aggregate route and widget tool (backend)

**Date:** 2026-10-20
**Status:** Implemented (2026-11-04), verified on SQLite only. Agreed with the project owner section by section on 2026-10-20 and built by `docs/superpowers/plans/2026-10-20-aggregate-route-and-widget-tool.md`; its delivery status lists every divergence and ruling. Statements the code superseded carry an *As built* note below; the behaviour of today is documented in `Modules/Core/docs/CRUD_SYSTEM.md` (section Aggregate Route) and `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md`.
**Contract:** `docs/superpowers/specs/2026-10-20-widget-requests-and-objects-contract.md` at the stack root. This spec is the backend half of it: the shapes of the request, the result, the errors and the objects are defined there and are not repeated here, except where this spec fixes a value the contract left open. The UI half is a separate spec at the stack root.
**Related:** `2026-08-06-crud-facet-counters-design.md` (the pattern copied), `2026-07-09-database-guidelines-design.md`, `2026-10-07-assistant-governed-writes-design.md`, `2026-07-16-in-app-ai-assistance-security-design.md`, `Modules/Core/docs/CRUD_SYSTEM.md`, `Modules/Core/docs/GRAPH_SYSTEM.md`, `Modules/AI/docs/rag/ASSISTANT_PROPOSALS_DEVELOPER.md`.

---

## 1. Goal

Add one CRUD route, `aggregate`, that answers "how much, how many, how it changed over time" under the same permissions as the list, and give the in-app assistant two things built on it: `summarize` that uses the same engine, and a `show_widget` tool that validates a widget request and hands the client an object to render. The model composes requests and never writes numbers. *As built:* the model never writes the figures, but the object's `title` is model text and may hold digits (5.2).

## 2. Decisions

| Topic | Decision |
|---|---|
| Route | `GET\|POST /crud/aggregate/{module}/{entity}`, a sibling of facets, in `Modules/Core/routes/crud.php`. |
| Engine | A Core service behind `CrudService::aggregate()`. `summarize` and `show_widget` call the same code path. |
| Time buckets | SQL groups by **day** and returns partial aggregates; week, month, quarter and year are rolled up in PHP. One portable SQL expression per driver. |
| Time zone | Days are the days of the application time zone, reported in `meta`. A per-user time zone is out of scope. |
| Measurable columns | Cast first, schema type second, keys excluded. |
| Currency | A currency column beside the amount is detected by convention. Mixed currencies give a `null` value and a warning, never a sum. |
| Labels | A to-one relation column is its own label; a foreign-key group takes an optional `label`, resolved through the facet label source. |
| Limits | A config block, a named rate limiter, structured errors. No hard timeout in v1. |
| Assistant | `summarize` delegates. `show_widget` is a tool of a new policy capability, `widget_objects`, and objects travel in `message.metadata.objects[]`. |

### Where this spec fixes what the contract left open

| Contract | Here |
|---|---|
| Open question 1, time zone | Section 4.4: the application time zone. |
| Open question 2, what is measurable | Section 4.5. |
| Open question 3, mixed currencies | Section 4.6: `null` value plus `MIXED_CURRENCY` for the affected value (the contract's "no total", extended to every cell that mixes). |
| Open question 4, group labels | Section 4.7. |
| `max_period_days` (no value) | 1096. |
| Objects per message (6) | Kept: 6, configurable. |
| `features.objects` | A list of the types the backend can emit, as in the contract, empty when none. |
| `context.page.objects` | Kept: the client declares the types it can render. |

## 3. What exists today (verified 2026-10-20)

- **Databases.** `config/database.php` declares sqlite (default), mysql, mariadb, pgsql, sqlsrv and oracle; the database guidelines name MySQL, MariaDB, PostgreSQL, Oracle and SQLite. Tests run on SQLite in memory (`phpunit.xml`), and the one CI workflow runs on SQLite. There is **no** helper that builds driver-specific date SQL (`strftime`, `DATE_FORMAT`, `date_trunc` and similar are absent from `Modules/*/app` and `app`).
- **Time zone.** `APP_TIMEZONE`, default UTC. No per-user time zone: the `users.preferences` bag holds UI settings only. `between` is a raw `whereBetween` with no time zone handling (`QueryBuilder.php`). API dates leave as UTC ISO strings.
- **Schema.** `SchemaInspector` returns `Table`/`Column`/`ForeignKey`, and `Column::type` is `DoctrineTypeEnum::fromString` of the raw type. On SQLite the raw type keeps its precision (`decimal(15,4)`), which maps to `Unknown`; only bare names map. There is no numeric or date helper. `ModelMetadata` exposes `casts`, `keyName` and `keyType`.
- **Readable columns.** `ReadableColumnGuard::assertReadablePath(Model, string)` checks every segment of a dotted path through relations; `CrudService` already calls it for aggregates, sorts, `group_by`, filters and facets.
- **Facets** (`CrudService`): `ensurePermission(select)`, `authorizeRequestedRelations`, the ACL query (`applyAclFiltersToQuery`), the list filters (`applyFilters`), a to-one join through `resolveToOneColumn` with `relationParentIds` and `visibleRelatedKeys`, and labels through `FacetLabelSource` and `resolveFacetLabels`. The controller action is `CrudController::facets(FacetsRequest)`; `FacetsRequest extends ListRequest`.
- **Currency.** ERP tables keep a `char(3) currency` beside the amounts of the document (invoices, sales and purchase orders, quotations, price lists, payment runs); movements and payments keep pairs, `amount_doc`/`currency_doc` and `amount_local`/`currency_local`. Line tables have no currency: it is on the parent. There is no Currency model or enum.
- **Rate limits and timeouts.** `/app/crud` has no `throttle`; `/api/v1` has the `api` limiter. No timeout handling exists (`max_execution_time`, `statement_timeout` and similar are absent).
- **Assistant.** The in-app profile is compiled from `AssistantPolicyCatalog`: tools reach a run only through a capability (`ui_proposals`, `crud_reads`, `governed_writes`, and others). `InAppAssistanceService` builds the proposal collector from `context.page.proposable`, and stores `{citations, proposals, writes}` as the metadata of the assistant message. `AssistantCapabilities::toArray()` returns `{enabled, configured, features: {help, proposals, writes, streaming}}`. `summarize` lives in `CrudToolProvider`, reads at most `SUMMARIZE_ROW_CAP` (5000) rows through the list and aggregates them in PHP; its allowlist is `ai.features.tools.crud.entities`.

## 4. The engine and the route

### 4.1 Pieces

| Piece | Where | Role |
|---|---|---|
| `AggregateRequest` | `Modules/Core/app/Http/Requests` | Extends `ListRequest`. Shape rules for `measures`, `group_by`, `period`, `sort`, `limit`, `other`, `compare`. A shape error is thrown as an `AggregateException` (below), so every error of the route is structured. *As built:* every `422` is structured, but the `403`/`401` refusal, the `429` of the limiter and the `500` are not (4.8). |
| `AggregateQuery` | `Modules/Core/app/Services/Crud` (DTO) | The validated request, as a value. Built from the HTTP request, from a saved widget and from the `show_widget` tool, so the three share one validator. |
| `AggregateEngine` | `Modules/Core/app/Services/Crud` | Runs an `AggregateQuery` and returns the result of the contract. Called by `CrudService::aggregate()`. |
| `ColumnKinds` | `Modules/Core/app/Support` | Says whether a column of a model is `numeric`, `temporal` or `other` (4.5). |
| `DayTruncation` | `Modules/Core/app/Support` | The "truncate to day" expression per driver (4.4). |
| `AggregateException` | `Modules/Core/app/Exceptions` | Carries `code`, `message` and the offending `field`. Codes are the contract's. |
| `CrudController::aggregate` | `Modules/Core/app/Http/Controllers` | The action, named `core.crud.aggregate`, mounted under `app/crud` and, like facets, under `api/v1`. |

### 4.2 Flow

1. `ensurePermission(select)` for the table; `authorizeRequestedRelations` for relations named by `group_by`, measures and filters.
2. `ReadableColumnGuard::assertReadablePath` on every field of `measures`, `group_by`, `period`, `sort` and `filters`. A field the caller cannot read is `UNREADABLE_FIELD`. *As built:* the currency column paired with an amount is checked too (4.6), and a client `relation` filter node is refused with `INVALID_FIELD` on `filters`, at any depth, because the list parser reads it as a group of the main record.
3. `ColumnKinds` checks each measure (4.5) and the `period` field (`temporal`).
4. Resolve the period (4.4) and check the limits that need no query (`max_period_days`, `max_points`).
5. Build the base query exactly as the facets do: the ACL query, then the list filters, then the period as a half-open range `field >= from 00:00:00 and field < to + 1 day`, in the application time zone.
6. Run the queries of 4.3, check `max_groups`, assemble the result and `meta`.

The row-level ACL and the list filters therefore bound every sum, count and average: a figure never includes a row the list would not show.

### 4.3 Queries

- **Total.** One query over the base query: the measures, plus `count(*)` for `meta.row_count`.
- **Grouped by a column.** One query grouped by the column, ordered by `sort` (default: the first measure, descending), with `limit`. The group key is the value of the column: an own column, or a column of a single-hop to-one relation (`customer.name`), reached with the join of `facetRelatedColumnValues` (a `BelongsTo` gives at most one related row, so no parent is counted twice). The group count is `count(distinct key)` and goes to `meta.group_count`. The measures are computed by SQL (`avg` included). *As built:* the join is inner, so `series` plus `other` can add up to less than `total`, and a relation to the entity's own table is refused with `INVALID_FIELD` on `group_by.field`.
- **Grouped by a time bucket.** One query grouped by the truncated day (4.4) returning **partials** per day: for `count`, `count(*)` or `count(field)`; for `sum`, the sum; for `avg`, the sum and `count(field)`; for `min` and `max`, the value. PHP sums the days into the requested bucket: sums are added with decimal-safe arithmetic (the plan picks the library the Core already uses for money), `avg` is the sum over the count of non-null values, `min` and `max` are taken over the days. Empty buckets of the period are filled (contract 5.1). The number of rows is bounded by `max_period_days`.
- **`other`.** With `limit` and `other: true`, one more query over the base query excluding the groups that were shown, so `avg`, `min` and `max` of the rest are correct, not derived by subtraction. `other.groups` is `group_count` minus the groups shown. *As built:* `other` is flat, `{ <measure names>..., groups }`, and is left out when no group was left out, even with `other: true`.
- **`compare`.** The same total (and, for a bucketed request, the same series) over the previous period. `compare.series` exists only for a bucketed request and is aligned to the current one by position. *As built:* `compare.series` is always chronological, whatever `sort` says, so it stands beside `series` by position only while `series` is chronological (the default); otherwise a consumer aligns the two by key. It can be one bucket longer or shorter than `series` when the period is not whole months.
- **`truncated`** is true when groups were left out of `series`, whether or not they were folded into `other`.

A relation measure is not part of v1: a measure is a column of the entity itself, or `count`.

### 4.4 Time buckets and time zone

- **Day expression**, by driver of the connection:

| Driver | Expression |
|---|---|
| sqlite | `date(col)` |
| mysql, mariadb | `DATE(col)` |
| pgsql | `CAST(col AS date)` |
| sqlsrv | `CAST(col AS date)` |
| oracle | `TRUNC(col)` |

  Only the sqlite branch runs in CI. The others are unit-tested as generated strings, and the first run against a real server is a release check (section 8). A bucket on a driver with no entry is refused with `UNSUPPORTED_FUNCTION` on the field `group_by.bucket`.
- **Roll-up.** A day belongs to a **week** that starts on Monday (ISO), a **month**, a **quarter** or a **year** of the application calendar. A bucket key is the date of its first day (`2026-07-01` for a month or for the quarter that starts there), and the client localizes the label.
- **Time zone.** The stored values are in the application time zone, so a day of the database is a day of that zone. `meta.resolved_period.timezone` says which. No conversion is done, which keeps the query portable (no `CONVERT_TZ`, no time zone tables). A per-user zone is in the backlog.
- **Resolving a period.** All dates are in the application zone and `to` is inclusive.
  - `last: {n, unit}` ends today and starts at the beginning of the unit `n - 1` units before the current one (`last 12 months` is twelve calendar months, the current one included, partial). For `day` it is `n - 1` days before today.
  - `preset` is a closed list: `today`, `yesterday`, `this_week`, `last_week`, `this_month`, `last_month`, `this_quarter`, `last_quarter`, `this_year`, `last_year`. A `this_*` preset is the whole unit, including the days that have not come yet.
  - `from`/`to` are dates, `from <= to`.
  - The **previous period** is the same length immediately before, shifted by calendar units when the period is expressed in them (months, quarters, years) and by days otherwise.
  - The period field must be `temporal`.

### 4.5 Measurable columns

`ColumnKinds` answers by the cast of the model first (`ModelMetadata::casts`: integer, float, double and decimal casts are `numeric`; date and datetime casts, mutable or immutable, are `temporal`) and by the schema type second. For the schema type the raw type is normalized before it is mapped (the precision is cut: `decimal(15,4)` is `decimal`), because the raw type on SQLite does not map as it is. A column that has neither is `other`. *As built:* a temporal cast on a column the schema stores as a number (unix seconds cast as `timestamp`) is `other`.

- **Keys are not measurable.** A primary key, a foreign key (`ForeignKey::localColumnNames`) and the model's `keyName` are `other` for `sum` and `avg`. Summing an `id` is meaningless.
- `count` works on any readable column, or with no field.
- `min` and `max` work on `numeric` and `temporal` columns.
- `sum` and `avg` work on `numeric` columns only; anything else is `NOT_MEASURABLE`.
- Nothing is declared per entity: readability is the guard's job, so a hidden column is `UNREADABLE_FIELD`, never a different number.

### 4.6 Currency

When the aggregated table has a column named `currency`, or for an amount named `<x>_<suffix>` a column named `currency_<suffix>`, that column is the currency of the amount. For a `sum` or `avg` over such an amount the day (or group) partials also carry `min(currency)` and `max(currency)`. *As built:* `min` and `max` are checked as `sum` and `avg` are; `null` and the empty string are not currencies; and an amount whose paired currency column the caller cannot read is refused with `UNREADABLE_FIELD` on `measures.N.field`, since its figure could not be checked (by the pairing rule this covers every numeric non-key column paired with that column).

- A value whose rows all share one currency is normal. When the whole result has one currency, `meta.currency` carries it, so the widget can format it without the request saying so.
- A value whose rows hold more than one currency (a group, a bucket, `other`, or the total of an ungrouped measure) is `null`, and `meta.warnings` gets `{ code: 'MIXED_CURRENCY', field: <measure>, message }` once per measure. `meta.currencies` lists the currencies met.
- Grouping by the currency column (`group_by: { field: 'currency' }`) splits a result into single-currency groups, which is the way to get figures when currencies are mixed. *As built:* the group values stay numbers, while `total` and `other`, which add the groups, are `null` with no warning and no `meta.currency`.
- No conversion is done in v1. `ExchangeRate` exists in the ERP and is out of scope.
- An amount with no currency column on its table (an order line, whose currency is on the parent) takes the `format.currency` of the request, and the backend does not check it.

### 4.7 Labels of groups

A group on a column has no label of its own: its key is the stored value, and the client translates enums and booleans. A group through a to-one relation column (`customer.name`) is its own label, as in the facets. To label a group on a foreign-key column (`customer_id`), `group_by` may carry `label`, a single-hop to-one relation column (`customer.name`): the labels of the groups shown are resolved in one bounded read through `resolveFacetLabels` (a `FacetLabelSource` the model declares, or the `BelongsTo` whose foreign key is the group key), under the permission and ACL of the related entity, falling back to the key. A group whose value is `null` has the key `null` and no label. `label` is an addition to the request shape of the contract (section 5.1 there). *As built:* a hidden or missing related row, or a `null` label, gives the key as text; no `select` on the related entity is refused like a relation of the list (`403`); a label column the caller cannot read is `UNREADABLE_FIELD` on `group_by.label`; `label` with a bucket is `INVALID_FIELD`.

### 4.8 Limits, rate limit, errors

- **Limits.** A new `aggregate` block in the Core config, with an environment override for each: `max_measures` 3, `max_groups` 50, `max_points` 400, `max_period_days` 1096, `max_objects_per_message` 6 (used by section 5), and `rate_per_minute` 60.
  - A `limit` above `max_groups` is `TOO_MANY_GROUPS`. A grouped request with no `limit` whose `group_count` exceeds `max_groups` is `TOO_MANY_GROUPS` too, and its message asks the caller to narrow it or to set a `limit`.
  - A resolved period over `max_period_days`, or a bucket that gives more than `max_points` points, is `PERIOD_TOO_LONG`, and its message names a coarser bucket.
- **Rate limit.** A named limiter, `aggregate`, defined next to the `api` limiter in the Core service provider, per user, applied to this route with `throttle:aggregate`. On `/api/v1` it adds to the existing one.
- **Timeout.** None in v1: the driver-specific mechanisms (`MAX_EXECUTION_TIME`, `statement_timeout`) cannot be exercised in CI. The code `TIMEOUT` stays in the contract and the backend does not emit it yet.
- **Errors.** `{ "error": { "code", "message", "field"? } }`. `403` when `select` is missing on the table, `422` for every other code of the contract. A `QueryException` is written to the log and answered as `500` with a generic message and **no SQL text** (facets answer with the message today; this route does not copy that). The `MIXED_CURRENCY` warning is not an error: it travels in `meta.warnings` of a `200`. *As built:* the `403` (and the `401` of the anonymous user) carries the refusal body of the list, `{ "error": "<text>", "data": [] }`, where `error` is a string; the `429` carries Laravel's `{ "message" }`; the `500` is `{ "error": { "message": "The aggregate could not be computed." } }` with no `code`. Any failure other than a refusal is reported, not only a `QueryException`. An `AggregateException` is not written to the log unless it is a fault of the deployment (an unsupported driver for a bucket): the exception carries a `reportable` flag and a `report()` method, instead of the `ShouldntReport` it was first built with.

### 4.9 Security

Every field is checked against the model and the caller's readable columns before it is used, and a field name never reaches SQL as text: SQL is built from resolved columns of the model, the functions and the buckets are closed lists. The ACL and the list filters bound every aggregate (4.2). `limit`, `group_by` and `period` bound the work, and a guest is refused like on the list. The route is exposed on `/api/v1` under the same switch as the rest of the CRUD API.

## 5. Assistant

### 5.1 `summarize` delegates

`summarize` keeps its name, its allowlist (`ai.features.tools.crud.entities`), its `select` permission check and the compact shape of the answer for the model. It builds an `AggregateQuery` from its `group_by` and `metrics` and calls `CrudService::aggregate()`, instead of fetching up to 5000 rows and summing in PHP. The engine has one grouping dimension: a `group_by` with more than one column is answered with the tool's usual error shape, saying that one column is allowed. A `sum` or `avg` over a primary or foreign key is `NOT_MEASURABLE` (the existing test sums `id`: it moves to a numeric column that is not a key). `SUMMARIZE_ROW_CAP` goes away. It gains `bucket`, `period`, the `total` and `other` of the result, and the warning `MIXED_CURRENCY`, which it reports to the model in words. The behavior pinned by `CrudToolProviderTest` stays green; where the two engines disagree (the old one grouped by exact value in PHP and capped rows) the test is updated to the exact result and the change is noted in the plan.

### 5.2 Tool `show_widget`

- **Class and capability.** `ShowWidgetTool`, next to `ProposeViewStateTool`, with `NAME = 'show_widget'`. It belongs to a new capability of `AssistantPolicyCatalog`, `widget_objects`, whose instruction says that an object shows data the backend computes, that the model states what it shows and never invents the numbers, and that the object is rendered by the application. The in-app profile allows the tool. The tool is built for a run only when the compiled policy allows it, when at least one entity is allowlisted for the user, and when the page declared at least one type it can render. *As built:* the tool is built when the page declared a type the run can use: `kpi` or `chart` when the acting person is offered `summarize` on at least one entity, `graph` when the policy allows `graph_expand`, with or without such an entity. Its `type` enum lists exactly those types.
- **Parameters.** `type` (`kpi`, `chart`, `graph`), `title`, `request` (the structured request of the contract as JSON text, as the proposal tools pass their value), and `variant` or `format`.
- **What it does.**
  1. The entity must offer the `summarize` operation to the caller: it is in `ai.features.tools.crud.entities` with `summarize`, which is the operator's opt-in to aggregation for the assistant, and the caller holds `select` (`CrudToolProvider::offeredOperations()` already says both).
  2. The type must be among the types the client declared in `context.page.objects` (a closed list, at most three entries).
  3. The request is parsed into an `AggregateQuery` (or a graph request, 5.5), which is the same validator as the route.
  4. It is run once through `CrudService::aggregate()`, bounded by the same limits. An `AggregateException` is returned to the model as a refusal that names the code and the field, so the model can correct and retry.
  5. On success, a `WidgetObjectCollector` (the sibling of `UiProposalCollector`) stores the object: `id`, `type`, `title`, `ref: { request, variant?, format?, delta? }`. *As built:* no `delta` in v1 (plan ruling R10); `ref.request` is the validated request with `kind` and `resource` and the applied `sort` always written; a graph `ref` holds only `request`. The result handed back to the model is a compact summary of the figures, and passes through `ToolResultGuard` like the other data tools.
- **Rules of the collector.** At most `max_objects_per_message` (6) objects per message; two objects with the same canonical request in one message are refused as a duplicate; `title` is plain text of at most 80 characters (the same plain-text check as a proposal reason); a refusal is a sentence for the model, never an exception. The object holds the request, not the figures. *As built:* the duplicate rule compares type, variant and request; a message expands at most 3 graphs; the title may hold digits, so a number in it is not a computed figure.
- **Audit.** The request is logged with the tool call, the result is not.

### 5.3 Emission

- `InAppAssistanceService` adds `objects` to the metadata of the assistant message, after `proposals` and `writes`: `{ citations, proposals?, writes?, objects? }`, each entry `{ id, type, title, ref }` as in contract section 6. A run with no object adds no key. The refusal path (`{ refused: true }`) is unchanged.
- The streaming run (`POST /app/ai/agent`) gets an event for an object, built like `AgentEvent::proposal`. An object does not interrupt the run: `runFinished` is unchanged and still interrupts only for proposals.
- Every place that returns the metadata of a stored message returns `objects` with it; the plan lists them and tests each.

### 5.4 Capabilities

`AssistantCapabilities::toArray()` becomes `{ enabled, configured, features: { help, proposals, writes, streaming, objects }, limits: { aggregate } }`:

- `features.objects` is the list of types the backend can emit: `[]` when the capability is off or no entity is allowlisted, otherwise `['kpi','chart']`, plus `'graph'` when the graph tool is available. A client that reads an empty list, or a response with no such key (an older backend), has the assistant answer in text only. *As built:* the list is per instance, in the order `kpi`, `chart`, `graph`: `kpi` and `chart` when an entity opts into `summarize`, `graph` when the in-app policy allows `graph_expand`, whether or not an entity opts in, `[]` when the policy allows no `show_widget`. It does not depend on `enabled`. Whether a given person gets the tool is decided per turn (5.2).
- `limits.aggregate` is `{ max_measures, max_groups, max_points, max_period_days }`, read from the Core config block of 4.8.

### 5.5 `graph` objects

A `graph` request is validated by the Core Graph request rules and run through the same service the graph tools use (`GraphToolProvider`), with the depth and size limits of Core Graph. It ships as the last task of the plan, behind its own entry of `features.objects`, so the rest does not wait on it. *As built:* the request is validated by the AI module (`GraphWidgetRequest`) with the bounds of the `graph_expand` tool and expanded once through `GraphToolGatewayInterface::expand()`; the stored request is `{ kind: 'graph', resource, id, relations, depth, node_detail?, limit: 25, relation_limit: 10 }`, so the client's `GET /app/crud/graph/expand/...` draws the map the model's counts describe. The model is told the counts and `truncated`, never that records were left out for permissions.

## 6. Tests

Pest, on SQLite, in the conventions of the facets tests.

- **Core feature** (`CrudAggregateTest`, route `core.crud.aggregate`): a guest is refused; `select` missing is `403`; every code of the contract is returned in its structured form with its field; a field the caller cannot read is `UNREADABLE_FIELD`; a `QueryException` gives a generic `500`; the limiter answers `429` over the rate.
- **Core integration** (`AggregateEngine*Test`): `count`, `sum`, `avg`, `min`, `max`; grouping by an own column and by a to-one relation, with labels; buckets (day, week on a Monday, month, quarter, year), the `avg` derived from the partials, the empty buckets filled, a period crossing a year; `limit` with `other` (including `avg` of the rest) and `truncated`; `compare` for a total and for a series; the period forms; the currency cases (one currency, two, grouped by currency, no currency column); `ColumnKinds` for each cast and for a `decimal(15,4)` column on SQLite, keys excluded.
- **ACL.** A row-level restriction narrows sums, counts and averages exactly as it narrows the list (modeled on `ContentFacetsAcl`), and a related row the caller cannot see is not a label.
- **Unit** (`DayTruncation`): the string of each driver; the sqlite one also run.
- **AI.** `ShowWidgetToolTest`: an entity outside the allowlist, a missing permission, an invalid request returned as a refusal the model can read, a type the page did not declare, the cap of 6, a duplicate. The metadata of a message carries `objects`, and a run with none carries no key. `summarize` regression (`CrudToolProviderTest` on the engine). `CapabilitiesEndpointTest` for `features.objects` and `limits.aggregate`, and for the old shape when the capability is off.

## 7. Documentation sync

When this is built, update together: `Modules/Core/docs/CRUD_SYSTEM.md` (the route, its request and result, the limits), `Modules/Core/docs/GRAPH_SYSTEM.md` (the object type), `Modules/AI/docs/rag/ASSISTANT_PROPOSALS_DEVELOPER.md` and the AI module docs (the tool, the capability, `objects`), the user-facing assistant documentation if it lists what the assistant can show, `docs/superpowers/specs/INDEX.md`, and at the stack root `docs/compatibility.md` (`aggregate`, `features.objects`, `limits.aggregate`) and the contract (section 11 resolved by this spec).

## 8. Open points

1. **Where the UI learns the limits when the AI module is off.** `limits.aggregate` is published by the AI capabilities, but a widget pinned to a dashboard does not need the assistant. Until the UI spec decides, every limit is also a structured error of the route, so the UI works without knowing them.
2. **First run on a real server.** The `DayTruncation` expressions for mysql, mariadb, pgsql, sqlsrv and oracle are covered only as strings. A release check runs the engine tests on at least MySQL and PostgreSQL before the route is documented as supported there. *Still open on 2026-11-04:* no such server was available when the plan closed.
3. **Values** proposed and not tested against real data: the limits of 4.8, the preset list of 4.4 and the rate of 60 a minute.

## 9. Out of scope and backlog

- Extending `select` with a constraint on per-row aggregates (a list column such as "Q3 revenue").
- A per-user time zone, a hard timeout per driver, a server-side cache, polling.
- Grouping by two dimensions, distinct counts, a measure on a related entity, currency conversion.
- Export and sharing of a widget.

## 10. Divergences from this spec (2026-11-04)

The *As built* notes above mark the statements of this spec that the implementation changed: the statuses outside the error envelope (4.1, 4.8), the paired currency column and the refused `relation` filter nodes (4.2, 4.6), the inner join and the self-referencing relation (4.3), the flat `other` and the chronological `compare.series` (4.3), the temporal cast on a numeric column (4.5), the labels (4.7), the gate of the tool, the stored object and `features.objects` (5.2, 5.4), and the stored graph request with its limits (5.5). The complete list, with the reason of each ruling and the work left open, is the delivery status of `docs/superpowers/plans/2026-10-20-aggregate-route-and-widget-tool.md`. Of section 7, `Modules/Core/docs/GRAPH_SYSTEM.md` was not changed: the graph object is described in `Modules/AI/docs/rag/ASSISTANT_WIDGETS_DEVELOPER.md`.
