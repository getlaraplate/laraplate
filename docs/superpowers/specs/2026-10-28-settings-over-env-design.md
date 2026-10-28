# Runtime tunables move from `.env` to Settings

**Date:** 2026-10-28
**Status:** Draft, waiting for the owner's review.
**Modules:** `Core`, `SAO`, `MES`, `AI`
**Origin:** the owner asked which of the 139 module variables in `.env.example` are not URLs or credentials and could be Settings instead. The list below is the part they agreed to after a review that cut the first proposal (about 70) down to 16.

## Goal

A variable that an administrator changes for business reasons, and that is harmless or bounded when wrong, is a Setting edited in Filament, not an entry of `.env`. `.env.example` keeps the rest: URLs, credentials, infrastructure, boot-time structure and protections.

## Rule for what moves

A variable moves only if all five hold:

1. An administrator changes it for business reasons, not an operator for infrastructure reasons.
2. Sending a wrong value is harmless, or the read site clamps it.
3. It is read at runtime, after the Settings overlay has run.
4. It is not a security or anti-abuse limit.
5. Changing it leaves no data inconsistent (no reindex, no re-signing).

## Mechanism (nothing new)

The existing one, as `AIDatabaseSeeder` already uses it:

- A module seeder returns definitions (`name`, `value`, `type`, `group_name`, `description`, optional `choices`) and `SeedReconciler` creates them. The name carries no module prefix: the overlay copies the row onto the config key `{module}.{name}`, so `sao.signals.window_minutes` stays the same key the code already reads.
- A managed value has **no config entry and no env variable**. The `env()` call and the config line are deleted; the read site keeps `config('key', default)` and the default must equal the seeded one.
- Where the overlay applies, verified in code: HTTP requests (`ApplyDatabaseSettingsOverlay` in the `web` and `api` groups and in the Filament panel stack), console commands (boot of `CoreServiceProvider`), queued jobs (`ApplySettingsOverlayBeforeJob` before each job). A long-lived worker therefore sees a change on the next job.
- `Setting` has no min/max. Bounds are enforced where the value is read (`max(1, ...)`), as the code already does.

## The 16 variables

| Module | Variable | Config key | Type | Seeded default | Read at |
|---|---|---|---|---|---|
| SAO | `SAO_SIGNAL_MAX_OCCURRENCES` | `signals.max_occurrences_per_window` | integer | 1000 | `SignalIngestService` (HTTP, jobs) |
| SAO | `SAO_SIGNAL_WINDOW_MINUTES` | `signals.window_minutes` | integer | 60 | `SignalIngestService` |
| SAO | `SAO_SIGNAL_AUTO_OPEN` | `signals.auto_open.enabled` | boolean | false | `SAOServiceProvider::registerCommandSchedules` (inside `booted`) |
| SAO | `SAO_SIGNAL_AUTO_OPEN_MIN` | `signals.auto_open.min_occurrences` | integer | 1 | `AutoOpenSignalTicketsCommand` |
| SAO | `SAO_RELEASE_HEALTH_WINDOW_DAYS` | `release_health.window_days` | integer | 7 | `ReleaseHealthService` |
| SAO | `SAO_RELEASE_HEALTH_REGRESSION_FACTOR` | `release_health.regression_rate_factor` | float | 1.5 | `ReleaseHealthService` |
| SAO | `SAO_RELEASE_HEALTH_REGRESSION_MIN` | `release_health.regression_min_occurrences` | integer | 3 | `ReleaseHealthService` |
| SAO | `SAO_RELEASE_HEALTH_REGRESSED_NEW_SIGNALS` | `release_health.regressed_new_signals` | integer | 3 | `ReleaseHealthService` |
| SAO | `SAO_CLOSURE_AUTO_CLOSE` | `closure.auto_close.enabled` | boolean | false | `ClosureCoordinator` |
| MES | `MES_PRODUCTION_DAILY_MINUTES` | `production.daily_minutes` | float | 480 | `ProductionLeadTimeEstimator` (already `max(1.0, ...)`) |
| MES | `MES_PRODUCTION_DEFAULT_LEAD_TIME_DAYS` | `production.default_lead_time_days` | integer | 5 | `ProductionLeadTimeEstimator` (already `max(1, ...)`) |
| Core | `CORE_VALIDITY_EXPIRING_WITHIN_HOURS` | `validity.expiring_within_hours` | integer | 48 | `HasValidity` |
| Core | `FILAMENT_TABS_COUNTS_TTL_SECONDS` | `filament.tabs_counts_ttl_seconds` | integer | 60 | `SettingsTable`, `ListModifications`, `PermissionsTable` |
| Core | `CORE_MEDIA_DRAFT_TTL_HOURS` | `media.draft_ttl_hours` | integer | 24 | `PruneMediaDraftsCommand` (console) |
| AI | `AI_SEARCH_ORCHESTRATION_ENABLED` | `features.search_orchestration.enabled` | boolean | true | `AiSearchStrategyResolver` |
| AI | `AI_SEARCH_LLM_TIMEOUT` | `features.search_orchestration.timeout` | float | 10 | `LlmSearchService` |

The AI switch fixes an inconsistency: every other `ai.features.*.enabled` is already a Setting.

## Decisions and traps found while reading the code

- **The scheduler read is safe.** `SAO_SIGNAL_AUTO_OPEN` is read in a `booted` callback, which runs after every provider's `boot()`, so the console overlay set by `CoreServiceProvider` is already applied. A test must prove it, because a reordering of providers would break it silently.
- **Clamp at the read site, not in the form.** The SAO values (windows, thresholds, factor) and the Core TTLs get a lower bound where they are read, so a typo in the form cannot disable a window or produce a zero TTL. `FILAMENT_TABS_COUNTS_TTL_SECONDS` is read with three different fallbacks (300, 60, a constant); once the seeded default is 60 the fallbacks are unified to read the same value.
- **Existing `.env` values are not carried over, and nothing documents the removal.** Laraplate is in development with no installation in use (owner, 2026-10-28), so a value set in an `.env` is ignored with no migration note. Revisit this rule before the first stable release.
- **`.env.example` and `env:fill`.** The 16 names are removed from `.env.example`. `EnvExampleCoversConfigTest` scans module configs, so it stays green because the config lines are removed too.

## Not moved, with the reason

- **`ERP_STOCK_RESERVATION_SOFT_TTL`**: no code reads `erp.stock_reservation.soft_ttl` (searched `Modules/*/app` and `Modules/*/src`). Moving a dead key would add a Setting that does nothing. Proposed instead: delete the config line and the variable, or leave it until stock reservation expiry is built. The owner decides.
- **Ingestion limits, rate limits, `CORE_AGGREGATE_MAX_*`**: protections against abuse and expensive queries; the rate limiters are registered at boot, so the overlay could also arrive late.
- **Embedding chunk size, search analyzers**: changing them leaves vectors and indexes inconsistent until a reindex.
- **`CACHE_DURATION_*`, `LOCKING_LEASE_TTL`**: cache and concurrency semantics, read very early.
- **`ARUBA_DRY_RUN`, `ARUBA_SKIP_EXTRA_SCHEMA`, ERP drivers, `ARUBA_SENDER_PIVA`**: a UI toggle could send real invoices; drivers are bound at boot; the VAT number belongs with the Aruba credentials.
- **SAO cron expressions and the `sync`/`health`/`retention` switches**: a malformed cron breaks the scheduler. Revisit the switches in a second round.
- **Retention days (SAO, MES)**: irreversible deletion and a compliance choice. Second round, only with a lower bound and an audit trail.
- **`GEOCODING_PROVIDER`**: depends on a key that stays in env; a dropdown could offer an unusable provider.

## Out of scope, separate small changes

- Delete the four provider `*_MODEL` fallbacks (`OPENAI_MODEL`, `OLLAMA_MODEL`, `VOYAGEAI_MODEL`, `MISTRAL_MODEL`): the model is already chosen by Settings and profiles.
- The nine `CMS_IMPORT_*` become options of `cms:import`.

## Testing

Per module, in the module's own `tests/`:

- The seeder creates each definition with the type and default above (idempotent on a second run).
- Overlay proof per read site: set the Setting, apply the overlay, assert the code uses it. The scheduler case runs the real `booted` path.
- Read-site clamps: a zero or negative stored value yields the lower bound.
- No `env()` or config line left for a moved name, enforced by the existing `EnvExampleCoversConfigTest`.

## Documentation

Each affected module README and `docs/rag/` page: the new Settings and their defaults. No list of removed variables.
