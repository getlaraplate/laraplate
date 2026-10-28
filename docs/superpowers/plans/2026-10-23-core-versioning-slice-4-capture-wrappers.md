# Core Versioning Rebuild, Slice 4 — Capture Wrappers

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** every write that passes through Core reaches the history inside the scope of its root, opened before its SQL: a save of a record with its translations, a translation written alone, a membership change from either side of a relation, a delete. A hard delete is owned by one coordinator that writes the snapshot set and removes the edit history in the same transaction.

**Parent plan:** `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`, Slice 4. **Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md`. **Decisions:** D10, D11, D23, D24, D28, D29, D34 (the wrapper), D41, D42, D44, D45 rule 3, D49, D52 (the wrappers' part), D54, D55, D57 (the descriptor field) in `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md`.

**Architecture:** three parts, each closed (tests, regression, commit) before the next, with a review pause between them, because each changes the write path of many models.

- **Part A — Save scope and translations.** `HasVersions` gets a `save()` wrapper that opens `run()` for a versioned root with translations or registered relations when there is something to write. Translation models record their changes on the owner: one row per locale (`relation_path = translations`, `subject_key = {locale}`) in the owner's set, and a translation saved or deleted alone opens the owner's scope first. A translation-only revision raises the owner's `lock_version` once. `withinRevision()` groups writes; `TranslateModelJob` runs as one revision of its own.
- **Part B — Relations.** The pivot models record membership rows from their own events; `Core\Overrides\Model` returns Core subclasses of `BelongsToMany` and `MorphToMany` whose mutators open the scope before their SQL, on both sides. `HasVersionedRelations` loses its explicit mutators. `CrudService::update()` wraps the record and its relations in one `run()`. The descriptor gains `coverage`; a symmetric relation is refused.
- **Part C — Hard delete.** `delete()` and `forceDelete()` of every versioned model run inside `run()`; the `HardDeleteCoordinator` reads the aggregate, lets the delete run, then writes the snapshot set (D55) and removes the root's edit history, with the delete's own writes kept out of the set (D54). `model:clear-expired` goes through the coordinator in chunks.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. Modules: `Core` (all parts), `CMS` (the translation models in Part A), `AI` (`TranslateModelJob` in Part A).

## Global Constraints

- As in the parent plan: `declare(strict_types=1);`, explicit types, PHPDoc over inline comments, `#[Override]` only on real overrides, tests first, Pint from the root on an explicit file list, commits inside each module repository, `/usr/bin/git`, no `Co-Authored-By` trailer. Run the Core Integration folder per subfolder.
- Facts this plan relies on (read on 2026-10-23):
  - `Core\Overrides\Model` uses `HasVersions` and `Core\SoftDeletes\SoftDeletes` and overrides nothing.
  - Nine versioned models do not extend it: `User`, `Role`, `Media` (with the Core bin), `Permission`, `Fieldable`, `License`, ERP `FiscalPeriod`, MES `BomLine` and `RoutingOperation`.
  - Translations are written by `HasTranslations::writeTranslation()` (`create()` or `fill()->save()`), on the owner's `saved` event or at once by `setTranslation()`; no production code deletes a single translation.
  - The Filament content save runs with no transaction (panel transactions are off).
  - `CrudService::update()` already opens a transaction per request; `insert()` does not.
  - `model:clear-expired` deletes with one builder query, no events.
  - No production model uses `HasVersionedRelations`.
- The CMS relations are registered on `Content` in Slice 10 (the pilot): Part B is tested in Core with stubs. The CMS translation models adopt Part A now, so a content's history starts to carry its translations.
- Measure before adopting the wrapper (D28: "performance and regressions of wrapping `save()`"): the number of queries of a content save before and after Part A, recorded in this plan.

---

## Part A — Save scope and translations

### Task A1: Translation changes are rows of the owner (D10, D11, D28, D45 rule 3)

**Files:**
- Create: `Modules/Core/app/Models/Concerns/RecordsChangesOnOwner.php` (trait for the models that are parts of a versioned root: the translations now, the pivots in Part B)
- Modify: the five translation models (`Modules/CMS/app/Models/Translations/{Content,Comment,Contributor,Tag}Translation.php`, `Modules/Core/app/Models/Translations/TaxonomyTranslation.php`)
- Test: `Modules/Core/tests/Integration/Versioning/TranslationHistoryTest.php` (create; a Core stub owner with a stub translation model under `Modules/Core/tests/Stubs/Versioning/`), `Modules/CMS/tests/Feature/Models/ContentTranslationHistoryTest.php` (create)

- [x] **Step 1: Write the failing tests:**
  - creating a translation writes on the owner a `created` row with `relation_path = translations`, `subject_key = {locale}`, strategy `SNAPSHOT`, contents read back from the database;
  - updating it writes an `updated` row whose contents are the changed fields for a `DIFF` owner and the full image for a `SNAPSHOT` owner (D11);
  - deleting it writes a `deleted` row with the image in `original_contents`;
  - an owner with versioning off writes nothing;
  - the translation model itself writes no row of its own (D10, already true since Slice 2).
- [x] **Step 2: Run them and see them fail.**
- [x] **Step 3: The trait.** It captures on `creating`/`updating`/`deleting` what the row needs (the original image, the dirty keys) and writes on `created`/`updated`/`deleted` through `VersionWriterInterface`, with `model` = the owner (found through `IsPartOfParent::parentRelation()`), `relationPath` and `subjectKey` set. The owner is versioned when `getVersionStrategy()` is not `false`. A translation's `created` row is always a `SNAPSHOT` read back (D45 rule 3).
- [x] **Step 4: Use it** on the five translation models, next to `TouchesOwnerOnChange`.
- [x] **Step 5: Run** the new tests, `Modules/Core/tests/Integration/Versioning`, the CMS model tests. Expected: PASS.
- [x] **Step 6: Commit** in Core and CMS.

**Found at execution (2026-10-23):** the owner a part records on is the instance that writes it: `HasTranslations::writeTranslation()` hands itself over (`recordOnHistoryOf()`), so the rows reach the record being saved, with its concrete class; `TaxonomyTranslation`, whose own owner class is abstract, records on the category or location that writes it and records nothing when saved on its own. A part saved alone resolves its owner through `IsPartOfParent`, without global scopes (the locale scope hides an owner with no translation yet) and without eager loads, and only after checking on the owner's class that its history is on: two CMS tests count the queries of a translation save. The CMS factories create random locales, so the CMS test reads an existing one. Until Task A2 each locale row is its own `best_effort` set.

### Task A2: The save of a root opens its scope (D28, D34)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`save()` wrapper, `opensRevisionScopeOnSave()`)
- Test: `Modules/Core/tests/Integration/Versioning/SaveScopeTest.php` (create), `Modules/CMS/tests/Feature/Models/ContentTranslationHistoryTest.php`

- [x] **Step 1: Write the failing tests:**
  - a save of a root with pending translations writes the root's row and the locale rows in one set (one revision, D28);
  - the creation of a content with its translations gives one `baseline` set holding the root and every locale (the creation anchor is complete);
  - a save with nothing to write opens no scope and writes no set;
  - a save of a versioned model with neither translations nor registered relations opens no scope (D52: no extra transaction);
  - an exception thrown in a `saved` handler rolls back the version rows too;
  - a stale save fails inside the scope as outside it (D34, Slice 3);
  - a nested save of another root inside the scope gets its own set (D43).
- [x] **Step 2: Measure** the queries of a content save (`DB::listen` count in a throwaway test) before the change; record the number here. **18 queries** (2026-10-23) for a title change of a saved content, versioning on for `cms_contents`.
- [x] **Step 3: The wrapper.** `HasVersions::save(array $options = []): bool` calls `parent::save($options)` unless the model is a versioned root that owns translations (`HasTranslations`) or registers relations (`versionedRelations()` not empty) and has something to write (dirty attributes, pending translations, or not yet saved); then it runs `parent::save()` inside `VersionSetManagerInterface::run(VersionSetRoot::forModel($this), …, new VersionSetOptions(actor: $this->getVersionUserId()))`. Models that override `save()` (`ExtendsContentTrait`, `Contributor`) keep calling `parent::save()`, which now reaches the wrapper.
- [x] **Step 4: Measure again** and record the difference (one transaction and one `SELECT … FOR UPDATE` per affected save are expected, D34). **18 queries**, unchanged: before the wrapper the writer's fallback already opened a scope for the translation row and locked the content; now the wrapper locks it once and the translation joins.
- [x] **Step 5: Run** Core per folder and CMS. Expected: PASS.
- [x] **Step 6: Commit.**

**Found at execution (2026-10-23):** inside an open scope (a revert, an explicit revision) the wrapper joins with no options of its own, which `runNested()` would refuse as a different operation; `HasVersionedRelations` gained `hasRegisteredRelations()`. `withinRevision()` (Task A5) was added with the wrapper, its tests come in A5.

### Task A3: A translation written alone opens its owner's scope (D52)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/RecordsChangesOnOwner.php` (or `HasVersions` for `ITranslated`): `save()` and `delete()` of a part open `run()` for its owner, when the owner is versioned and no scope of it is active
- Test: `Modules/Core/tests/Integration/Versioning/TranslationHistoryTest.php`, `VersionCaptureTest.php`

- [x] **Step 1: Write the failing tests:** `setTranslation()` on a saved owner, with no scope open, gives one `atomic` set with the locale row; inside an owner's `run()` it joins the owner's set; the owner's row is locked when the scope opens.
- [x] **Step 2: Run them and see them fail** (today the fallback writes a `best_effort` set).
- [x] **Step 3: Open the owner's scope** before the part's SQL. Mind the trait conflict: the translation models extend `Core\Overrides\Model`, whose `save()` is now the wrapper of Task A2; resolve it in one place, the wrapper asks the model for the root to scope (itself, or its owner for a part).
- [x] **Step 4: Run** the versioning tests and CMS. **Step 5: Commit.**

**Found at execution (2026-10-23):** the part's `save()` and `delete()` live in `RecordsChangesOnOwner` and open the owner's scope only when no scope is open at all: inside a scope, opened by the owner or by another root, the row joins through `setFor()`, so a part never opens a second `run()` for an owner instance other than the one already scoped.

### Task A4: A translation-only revision raises the owner's `lock_version` once (D23)

**Files:**
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (whether the root's own row was written), `VersionSetManager.php` (before finishing, raise the lock version of a root whose set has part rows and no own row)
- Test: `Modules/Core/tests/Integration/Versioning/TranslationHistoryTest.php`

- [x] **Step 1: Write the failing tests:** a translation-only change raises the owner's `lock_version` by one, and a form holding the old version is refused (`StaleModelLockingException`); a save of root and translations raises it once, not twice; relation-only changes (Part B) do not raise it.
- [x] **Step 2–5:** run and fail, implement (a direct `UPDATE` of the lock column, as `HasLocks::writeLockColumns` does, and the instance updated), run, commit.

**Found at execution (2026-10-23):** each set notes which streams it wrote (`noteStream()`); before finishing, the manager raises the lock version of a root whose set wrote translation rows and no row of its own. The relation-only case is tested in Part B, when membership rows exist.

### Task A5: `withinRevision()` and the translation job (D28)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`withinRevision(Closure $operation, ?string $reason = null): mixed`)
- Modify: `Modules/AI/app/Jobs/TranslateModelJob.php` (the locale loop inside `withinRevision(..., reason: 'ai-translation')`, actor none)
- Test: `TranslationHistoryTest.php`, `Modules/AI/tests` for the job

- [x] **Step 1: Write the failing tests:** `withinRevision()` groups a loop of `setTranslation()` over three locales in one set; the job writes its locales in one `atomic` set with reason `ai-translation` and no actor; a job that fails on one locale still commits the others as before (check the job's current per-locale error handling and keep it).
- [x] **Step 2–5:** run and fail, implement, run Core versioning, CMS and AI, commit in Core and AI.

**Found at execution (2026-10-23):** `withinRevision()` takes the set's options (`?VersionSetOptions`), so the job names its reason and no actor; the job keeps its per-locale error handling inside the revision, so a failed locale does not undo the others. The job's docblock named `ITranslatableModel` without importing it, which PHPStan reported once the job called the model; the import was added.

### Part A closing

- [x] Regression per suite; `MODULE.md` (Core: the save scope, translation rows, `withinRevision()`); record the query counts; **pause for review** before Part B. Done 2026-10-23: every suite green but `tests/Feature/Filament/ResourceFormPagesCloseOrCancelTest` (the CRM pages in progress); a content save keeps 18 queries; docs in Core, CMS and AI. Commits: Core `8924170d`, `734cf4f2`, `7c059412`, `171f0a05`, `f1eda203`, `b117ff0b`; CMS `6f12977`, `5f4b8f2`; AI `fb303f4`, `58badb9`.

---

## Part B — Relations

### Task B1: The descriptor gains coverage; a symmetric relation is refused (D24, D57)

**Files:**
- Modify: `Modules/Core/app/Versioning/Data/RelationDescriptor.php` (`coverage`, `RelationCoverage` enum `Complete`/`Partial`, default `Partial`)
- Modify: `Modules/Core/app/Models/Concerns/HasVersionedRelations.php` (registration check)
- Test: `Modules/Core/tests/Unit/Versioning/RelationDescriptorTest.php`, `VersionedRelationCaptureTest.php`

- [x] **Step 1: Write the failing tests:** a descriptor defaults to `partial` and can be declared `complete`; registering a relation whose related model is the root's own class (a symmetric relation like `Content::related()`) is refused.
- [x] **Step 2–5:** run and fail, implement, run, commit. The fingerprint (Slice 5) and the revert gate (Slice 6) read `coverage` later.

### Task B2: Membership rows from the pivot events (D41, D42)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/RecordsChangesOnOwner.php` (the pivot case: `created` and `deleted` give membership rows)
- Modify: the test stubs of `Modules/Core/tests/Stubs/Versioning/Relations/` (a custom pivot class with the trait)
- Test: `VersionedRelationCaptureTest.php`

- [x] **Step 1: Write the failing tests:** attaching through a custom pivot of a registered relation writes a `created` membership row on the root (`relation_path` = the relation, `subject_key = {id}`, empty contents unless a column is declared authoritative, D41); detaching, also without ids, writes `deleted` rows; a relation not registered writes nothing; versioning off writes nothing; a raw query on the pivot table writes nothing.
- [x] **Step 2–5:** run and fail; implement: the pivot finds its root from its keys and the relation whose pivot class it is among the root's descriptors; run; commit.

**Found at execution (2026-10-26):** the pivot capture is its own trait, `RecordsMembershipOnRoot`, not `RecordsChangesOnOwner`: a pivot's `delete()` returns an int and the translation trait's wrapper would not fit it. The root is the pivot's parent when the link is made from the root, otherwise its related record, named by the pivot's key without loading it; the relation is the registered one stored in the pivot's table with the root on that pivot column (`versionedRelationThroughPivot()`). The descriptor gained `pivotColumns`, the authoritative pivot columns of D41, which the stubs declare for `position`. The explicit methods of `HasVersionedRelations` no longer write rows themselves, the pivot does; they are removed in B3. PHPStan does not analyse the trait until an application class uses it (the CMS pivots, Slice 10).

### Task B3: Relation subclasses open the scope on both sides (D42, D52)

**Files:**
- Create: `Modules/Core/app/Overrides/Relations/BelongsToMany.php`, `MorphToMany.php` (mutators `attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle`, `updateExistingPivot` wrapped)
- Modify: `Modules/Core/app/Overrides/Model.php` (`newBelongsToMany()`, `newMorphToMany()`); the versioned models outside it get the same through a small trait if they have relations to register (check; none does today)
- Modify: `Modules/Core/app/Models/Concerns/HasVersionedRelations.php` (remove `attachVersioned`, `detachVersioned`, `syncVersioned`; the revert's `applyMembershipTarget()` relies on the pivot events instead of writing rows itself)
- Test: `VersionedRelationSyncTest.php`, `VersionedRelationCaptureTest.php`, `VersionedRelationRestoreTest.php`, `VersionedRelationMembershipReadTest.php` (moved off the removed methods)

- [x] **Step 1: Write the failing tests:** a `sync()` of three subjects from the root gives one `atomic` set of three rows; a `sync()` from the inverse side over two roots gives two sets, one per root, both `atomic`; `detach()` without ids writes the rows; the relation's mutators on a model that is not a versioned root open nothing.
- [x] **Step 2: Move the existing tests** off `attachVersioned`, `detachVersioned` and `syncVersioned` to plain `attach`, `detach` and `sync` (the methods are removed by D42; their tests keep their intent).
- [x] **Step 3–5:** implement (the root side runs `run()` for the parent; the inverse side runs `run()` for the parent too, whose own set stays empty and is removed, while each pivot joins its root's set through `setFor()`), run, commit.

**Found at execution (2026-10-26):** the factories live in a trait, `ReturnsVersionedRelations`, used by `Core\Overrides\Model` and by the test stubs; the wrapping lives in `OpensRevisionOnMutation`, shared by the two subclasses. `syncWithoutDetaching()` is not wrapped on its own: it calls `sync()`, which is. A model outside `Core\Overrides\Model` that takes part in a versioned relation adds the trait (none does today). PHPStan reports on `Modules/Core/app/Overrides` are identical before and after.

### Task B4: A CRUD update of a record and its relations is one revision (D42)

**Files:**
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`update()`: per record, `$found_record->update()` and `syncModelRelations()` inside one `run()` for the record)
- Test: `Modules/Core/tests/Integration/Services/CrudServiceTest.php` or a new versioning test with a stub root

- [ ] **Step 1–5:** a CRUD update with fields and relations gives one set; run and fail; implement; run the CRUD tests; commit. (The compare and set of D40 is Slice 6.)

### Part B closing

- [ ] Regression per suite; `MODULE.md` (membership capture, relation subclasses, coverage); **pause for review** before Part C.

---

## Part C — Hard delete

### Task C1: The delete wrapper (D44)

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`delete()` and `forceDelete()` wrappers)
- Modify: `Modules/Core/app/Overrides/Model.php`, `User.php`, `Role.php`, `Media.php` (the `insteadof` resolution with `Core\SoftDeletes\SoftDeletes`, keeping the bin's methods under an alias the wrapper calls)
- Test: `Modules/Core/tests/Integration/Versioning/DeleteScopeTest.php` (create)

- [ ] **Step 1: Write the failing tests:** a soft delete through the wrapper writes its `trashed` row in one `atomic` set; a hard delete refused by the lock (D39) writes nothing and removes nothing; a hard delete of a `User` (not a `Core\Overrides\Model`) and of a model without a bin run inside the scope; an exception after the DELETE rolls everything back.
- [ ] **Step 2–5:** run and fail, implement, run Core per folder, CMS, ERP, MES, commit.

### Task C2: The coordinator writes the snapshot set and removes the history (D29, D44, D54, D55)

**Files:**
- Create: `Modules/Core/app/Versioning/HardDeleteCoordinator.php`
- Modify: `ActiveVersionSet` (the "being hard-deleted" flag of D54), `HasVersions` (the fallback hooks and the part capture skip a root being hard-deleted)
- Test: `Modules/Core/tests/Integration/Versioning/HardDeleteSnapshotTest.php` (create), `Modules/CMS/tests/Feature/Models/ContentHardDeleteChildrenTest.php`

- [ ] **Step 1: Write the failing tests** (D55 row format): the snapshot set of a record with two locales and three registered memberships holds exactly the root row (`SNAPSHOT`, `deleted`, image in `original_contents`, no `relation_path`), one row per locale, one per membership, and one `origin` row when a `RecordOrigin` exists; the delete's own detaches and the fallback `Deleted` row are not in it (D54); the root's other sets are removed and a `Revert` set pointing at one of them keeps a null link (D58); the extender deleted by `ContentExtensionObserver` gets its own snapshot set (D43); the children of D49 are still removed.
- [ ] **Step 2–5:** run and fail, implement (read the aggregate before the delete, delete, then write the rows and remove the history, all inside the wrapper's scope), run, commit.

### Task C3: `model:clear-expired` through the coordinator (D29)

**Files:**
- Modify: `Modules/Core/app/Console/ClearExpiredModels.php` (`chunkById` and the coordinator's `purge()`, reason `expired`)
- Test: the command's tests

- [ ] **Step 1–5:** an expired trashed record purged by the command leaves its snapshot set with reason `expired` and no orphan origin or embedding; run and fail; implement; run; commit.

### Part C closing and slice closing

- [ ] **Documentation:** `MODULE.md` (Core: the delete wrapper, the coordinator, the snapshot set format, `model:clear-expired`; CMS: the content history now carries its translations); the review log and spec status lines; the parent plan (tick Slice 4); `docs/superpowers/plans/INDEX.md`.
- [ ] **Regression** per suite, recorded in the delivery status.
- [ ] **Commit** in each module and in `laraplate/`.

**Documented in (when the plan closes):** `Modules/Core/docs/rag/MODULE.md`, `Modules/CMS/docs/rag/MODULE.md`.
