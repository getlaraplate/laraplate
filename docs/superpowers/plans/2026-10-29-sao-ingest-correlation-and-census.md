---
status: open
created_on: 2026-10-29
module: sao
---
# SAO ingest correlation rules, reprocessing, recurrence and deploy containment. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** operator correlation rules replace the logs fan-out, unmatched events can be reprocessed, a recurring signal comments on its open ticket, environments know their deployed commit (pushed or probed), and SAO tells whether a fix commit is inside each environment's commit.

**Architecture:** five slices, one SAO branch and PR each, merged in order. Correlation stays in `CorrelationRuleset`, now given a `CorrelationScope`; both ingest paths call it per event. Reprocessing replays a stored `IngestEvent` on its own row. The recurrence comment runs inside the signal ingest transaction, under a row lock on the signal. Census data grows by two columns and a probe capability; containment is a new VCS method plus a memo table.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Pest, nwidart modules, SAO driver framework (`DriverRegistry`, capability contracts, `tests/Support/Conformance/*`), `Http::fake()`.

**Spec:** `../specs/2026-10-29-sao-ingest-correlation-and-census-design.md`.

## Global Constraints

- SAO is its own repository (`Modules/SAO`). One branch and one PR per slice, in a worktree from SAO `origin/master`, never a commit on master; the owner merges. Never bump submodule pointers in the stack or in laraplate.
- Commit with `git -c user.name=swolley -c user.email=myentity@inwind.it commit`. No `Co-Authored-By` trailer and no "Generated with Claude Code" line, in commits or PR descriptions.
- Schema changes to SAO's own tables are folded into their create migrations (pre-stable, `migrate:fresh` is acceptable). Only `sao_correlation_rules` and `sao_fix_presences` get new create migrations. Table names go through `SAOTables`.
- Run Pint from the laraplate root on explicit file lists, never inside `Modules/SAO` (`.ai/rules/modules.md`). Run only narrow tests with `php artisan test --compact <path or --filter>`; `bootstrap/cache/config.php` must not exist.
- Transactions and locks derive from the owning model (`$model->getConnection()->transaction(...)`, `->lockForUpdate()`), never `DB::`.
- Every current behaviour has a fallback (spec §1): the built-in `project_key` rule and the single-binding route stay; closure conditions are not touched.
- Values from the spec, verbatim: `signals.recurrence_comment.enabled` true; `signals.recurrence_comment.cooldown_minutes` 60, read as `max(1, ...)`; `SAO_PROBE_SCHEDULE` default false; `SAO_PROBE_CRON` default `0 */6 * * *`; recurrence source key `signal-recurrence:{signal_id}`; logs delivery id `{delivery}#{index}`; winning rule recorded as `rule:{id}`, `project_key`, or `binding`.
- Outcome strings: `no-matching-profile`, `no-correlation-rule-matched`, `no-logs-binding`, `signal-recorded` (existing, unchanged).
- Settings rows go through `SAODatabaseSeeder::runtimeSettingDefinitions()`, editable, never managed. Env names are documented in the SAO README.

## Review Focus

1. A wildcard pattern containing regex or `Str::is` specials other than `*` (a dot in `*.example.com`, a `?`) must match literally: the dot is a dot, not "any character". Test in Task 1.
2. A logs delivery re-sent after a rule was added must stay idempotent: the second delivery of `{delivery}#{index}` records nothing new and ingests nothing, even if it would now correlate differently. Reprocessing, not re-delivery, is the way to re-route. Test in Task 2.
3. A bulk reprocess of hundreds of old events for one signal posts one recurrence comment and the occurrences keep their original times, so the rate-limit window, which reads `occurred_at`, is not filled by the replay. Test in Task 8.
4. A probe endpoint that answers 200 with HTML, a JSON scalar, or a JSON object without the version path must leave the environment unchanged and be reported. Test in Task 10.
5. A short sha in a change ref or a deployed commit (7 characters) against a full sha from the provider must still compare correctly in the merge-base test. Test in Task 12.

---

## Slice 1: correlation rules (SAO branch `feat/sao-correlation-rules`)

### Task 1: rules table, enums and the ruleset

**Files:**
- Create: `Modules/SAO/database/migrations/2026_10_29_100000_create_sao_correlation_rules_table.php`, `Modules/SAO/app/Models/CorrelationRule.php`, `Modules/SAO/database/factories/CorrelationRuleFactory.php`, `Modules/SAO/app/Enums/CorrelationTarget.php`, `Modules/SAO/app/Enums/MatchType.php`, `Modules/SAO/app/Ingest/CorrelationScope.php`
- Modify: `Modules/SAO/app/Enums/SAOTables.php` (case `CorrelationRules = 'sao_correlation_rules'`), `Modules/SAO/app/Ingest/CorrelationRuleset.php`, `Modules/SAO/app/Ingest/WebhookIngestService.php`, `Modules/SAO/app/Ingest/IngestReplayService.php`
- Test: `Modules/SAO/tests/Unit/Ingest/CorrelationMatchTest.php`, `Modules/SAO/tests/Feature/Ingest/CorrelationRulesetTest.php`, existing `WebhookIngestServiceTest.php`, `ReplayIngestEventCommandTest.php`

**Interfaces:**
- Produces: `enum CorrelationTarget: string { ProjectKey = 'project_key'; ServerHost = 'server_host'; RequestHost = 'request_host'; public function valueFrom(array $canonical): ?string }`; `enum MatchType: string { Exact = 'exact'; Wildcard = 'wildcard'; public function matches(string $pattern, string $value): bool }`.
- Produces: `final readonly class CorrelationScope { __construct(public ?int $connectionId, public ?int $sourceProfileId, public bool $logsBindingRequired); static forProfile(?Connection $connection, SourceProfile $profile): self; static forLogs(Connection $connection): self }`.
- Produces: `CorrelationRuleset::correlate(array $canonical, CorrelationScope $scope): array{project: ?Project, rule: ?string, environment: ?string}`. `CorrelationRule` scope `active()` ordered by `priority`, `id`; casts `target`, `match` to the enums.

- [x] **Step 1: Write the failing tests**

`CorrelationMatchTest`: `MatchType::Exact->matches('Api.Example.com', 'api.example.com')` true; wildcard `*.example.com` matches `a.b.example.com`, not `example.com`, not `xexample.com` (Review Focus 1: the dot is literal); `api-*` matches `api-eu1`; `*prod*` matches `my-prod-host`; `?` in a pattern matches only a literal `?`. `CorrelationTarget::RequestHost->valueFrom(['request_url' => 'https://Shop.Example.com:8443/a?b=1'])` is `shop.example.com`; `'not a url'` gives null; missing field gives null.

`CorrelationRulesetTest` (feature, factories): priority then id breaks ties; inactive skipped; rule scoped to connection A ignored on B; rule scoped to profile P ignored for profile Q; a rule beats the built-in `project_key` rule; the built-in rule still works with no rules; `forLogs` scope, two logs bindings and no rule gives project null; one binding gives that project with rule `binding`; a rule targeting a project without a logs binding on the connection is skipped and the next rule wins; environment is the rule's, else `canonical['environment']`, else `canonical['server_host']`, else null.

- [x] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Unit/Ingest/CorrelationMatchTest.php Modules/SAO/tests/Feature/Ingest/CorrelationRulesetTest.php`
Expected: FAIL, classes missing.

- [x] **Step 3: Migration, model, factory, enums**

Columns as spec §3.1; FKs to connections, source profiles, projects named like siblings (`{$table_name}_project_FK`); index `(is_active, priority)`. Factory default: active, priority 100, `ServerHost`, `Exact`.

`MatchType::Wildcard->matches()`: lowercase both, `preg_quote` the pattern, replace the quoted `\*` with `.*`, anchor `^...$`, flag `u`. This is the spec's `Str::is` semantics written out, so Review Focus 1 does not depend on the framework's escaping. `Exact`: lowercase equality.

- [x] **Step 4: `CorrelationScope` and the ruleset**

Order of spec §3.4. With `logsBindingRequired`, a rule whose `project_id` has no `ProjectBinding` with `Capability::Logs` on `connectionId` is skipped; the single-binding step applies only then. Load the rules once per call with one query (`active()` plus scope filters `whereNull or =`), and the logs-bound project ids once.

- [x] **Step 5: Callers**

`WebhookIngestService::handle()` passes `CorrelationScope::forProfile($connection, $profile)` and, before `SignalIngestService::ingest()`, sets `$canonical['environment'] = $correlation['environment']`. `IngestReplayService::dryRun()` passes `forProfile($event->connection, $profile)` and returns `environment` too.

- [x] **Step 6: Run the new tests and the existing ingest and replay tests**

Run: `php artisan test --compact Modules/SAO/tests/Unit/Ingest Modules/SAO/tests/Feature/Ingest Modules/SAO/tests/Feature/Console/ReplayIngestEventCommandTest.php`
Expected: PASS.

- [x] **Step 7: Pint, commit**

`feat(sao): operator correlation rules`.

### Task 2: logs path correlates per event

**Files:**
- Modify: `Modules/SAO/app/Ingest/DriverWebhookIngestService.php`, `Modules/SAO/app/Drivers/External/GraylogDriver.php`
- Test: `Modules/SAO/tests/Feature/Ingest/DriverWebhookIngestTest.php`, `Modules/SAO/tests/Feature/Drivers/GraylogDriverTest.php`

**Interfaces:**
- Consumes: `CorrelationRuleset::correlate()`, `CorrelationScope::forLogs()` (Task 1).
- Produces: canonical fields `server_host`, `request_url` on logs events; `IngestEvent.winning_rule` set on the logs path.

- [ ] **Step 1: Write the failing tests**

`GraylogDriverTest`: a backlog row with `source` `web-01` and `fields.application_url` `https://shop.example.com/cart` unpacks to `server_host` `web-01`, `request_url` the URL, and no `environment` key.

`DriverWebhookIngestTest`, replacing the fan-out cases: two logs bindings, a rule on `request_host` `shop.example.com` → one `IngestEvent` per item, delivery id `d1#0`, project of the rule, `winning_rule` `rule:{id}`, occurrence environment `web-01` (fallback to `server_host`); two bindings and no rule → `Uncorrelated` `no-correlation-rule-matched`, no signal; one binding, no rule → ingested with `binding`; no binding → each item `Discarded` `no-logs-binding` with its canonical payload; a delivery unpacking to no item records nothing; re-sending `d1` after adding a rule that would route elsewhere records and ingests nothing new (Review Focus 2).

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Ingest/DriverWebhookIngestTest.php Modules/SAO/tests/Feature/Drivers/GraylogDriverTest.php`
Expected: FAIL.

- [ ] **Step 3: Graylog mapping**

`server_host` from `$row['source'] ?? $event['source']`; `request_url` from `$row['fields']['application_url']` when a string. Drop the `environment` key.

- [ ] **Step 4: Per-event loop in `DriverWebhookIngestService::handle()`**

Loop over items, not bindings: dedupe on `{delivery}#{index}`; with no logs binding record `Discarded`; else correlate, record `Uncorrelated` or set `environment` and ingest. `record()` gains `?string $winningRule`. Update the class PHPDoc (fan-out sentence).

- [ ] **Step 5: Run tests, Pint, commit**

Run: the Step 2 command plus `Modules/SAO/tests/Feature/Drivers/LogsDriversTest.php`. Expected: PASS. Commit `feat(sao): logs deliveries correlate per event`.

### Task 3: Filament resource and docs, open the slice PR

**Files:**
- Create: `Modules/SAO/app/Filament/Resources/CorrelationRules/` (resource, `Pages/`, `Schemas/`, `Tables/`, following `SourceProfiles/`)
- Modify: `Modules/SAO/lang/*/sao.php` labels as siblings do, `Modules/SAO/docs/rag/MODULE.md` (correlation rules, canonical `server_host`/`request_url`), `Modules/SAO/docs/rag/DRIVERS_USER.md` (Graylog `application_url`)
- Test: `Modules/SAO/tests/Feature/Filament/CorrelationRuleResourceTest.php`

- [ ] **Step 1: Write the failing test**

Copy the shape of `SourceProfileResourceTest`: list renders, create stores target/match/pattern/project/environment/scope, table sorted by priority, an unauthorized user is refused.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Filament/CorrelationRuleResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Build the resource**

Form: `target` and `match` selects from the enums, `pattern` required, `project_id` required select, `environment` text, optional `connection_id` and `source_profile_id` selects, `priority` numeric, `is_active` toggle.

- [ ] **Step 4: Run the test, Pint, docs, commit, push, PR**

Expected: PASS. Commit `feat(sao): correlation rules resource`. PR "SAO correlation rules" linking the spec §3.

---

## Slice 2: reprocessing (SAO branch `feat/sao-ingest-reprocess`, after slice 1 is merged)

### Task 4: pipeline marker and original occurrence time

**Files:**
- Create: `Modules/SAO/app/Enums/IngestPipeline.php`
- Modify: `Modules/SAO/database/migrations/2026_08_16_110100_create_sao_ingest_events_table.php`, `Modules/SAO/app/Models/IngestEvent.php`, the four writers (`WebhookIngestService`, `DriverWebhookIngestService`, `DeployWebhookIngestService`, `CodeWebhookIngestService`), `Modules/SAO/app/Ingest/SignalIngestService.php`
- Test: `Modules/SAO/tests/Feature/Ingest/IngestModelsTest.php`, `SignalIngestServiceTest.php`, the four writers' existing tests

**Interfaces:**
- Produces: `enum IngestPipeline: string { Profile = 'profile'; Logs = 'logs'; Deploy = 'deploy'; Code = 'code'; public function isReprocessable(): bool }` (true for `Profile`, `Logs`).
- Produces: `IngestEvent.pipeline` (cast to the enum), `IngestEvent.reprocessed_at` (datetime).
- Produces: `SignalIngestService::ingest(Project $project, array $payload, ?CarbonInterface $occurredAt = null): Signal`; the occurrence's `occurred_at` and the signal's `last_seen_at` use `$occurredAt ?? now()` (a new signal's `first_seen_at` too). `last_seen_at` never moves backwards: keep the later of the stored value and `$occurredAt`.

- [ ] **Step 1: Write the failing tests**

Each writer's test asserts its pipeline value. `SignalIngestServiceTest`: with `occurredAt` two days ago, the occurrence has that time, `last_seen_at` of an existing signal seen today stays today, and the rate-limit window counts it as old.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Ingest`
Expected: FAIL.

- [ ] **Step 3: Column `pipeline` (string, not null, default `profile`) and `reprocessed_at` (nullable) in the create migration; set the pipeline in each writer; the `occurredAt` parameter**

- [ ] **Step 4: Run tests, Pint, commit**

Expected: PASS. Commit `feat(sao): ingest events record their pipeline`.

### Task 5: `IngestReprocessService` and command

**Files:**
- Create: `Modules/SAO/app/Ingest/IngestReprocessService.php`, `Modules/SAO/app/Ingest/ReprocessOutcome.php`, `Modules/SAO/app/Console/ReprocessIngestEventsCommand.php`
- Modify: `Modules/SAO/app/Ingest/WebhookIngestService.php`, `Modules/SAO/app/Ingest/DriverWebhookIngestService.php` (extract the per-event body each path already runs into a public method the reprocess service calls), `Modules/SAO/app/Providers/SAOServiceProvider.php` (command registration as siblings)
- Test: `Modules/SAO/tests/Feature/Ingest/IngestReprocessServiceTest.php`, `Modules/SAO/tests/Feature/Console/ReprocessIngestEventsCommandTest.php`

**Interfaces:**
- Consumes: `IngestPipeline`, `SignalIngestService::ingest(..., $occurredAt)` (Task 4), `CorrelationScope` (Task 1).
- Produces: `WebhookIngestService::process(IngestEvent $event, ?CarbonInterface $occurredAt = null): IngestEvent` and `DriverWebhookIngestService::process(IngestEvent $event, Connection $connection, ?CarbonInterface $occurredAt = null): IngestEvent`: both fill and save the given row (status, outcome, source profile, project, winning rule, signal). The live paths call them on a new row.
- Produces: `IngestReprocessService::reprocess(IngestEvent $event, bool $dryRun = false): ReprocessOutcome` and `reprocessMany(Builder $query, bool $dryRun = false): Collection<int, ReprocessOutcome>`; `final readonly class ReprocessOutcome { __construct(public int $eventId, public IngestStatus $before, public IngestStatus $after, public ?string $winningRule, public bool $applied) }`.
- Produces: command `sao:ingest:reprocess {event?} {--status=} {--connection=} {--since=} {--dry-run}`.

- [ ] **Step 1: Write the failing tests**

Service: `Uncorrelated` profile event fixed by a new rule becomes `Ingested` on the same id with `reprocessed_at` set and the occurrence at the event's `created_at`; `Discarded` `no-logs-binding` logs event becomes `Ingested` after a binding is added; `Failed` is eligible; `Ingested` and `deploy`/`code` pipelines return `applied` false and change nothing; dry-run reports the would-be status and writes nothing (no signal, no occurrence, row unchanged); two `reprocess()` calls on the same row ingest once (call it twice; the second sees `Ingested` after the lock and the status re-check). Command: filters by status, connection, `--since` (date), single id; prints one line per event and a total.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Ingest/IngestReprocessServiceTest.php Modules/SAO/tests/Feature/Console/ReprocessIngestEventsCommandTest.php`
Expected: FAIL.

- [ ] **Step 3: Extract `process()` in both ingest services; implement the service**

Per event: `$event->getConnection()->transaction(...)`, re-read with `lockForUpdate()`, check `pipeline->isReprocessable()` and status in {Discarded, Uncorrelated, Failed}, call the pipeline's `process()` with `$event->created_at`, set `reprocessed_at`. Wrap the whole call in `PipelineContext::run()`. Dry-run: run the same code inside the transaction, capture the outcome, then throw a private `DryRunRollback` exception caught outside the transaction, so nothing is written and the decision logic is not duplicated. An exception inside `process()` marks the row `Failed` with the exception class as outcome.

- [ ] **Step 4: Run tests, Pint, commit**

Expected: PASS. Commit `feat(sao): reprocess unmatched ingest events`.

### Task 6: Filament actions, permission, docs, open the slice PR

**Files:**
- Modify: `Modules/SAO/app/Authorization/SAOPermissions.php` (`IngestEvent::class => ['replay', 'reprocess']`), `Modules/SAO/app/Filament/Resources/IngestEvents/Tables/IngestEventsTable.php`, lang labels, `Modules/SAO/docs/rag/MODULE.md`
- Test: `Modules/SAO/tests/Feature/Filament/IngestEventResourceTest.php`

- [ ] **Step 1: Write the failing test**

Row action "Reprocess" visible on an `Uncorrelated` row, hidden on `Ingested`; bulk action reprocesses the selected rows; both hidden and refused without `PermissionName::forClass(IngestEvent::class, 'reprocess')`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Filament/IngestEventResourceTest.php`
Expected: FAIL.

- [ ] **Step 3: Add the actions (pattern of `testConnection` in `ConnectionsTable`), notification with the counts of `ReprocessOutcome`**

- [ ] **Step 4: Run the test, Pint, docs (reprocessing, retention limit `SAO_RETENTION_INGEST_DAYS`), commit, push, PR**

Commit `feat(sao): reprocess actions on ingest events`. PR "SAO ingest reprocessing" linking spec §4.

---

## Slice 3: recurrence comment (SAO branch `feat/sao-recurrence-comment`, after slice 2)

### Task 7: signal row lock

**Files:**
- Modify: `Modules/SAO/app/Ingest/SignalIngestService.php`
- Test: `Modules/SAO/tests/Feature/Ingest/SignalIngestServiceTest.php`

**Interfaces:**
- Produces: `ingest()` body runs in `Signal`'s connection transaction; an existing signal is read with `lockForUpdate()` (serializes the recurrence check of Task 8 on MySQL); a new one is created inside the transaction, and on a unique violation of `(project_id, group_key)` re-read with the lock. `occurrence_count` is written as an atomic `increment()` (`occurrence_count = occurrence_count + 1`), which closes the lost update on every driver, SQLite included.

- [ ] **Step 1: Write the failing test**

SQLite cannot race two processes, so the proof is the SQL: with a query listener on the `Signal` connection, a second `ingest()` of the same group issues an `update` whose SQL contains `occurrence_count` = `occurrence_count` + 1 (grammar-quoted), and `occurrence_count` is 2. A resolved signal still reopens; a rate-limited occurrence still leaves the count unchanged.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Ingest/SignalIngestServiceTest.php`
Expected: FAIL (the update writes a literal count).

- [ ] **Step 3: Implement, run all `tests/Feature/Ingest`, Pint, commit**

Expected: PASS. Commit `fix(sao): lock the signal row while recording an occurrence`.

### Task 8: `SignalRecurrenceCommenter`, settings, index

**Files:**
- Create: `Modules/SAO/app/Services/SignalRecurrenceCommenter.php`
- Modify: `Modules/SAO/app/Ingest/SignalIngestService.php` (call after the occurrence is created, inside the transaction), `Modules/SAO/database/migrations/2026_08_01_100700_create_sao_ticket_comments_table.php` (index `(ticket_id, source_key, created_at)` replacing `(ticket_id, created_at)`), `Modules/SAO/database/seeders/SAODatabaseSeeder.php`, `Modules/SAO/config/config.php` (literal defaults), `Modules/SAO/README.md`, `Modules/SAO/docs/rag/MODULE.md`
- Test: `Modules/SAO/tests/Feature/Services/SignalRecurrenceCommenterTest.php`, the existing SAO runtime settings definitions test

**Interfaces:**
- Consumes: `TicketComment::postFor(Ticket, string, ChangeContext)`, `ChangeContext::forAutomation(string $sourceKey)`, `StatusCategory::isTerminal()`.
- Produces: `SignalRecurrenceCommenter::afterOccurrence(Signal $signal, SignalOccurrence $occurrence): ?TicketComment`; `public static function sourceKey(Signal $signal): string` returning `signal-recurrence:{id}`.

- [ ] **Step 1: Write the failing tests**

Comment posted on an open and on an in-progress ticket; none on a terminal one, none without `ticket_id`, none when disabled, none for an occurrence dropped by the rate limit (the commenter is never reached); second occurrence inside 60 minutes posts nothing, after 61 minutes one comment whose body states the count since the previous comment, first and last time, environments and versions of that span; cooldown setting 0 behaves as 1; the comment has `System` origin and the source key; reprocessing 300 old events of one signal (Task 5 service) posts exactly one comment (Review Focus 3).

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Services/SignalRecurrenceCommenterTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Last comment: `TicketComment` where `ticket_id`, `source_key`, latest `created_at`. Span start: that comment's `created_at`, else the linked ticket's `created_at`; the span counts the signal's occurrences recorded (`created_at`, not `occurred_at`, so replayed ones count) after it. Body: plain English lines, no markup beyond what siblings use in system comments. Cooldown against `now()`.

- [ ] **Step 4: Settings and docs, run tests, Pint, commit, push, PR**

Rows `signals.recurrence_comment.enabled` (Boolean, true) and `signals.recurrence_comment.cooldown_minutes` (Integer, 60). Commit `feat(sao): recurrence comment on the open ticket`. PR "SAO recurrence comment" linking spec §5.

---

## Slice 4: environment deploy data and probe (SAO branch `feat/sao-census-probe`, after slice 3)

### Task 9: commit, branch, base URL and passive feeds

**Files:**
- Modify: `Modules/SAO/database/migrations/2026_08_16_120400_create_sao_environments_table.php`, `Modules/SAO/app/Models/Environment.php`, `Modules/SAO/app/Services/DeployCensusService.php`, `Modules/SAO/app/Ingest/DeploymentIngestService.php`, `Modules/SAO/app/Ingest/SignalIngestService.php`, `Modules/SAO/app/Filament/Resources/Environments/` (form and infolist fields)
- Test: `Modules/SAO/tests/Feature/Services/DeployCensusServiceTest.php`, `Modules/SAO/tests/Feature/Ingest/DeploymentIngestServiceTest.php`, `Modules/SAO/tests/Feature/Ingest/SignalIngestVersionTest.php`

**Interfaces:**
- Produces: `DeployCensusService::observe(Environment $environment, string $version, ?string $commit = null, ?string $branch = null): Environment` and `recordProbe(...)` with the same signature; null keeps the stored value.
- Produces: `Environment.base_url`, `current_commit`, `current_branch`.

- [ ] **Step 1: Write the failing tests**

Census: commit and branch stored when given, kept when null. Deployment: GitHub deployment meta `sha`/`ref` reach the environment. Signal: payload `version` `1.4.0`, `commit` `abc1234`, environment `customer-a` existing on the project → environment updated; environment `web-01` unknown → no `Environment` row created and nothing stamped.

- [ ] **Step 2: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Services/DeployCensusServiceTest.php Modules/SAO/tests/Feature/Ingest/DeploymentIngestServiceTest.php Modules/SAO/tests/Feature/Ingest/SignalIngestVersionTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

`DeploymentIngestService` reads `$event->meta['sha']` and `['ref']` when strings. `SignalIngestService` looks up `Environment` by `(project_id, name)` with `first()`, never creates.

- [ ] **Step 4: Run tests, Pint, commit**

Commit `feat(sao): environments record commit and branch`.

### Task 10: `Probe` capability and the `http-version` driver

**Files:**
- Create: `Modules/SAO/app/Drivers/Contracts/VersionProbeCapability.php`, `Modules/SAO/app/Drivers/Support/ProbedVersion.php`, `Modules/SAO/app/Drivers/External/HttpVersionDriver.php`, `Modules/SAO/tests/Support/Conformance/ProbeConformance.php`
- Modify: `Modules/SAO/app/Enums/Capability.php` (`Probe = 'probe'`), `Modules/SAO/config/config.php` (`drivers.registered`), `Modules/SAO/docs/rag/DRIVERS_USER.md`
- Test: `Modules/SAO/tests/Feature/Drivers/HttpVersionDriverTest.php`

**Interfaces:**
- Produces: `interface VersionProbeCapability { public function probe(BindingContext $context, Environment $environment): ?ProbedVersion; }`; `final readonly class ProbedVersion { __construct(public string $version, public ?string $commit = null, public ?string $branch = null) }`.
- Produces: driver key `http-version`, capabilities `[Capability::Probe]`, configuration fields `path` (default `/version`), `version_path` (default `version`), `commit_path`, `branch_path`, `timeout` (seconds, default 5), and secret `auth_header` (`Name: value`). `$context->config['path']` overrides `path`.
- Produces: `ProbeException` (or the driver's existing exception style) with a short reason, thrown on refusal or bad response, so the command can report it.

- [ ] **Step 1: Write the failing tests**

Over `Http::fake()`: JSON `{"app":{"version":"1.4.0","git":{"sha":"abc"}}}` with `version_path` `app.version`, `commit_path` `app.git.sha` gives the probed version; binding path override is requested; auth header sent; redirect response (302) is not followed and fails; `ftp://` or empty `base_url` refused without a request; 200 with HTML, a JSON scalar, or a JSON object missing the version path fails (Review Focus 4); timeout passed to the client. `ProbeConformance` asserts the contract on a faked happy path.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Drivers/HttpVersionDriverTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement**

Read schema fields the way `GraylogDriver` reads `secret`. URL is `rtrim(base_url, '/') . '/' . ltrim(path, '/')`; scheme from `parse_url` must be `http` or `https`. `Http::timeout()->withoutRedirecting()`; dot-paths through `data_get`. Health check: base URL and path valid.

- [ ] **Step 4: Run tests, Pint, commit**

Commit `feat(sao): http-version probe driver`.

### Task 11: `sao:census:probe` and schedule, open the slice PR

**Files:**
- Create: `Modules/SAO/app/Console/ProbeCensusCommand.php`
- Modify: `Modules/SAO/app/Providers/SAOServiceProvider.php` (command and schedule), `Modules/SAO/config/config.php` (`probe.enabled` from `SAO_PROBE_SCHEDULE`, `probe.cron` from `SAO_PROBE_CRON`), `Modules/SAO/README.md`, `Modules/SAO/docs/rag/MODULE.md`; laraplate root `.env.example` (commented `# SAO_PROBE_SCHEDULE=false` and `# SAO_PROBE_CRON="0 */6 * * *"` next to `SAO_HEALTH_*`, required by `tests/Unit/EnvExampleCoversConfigTest.php`)
- Test: `Modules/SAO/tests/Feature/Console/ProbeCensusCommandTest.php`

**Interfaces:**
- Consumes: `VersionProbeCapability` (Task 10), `DeployCensusService::recordProbe()` (Task 9).
- Produces: `sao:census:probe {project?}`: for each project (or the given key prefix) with a `probe` binding, each environment with a `base_url`; one output line per environment, exit code non-zero if any failed.

- [ ] **Step 1: Write the failing test**

Two environments, one probe fails: the other is recorded, the failing one unchanged and reported, exit code 1; environment without `base_url` skipped; schedule contains the command only with `sao.probe.enabled` true and uses `sao.probe.cron`.

- [ ] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Console/ProbeCensusCommandTest.php`
Expected: FAIL.

- [ ] **Step 3: Implement, run, Pint, docs, commit, push, PR**

The `.env.example` lines go in a laraplate branch and PR of their own (`chore(env): SAO probe schedule names`); run `php artisan test --compact tests/Unit/EnvExampleCoversConfigTest.php` there. Commit `feat(sao): scheduled census probe`. PR "SAO environment deploy data and probe" linking spec §6.

---

## Slice 5: fix containment (SAO branch `feat/sao-fix-containment`, after slice 4)

### Task 12: `VcsCapability::containsCommit`

**Files:**
- Modify: `Modules/SAO/app/Drivers/Contracts/VcsCapability.php`, `GitHubDriver.php`, `GitLabDriver.php`, `BitbucketDriver.php`, `Modules/SAO/tests/Support/Drivers/StubVcsDriver.php`, `Modules/SAO/tests/Support/Conformance/VcsConformance.php`
- Test: `GitHubDriverTest.php`, `GitLabDriverTest.php`, `BitbucketDriverTest.php`

**Interfaces:**
- Produces: `VcsCapability::containsCommit(BindingContext $context, string $sha, string $ref): ?bool`. `firstTagContaining()` in the three drivers returns the first tag for which it is true.

- [ ] **Step 1: Confirm the provider endpoints**

Read the current API docs and record the URL in the step: GitHub `GET /repos/{owner}/{repo}/compare/{base}...{head}` (`status`); GitLab `GET /projects/:id/repository/merge_base?refs[]=..&refs[]=..`; Bitbucket Cloud merge base (`/repositories/{workspace}/{repo}/merge-base/{revspec}`). If an endpoint differs from this list, use the documented one and note it here.

- [ ] **Step 2: Write the failing tests**

Per driver over `Http::fake()`: contained, identical (sha equals ref), diverged, unknown sha (404) gives null, server error gives null; a 7-character sha matches a full merge-base sha (prefix compare, both lowercased; Review Focus 5). `firstTagContaining`: GitHub's existing tests unchanged; GitLab and Bitbucket skip a diverged tag and return an identical one. `VcsConformance` gains a case on the stub.

- [ ] **Step 3: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Drivers/GitHubDriverTest.php Modules/SAO/tests/Feature/Drivers/GitLabDriverTest.php Modules/SAO/tests/Feature/Drivers/BitbucketDriverTest.php`
Expected: FAIL.

- [ ] **Step 4: Implement**

GitHub: compare `{sha}...{ref}`, `ahead`/`identical` true, `behind`/`diverged` false, other null. GitLab and Bitbucket: merge base of the two equals `$sha` (prefix rule). Update the three `firstTagContaining` PHPDocs.

- [ ] **Step 5: Run tests, Pint, commit**

Commit `feat(sao): vcs drivers tell whether a ref contains a commit`.

### Task 13: `commit_sha` on change refs

**Files:**
- Modify: `Modules/SAO/database/migrations/2026_08_16_120000_create_sao_change_refs_table.php`, `Modules/SAO/app/Models/ChangeRef.php`, `Modules/SAO/app/Attribution/CodeReference.php` (`?string $commitSha = null` last), `Modules/SAO/app/Attribution/CodeReferenceWriter.php`, `Modules/SAO/app/Drivers/External/GitHubPullRequestDriver.php` (`merge_commit_sha`), `Modules/SAO/app/Drivers/External/WebhookCodeDriver.php` (row `commit_sha`), `Modules/SAO/app/Attribution/VcsScanService.php`
- Test: `Modules/SAO/tests/Feature/Ingest/CodeWebhookIngestTest.php`, `Modules/SAO/tests/Feature/Attribution/VcsScanServiceTest.php`

**Interfaces:**
- Produces: `ChangeRef.commit_sha` (nullable string, index). For `ChangeRefType::Commit` the writer sets it to `identifier` regardless of the reference; otherwise `$reference->commitSha ?? stored`.

- [ ] **Step 1: Failing tests**: a merged GitHub PR stores its merge sha; a scanned commit stores its own sha; a re-delivery without sha keeps the stored one.
- [ ] **Step 2: Run them to verify they fail**: `php artisan test --compact Modules/SAO/tests/Feature/Ingest/CodeWebhookIngestTest.php Modules/SAO/tests/Feature/Attribution/VcsScanServiceTest.php`, expected FAIL.
- [ ] **Step 3: Implement, run tests, Pint, commit** `feat(sao): change refs keep the commit an environment can contain`.

### Task 14: fix presences, job and backfill command

**Files:**
- Create: `Modules/SAO/database/migrations/2026_10_29_100100_create_sao_fix_presences_table.php`, `Modules/SAO/app/Models/FixPresence.php`, `Modules/SAO/database/factories/FixPresenceFactory.php`, `Modules/SAO/app/Services/FixPresenceService.php`, `Modules/SAO/app/Jobs/CheckFixPresenceJob.php`, `Modules/SAO/app/Console/CheckFixPresenceCommand.php`
- Modify: `SAOTables` (`FixPresences = 'sao_fix_presences'`), `Modules/SAO/app/Models/Environment.php` and `ChangeRef.php` (`booted()` `saved` hooks), `SAOServiceProvider` (command)
- Test: `Modules/SAO/tests/Feature/Services/FixPresenceServiceTest.php`, `Modules/SAO/tests/Feature/Console/CheckFixPresenceCommandTest.php`

**Interfaces:**
- Consumes: `VcsCapability::containsCommit()` (Task 12), `ChangeRef.commit_sha` (Task 13), `Environment.current_commit` (Task 9).
- Produces: `FixPresenceService::checkEnvironment(Environment $environment): int` and `checkChangeRef(ChangeRef $changeRef): int` (rows written); `CheckFixPresenceJob` with `forEnvironment(int $id)` / `forChangeRef(int $id)`; command `sao:fix:check {project?}`.
- Pairs: `Fixes` change refs with `commit_sha` of tickets of the environment's project × environments of that project with `current_commit`. VCS binding: the project's `vcs` binding (first by id). A pair whose row exists for `(change_ref, environment, deployed_commit)` with `contained` not null is skipped; a null row is retried and overwritten.

- [ ] **Step 1: Failing tests**: one row per pair with true/false; a null answer stored as null and retried on the next call, never false; a new `current_commit` adds a row and leaves the old one; an unchanged commit makes no VCS call (assert with `StubVcsDriver` call count); saving an environment with a changed `current_commit` dispatches the job (`Queue::fake()`), saving other fields does not; saving a `Fixes` ref with `commit_sha` dispatches, a `Mentions` ref does not; no `vcs` binding writes nothing; the command backfills one project.
- [ ] **Step 2: Run them to verify they fail**: `php artisan test --compact Modules/SAO/tests/Feature/Services/FixPresenceServiceTest.php Modules/SAO/tests/Feature/Console/CheckFixPresenceCommandTest.php`, expected FAIL.
- [ ] **Step 3: Implement, run tests, Pint, commit** `feat(sao): record whether each environment contains each fix`.

### Task 15: `FixStatus` read side, docs, open the slice PR

**Files:**
- Modify: `Modules/SAO/app/Data/FixStatus.php` (`contained_environments`, `not_contained_environments`, lists of environment names, appended last with default `[]`), `Modules/SAO/app/Services/FixStatusResolver.php`, the ticket infolist under `Modules/SAO/app/Filament/Resources/Tickets/Schemas/`, `Modules/SAO/docs/rag/MODULE.md`
- Test: `Modules/SAO/tests/Feature/Services/FixStatusResolverTest.php`, `Modules/SAO/tests/Feature/Services/ClosureEvaluationTest.php` (unchanged outcomes), `Modules/SAO/tests/Feature/Filament/TicketResourceTest.php`

- [ ] **Step 1: Failing tests**: an environment whose latest presence row for its current commit is true is listed contained, false not contained, null in neither; an environment with a stale row (older commit) is in neither; existing `deployed_environments`, `missing_environments`, `deployed_there` and every closure outcome unchanged; the infolist shows both lists.
- [ ] **Step 2: Run them to verify they fail**: `php artisan test --compact Modules/SAO/tests/Feature/Services/FixStatusResolverTest.php Modules/SAO/tests/Feature/Filament/TicketResourceTest.php`, expected FAIL.
- [ ] **Step 3: Implement, run the two files plus `ClosureEvaluationTest.php` and `ClosureContextResolverTest.php`, Pint, docs, commit, push, PR** `feat(sao): fix status shows containment per environment`; PR "SAO fix containment" linking spec §7 and stating that closure is unchanged (open product decision).

---

### Task 16: close the plan (laraplate root)

**Files:**
- Modify: this plan (front matter `status: completed`, `## Delivery status (date): ...` with `**Documented in:** Modules/SAO/docs/rag/MODULE.md, Modules/SAO/docs/rag/DRIVERS_USER.md, Modules/SAO/README.md`, divergences), the spec's `**Status:**` line.

- [ ] **Step 1: Verify every box is `- [x]` or `- [-]` with reason, the five SAO PRs are merged**
- [ ] **Step 2: Run** `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`, expected PASS
- [ ] **Step 3: Branch, commit, push, PR in laraplate** `docs(sao): close the ingest correlation and census plan`
