---
module: core, ai
---
# Core Versioning Rebuild, Slice 6 — Revert

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** one revert implementation, `revertToRevision()`, in four modes, every check under the root's lock, with the side effects and the authorization of D26 and D27: no automatic translation during a revert, a revert that needs approval becomes one request, the `history` and `revert` permissions, an HTTP route, and the membership a form loaded checked before it is replaced (D40).

**Parent plan:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, Slice 6. **Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md` ("Revert modes", "Authorization and side effects (D26)", "Concurrency"). **Decisions:** D25, D26, D27, D36, D39, D40, D45 rule 2, D48, D56, D57 in `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md`.

**Decided with the user on 2026-11-27, to record in the review log in Task A1:**

- **A revert that needs approval is one request (completes D26).** When the model would ask approval for the writer, the revert is not run: it becomes one `Modification` with operation `revert`, carrying the target revision, the expected revision and the mode. Approving it runs the revert under the approver's vote, with the expected revision of the request, so a record changed in the meantime refuses the approval with a conflict and the request stays pending. The writes the revert performs internally create no modification.
- **The revert has a CRUD route.** `POST /revert/{module}/{entity}` checks `revert` and the ACL, answers 409 on a stale revision, 202 when the revert became a request.

**Architecture:** four parts, each closed (tests, regression, commit) before the next.

- **Part A — One implementation, four modes.** A `RevisionReverter` service holds the only implementation; `HasVersions::revertToRevision()` and the shorthand `revertToVersion()` call it. Everything that decides whether the revert may run happens inside the revert set, after the root is locked. `restoreToRevision()` and its report and exceptions are replaced.
- **Part B — Side effects.** The revert context of D36 (`isRevert()`, `fromRevert`, the AI listener) and the revert as one approval request.
- **Part C — Permissions and route.** `history` and `revert` verbs, the history route on `history`, the new revert route.
- **Part D — Compare and set of a relation's membership (D40)** in the CRUD update.

**Tech Stack:** PHP 8.5, Laravel 13, Pest, Filament 5. Modules: `Core`; `AI` for Task B1 only.

## Global Constraints

- As in the parent plan: `declare(strict_types=1);`, explicit types, PHPDoc over inline comments, `#[Override]` only on real overrides (never on a method that redefines a trait method), tests first, Pint from the root on an explicit file list, commits inside the module repository with `/usr/bin/git`, no `Co-Authored-By` trailer, a branch and a PR per repository. Run the Core Integration folder per subfolder.
- Type coverage stays at 100% (`composer test:type-coverage`); PHPStan reports of the touched files are compared with the baseline before and after; nothing is silenced.
- Schema changes go into the create migrations (pre-stable, `migrate:fresh`), no alter migration. This slice needs none.
- Facts this plan relies on (read on 2026-11-27):
  - `HasVersions::revertToVersion(int|string $id): bool` (`Modules/Core/app/Models/Concerns/HasVersions.php`) opens a `Revert` set, checks the lock of D39 inside it and calls `Version::revert()`. It takes no expected revision and accepts any row of the record, a translation or membership row included.
  - `HasVersionedRelations::restoreToRevision(int $targetSetId, int $expectedSetId, bool $force)` compares `currentRevision()`, replays the membership and checks references **before** opening its set, restores the root's columns and the `reference` relations, never the translations. `currentRevision()` lives in `HasVersionedRelations`, so a model without registered relations has none.
  - No production model uses `HasVersionedRelations` (Content joins in Slice 10); the 24 calls to the two entry points are in `Modules/Core/tests/Integration/Versioning/`.
  - `Version::stateAsOf()` rebuilds the root's columns only. Translation rows carry `relation_path = translations` and `subject_key = {locale}`; the first row of a locale and its row in a baseline are complete `SNAPSHOT` images (D45 rule 3).
  - `TranslatedModelSaved` is emitted by `HasTranslations` (`created` handler; `updated` handler with `force = true`) and by the `HasTable` action; `Modules\AI\Listeners\HandleModelTranslationListener` is synchronous and queues `TranslateModelJob`.
  - `Operation` (`Modules/Core/app/Approvals/Operation.php`) has `create`, `update`, `delete`, `force_delete`, `restore`; `HasApprovals::approvalOperations()` returns every case by default; `applyModificationChanges()` runs the operations without a diff.
  - The history route (`CrudService::history()` and `authorizeCachedRead()`) checks `select` plus `RelationAuthorizer::maySeePendingChanges()` (`update` or `approve`).
  - `PermissionsRefreshCommand` generates `ActionEnum` verbs per model, dropping a verb the model cannot use (`delete` and `restore` without soft deletes, `lock` without `HasLocks`, `approve` without `HasApprovals`); the seeded `admin` role holds `select` on every table and everything on users and roles, so no seeded role loses a history it reads today.
  - The CRUD update syncs `relations` (`{ "tags": [1, 2] }`) inside `withinRevision()`, on the subset of related records the writer can read (`CrudService::syncTarget()`).

## Review Focus

- **A revert to the current revision.** Nothing changes: the empty `Revert` set is removed (no row written), the report says so, no conflict.
- **A record in the bin.** The route finds it and reverts it, and it stays in the bin (D61); the scalar revert never touches the deleted-at column.
- **Versioning switched off for the table.** A revert would write no history and break the append-only promise: it is refused with a clear message, before any write.
- **A role that read the history through `update`.** After the deploy it needs `history`; no seeded role is affected, a custom role is. The operator note in the docs says so, and `permissions:refresh` reports the new names.
- **An approval that arrives after the record changed.** The approval fails with a conflict and the request stays pending, to be disapproved or asked again; the vote is not recorded.

---

## Part A — One implementation, four modes (D56, D48, D45 rule 2, D57, D25)

### Task A1: `revertToRevision()` in scalar mode, every check under the lock

**Files:**
- Create: `Modules/Core/app/Enums/RevertMode.php`, `Modules/Core/app/Versioning/RevisionReverter.php`, `Modules/Core/app/Versioning/Data/RevertReport.php`, `Modules/Core/app/Versioning/Exceptions/RevisionConflictException.php`, `Modules/Core/app/Versioning/Exceptions/RevertRefusedException.php`
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`currentRevision()` moves here, `revertToRevision()`, the shorthand), `Modules/Core/app/Models/Concerns/HasVersionedRelations.php` (`restoreToRevision()` and `currentRevision()` removed), `Modules/Core/app/Models/Version.php` (`stateAsOf()` callers unchanged)
- Delete: `Data/RestoreReport.php`, `Exceptions/AggregateRestoreConflictException.php` (replaced; `MissingRestoreSubjectException` is renamed `MissingRevertSubjectException` in A2)
- Test: `Modules/Core/tests/Integration/Versioning/RevertToRevisionTest.php` (create); the existing callers in `tests/Integration/Versioning/` move to the new signatures
- Docs: the review log gets D62 (the two decisions of 2026-11-27 above) and its status line

**Interfaces:**
- Produces: `enum RevertMode: string { case Scalar = 'scalar'; case Link = 'link'; case OwnedPart = 'owned_part'; case Complete = 'complete'; }`
- Produces: `final readonly class RevertReport(int $targetRevision, RevertMode $mode, ?int $revision, list<string> $skippedColumns, array<string, list<int|string>> $skippedSubjects, ?Modification $pendingRequest)` with `isPartial(): bool` (a skipped column or subject) and `isPending(): bool`. `$revision` is the `Revert` set written, null when nothing changed or the revert became a request.
- Produces: `RevisionConflictException(int $expected, ?int $current)` and `RevertRefusedException(string $reason)` with reason constants `NOT_VERSIONED`, `NOT_A_REVISION_OF_THIS_RECORD`, `NOT_A_ROOT_ROW`, `NO_COMPLETE_ANCHOR`, `INCOMPLETE_COVERAGE`, `NOT_ATOMIC`, `EPOCH_CHANGED`, `UNSUPPORTED_RELATION`.
- Produces: `HasVersions::currentRevision(): ?int` (unchanged body), `HasVersions::revertToRevision(int $revision, int $expectedRevision, RevertMode $mode = RevertMode::Complete, bool $force = false): RevertReport`, `HasVersions::revertToVersion(int|string $versionId, int $expectedRevision): RevertReport`.
- Produces: `RevisionReverter::revert(Model $root, int $revision, int $expectedRevision, RevertMode $mode, bool $force): RevertReport`, the only implementation, bound as a singleton.

- [x] **Step 1: Failing tests** in `RevertToRevisionTest.php`:
  - a scalar revert to an earlier revision restores the columns, writes one `Revert` set pointing at it, and returns its id in `revision`;
  - a stale expected revision throws `RevisionConflictException` with nothing written, on both entry points;
  - a write committed after the caller read the revision and before the revert makes it fail with a conflict (the check runs under the lock: read the revision, write through another instance, then revert);
  - a revert of a record locked by somebody else throws `LockedModelException` with nothing written, on both entry points (D39, D48);
  - a revert started inside `withinRevision()` of the same root is refused (`VersionSetOptionsMismatchException`);
  - `revertToVersion()` on a translation row or a membership row throws `RevertRefusedException::NOT_A_ROOT_ROW`; on a root row it gives the same state as `revertToRevision($row->version_set_id, $expected, RevertMode::Scalar)` (D56);
  - a revision of another record throws `NOT_A_REVISION_OF_THIS_RECORD`;
  - a revert to the current revision writes no set and returns `revision === null`;
  - with versioning off for the table, a revert throws `NOT_VERSIONED` before any write;
  - a column dropped from the table after the revision is skipped and listed in `skippedColumns`, the others are restored, and no query error escapes (schema drift, spec "Complete revert").
- [x] **Step 2:** Run, expect failures. Not run in the authoring environment (no host app, lint only): run in the host app at the Part A closing.
- [x] **Step 3: Implement.** `RevisionReverter::revert()` opens the `Revert` set through `VersionSetManagerInterface::run()` and, inside it, in this order: the lock check of D39; `currentRevision()` against `$expectedRevision`; the target set belongs to the root (`root_type`, `root_id`, `root_table_ref`); the mode's preflight (A2 to A4); only then the writes. The scalar write rebuilds `stateAsOf()` from the latest own row at or before the target, drops the keys that are not columns of the table any more (listed once per call from the schema builder), and saves with `setForcedApprovalUpdate(true)` when the model uses `HasApprovals` (B2 decides whether to get here). `revertToVersion()` resolves the row, refuses a non-null `relation_path`, and calls `revertToRevision(..., RevertMode::Scalar)`.
- [x] **Step 4:** Run the file, then `tests/Integration/Versioning` per file: the old callers pass an expected revision (`$model->currentRevision()`) and read a `RevertReport`.
- [x] **Step 5:** Record D62 in the review log. Commit (`feat(versioning): one revert implementation, every check under the root's lock (D48, D56)`). Core `f0b7c087`, together with A2: `RevisionReverter` and the removal of `restoreToRevision()` span both. As built: the revision's ownership is checked before the set opens (what a set belongs to never changes); `RevertReport` also carries `restoredRelations`; the `VersionedDocument` stub's strategy is nullable (null defers to the setting) to test `NOT_VERSIONED`; `VersioningSwitchTest` reverts to the baseline in scalar mode, the stub's coverage being partial.

### Task A2: Link mode (D25, D45 rule 2)

**Files:**
- Modify: `Modules/Core/app/Versioning/RevisionReverter.php`, `Modules/Core/app/Models/Concerns/HasVersionedRelations.php` (keeps `replayMembership()` and `applyMembershipTarget()`, made reachable by the reverter through a public `membershipAsOf(string $relation, int $revision): list<array{id: int|string, pivot: array<string, mixed>}>` and `replaceMembership(string $relation, array $target): void`)
- Rename: `Exceptions/MissingRestoreSubjectException.php` to `MissingRevertSubjectException.php`
- Test: `Modules/Core/tests/Integration/Versioning/VersionedRelationRestoreTest.php` (moves to `revertToRevision(..., RevertMode::Link)`), `RevertToRevisionTest.php`

**Interfaces:**
- Consumes: `RevisionReverter::revert()` from A1.
- Produces: `RevisionReverter::completeAnchorAtOrBefore(Model $root, int $revision): ?int`, the latest set of kind `baseline` (creation included) at or before the revision inside its epoch, used by A3 and A4.

- [x] **Step 1: Failing tests:** a link revert restores the membership of every `reference` relation and leaves the columns and the related records untouched; a reference deleted after the caller prepared the revert, deleted inside the lock window, throws `MissingRevertSubjectException` with nothing written (D48); with `$force` it is skipped, listed in `skippedSubjects`, and the report `isPartial()`; a revert to a revision with no complete anchor at or before it throws `NO_COMPLETE_ANCHOR` (D45 rule 2), never an empty membership.
- [x] **Step 2–4:** run and fail, implement the replay and the reference check inside the set, run. An `owned` descriptor is skipped by this mode.
- [x] **Step 5:** Commit: in Core `f0b7c087` with A1. As built: the membership is replayed from the anchor's image (rows from `baseline_from_sequence` in a baseline set), so a link a capture gap removed does not come back; `replaceMembership()` compares with the live pivot rows; the anchor must share the revision's epoch. Not run here: run in the host app.

### Task A3: Owned-part mode, the translations by locale

**Files:**
- Modify: `Modules/Core/app/Versioning/RevisionReverter.php`
- Test: `Modules/Core/tests/Integration/Versioning/RevertTranslationsTest.php` (create)

**Interfaces:**
- Consumes: `completeAnchorAtOrBefore()` from A2; `HasTranslations::translations()`, `setTranslation()`.
- Produces: `RevisionReverter::translationsAsOf(Model $root, int $revision): array<string, array<string, mixed>>`, locale to fields, a locale whose last row up to the revision is `deleted` absent.

- [x] **Step 1: Failing tests:** a translation changed after the revision gets its fields back; one added after it is removed; one deleted after it is recreated; each change is a row of the `Revert` set on the owner (D28) and the owner's `lock_version` rises once; the root's columns are untouched; no complete anchor at or before the revision throws `NO_COMPLETE_ANCHOR`.
- [x] **Step 2–4:** run and fail, implement: per locale, the latest `SNAPSHOT` row at or before the revision and every later row applied forward (the rule of `stateAsOf()`), then a write or a delete through the translation model so its observer writes the row; run.
- [x] **Step 5:** Commit (`feat(versioning): revert the translations by locale`). Core `b39a2d8e`. As built: each locale is written through `setTranslation()` (revives a trashed one, hands the owner over), a locale absent from the target is deleted with `recordOnHistoryOf()`; replayed from the anchor's image like the links. Not run here: run in the host app.

### Task A4: Complete mode and its availability (spec "Complete revert", D57)

**Files:**
- Modify: `Modules/Core/app/Versioning/RevisionReverter.php`
- Test: `Modules/Core/tests/Integration/Versioning/CompleteRevertTest.php` (create)

**Interfaces:**
- Produces: `RevisionReverter::completeRevertBlockers(Model $root, int $revision): list<string>`, the `RevertRefusedException` reasons that refuse a complete revert to that revision, empty when it may run. Read by the complete mode and, later, by the timeline.

- [x] **Step 1: Failing tests:** a complete revert restores columns, translations and links in one `Revert` set; a root with one `partial` descriptor is refused `INCOMPLETE_COVERAGE` and allowed a link revert (D57); a `best_effort` set between the anchor and the revision refuses `NOT_ATOMIC`; a revision written in another epoch than the current one refuses `EPOCH_CHANGED`; an `owned` descriptor refuses `UNSUPPORTED_RELATION`; each refusal writes nothing; a model with no translations and no registered relations gets a complete revert equal to the scalar one.
- [x] **Step 2–4:** run and fail, implement the blockers (coverage of every descriptor; `capture = atomic` for every set of the root from the anchor up to the revision; the revision's `epoch_id` against `VersionEpochs::currentFor()`; an anchor) and the complete mode as scalar, then owned part, then link; run.
- [x] **Step 5:** Commit (`feat(versioning): the complete revert, offered only when coverage, capture and epoch allow it`). Core `0999c11e`. As built: stubs `CoveredRelationRoot` (complete coverage, every column versioned so a column added opens an epoch) and `OwnedRelationRoot`; the success case covers columns and links, no stub has translations and links together (A3 covers the translations); a record with neither reverts completely as its columns with no anchor asked. Not run here: run in the host app.

### Part A closing

- [ ] Regression per suite (Core per folder, CMS, ERP, MES, Shop); `VERSIONING_DEVELOPER.md` of Core: "Restore a model to an earlier version" rewritten for the two entry points, the modes, the report and the refusals; `MODULE.md` of Core, the versioning flow. Documentation done 2026-11-27, Core `docs(versioning): reverting to a revision`: `MODULE.md` *Reverting to a revision*, `VERSIONING_DEVELOPER.md`, `RECORD_LOCKING_DEVELOPER.md`. Regression waits for the host app.

---

## Part B — Side effects (D36, D26)

### Task B1: No automatic translation during a revert (D36)

**Files:**
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (`isRevert(): bool`), `Modules/Core/app/Events/TranslatedModelSaved.php` (`public readonly bool $fromRevert = false`, last constructor argument), `Modules/Core/app/Models/Concerns/HasTranslations.php` (both emissions read `isRevert()` of the current set at emission)
- Modify (AI repository): `app/Listeners/HandleModelTranslationListener.php` (`shouldHandle()` is false when `fromRevert`)
- Test: `Modules/Core/tests/Integration/Versioning/RevertContextTest.php` (create); `Modules/AI/tests/Feature/Listeners/HandleModelTranslationListenerTest.php` (extend)

- [ ] **Step 1: Failing tests (D36):** `fromRevert` is true only inside a set of kind `Revert`; with automatic translation on, a revert of a translated model queues no `TranslateModelJob` (`Bus::fake`) while a normal save does; after a revert, also one that failed, a normal save queues the job again; the `HasTable` action still emits an event with `fromRevert = false`; the revert still dispatches `ModelRequiresIndexing` for a searchable model.
- [ ] **Step 2–4:** run and fail, implement, run Core and AI files.
- [ ] **Step 5:** Commit in Core (`feat(versioning): the revert context marks the translation event (D36)`) and in AI (`feat(ai): no automatic translation for a revert (D36)`).

### Task B2: A revert that needs approval is one request (D26, D62)

**Files:**
- Modify: `Modules/Core/app/Approvals/Operation.php` (`case Revert = 'revert';`, `carriesDiff()` false), `Modules/Core/app/Models/Concerns/HasApprovals.php` (`applyModificationChanges()` runs it), `Modules/Core/app/Versioning/RevisionReverter.php` (asks first), the approvals' Filament labels for the new operation
- Test: `Modules/Core/tests/Integration/Versioning/RevertApprovalTest.php` (create)

**Interfaces:**
- Produces: the request's `modifications` hold `{revision: int, expected_revision: int, mode: string, force: bool}`; its `md5` is `md5('revert|' . class . '|' . key . '|' . revision . '|' . mode)`, so asking twice for the same revert reuses the pending request.

- [ ] **Step 1: Failing tests:** a writer without `approve` on an approval-gated stub gets a `RevertReport` with `isPending()` and one `revert` modification, and nothing reverted; approving it reverts the record with the stored expected revision, as one `Revert` set, with no second modification for the root's save; a record changed before the approval makes the approval throw `RevisionConflictException`, the request stays active and the vote is not stored; a superadmin, a writer holding `approve` with one approver required, and the console revert at once; a model whose `approvalOperations()` leaves out `Revert` reverts at once.
- [ ] **Step 2–4:** run and fail, implement: the reverter checks `shouldCapture(Operation::Revert) && requiresApprovalForOperation(Operation::Revert)` before opening the set, after a cheap check of the expected revision; run.
- [ ] **Step 5:** Commit (`feat(versioning): a revert that needs approval becomes one request (D26)`).

### Part B closing

- [ ] Regression per suite, AI included; `MODULE.md` of Core (the revert and approvals, the revert context); `Modules/AI/docs/rag/MODULE.md` where automatic translation is described.

---

## Part C — Permissions and route (D26, D27)

### Task C1: The `history` and `revert` permissions

**Files:**
- Modify: `Modules/Core/app/Casts/ActionEnum.php` (`case History = 'history'; case Revert = 'revert';`), `Modules/Core/app/Console/PermissionsRefreshCommand.php` (generated when the model is versioned in code: `HasVersions` and not `NeverVersionedModels::includes()`; the runtime setting is not read, as for locking), `Modules/Core/app/Authorization/PermissionEnforcement.php` (both inert while `versioning.strategy.{table}` is off), `Modules/Core/app/Services/Crud/RelationAuthorizer.php` (`mayReadHistory(Request, Model): bool`, true with `history` or `revert`), `Modules/Core/app/Services/Crud/CrudService.php` (`history()` and `authorizeCachedRead()` use it)
- Test: `Modules/Core/tests/Feature/Console/PermissionsRefreshCommandTest.php`, `Modules/Core/tests/Feature/Controllers/CrudHistoryTest.php`, `Modules/Core/tests/Integration/Services/Crud/RelationAuthorizationTest.php`, `Modules/Core/tests/Feature/Crud/CrudResponseCacheTest.php` (the history cases of each move from `update` to `history`)

- [ ] **Step 1: Failing tests:** `permissions:refresh` creates both verbs for a versioned model on both guards and none for a translation model, a comment or `Version`; `select` with `history` reads the history, `select` with `revert` too, `update` alone no longer does (403); without `select` the history is refused whatever else is held; the history of a record outside the caller's ACL answers like a missing record; a cached history response is refused once `history` is revoked.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit (`feat(versioning): the history and revert permissions (D26, D27)`).

### Task C2: `POST /revert/{module}/{entity}`

**Files:**
- Create: `Modules/Core/app/Http/Requests/RevertRequest.php`, `Modules/Core/app/Casts/RevertRequestData.php`
- Modify: `Modules/Core/routes/crud.php` (`Route::post('/revert/{module}/{entity}', 'revert')->name('revert');`), `Modules/Core/app/Http/Controllers/CrudController.php` (`revert()`, and `RevisionConflictException` mapped to 409 next to `StaleModelLockingException`, `RevertRefusedException` and `MissingRevertSubjectException` to 422), `Modules/Core/app/Services/Crud/CrudService.php` (`revert(RevertRequestData): CrudResult`)
- Test: `Modules/Core/tests/Feature/Crud/RevertRouteTest.php` (create)

**Interfaces:**
- Produces: request body `{ id, revision: int, expected_revision: int, mode?: 'scalar'|'link'|'owned_part'|'complete' (default complete), force?: bool }`; answer 200 with the record and `meta.revert` (`revision`, `mode`, `partial`, `skipped_columns`, `skipped_subjects`), 202 with the pending request like a captured update.

- [ ] **Step 1: Failing tests:** `revert` and the record's ACL are required (403 without, a record outside the ACL answers like a missing one); a stale `expected_revision` answers 409; a refused complete revert answers 422 with its reason; a missing reference answers 422, and with `force` 200 and `partial: true`; a writer needing approval gets 202 and a pending request; a record in the bin is found and stays there; the response cache of the entity is flushed by the revert.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit (`feat(crud): the revert route`).

### Part C closing

- [ ] Regression per suite; `Modules/Core/docs/CRUD_SYSTEM.md` (the revert route, the history permission), `Modules/Core/docs/rag/API_ACCESS_CONTROL.md` (the two verbs, `revert` implies reading the history, the operator note for roles that read the history through `update`), `VERSIONING_DEVELOPER.md` ("Read the history over HTTP").

---

## Part D — The membership a form loaded (D40)

### Task D1: Compare and set per relation in the CRUD update

**Files:**
- Create: `Modules/Core/app/Versioning/Exceptions/MembershipConflictException.php`
- Modify: `Modules/Core/app/Http/Requests/ModifyRequest.php` (`expected_relations`, same shape as `relations`), the update request data, `Modules/Core/app/Services/Crud/CrudService.php` (`syncModelRelations()` compares inside `withinRevision()`), `CrudController` (409)
- Test: `Modules/Core/tests/Feature/Crud/RelationSyncConflictTest.php` (create)

**Interfaces:**
- Produces: `MembershipConflictException(string $relation, list<int> $expected, list<int> $current)`; the comparison is on the related ids the writer can read, the set `syncTarget()` already computes, order ignored.

- [ ] **Step 1: Failing tests (D40):** an update whose `expected_relations.tags` equals the current readable tags syncs; one that differs answers 409 and writes nothing, the record's columns included; a tag added by somebody else does not block an update that sends `expected_relations` only for `categories`; a relation in `relations` without an entry in `expected_relations` syncs as today; an attached tag the writer cannot read does not count in the comparison.
- [ ] **Step 2–4:** run and fail, implement, run.
- [ ] **Step 5:** Commit (`feat(crud): refuse a relation sync when the membership the form loaded changed (D40)`).

### Part D closing and slice closing

- [ ] **Documentation:** `Modules/Core/docs/CRUD_SYSTEM.md`, "Relation sync on update" (`expected_relations` and the 409); the spec and review status lines; the parent plan (tick Slice 6). The Vue form sending `lock_version` and `expected_relations` stays UI work (D40), named in the delivery status.
- [ ] **Regression** per suite, recorded in the delivery status.
- [ ] **Commit** in Core, AI and `laraplate/`; PRs.

**Documented in (when the plan closes):** `Modules/Core/docs/rag/MODULE.md`, `Modules/Core/docs/rag/VERSIONING_DEVELOPER.md`, `Modules/Core/docs/CRUD_SYSTEM.md`.
