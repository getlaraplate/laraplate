# SAO ingest correlation rules, reprocessing, recurrence and deploy containment

**Date:** 2026-10-29
**Status:** Design proposed.
**Module:** `Modules/SAO`
**Parent spec:** `2026-07-31-sao-module-design.md` (§4 census, §6 ingest; D12, D13, D14, D15, D18)
**Builds on:** phase 4 (`2026-08-16-sao-phase-4-ingest-source-profiles-design.md`), phase 5b (`2026-08-16-sao-phase-5b-code-to-work-releases-design.md`), phase 6 (`2026-08-16-sao-phase-6-fix-propagation-closure-design.md`).
**Origin:** a comparison with a separate internal error orchestrator (Graylog to Redmine, per-customer tenants). Five of its working ideas fill gaps in SAO. This spec takes them over in SAO's terms; what it deliberately does not take is listed in §8.

---

## 1. Goal and scope

Today an event reaches a project only through one built-in rule (`project_key` equal to `Project.key_prefix`), or, on the driver push path, by fan-out to every project with a `logs` binding on the connection. An event that matches nothing stays `Uncorrelated` forever. A recurring signal leaves no trace on its open ticket. The deploy census knows a version string, never a commit, and nothing ever calls the active probe.

This spec closes those gaps in five slices, built in order:

| Slice | Subject | Section |
|---|---|---|
| 1 | Operator-defined correlation rules (`project_key`, server host, request host; exact or wildcard) | §3 |
| 2 | Reprocessing of discarded, uncorrelated and failed ingest events | §4 |
| 3 | Recurrence comment on the open ticket of a signal, with cooldown | §5 |
| 4 | Environment deploy data (commit, branch, base URL) and the HTTP version probe | §6 |
| 5 | Whether a fix commit is contained in the commit each environment runs | §7 |

The slices build in that order: each ships on its own once the ones before it are merged, and none depends on work outside this spec.

**Constraint from the owner: do not make things worse.** Every current behaviour stays available as a fallback (§3.4), no existing column changes meaning, and closure rules are not changed (§7.5).

---

## 2. Customer model

The correlation rule needs a target. Two shapes of customer exist (D18):

- **Same product, many installs** (for example one product deployed for many customers): one `Project`, one `Environment` per install. Signals from every install share the project's `group_key` space, so the same bug is one signal and one ticket, with the affected installs visible as environments.
- **Customer fork** (diverging code): its own `Project`. The same bug gets a ticket per project, comparable through `group_key` (D13).

A rule therefore always names a `project_id` and may name an `environment`. No new tenant or customer entity is introduced; SAO stays single-tenant (D1).

---

## 3. Slice 1: correlation rules

### 3.1 Table `sao_correlation_rules`

New table, in its own create migration.

| Column | Type | Notes |
|---|---|---|
| `id` | bigint | |
| `priority` | unsigned int | Lower wins. |
| `is_active` | bool | Default true. |
| `target` | string, enum `CorrelationTarget` | `project_key`, `server_host`, `request_host`. |
| `match` | string, enum `MatchType` | `exact`, `wildcard`. |
| `pattern` | string | |
| `connection_id` | FK nullable | Scope: rule applies only to events received on this connection. |
| `source_profile_id` | FK nullable | Scope: rule applies only to events normalized by this profile. |
| `project_id` | FK, required | Target project. |
| `environment` | string nullable | Target environment name, written on the occurrence like a payload environment. No `Environment` row is created from it. |
| timestamps | | |

Index `(is_active, priority)`.

### 3.2 Canonical fields

Two new canonical fields, available to source profiles and set by drivers:

- `server_host`: the host that emitted the event.
- `request_url`: the URL of the request being served when the event happened.

`GraylogDriver::unpack()` maps GELF `source` to `server_host` and `fields.application_url` to `request_url`. It stops writing the GELF host into `environment`. To keep today's result, environment resolution falls back to `server_host` when no rule sets an environment and the payload has none (§3.4).

### 3.3 Matching

- The value compared is the canonical field named by `target`. For `request_host` it is `parse_url(request_url, PHP_URL_HOST)`; an unparsable URL yields no value and the rule does not match.
- Comparison is on the whole string and case-insensitive: both sides are lowercased.
- `exact`: string equality.
- `wildcard`: `*` matches any sequence, anywhere in the pattern, through `Str::is()` on the lowercased values. No other metacharacter, no regex.
- A rule is in scope when each of its scope columns is null or equal to the event's connection and profile.

### 3.4 Evaluation order

`CorrelationRuleset` evaluates, and stops at the first match:

1. Active rules in scope, ordered by `priority`, then `id`. Winning rule recorded as `rule:{id}`.
2. The built-in rule: `project_key` equal to `Project.key_prefix` (unchanged, recorded as today).
3. Driver path only: if the connection has exactly one `logs` binding, that binding's project. Recorded as `binding`.

No match: the event is `Uncorrelated` (`no-correlation-rule-matched`), on both paths. A driver connection with no `logs` binding at all keeps today's `Discarded` (`no-logs-binding`): no rule can route there. The driver path's fan-out to every `logs`-bound project is removed: one event correlates to at most one project. A connection with one binding behaves as today through step 3.

On the driver path a rule may only route to a project that has a `logs` binding on the receiving connection; a rule pointing elsewhere does not match there, and evaluation continues.

Environment: the rule's `environment` if set, else the payload's `environment`, else `server_host`, else none.

### 3.5 Surfaces

- Filament `CorrelationRuleResource` (list, create, edit), with the target, match type, pattern, scope and target project/environment, sortable by priority.
- The dry-run replay (`IngestReplayService::dryRun`, `sao:ingest:replay`) shows the rule that would win.

### 3.6 Tests

- Exact and wildcard matches per target, case-insensitive, `*` at start, middle and end.
- `request_host` from a URL with port, path and query; unparsable URL does not match.
- Priority, then id, breaks ties; inactive rules are skipped; scope by connection and by profile.
- A rule beats the built-in rule; the built-in rule beats the single-binding fallback.
- Driver path: two `logs` bindings and no rule gives `Uncorrelated`, not fan-out; a rule pointing to a project without a binding on that connection does not match.
- Environment precedence: rule, payload, `server_host`.
- `GraylogDriver` maps `source` and `application_url` into the new fields.

---

## 4. Slice 2: reprocessing

### 4.1 Columns on `sao_ingest_events`

Folded into the existing create migration (pre-stable, `migrate:fresh` is acceptable):

- `pipeline`: string, enum `IngestPipeline` {`profile`, `logs`, `deploy`, `code`}, one per writer of `IngestEvent` (generic webhook, logs driver push, deploy webhook, code webhook). Only `profile` and `logs` are reprocessable.
- `reprocessed_at`: timestamp nullable.

### 4.2 Driver path records one event per item

`DriverWebhookIngestService` verifies the signature, unpacks, and records one `IngestEvent` per unpacked item with `delivery_id` `{delivery}#{index}` and the canonical item as `payload` (the `#{binding}` segment disappears with the fan-out). Correlation then runs per event. A connection with no `logs` binding records each item as `Discarded` (`no-logs-binding`), so the items can be reprocessed once a binding exists. A delivery that unpacks to no item records nothing. The signature is checked only at receipt; reprocessing works on the stored canonical payload.

### 4.3 `IngestReprocessService`

- Eligible: pipeline `profile` or `logs`, status `Discarded`, `Uncorrelated` or `Failed`. An `Ingested` event is never reprocessed.
- Per event: a transaction, `lockForUpdate` on the row, re-check that the status is still eligible, then replay the event's `pipeline` (profile selection and normalization for `profile`, correlation for `logs`).
- The same row is updated (status, outcome, project, winning rule, signal) and `reprocessed_at` is set. No new row, so `(connection_id, delivery_id)` stays unique.
- Runs inside `PipelineContext`, like the live ingest, so loop protection holds.
- The occurrence keeps the time of the original delivery: `SignalIngestService::ingest()` gains an optional `?CarbonInterface $occurredAt`, passed the event's `created_at`.

### 4.4 Surfaces

- Command `sao:ingest:reprocess {event?} {--status=} {--connection=} {--since=} {--dry-run}`. `--dry-run` reports what would correlate where and changes nothing. `sao:ingest:replay` stays a dry-run against one profile.
- Filament: a row action and a bulk action "Reprocess" on `IngestEventResource`, behind a dedicated `reprocess` permission on ingest events.

Reprocessing reaches only events still inside retention (`SAO_RETENTION_INGEST_DAYS`, default 30).

### 4.5 Tests

- Each eligible status is reprocessed; `Ingested` is refused.
- An event fixed by a new rule becomes `Ingested` on the same row with `reprocessed_at` set and the occurrence at the original time.
- A concurrent reprocess of the same row ingests once (status re-check under lock).
- `profile` and `logs` pipelines both replay, `deploy` and `code` are refused; the logs path does not re-verify signatures.
- `--dry-run` changes nothing; the bulk action requires the permission.

---

## 5. Slice 3: recurrence comment

### 5.1 When

After an occurrence is recorded, a comment is posted on the signal's ticket only if all hold:

- the signal has a `ticket_id`;
- the ticket's status category is non-terminal (open or in progress). A terminal ticket is left to the closure engine, which already reopens and flags it;
- the occurrence was not dropped by the rate limit;
- the setting `signals.recurrence_comment.enabled` is true;
- the last recurrence comment on that ticket for that signal is older than the cooldown, or there is none.

### 5.2 Where the cooldown is read

No column on `sao_signals`. The last recurrence comment is read from `sao_ticket_comments`, by `source_key = signal-recurrence:{signal_id}`. The index on that table becomes `(ticket_id, source_key, created_at)`, folded into its create migration. A column would duplicate a fact the comments table already holds and could drift from it (system comments can be deleted).

### 5.3 Concurrency

`SignalIngestService::ingest()` runs in a transaction with `lockForUpdate` on the signal row. Two concurrent occurrences therefore serialize on the signal, so only one of them can post within a cooldown. The same lock fixes a lost update that exists today: `occurrence_count++` is read-modify-write without a lock.

### 5.4 Settings

Seeded through `SAODatabaseSeeder` like the other `signals.*` settings:

- `signals.recurrence_comment.enabled`: boolean, default true.
- `signals.recurrence_comment.cooldown_minutes`: integer, default 60. The read site clamps it to at least 1, so 0 never means "comment on every occurrence".

### 5.5 Body

Deterministic, no AI: occurrences since the previous recurrence comment (or since the ticket was linked), first and last occurrence time in that span, the distinct environments and versions seen. Posted with `TicketComment::postFor()` and `ChangeContext::forAutomation(...)` carrying the source key, so the comment has `System` origin and is immutable.

During reprocessing (§4) the cooldown is measured against `now()`, not the occurrence time, so a bulk run posts at most one comment per signal.

### 5.6 Out of scope

Pushing comments to an external tracker (Redmine, Jira). `IssueSyncService` syncs tickets, not comments; that is a separate capability extension.

### 5.7 Tests

- Comment posted on an open ticket; not on a terminal one; not without a ticket; not for a rate-limited occurrence; not when disabled.
- Second occurrence inside the cooldown posts nothing; after it, one comment with the counts since the previous one.
- Cooldown 0 behaves as 1.
- Two concurrent ingests of the same signal: `occurrence_count` increases by two, one comment.
- Bulk reprocess of many old events posts at most one comment per signal.

---

## 6. Slice 4: environment deploy data and probe

### 6.1 Columns on `sao_environments`

Folded into its create migration, all nullable: `base_url`, `current_commit`, `current_branch`. Nothing product-specific.

### 6.2 Census service

`DeployCensusService::observe(Environment, string $version, ?string $commit = null, ?string $branch = null)`. `recordProbe()` takes the same optional pair. Both still stamp `current_version` and `last_seen_at`; commit and branch are overwritten only when given.

### 6.3 Passive feeds

- Deploy webhook (`DeploymentIngestService`): passes the deployment's sha and ref when the payload carries them.
- Signal occurrence: when the canonical payload carries `version` (and optionally `commit`, `branch`) and the project already has an `Environment` with the occurrence's environment name, the census observes it. Occurrences never create environments: a host name used as fallback environment must not fill the census. This is new: today only deployments feed the census.

### 6.4 Active feed: `http-version` driver

- New capability `Probe` (`probe`) on the `Capability` enum, contract `VersionProbeCapability::probe(BindingContext, Environment): ?ProbedVersion`. `ProbedVersion` carries `version`, `commit`, `branch`.
- New driver `http-version`. Connection config: `path`, dot-paths for `version`, `commit` and `branch` in the JSON response, `timeout`; an optional auth header stored as an encrypted credential. A binding's `config` may override `path`, so one connection serves projects that expose the endpoint at different paths.
- Command `sao:census:probe {project?}` probes every environment with a `base_url` on projects with a `probe` binding and calls `recordProbe()`. Scheduled when `SAO_PROBE_SCHEDULE` is true (default false), cron `SAO_PROBE_CRON` (default `0 */6 * * *`), following the existing scheduler toggles.
- A failed probe is reported per environment and changes nothing: an environment whose probe fails becomes stale through the existing TTL, as it would without a probe.

### 6.5 Security

- Only the operator-set `Environment.base_url` is probed. A `request_url` from a payload is never fetched, so an event cannot make SAO call an arbitrary host.
- `http` and `https` only, the configured timeout, no redirects followed.

### 6.6 Tests

- `observe` and `recordProbe` store commit and branch when given and keep them when not.
- A deployment webhook stores sha and ref; an occurrence with version and commit updates the existing environment of that name; an occurrence naming an unknown environment creates none.
- Probe over `Http::fake()`: dot-paths, binding path override, auth header sent, non-JSON or missing field yields no update, timeout and redirect refused, non-http scheme refused.
- The command reports a per-environment failure and leaves other environments probed.
- The driver passes the conformance suite for the new capability.

---

## 7. Slice 5: is the fix in the deployed commit?

### 7.1 Question

`FixStatusResolver` says a fix is deployed in an environment when that environment runs the version of the release that shipped it. That misses two real cases: a deploy that is not a release (a hotfix branch, a customer install on a commit), and an install already on a later version whose release did not record the ticket. With `current_commit` known (§6), SAO can ask the VCS whether the fix commit is an ancestor of the deployed commit.

### 7.2 `VcsCapability::containsCommit`

New normalized method `containsCommit(BindingContext, string $sha, string $ref): ?bool`: true when `$sha` is reachable from `$ref`, false when it is not, null when the provider cannot tell (unknown sha, API error). Each Git driver (GitHub, GitLab, Bitbucket) implements it, and `firstTagContaining()` then calls it for each tag. GitHub keeps its current test (compare status `ahead` or `identical`), so its result does not change. GitLab and Bitbucket today test only that the commit and the tag differ, which also counts a diverged tag and misses an identical one; they move to a merge-base test (the merge base of sha and ref is sha), which corrects both cases. The conformance suite pins the semantics for all three.

### 7.3 Data

- `sao_change_refs.commit_sha`: nullable, folded into the create migration. For a commit ref it equals `identifier`; for a pull request it is the merge commit sha, which is what an environment can contain.
- New table `sao_fix_presences`: `change_ref_id`, `environment_id`, `deployed_commit`, `contained` (bool nullable), `checked_at`; unique `(change_ref_id, environment_id, deployed_commit)`. One row answers "was this fix in that commit", which never changes for a given commit, so it is computed once.

### 7.4 Computation

- A queued job checks the pairs affected when an environment's `current_commit` changes, or when a `Fixes` change ref with a `commit_sha` is recorded.
- Command `sao:fix:check {project?}` backfills.
- A null answer is stored as null and retried on the next run; it is never stored as false.

### 7.5 Read side

`FixStatus` gains `contained_environments` and `not_contained_environments`, shown in the ticket infolist next to the existing deployed/missing environments.

Closure is unchanged: the `fix_deployed_there` condition and `deployed_there` stay version-based (D15). Whether containment may also satisfy closure is an open product decision, not taken here.

### 7.6 Tests

- `containsCommit` per Git driver over `Http::fake()`: contained, identical, diverged, unknown sha gives null; GitHub's `firstTagContaining()` results unchanged; GitLab and Bitbucket skip a diverged tag and accept an identical one.
- PR change ref stores the merge sha in `commit_sha`.
- The job writes one presence per `(change ref, environment, commit)`; a new commit adds a row, an unchanged commit does not re-query.
- Null is retried, never written as false.
- `FixStatus` lists contained and not-contained environments; closure outcomes are unchanged.

---

## 8. Not taken from the orchestrator

- Product families with defaults: covered by binding `config` overriding connection config.
- A commit cutoff date for fix lookup, CIDR-based sync, fuzzy matching of a free-text customers field, a custom fingerprint: SAO already has precise equivalents (change refs, correlation rules, the Core fingerprint).
- Opening a new ticket with a "relates" link when the old one is closed: the closure engine reopens and flags instead, which keeps one ticket per signal.

The orchestrator's AI ideas (a deterministic triage gate before any model call, a source snippet through `fileAtRef`, a structured analysis output, one provider per step) belong to SAO phase 8 (parent spec §10) and are not part of this spec.

---

## 9. Documentation

Each slice updates `Modules/SAO/docs/` (operator: correlation rules, reprocessing, recurrence comments, probe setup; developer: canonical fields, capabilities) and the module README for `SAO_PROBE_SCHEDULE` and `SAO_PROBE_CRON`.
