# Core Versioning Rebuild, Slice 5 — Epochs and Baselines

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** every root has a complete anchor of its whole aggregate inside its current schema epoch: a baseline set, written by the first write after its creation, after versioning is switched back on, after a `best_effort` set, or after its schema changed, never in a revert set. Switching versioning off for a table discards its edit history and keeps the hard-delete snapshots.

**Parent plan:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, Slice 5. **Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`. **Decisions:** D13, D22, D32, D33, D45 rule 1, D52 (the baseline after `best_effort`), D53, D57 (coverage in the fingerprint), D60 in `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md`.

**Architecture:** three parts, each closed (tests, regression, commit) before the next.

- **Part A — The whole-aggregate baseline.** One method of `HasVersions` reads the aggregate as rows (root, locales, membership); the hard-delete snapshot of Slice 4 is rebuilt on it. A `BaselinePolicy` says whether a baseline is due for a root when the manager opens its set; the writer appends the aggregate image at the end of that set and stores it as `baseline`. Creation keeps its own rows.
- **Part B — Schema epochs.** A table `core_versions_epochs`, a fingerprint per root class computed at its first write per process, `epoch_id` on every version row, and an epoch change as one more reason a baseline is due.
- **Part C — Switching versioning off and on.** Turning a table's strategy to `false` purges its edit history in a queued, chunked job, after a confirmation on the settings page that names what is lost; the snapshot sets of hard-deleted records survive. Turning it back on needs no code: a root with no set left has no baseline, so its first write writes one.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, Filament 5. Module: `Core` only.

## Global Constraints

- As in the parent plan: `declare(strict_types=1);`, explicit types, PHPDoc over inline comments, `#[Override]` only on real overrides (never on a method that redefines a trait method), tests first, Pint from the root on an explicit file list, commits inside the module repository with `/usr/bin/git`, no `Co-Authored-By` trailer, a branch and a PR per repository. Run the Core Integration folder per subfolder.
- Schema changes go into the create migrations (pre-stable, `migrate:fresh`), no alter migration.
- Tables of the history are named `core_versions_*` (D32): the epochs table is `core_versions_epochs`.
- PHPStan reports of the touched files are compared with the baseline before and after; nothing is silenced.
- Facts this plan relies on (read on 2026-10-30):
  - `ActiveVersionSet::storeAsBaseline()` (`Modules/Core/app/Versioning/ActiveVersionSet.php`) stores `baseline` only while the set row does not exist and the options kind is `Change`; its only caller is `VersionWriter::write()`, for the root's own `Created` row. Creation is the only trigger today.
  - `VersionSetManager::runAfterTheFact()` records `best_effort` when no application transaction is open; nothing reads the capture of earlier sets.
  - `HasVersions::hardDeleteSnapshot()` already reads root, translations (`RecordsChangesOnOwner::imageOnOwner()`), membership (`HasVersionedRelations::currentVersionedMembership()`) and origins.
  - `Version::stateAsOf()` anchors on the latest own `SNAPSHOT` row that is not `deleted`; it reads neither the set kind nor the capture. The complete-anchor gate of D45 rule 2 and the classification of D13 at revert time belong to Slice 6.
  - The strategy is the setting `versioning.strategy.{table}` (group `versioning`, value `false` or a `VersionStrategy`); `SettingObserver` flushes the caches through `SettingsCacheCoordinator::flushVersioningCaches()` and nothing else. `EditSetting` already runs `SettingChangeConfirmations::for($name)?->warn()` before a save and `->confirmed()` after it (`ISettingChangeConfirmation`); the only implementation is AI's `EmbeddingModelSettingConfirmation`.
  - No `epoch` exists anywhere in Core; `RelationDescriptor` has `relation`, `ownership`, `coverage`, `pivotColumns`.

## Review Focus

- **A record that existed before this slice** has sets but no `baseline` set: its first write must write one, once. Pinned in Task A2.
- **A revert as the first write after a `best_effort` set** must not carry the baseline, and the next ordinary write must (D60). Pinned in Task A2.
- **A model with no translations and no registered relations** gets a baseline of the root row only, and a plain versioned model's write cost grows by one indexed query at most. Pinned in Task A2 (query count).
- **Re-enabling while the purge job is still queued** must not lose the new history: the job checks the setting again when it runs. Pinned in Task C1.
- **Two models sharing one table** (`Entity`, `Taxonomy`, `Preset`): the purge works by table, the epoch by root class. Pinned in Tasks B2 and C1.

---

## Part A — The whole-aggregate baseline

### Task A1: The aggregate as rows

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`aggregateImage()`; `hardDeleteSnapshot()` rebuilt on it)
- Test: `Modules/Core/tests/Integration/Versioning/HardDeleteSnapshotTest.php` (unchanged, must stay green)

**Interfaces:**
- Produces: `public function aggregateImage(VersionChangeType $rootType, VersionChangeType $partType, VersionChangeType $linkType): list<VersionChange>` on `HasVersions`: one `SNAPSHOT` row of the root read back (`filterVersionableImage()` of the refreshed raw attributes), one `SNAPSHOT` row per locale read back (`relation_path = translations`, `subject_key = {locale}`), one row per link of each registered relation (`relation_path` = the relation, `subject_key = {id}`, contents = its declared pivot columns). The image goes in `contents`; `hardDeleteSnapshot()` maps it to `original_contents` and adds the origin rows.

- [ ] **Step 1:** Extract the root, locale and membership parts of `hardDeleteSnapshot()` into `aggregateImage()`; `hardDeleteSnapshot()` keeps its row format (D55) and its origin rows.
- [ ] **Step 2:** Run `HardDeleteSnapshotTest`, `ClearExpiredSnapshotTest` and the CMS `ContentExtensionHardDeleteSnapshotTest`: green, unchanged.
- [ ] **Step 3:** Commit (`refactor(versioning): read the aggregate as rows once`).

### Task A2: A baseline is due, and the writer appends it (D45 rule 1, D52, D53, D60)

**Files:**
- Create: `Modules/Core/app/Versioning/BaselinePolicy.php`
- Modify: `ActiveVersionSet` (`baselineDue`, set when the set opens; `storeAsBaseline()` unchanged in its refusals), `VersionSetManager::openSet()` (asks the policy), `VersionSetManager::runOutermost()`/`finish()` (appends the image before the set is finished), `VersionWriter` (the creation path keeps writing its own rows and clears the due flag)
- Test: `Modules/Core/tests/Integration/Versioning/BaselineAfterBestEffortTest.php` (create), `Modules/Core/tests/Integration/Versioning/CreationBaselineTest.php` (stays green)

**Interfaces:**
- Produces: `final readonly class BaselinePolicy` with `public function isDue(VersionSetRoot $root): bool`. Due when the root has no `baseline` set, or its latest `best_effort` set is newer than its latest `baseline` set (Part B adds the epoch). One query on `core_versions_sets` by `(root_type, root_id)` (index `vsets_root_IDX`) selecting the two maxima with portable `CASE WHEN` aggregates.
- Produces: `ActiveVersionSet::isBaselineDue(): bool`.
- Rule (D45, D22): the baseline rows are written after the set's own rows, in the same set, through the writer, with change type `updated` for root and locales and `created` for links; the set is stored as `baseline` (D53). A set whose options kind is `Revert` never writes it and leaves it due (D60). A set that wrote no row of its own writes no baseline either (an empty set is removed as today). The creation set is already whole: no extra rows.

- [ ] **Step 1: Write the failing tests** (`BaselineAfterBestEffortTest`, stubs `TranslatedDocument` and `VersionedRelationRoot`):
  - `it writes a baseline with the root, every locale and every link at the first atomic write after a best_effort set`: a write through `runAfterTheFact()` with no transaction gives a `best_effort` set; the next `save()` gives a set of kind `baseline` holding its own diff row first, then one root `SNAPSHOT`, one `SNAPSHOT` per locale, one `created` row per link.
  - `it writes the baseline once`: a second save after it writes a plain `change` set.
  - `it writes a baseline at the first write of a record that has sets but no baseline`: delete the record's `baseline` set rows directly, save, expect a `baseline` set.
  - `it never writes the baseline in a revert set and writes it at the next save (D60)`: after a `best_effort` set, `revertToVersion()` writes a `revert` set with no baseline rows; the next save writes a `baseline` set.
  - `it joins a set stored as baseline from a nested run with ordinary options`.
  - `it adds at most one query to the write of a plain versioned model`: count queries of a `VersionedArticle` update before and after with `DB::enableQueryLog()`; record the numbers in the plan.
- [ ] **Step 2:** Run them: they fail (no baseline written after `best_effort`).
- [ ] **Step 3:** Implement `BaselinePolicy::isDue()`, the due flag in `ActiveVersionSet`, and the append in the manager before `finish()`.
- [ ] **Step 4:** Run `Modules/Core/tests/Integration/Versioning`, then the CMS suite (translations write in the owner's set): green.
- [ ] **Step 5:** Commit (`feat(versioning): a whole-aggregate baseline after a best_effort set (D45, D52, D60)`).

### Part A closing

- [ ] Regression per suite (Core per folder, CMS, ERP, MES); `MODULE.md` of Core (when a baseline is written and what it holds).

---

## Part B — Schema epochs (D13, D32, D33, D57)

### Task B1: The epochs table and the row's epoch

**Files:**
- Modify: `Modules/Core/database/migrations/2019_05_31_042933_create_version_sets_table.php` (also creates the epochs table, before `core_versions`)
- Create: `Modules/Core/app/Models/VersionEpoch.php`, `Modules/Core/database/factories/VersionEpochFactory.php`
- Modify: `Modules/Core/database/migrations/2019_05_31_042934_create_versions_table.php` (`epoch_id`), `Modules/Core/app/Enums/CoreTables.php` (`VersionsEpochs = 'core_versions_epochs'`), `Modules/Core/app/Models/Version.php` (`epoch()` relation, `@property`), the generated-permission exclusions where `Version` and `VersionSet` are excluded
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetSchemaTest.php`

**Interfaces:**
- Produces: table `core_versions_epochs` with `id`, `root_type` (string), `fingerprint` (string 64), `definition` (json), `first_seen_at` (timestamp); unique `(root_type, fingerprint)` named `vepochs_type_fingerprint_UN`. `core_versions.epoch_id` nullable foreign key to it, `restrictOnDelete` (epochs are never removed). `VersionEpoch` casts `definition` to array, no `updated_at`, no soft delete.

- [ ] **Step 1: Failing schema tests:** the table and its unique key exist; `core_versions.epoch_id` references it; deleting a referenced epoch fails.
- [ ] **Step 2–4:** run and fail, implement, `php artisan migrate:fresh` on the test database through the suite, run.
- [ ] **Step 5:** Commit.

### Task B2: The fingerprint, detected at the first write per process

**Files:**
- Create: `Modules/Core/app/Versioning/EpochFingerprint.php`, `Modules/Core/app/Versioning/VersionEpochs.php`
- Modify: `VersionWriter` (stamps `epoch_id` on every row with the epoch of the row's root class)
- Test: `Modules/Core/tests/Integration/Versioning/VersionEpochTest.php` (create)

**Interfaces:**
- Produces: `final readonly class EpochFingerprint` with `public function definitionOf(Model $root): array{columns: list<array{name: string, type: string, nullable: bool}>, translation_columns: list<array{name: string, type: string, nullable: bool}>, relations: list<array{relation: string, ownership: string, coverage: string, pivot_columns: list<string>}>}` and `public function fingerprintOf(array $definition): string` (sha256 of the definition JSON with sorted keys and lists). Columns come from `Schema::getColumns()` of the root's table, kept when versionable (`getVersionable()` when declared, otherwise every column minus `getDontVersionable()`); translation columns from the translation model's table restricted to `ownerImageKeys()`; relations from `versionedRelations()`. Defaults, non-versionable columns and the inside of JSON columns are left out (D33).
- Produces: `final class VersionEpochs` (container singleton) with `public function currentFor(Model $root): VersionEpoch` (cached per root class for the process; `firstOrCreate` on `(root_type, fingerprint)` with `first_seen_at = now()`, retried once on a unique violation) and `public function hasSeveral(string $rootType): bool` (cached per process), `public function flush(): void` (called by the `MigrationsEnded` listener in `CommandListenerProvider` and by the tests).

- [ ] **Step 1: Failing tests:** two writes of a class in one process create one epoch; adding a versionable column (Schema change in the test, then `flush()`) creates a second epoch and the next row carries it; adding a column listed in `$dontVersionable`, or changing a default, keeps the epoch; a new registered relation or a changed coverage changes the fingerprint (D57); the same fingerprint coming back reuses its epoch; two classes on one table get an epoch each.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit.

### Task B3: An epoch change makes a baseline due (D33 lazy baseline)

**Files:**
- Modify: `BaselinePolicy::isDue()` (consumes `VersionEpochs`)
- Test: `VersionEpochTest.php`

**Interfaces:**
- Consumes: `VersionEpochs::hasSeveral(string $rootType)`, `VersionEpochs::currentFor(Model)`.
- Rule: only when the root's class has several epochs, read the `epoch_id` of the root's latest row; a different epoch makes the baseline due. With one epoch nothing more is queried.

- [ ] **Step 1: Failing tests:** after an epoch change the first save of a record writes a `baseline` set whose rows carry the new epoch; the first pivot change after an epoch change writes the baseline too (D45); with a single epoch the query count of a write is the one measured in Task A2.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit.

### Part B closing

- [ ] Regression per suite; `MODULE.md` of Core (epochs, what the fingerprint covers, the workers that must restart after a deploy so the per-process cache is rebuilt).
- [-] The migration declaration of D13 (a migration that drops, renames or retypes a versioned column declares it, enforced by an architecture test) moves to Slice 6 with the classification it feeds: nothing reads it before the revert classifies columns.

---

## Part C — Switching versioning off and on (D22)

### Task C1: Turning a table's versioning off purges its edit history

**Files:**
- Create: `Modules/Core/app/Versioning/VersionHistoryPurger.php`, `Modules/Core/app/Jobs/PurgeVersionHistoryJob.php`, `Modules/Core/app/Versioning/VersioningSettingConfirmation.php`
- Modify: `Modules/Core/app/Services/SettingsCacheCoordinator.php` or the `SettingObserver` (dispatch the job when a `versioning.strategy.*` value becomes `false`), `CoreServiceProvider` (register the confirmation in `SettingChangeConfirmations`)
- Test: `Modules/Core/tests/Integration/Versioning/VersioningSwitchTest.php` (create), `Modules/Core/tests/Feature/Filament/` the settings edit page test for the warning

**Interfaces:**
- Produces: `final readonly class VersionHistoryPurger` with `public function countFor(string $table): array{sets: int, rows: int}` and `public function purge(string $table): int` (sets removed). It removes, `chunkById(500)`, the sets whose `root_table_ref` is the table, except the snapshot sets of hard-deleted records: a set holding a `deleted` row with no `relation_path` (D17, D22 "DO NOT FORGET").
- Produces: `PurgeVersionHistoryJob(string $table, string $settingName)`, queued; `handle()` reads the setting again and does nothing unless it is still `false`.
- Produces: `VersioningSettingConfirmation implements ISettingChangeConfirmation`: `supports()` for names starting with `versioning.strategy.`; `warn()` only when the new value is `false` and the old one is not, naming the sets and rows lost (`countFor()`) and saying that hard-delete snapshots are kept; `confirmed()` nothing (the observer dispatches, so an approved modification or the API purge too); `lockedReason()` null.

- [ ] **Step 1: Failing tests:** setting a table's strategy to `false` dispatches the job (`Bus::fake()`); the job removes every set of the table's roots and keeps a hard-delete snapshot set; the job does nothing when the setting is a strategy again by the time it runs; the purge is by table, so two models on one table both lose their history; the settings page asks for confirmation naming the counts and saves after it.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit.

### Task C2: Turning it back on anchors each record at its first write

**Files:**
- Test: `Modules/Core/tests/Integration/Versioning/VersioningSwitchTest.php`

- [ ] **Step 1: Write the tests (D22, D45):** off (job run), on, then a save gives one `baseline` set holding the save's own row, the root, every locale and every tag (the image after the save); a record not saved since has no version and `currentRevision()` is `null`; a revert to the baseline set leaves tags and translations in place.
- [ ] **Step 2:** Run: expected to pass on Part A and C1 with no new code; any failure is fixed in the task that owns the code.
- [ ] **Step 3:** Commit.

### Part C closing and slice closing

- [ ] **Documentation:** `MODULE.md` of Core (switching versioning off and on, what is kept); `docs/rag` operator note if the settings documentation lists the versioning settings; the spec and review status lines; the parent plan (tick Slice 5).
- [ ] **Regression** per suite, recorded in the delivery status.
- [ ] **Commit** in Core and in `laraplate/`; PRs.

**Documented in (when the plan closes):** `Modules/Core/docs/rag/MODULE.md`.
