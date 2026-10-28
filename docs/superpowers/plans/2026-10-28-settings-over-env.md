---
status: completed
created_on: 2026-10-28
module: core, sao, mes, ai
---
# Runtime tunables move from `.env` to Settings. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** 16 runtime tunables of SAO, MES, Core and AI are Settings edited in Filament instead of `.env` variables, and `.env.example` no longer lists them.

**Architecture:** each module seeder returns the definitions through the existing `runtimeSettingDefinitions()` / `SeedReconciler` mechanism; the overlay already copies a row onto the config key `{module}.{name}`. Module config files keep the literal default (no `env()`), read sites keep `config('key', default)` and gain a lower bound where the spec asks for one. No new class, no new dependency.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, nwidart modules, `Modules\Core\Overrides\Seeder`, `SeedReconciler`, `Modules\Core\Casts\SettingTypeEnum`.

**Spec:** `../specs/2026-10-28-settings-over-env-design.md`.

## Global Constraints

- Each module is its own repository (laracore, laraai, SAO, MES): one branch and one PR per module task, in a worktree, never a commit on master. The laraplate root change is its own PR. Never bump submodule pointers in the stack (the owner does).
- Commit with `git -c user.name=swolley -c user.email=myentity@inwind.it commit`. No `Co-Authored-By` trailer and no "Generated with Claude Code" line, in commits or PR descriptions.
- Run Pint from the laraplate root on explicit file lists, never inside `Modules/<Name>` (`.ai/rules/modules.md`).
- Run only the narrow tests (`php artisan test --compact <path or --filter>`); never with a cached config (`bootstrap/cache/config.php` must not exist).
- Every seeded default equals the literal in the module config and the fallback at the read site. Values (verbatim from the spec): `signals.max_occurrences_per_window` 1000, `signals.window_minutes` 60, `signals.auto_open.enabled` false, `signals.auto_open.min_occurrences` 1, `release_health.window_days` 7, `release_health.regression_rate_factor` 1.5, `release_health.regression_min_occurrences` 3, `release_health.regressed_new_signals` 3, `closure.auto_close.enabled` false, `production.daily_minutes` 480, `production.default_lead_time_days` 5, `validity.expiring_within_hours` 48, `filament.tabs_counts_ttl_seconds` 60, `media.draft_ttl_hours` 24, `features.search_orchestration.enabled` true, `features.search_orchestration.timeout` 10.
- Lower bounds, applied where the value is read: every integer above that is a window, threshold, TTL or lead time reads as `max(1, ...)`; `regression_rate_factor` as `max(1.0, ...)`; `daily_minutes` keeps its existing `max(1.0, ...)`. Three values keep an existing "0 = off" meaning and get no floor of 1 (spec, Mechanism): `media.draft_ttl_hours` (0 or negative = never prune), `signals.max_occurrences_per_window` (0 or negative = no cap), `features.search_orchestration.timeout` (0 = no limit, read as `max(0.0, ...)`). Booleans have none.
- Not moved (spec, last sections): `ERP_STOCK_RESERVATION_SOFT_TTL`, every protection and rate limit, chunk sizes, cron expressions, retention days, drivers. Do not touch them.
- Setting rows are editable: use `internalSettingsDefinition(...)`, never mark them `managed`.
- Docs: the module README and `docs/rag/` page of each affected module list the new Settings and defaults; no list of removed variables (spec, Documentation).

## Review Focus

- A stored value of 0 or a negative number reaches a read site (SAO windows, Core TTLs): it reads as the lower bound, never as a disabled window or an instant expiry; the three values with an "off" meaning (Global Constraints) keep it instead.
- The seeder runs twice, or after an operator changed a value: the second run leaves the operator's value and realigns only description and type (the reconciler's `initial(['value'])` rule).
- A console process boots with the Setting set to true: `SAO_SIGNAL_AUTO_OPEN` registers the scheduled command through the `booted` callback (provider order must not matter).
- The Settings table is missing (fresh install before migrations): the overlay's `try`/`catch` returns and the config literal answers; the code never throws.
- A queue worker started before a Setting changed: the next job sees the new value (`ApplySettingsOverlayBeforeJob`); assert it for one SAO read site.

---

### Task 1: SAO, nine Settings

**Repository:** SAO (`Modules/SAO`), branch `feat/sao-runtime-settings`.

**Files:**
- Modify: `Modules/SAO/database/seeders/SAODatabaseSeeder.php` (extend `Modules\Core\Overrides\Seeder`, add `runtimeSettingDefinitions()`, reconcile in `run()` before `SAOPermissionSeeder`)
- Modify: `Modules/SAO/config/config.php` (`signals`, `release_health`, `closure.auto_close.enabled`: literal defaults, no `env()`)
- Modify: `Modules/SAO/app/Ingest/SignalIngestService.php:84-85`, `Modules/SAO/app/Console/AutoOpenSignalTicketsCommand.php:34` (already bounded), `Modules/SAO/app/Services/ReleaseHealthService.php:38,184,185,215` (bounds)
- Test: `Modules/SAO/tests/Unit/Settings/SaoRuntimeSettingDefinitionsTest.php`, `Modules/SAO/tests/Feature/Seeders/SAODatabaseSeederTest.php`, `Modules/SAO/tests/Feature/Settings/SaoSettingsReadSitesTest.php`
- Docs: `Modules/SAO/README.md`, `Modules/SAO/docs/rag/` page that describes configuration

**Interfaces:**
- Consumes: `Seeder::internalSettingsDefinition(string $module, array $rows): SeedDefinition`; `SeedReconciler::reconcile(SeedDefinition): SeedOutcome`; the row shape `array{name, value, encrypted, choices, type: SettingTypeEnum, group_name, description}` of `AIDatabaseSeeder::setting()`.
- Produces: `SAODatabaseSeeder::runtimeSettingDefinitions(): array` (nine rows, `group_name` `sao`, names without module prefix: `signals.max_occurrences_per_window`, `signals.window_minutes`, `signals.auto_open.enabled`, `signals.auto_open.min_occurrences`, `release_health.window_days`, `release_health.regression_rate_factor`, `release_health.regression_min_occurrences`, `release_health.regressed_new_signals`, `closure.auto_close.enabled`). Same shape the AI and MES seeders expose.

- [x] **Step 1: Write the failing definitions test**

`SaoRuntimeSettingDefinitionsTest`: a dataset over the nine names asserting, as the AI test does, `value`, `type` (`Integer`, `Float` for the factor, `Boolean` for the two switches), `choices` null, `group_name` `sao`, and `config('sao.' . $name)` equal to the value.

- [x] **Step 2: Run it to verify it fails**

Run: `php artisan test --compact Modules/SAO/tests/Unit/Settings/SaoRuntimeSettingDefinitionsTest.php`
Expected: FAIL, `runtimeSettingDefinitions` is not defined.

- [x] **Step 3: Implement `SAODatabaseSeeder::runtimeSettingDefinitions(): array` and the `run()` reconcile**

Follow `MESDatabaseSeeder::run()` (reconcile, then the `created/realigned/unchanged` console line). The class now extends `Modules\Core\Overrides\Seeder`; keep `$this->call(SAOPermissionSeeder::class)`. Descriptions are one short English sentence each.

- [x] **Step 4: Replace the `env()` calls in `config/config.php` with the literals of Global Constraints**

Remove only the nine names; leave the cron, sync, health and retention entries untouched.

- [x] **Step 5: Run the definitions test to verify it passes**

Run: the Step 2 command. Expected: PASS.

- [x] **Step 6: Write the failing seeder and read-site tests**

`SAODatabaseSeederTest`: the nine rows are created stamped module `SAO`; a second run keeps an operator-changed value (copy the shape of `AIDatabaseSeederTest`'s idempotence test with `signals.window_minutes`). `SaoSettingsReadSitesTest`, each case sets a `Setting` row, applies `DatabaseConfigOverlay::applyFromDatabase(app(PerModelSettingResolver::class))` and asserts the code follows it:
- `SignalIngestService` with `signals.window_minutes` 5 and `signals.max_occurrences_per_window` 2 stops accepting occurrences after two in five minutes;
- stored 0 for `signals.window_minutes`, `release_health.window_days`, `release_health.regression_min_occurrences`, `release_health.regressed_new_signals` behaves as 1, a stored 0 or negative for `signals.max_occurrences_per_window` never limits, and `release_health.regression_rate_factor` stored 0.2 behaves as 1.0 (Review Focus 1);
- `ClosureCoordinator` applies a `close` policy when `closure.auto_close.enabled` is true and only proposes when false;
- a console boot with `signals.auto_open.enabled` true: after seeding the row, `app(Schedule::class)->events()` contains `sao:signals:auto-open` (Review Focus 3); with false it does not;
- a queued job processed after the Setting changed sees the new `signals.window_minutes` (Review Focus 5, dispatch through the `database` queue connection and `JobProcessing`, not `sync`).

- [x] **Step 7: Run them to verify they fail**

Run: `php artisan test --compact Modules/SAO/tests/Feature/Seeders/SAODatabaseSeederTest.php Modules/SAO/tests/Feature/Settings/SaoSettingsReadSitesTest.php`
Expected: FAIL (seeder rows missing; bounds absent).

- [x] **Step 8: Add the lower bounds at the read sites listed in Files**

`max(1, (int) config(...))` and `max(1.0, (float) config(...))` per Global Constraints; no other change to those methods.

- [x] **Step 9: Run the SAO tests, then Pint**

Run: `php artisan test --compact Modules/SAO/tests/Unit/Settings Modules/SAO/tests/Feature/Seeders Modules/SAO/tests/Feature/Settings`, then from the laraplate root `vendor/bin/pint --format agent <the files of this task>`.
Expected: PASS, Pint clean.

- [x] **Step 10: Document in the SAO README and RAG page, commit, push, open the PR**

Document the nine Settings with defaults and where they are edited. Commit message `feat(sao): runtime tunables are settings`. PR description: what moved, link to the spec.

---

### Task 2: MES, two Settings

**Repository:** MES (`Modules/MES`), branch `feat/mes-runtime-settings`.

**Files:**
- Modify: `Modules/MES/database/seeders/MESDatabaseSeeder.php` (two rows in the existing `runtimeSettingDefinitions()`)
- Modify: `Modules/MES/config/config.php:43-44` (literals 480 and 5)
- Test: the existing test that covers `MESDatabaseSeeder::runtimeSettingDefinitions()` (find it under `Modules/MES/tests/*/Seeders`), `Modules/MES/tests/Feature/Settings/MesProductionSettingsTest.php`
- Docs: `Modules/MES/README.md`, MES `docs/rag/` page that describes configuration

**Interfaces:**
- Consumes: the row shape of `MESDatabaseSeeder::runtimeSettingDefinitions()` (the lot number row is the model).
- Produces: rows `production.daily_minutes` (`Float`, 480) and `production.default_lead_time_days` (`Integer`, 5), `group_name` `mes`.

- [x] **Step 1: Write the failing tests**

The definitions test asserts the two rows with value, type, group and `config('mes.' . $name)` equal to the value. `MesProductionSettingsTest`: with `production.daily_minutes` 960 stored and overlaid, `ProductionLeadTimeEstimator` uses 960; a stored 0 for either value behaves as the existing floors (1.0 and 1).

- [x] **Step 2: Run to verify they fail**

Run: `php artisan test --compact Modules/MES/tests --filter="runtime setting|MesProductionSettings"`
Expected: FAIL.

- [x] **Step 3: Add the two rows, replace the two `env()` calls with literals**

The floors in `ProductionLeadTimeEstimator` already exist: do not change the estimator.

- [x] **Step 4: Run the tests and Pint**

Run: the Step 2 command, then Pint on the touched files from the root.
Expected: PASS, Pint clean.

- [x] **Step 5: Document, commit (`feat(mes): production defaults are settings`), push, open the PR**

---

### Task 3: Core, three Settings

**Repository:** Core (`Modules/Core`), branch `feat/core-runtime-settings`.

**Files:**
- Modify: `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (three `self::setting(...)` rows next to `media.search_visibility`)
- Modify: `Modules/Core/config/config.php:129,137,147` (literals 48, 60, 24)
- Modify: `Modules/Core/app/Models/Concerns/HasValidity.php:210`, `Modules/Core/app/Console/PruneMediaDraftsCommand.php:37`, `Modules/Core/app/Filament/Resources/Settings/Tables/SettingsTable.php:228,247`, `Modules/Core/app/Filament/Resources/Modifications/Pages/ListModifications.php:88`, `Modules/Core/app/Filament/Resources/Permissions/Tables/PermissionsTable.php:78`
- Test: `Modules/Core/tests/Feature/Settings/CoreRuntimeSettingDefinitionsTest.php`, `Modules/Core/tests/Feature/Settings/CoreTunableReadSitesTest.php`
- Docs: `Modules/Core/README.md`, `Modules/Core/docs/rag/MODULE.md`

**Interfaces:**
- Consumes: `CoreDatabaseSeeder::setting(string $name, mixed $value, SettingTypeEnum $type, string $group, string $description, ?array $choices = null)`.
- Produces: rows `validity.expiring_within_hours` (Integer, 48, group `validity`), `filament.tabs_counts_ttl_seconds` (Integer, 60, group `filament`), `media.draft_ttl_hours` (Integer, 24, group `media`). The three Filament read sites all fall back to 60 and read `max(1, (int) config(...))` (the spec's unification).

- [x] **Step 1: Write the failing tests**

Definitions test: the three rows (value, type, `config('core.' . $name)` equal to the value). Read-site test: `HasValidity` expiring window follows a stored 72 and reads a stored 0 as 1; `PruneMediaDraftsCommand` with `media.draft_ttl_hours` 1 prunes a draft two hours old and keeps one of thirty minutes, and a stored 0 or negative prunes nothing (0 = never prune, spec Mechanism); the Filament sites read the same TTL: assert through a small cache-key spy that all three call `Cache::remember` with 60 when nothing is stored and with the stored value after overlay.

- [x] **Step 2: Run to verify they fail**

Run: `php artisan test --compact Modules/Core/tests --filter="CoreRuntimeSettingDefinitions|CoreTunableReadSites"`
Expected: FAIL.

- [x] **Step 3: Add the rows, replace the three `env()` calls with literals, add the bounds and unify the Filament fallbacks to 60**

The `PermissionsTable` constant `FILTER_OPTIONS_TTL_SECONDS` is no longer the fallback; remove it if nothing else uses it.

- [x] **Step 4: Run the tests and the existing Settings and seeder tests that touch the Core seeder, then Pint**

Run: the Step 2 command plus `php artisan test --compact Modules/Core/tests --filter="Seeder|Setting"`.
Expected: PASS, Pint clean.

- [x] **Step 5: Document, commit (`feat(core): validity, tab-count and media draft values are settings`), push, open the PR**

---

### Task 4: AI, two Settings

**Repository:** AI (`Modules/AI`), branch `feat/ai-runtime-settings`.

**Files:**
- Modify: `Modules/AI/database/seeders/AIDatabaseSeeder.php` (two rows in `runtimeSettingDefinitions()`, next to the other `features.*.enabled` rows)
- Modify: `Modules/AI/config/config.php:219,222` (literals `true` and `10.0`)
- Modify: `Modules/AI/app/Services/LlmSearchService.php:118` (bound at `max(0.0, ...)`), `Modules/AI/app/Search/AiSearchStrategyResolver.php:47` (unchanged)
- Test: `Modules/AI/tests/Unit/Settings/AiRuntimeSettingDefinitionsTest.php` (two dataset rows), `Modules/AI/tests/Feature/Settings/SearchOrchestrationSettingsTest.php`
- Docs: `Modules/AI/README.md`, AI `docs/rag/` configuration page

**Interfaces:**
- Consumes: `AIDatabaseSeeder::setting(...)` (private static, same class).
- Produces: rows `features.search_orchestration.enabled` (Boolean, true) and `features.search_orchestration.timeout` (Float, 10.0), `group_name` `ai`.

- [x] **Step 1: Write the failing tests**

Add two rows to the existing dataset of `AiRuntimeSettingDefinitionsTest` (it already asserts `config('ai.' . $name)`). `SearchOrchestrationSettingsTest`: with the switch stored false and overlaid, `AiSearchStrategyResolver` degrades with reason `search_orchestration_disabled`; with `timeout` stored 0, `LlmSearchService` configures the agent with 0.0 (no limit), with a negative value 0.0, with 25 it configures 25.0.

- [x] **Step 2: Run to verify they fail**

Run: `php artisan test --compact Modules/AI/tests/Unit/Settings/AiRuntimeSettingDefinitionsTest.php Modules/AI/tests/Feature/Settings/SearchOrchestrationSettingsTest.php`
Expected: FAIL.

- [x] **Step 3: Add the rows, replace the two `env()` calls with literals, bound the timeout at `LlmSearchService.php:118` as `max(0.0, ...)`**

- [x] **Step 4: Run the tests and `AIDatabaseSeederTest`, then Pint**

Run: the Step 2 command plus `php artisan test --compact Modules/AI/tests/Feature/Seeders/AIDatabaseSeederTest.php`.
Expected: PASS, Pint clean.

- [x] **Step 5: Document, commit (`feat(ai): search orchestration switch and timeout are settings`), push, open the PR**

---

### Task 5: laraplate root, `.env.example` and plan closure

**Repository:** laraplate root, branch `feat/settings-over-env-env-example`. Starts after Tasks 1 to 4 are merged in their repositories and the submodule pointers are bumped by the owner, because `EnvExampleCoversConfigTest` reads the module configs through the pointers.

**Files:**
- Modify: `.env.example` (delete the 16 names and, where a comment block becomes empty, the block)
- Modify: `docs/superpowers/plans/2026-10-28-settings-over-env.md` (close), `docs/superpowers/plans/INDEX.md`
- Test: `tests/Unit/EnvExampleCoversConfigTest.php` (existing, unchanged)

**Interfaces:**
- Consumes: the module configs after Tasks 1 to 4 (no `env()` for the 16 names).
- Produces: a green `EnvExampleCoversConfigTest` and a closed plan.

- [x] **Step 1: Confirm the 16 names are no longer read by any config**

Run: `grep -rnE "SAO_SIGNAL_MAX_OCCURRENCES|SAO_SIGNAL_WINDOW_MINUTES|SAO_SIGNAL_AUTO_OPEN|SAO_RELEASE_HEALTH_|SAO_CLOSURE_AUTO_CLOSE|MES_PRODUCTION_DAILY_MINUTES|MES_PRODUCTION_DEFAULT_LEAD_TIME_DAYS|CORE_VALIDITY_EXPIRING_WITHIN_HOURS|FILAMENT_TABS_COUNTS_TTL_SECONDS|CORE_MEDIA_DRAFT_TTL_HOURS|AI_SEARCH_ORCHESTRATION_ENABLED|AI_SEARCH_LLM_TIMEOUT" Modules/*/config`
Expected: no match. The guard `EnvExampleCoversConfigTest` only checks that every name a config reads is in the example, not the reverse, so it cannot flag a stale line: this grep is the check.

- [x] **Step 2: Remove the 16 names from `.env.example`**

- [x] **Step 3: Verify**

Run: the grep of Step 1 over `.env.example` (expected: no match), `php artisan test --compact tests/Unit/EnvExampleCoversConfigTest.php` (expected: PASS), `bash plan-status --open` (expected: this plan listed with Tasks 1 to 4 done).

- [x] **Step 4: Close the plan**

Front matter `status: completed`, a `## Delivery status (2026-MM-DD): ...` section with a `**Documented in:**` line naming the four module READMEs and RAG pages, every box `- [x]` or `- [-]` with its reason, and a divergences list (`ERP_STOCK_RESERVATION_SOFT_TTL` decision made by the owner). Add the plan's line to `docs/superpowers/plans/INDEX.md` (name and what it covers, no status). Run `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`.
Expected: PASS.

- [x] **Step 5: Commit (`docs: close the settings over env plan, drop the moved names from .env.example`), push, open the PR**

## Delivery status (2026-10-28): delivered

16 runtime tunables are Settings: nine in SAO, two in MES, three in Core, two in AI. Each module repository merged its own PR (larasao#2, larames#2, laracore#9, laraai#4), followed by two fixes that landed after the first merges (larasao#3, laraai#5). The 16 names and their comment blocks are gone from `.env.example`; `EnvExampleCoversConfigTest` stays green.

**Documented in:** `Modules/SAO/docs/rag/MODULE.md`, `Modules/MES/docs/rag/MODULE.md`, `Modules/Core/docs/rag/MODULE.md`, `Modules/AI/docs/rag/MODULE.md` (each module's `README.md` carries the same Settings table).

Divergences from the plan, all decided during the run and recorded in the spec (Mechanism and Decisions sections):

- `media.draft_ttl_hours`, `signals.max_occurrences_per_window` and `features.search_orchestration.timeout` keep an existing "0 = off" meaning and take no lower bound of 1; the timeout is read as `max(0.0, ...)`. A floor would have turned an off-switch into an active mode.
- `features.search_orchestration.enabled` moved although the 2026-10-07 decision kept it in env: its boot-time reader no longer exists.
- `ERP_STOCK_RESERVATION_SOFT_TTL` was dropped from the list: no code reads `erp.stock_reservation.soft_ttl` today. The owner decided to leave the key in `.env.example` and the ERP config as it is: it is read by whoever creates a `soft` hold, and the intended creator is the Shop checkout (not yet planned; Shop spec E29).
- The Core definitions test lives in `tests/Feature/Settings` (Core's Unit test case has no config).
- The Filament tab-count TTL is read at four call sites, all now with the same fallback (60); the `FILTER_OPTIONS_TTL_SECONDS` constant of `PermissionsTable` was removed.
- `.env.example` comment blocks that only described removed variables were deleted with them.
