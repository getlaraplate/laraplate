# Restoring a Record With Its Relations: Open Review Findings

## Status

- Date: 2026-08-04
- Target: `2026-07-29-revision-centric-aggregate-history-design.md`
- Target revision reviewed: `30b75b0` (first review, 2026-08-04); the spec as aligned with D1–D51 (independent recheck, 2026-10-19); the spec and this log as of 2026-10-20 (second recheck)
- Status: every finding resolved by D1–D61. The independent recheck of 2026-10-19 found eight design gaps, answered by D42–D49, plus D50 and D51; the second recheck of 2026-10-20 found one critical and seven important gaps, answered by D52–D59 and approved by the user; the check of the plan against them added D60. An implementation plan may be written: `docs/superpowers/plans/2026-10-20-core-versioning-rebuild.md`. Implemented so far: D14, D15, D19, D20 (Slice 1 of the plan, 2026-10-20), C08, C09, D8, D10, D21, D53 for the creation set and D61 (Slice 2, 2026-10-22), the lock part of D34, D43, the stored capture of D47 and D52, D60 (Slice 3, 2026-10-22), D23, D24, D28, D29, the wrappers of D34 and D52, D41, D42, D44, D54, D55 and the coverage field of D57 (Slice 4, 2026-10-29) and the schema of D52, D53 and D58 (the `capture` column, the `baseline` kind, `SET NULL`), D30, D37, D38, D39, D49, D50 and D51; D2 and D16 match code shipped before this design (commits `19e86c1`, `c05cf32`)
- Scope: generic Core architecture, not the CMS pilot

This log is the durable hand-off between reviewers. It records findings separately from design decisions so another agent can challenge the proposal without losing earlier reasoning.

Reviewers must not silently close a finding. A finding closes only when the design spec contains an unambiguous resolution, the evidence named here has been rechecked, and the resolution is recorded in this log.

## Discussion agenda (agreed 2026-10-09)

This ledger stays open and is to be re-examined with the user, not closed by a reviewer alone. The findings are discussed grouped by the decision they depend on, in this order, rather than one by one:

1. **The problem in plain words.** What a user must be able to do (for example "bring this content back to how it was yesterday, with its tags and categories") and what does not work today.
2. **The three underlying decisions.** Most findings descend from these:
   1. Is the restore coordinate the *observed state* (aggregate revision) or the *executed operation* (version set)?
   2. Must capture be atomic, or best-effort with an honest quality label?
   3. What happens to writes that do not go through Core: raw SQL, database cascades, jobs?
3. **The findings, grouped by decision.** For example C01 and C07 depend on decision 2, C03 and C06 on decision 1. Each group is settled before moving to the next.

After the discussion this log is rewritten in plain language, with every finding explained by an example, and the design spec is updated with whatever was decided.

## Severity and states

- `Critical`: the design can publish a false restore guarantee, corrupt revision ordering, or mutate the wrong aggregate.
- `Important`: the design remains unsafe, incomplete, or operationally ambiguous but does not immediately invalidate every restore coordinate.
- States: `open`, `proposed`, `resolved`, `rejected`.

## Finding summary

| ID | Severity | State | Finding |
|---|---|---|---|
| C01 | Critical | resolved (D6; spec aligned) | Best-effort capture cannot satisfy the same-transaction invariant |
| C02 | Critical | resolved (D23; spec aligned) | The concurrency section contradicts the separation of lock and aggregate revisions |
| C03 | Critical | resolved (D16, D17, D29, D44; spec aligned) | A root-local revision counter disappears on hard delete |
| C04 | Critical | resolved (D16, D18, D28, D42, D43; spec aligned) | The revision allocation boundary and canonical checkpoint are undefined |
| C05 | Critical | resolved (D13, D22; spec aligned) | Disabling versioning or changing descriptors can leave a falsely complete history |
| C06 | Critical | resolved (D24; spec aligned) | Multi-root relations do not fit the one-root revision model |
| C07 | Critical | resolved (D6, D17; spec aligned) | The force-delete design assumes central mutation paths that do not exist |
| C08 | Critical | resolved (D14, D18; spec aligned) | A `DIFF` restore does not reconstruct the state as of a revision |
| C09 | Critical | resolved (D18, D22; spec aligned) | The creation snapshot is not a complete image of the record |
| I01 | Important | deferred (D25) | Stable version, subject, root, and history-store identities are missing |
| I02 | Important | resolved (D2, D25; spec aligned) | Skipping a missing subject contradicts complete restore |
| I03 | Important | resolved (m1) | JSON-only relation vectors cannot support integrity and retention safely |
| I04 | Important | resolved (C08, C09, D18; spec aligned) | Aggregate checkpoints need a scalar snapshot independent of DIFF audit rows |
| I05 | Important | resolved (D26, D27; spec aligned) | Authorization, approvals, side-effect control, and history visibility are absent |
| I06 | Important | resolved (D13, D22, this log; spec aligned) | Review evidence and historical coverage epochs are not represented durably |

### Independent recheck (2026-10-19)

| Finding of the recheck | Severity | Answered by |
|---|---|---|
| 1. No design captures membership changes | Critical | D42 |
| 2. Baselines cover only the root | Critical | D45 |
| 3. The hard-delete coordinator cannot be atomic from a hook | Critical | D44 |
| 4. The join rule puts another root's rows into the active set | Critical | D43 |
| 5. Compaction leaves revisions that rebuild states that never existed | Important | D46 |
| 6. Capture quality has no storage and no rule | Important | D47 |
| 7. The relation revert lacks the lock and revision checks under the lock | Important | D48 |
| 8. The orphan check of D21 is still open | Important | D49 |
| Minor: `core_versions` readable through the generic CRUD | Minor | D50 |
| Minor: the same-second skip of `OwnerTimestamp` can miss a change | Minor | D51 |
| Minor: recovery of an older epoch or a reused key; the `delete()` trait collision; nested revert | Minor | D44, D48 |
| Stale text in the spec and in this log | Minor | aligned on 2026-10-19 |

## Milestone 1 dispositions (2026-08-05)

*Historical. Superseded where later decisions differ: C03 by D17 and D29 (a hard-deleted root is recoverable from its snapshot); C01, C05 and C07 by D6, D13, D22 and D44 (atomic where Core writes, labelled `best_effort` elsewhere); the `uuid` on reference subjects by D25 (local ids); `subject_version_id` by D10 (not needed for translations).*

The user confirmed a CMS-grade milestone-1 bundle (see the design spec, "Milestone 1 — confirmed scope"). Findings are resolved for the milestone by narrowing scope, which this log's closure gate explicitly permits.

- **C03 → resolved (scope).** The revision coordinate is the version set; no durable head. Hard-deleted roots are non-restorable; the SNAPSHOT tombstone is audit-only.
- **C04 → resolved (scope).** One supported operation = one version set = one revision. Membership is normalized version rows keyed by `relation_path` + `subject_key`.
- **I03 → resolved.** Normalized relation items via existing `relation_path`/`subject_key` + a new nullable `subject_version_id` self-FK (owned only). No JSON blob.
- **I01 → proposed (partial).** Local ids for the single-connection milestone; a stable `uuid` is added to shared reference subjects; version-row uuid and cross-connection identity are deferred.
- **C06 → out of scope (m1).** Single-authoritative-root relations only.
- **C01, C05, C07** remain deferred under the CMS-grade posture: honest best-effort/degradation and non-continuable hard delete instead of fail-closed atomic guarantees. **C02, I02, I04, I05, I06** are unchanged by this bundle.

## Decisions taken in the discussion (2026-10-09)

Recorded as agreed with the user; the design spec is to be aligned with them, and no finding is closed by this section alone.

1. **Customer expectation.** Restore follows the Drupal / enterprise-CMS model: the document and its owned parts come back, shared reference data does not. What must be avoided above all is a restore control that looks complete and is not.
2. **Shared references (tags, categories, contributors, locations, related contents).** Restore the *membership* only (which records were linked, and the pivot state), never the values of the linked records. A linked record that no longer exists is skipped and reported (matches the existing `restoreToRevision()` behaviour with `$force`).
3. **Owned children are part of milestone 1.** They are restored with the root, through a pointer: the revision stores, per owned child, the child's own version id (`subject_version_id`), and restore rolls each child back to that version instead of keeping a full copy. This reverses the earlier "owned relations are out of scope for this milestone". **Superseded for translations by D10** (no pointer is needed for them); the pointer remains the mechanism only for an owned child with an independent history, none of which is known today.
4. **Consequences to carry into the design (Claude's proposal, not discussed; superseded in part by D10: translations have no history of their own).** Translations (`ContentTranslation`) are the first owned children, since the text lives there; each owned child needs its own history; a child version must be reconstructable on its own (full snapshot or a checkpoint per aggregate revision, finding I04 therefore enters milestone 1); versions referenced by a revision must not be pruned; a child added after the target revision is removed on restore and one deleted after it is recreated.
5. **Not restored although technically children (consistent with D8, where the user decided that comments and ratings are never versioned):** user contributions such as comments and ratings.

6. **Capture quality (decision 2 of the agenda): option C.** Writes that go through Core's supported mutation paths are captured atomically, inside an aggregate revision scope that opens a local transaction when the caller has none. Every revision carries a quality label, `atomic` or `best-effort`, and "restore everything" is offered only on `atomic`. Writes that bypass Core (query-builder deletes, foreign-key cascades, raw SQL, `ClearExpiredModels`, lock-column updates, `updateOrInsert`) are `best-effort` by definition.
7. **Versioning of owned children follows the root.** If a root is versioned, its owned children (translations first) are versioned automatically; a child cannot be versioned without its root, nor the root without its owned children. There is no independent setting for the child. **Superseded by D10** for translations: they are not versioned at all.
8. **Comments and ratings are never versioned.** They are third-party contributions, not part of the document. Their models must force versioning off, so the per-table setting cannot turn it on. Today the seeder creates `versioning.strategy.*` with `DIFF` for every model that extends `Core\Overrides\Model` unless the model declares `$versionStrategy`, which is why `cms_contents_ratings` is versioned by accident.

9. **Translation models have no versioning setting of their own.** They override `getVersionStrategy()` and look up the setting key of their parent entity (for `ContentTranslation`, the key of `cms_contents`), so the child can never disagree with its root. This is the mechanism behind decision 7. The seeder must stop emitting `versioning.strategy.*` for these tables, otherwise a dead setting stays visible in the settings UI. `parentRelation()` (already declared on the translation models through `IsPartOfParent`) is the natural way to find the parent; whether the same override is shared with other owned children is to be decided in the design. **Superseded by D10:** translation models need no `getVersionStrategy()` override because they are not versioned. *Amended by D28: they extend `Core\Overrides\Model`, which uses `HasVersions`, so they do override `getVersionStrategy()`, returning `false`.*

10. **Translations have no history of their own (user decision, 2026-10-09).** A change to a translation produces a version of the *owner* (for `ContentTranslation`, the `Content`), and that version carries the state of the owner's translations. The owner is the only thing that can be rolled back. Translation models do not use `HasVersions`; like comments and ratings their versioning is forced off. *Amended by D28: they do use it through `Core\Overrides\Model`; `getVersionStrategy()` returns `false` for them.* Restore recreates or removes translation rows by locale (the natural key), never reusing a historical id. **Mandatory, not optional (user, 2026-10-09; designed in D28):** the design must define (a) the hook that creates the owner's version when only a translation changes, and (b) how many writes of one logical operation (for example a machine translation of several locales) are grouped into one revision. Neither may be left to a later phase: without them D10 is not complete.
11. **Weight follows the owner's strategy (user decision).** If the owner uses `DIFF`, the translation state in its version is stored as a difference; if it uses `SNAPSHOT`, in full. There is no separate strategy for translations.
12. **Existing history is discarded (user decision).** The product is pre-stable and will change a lot: no data migration of existing version rows, including the `cms_contents_translations` ones; the database is rebuilt with `migrate:fresh`.

13. **Schema changes: schema epoch, lazy baseline, history never deleted (user decision, 2026-10-09).** Each version row carries the schema epoch of its table; a new epoch opens when the table's versionable columns change, detected at the end of a migration (not computed on every save). The first write of a record after an epoch change is stored as a full `SNAPSHOT`, so no `DIFF` chain crosses two epochs and no record is rewritten during the migration. History is never discarded because of a schema change. A restore towards an older epoch classifies each column: apply those still compatible, skip and report those that no longer exist, refuse when recreating a row would miss a required value; any such discrepancy labels the revision `schema-mismatch` and withholds "restore everything". A migration that drops, renames or retypes a versioned column must declare it, enforced by an architecture test; a change of shape inside a JSON column stays a process rule. Related to finding I06 (coverage epochs). *Amended by D33: the epoch is detected at the first write per process and per class, not only at the end of a migration; by D45: the lazy baseline is a complete image of the whole aggregate.*

14. **Laraplate detaches from `overtrue/laravel-versionable` (user decision, 2026-10-09).** Too much of what is being decided changes the library's behaviour and format (point-in-time restore, schema epochs, membership versions, owner-held translation state), so Core absorbs the small part it still uses and drops the dependency, following the precedent of the approvals subsystem (MIT attribution, dependency removed). No fork. An upstream contribution becomes optional and separate, for the generic mechanisms once the design is stable. Surface used today: the `VersionStrategy` enum, the base `Version` model (extended and partly overridden by `Core\Models\Version`) and the config keys `versionable.version_model` and `versionable.user_foreign_key`. To do when implemented, not now: attribution notice for the copied code, move the config keys to a Core configuration, remove the requirement from `Modules/Core/composer.json` (needs user approval, as for any dependency change), check whether `Diff` or other base-class members are used elsewhere before copying them. Consequence for C08: the point-in-time restore is written in Core's own `Version`, with no compatibility constraint towards the library.

15. **Absorbed code gets the `core_` table prefix (user decision, 2026-10-09).** A table whose mechanism Laraplate takes over from an external source stops carrying the `vend_` prefix. The approvals tables already did (`core_modifications`, `core_approvals`, `core_disapprovals`); with D14 the versioning tables follow: `vend_versions` becomes `core_versions` and `vend_versions_sets` becomes `core_versions_sets`. Scope is limited to what is detached from an external source; the other `vend_*` tables (roles, permissions, `model_has_*`, `role_has_permissions`, media) are not touched until their own mechanism is detached. To do when implemented, not now: the `CoreTables` enum values, the create migrations (no alter migration, `migrate:fresh` per D12), literal table names in tests and in the current documentation. Dated plans and specs that mention `vend_versions` stay as written; the live design spec is aligned when the decisions are carried into it.

16. **The restore coordinate is the observed state (user decision, 2026-10-09; agenda decision 1).** The user restores "the document as it was at revision R", not "undo operation N". The aggregate revision is the primary coordinate; a version set stays an optional envelope that groups the writes of one logical operation (it is the unit D10 uses to fold several translation writes into one revision). This confirms the direction of the design spec `2026-07-29-revision-centric-aggregate-history-design.md` against the operation-centric `2026-07-21-aggregate-version-sets-design.md`. The operation-centric design is not discarded: its version-set and transaction machinery remains the carrier for atomic capture (D6).

17. **Hard delete keeps one full snapshot and drops the edit history (user decision, 2026-10-09).** Context: `ClearExpiredModels` (`clear-expired`) does not concern validity periods; it hard-deletes records that have been soft-deleted for more than `core.soft_deletes.expiration_days`. When a versioned root is hard-deleted, Core writes one complete `SNAPSHOT` of the entity (the safety net for "I did not mean to delete it"), then removes the entity's edit history. Those hard-delete snapshots are themselves purged periodically; after that the data cannot be recovered, and the user accepts the loss of the history. The soft delete stage is unchanged: while a record is in the bin its full history is kept.
    - **Supersedes** the milestone-1 disposition of C03 ("hard-deleted roots are non-restorable; the SNAPSHOT tombstone is audit-only"): a hard-deleted root is now restorable from its snapshot until the snapshot is purged.
    - **Atomicity:** snapshot written, history removed and root deleted in one transaction, in that order of effect; otherwise a failure can lose both (*amended by D44 and D54: the state is read first, the delete runs, and only then is the snapshot written and the history removed, all in the same transaction*).
    - **The snapshot must be complete for the aggregate** (D10, D2): root columns, the state of the owned translations, and the relation membership at that moment, all taken before the foreign-key cascades remove them.
    - **Known limits of a recovery, to state to the user:** comments and ratings are not versioned (D8) and cascade away, files of attached media are deleted with the record; neither comes back.
    - **Capture:** it only works if every hard-delete path goes through a coordinator that snapshots first (C07; *amended by D44 and D54: the coordinator reads the state first and writes the snapshot after the delete*). `ClearExpiredModels` must therefore stop deleting at builder level without a snapshot (bulk, in chunks, is acceptable). Deletes that bypass Core, including database cascades from an `Entity` or `Preset` delete, leave no snapshot and are `best-effort` (D6).
    - **To define** (*closed by D35*)**:** the second retention period (name and default of the config key), the command that purges expired hard-delete snapshots and its schedule, the metadata kept in the snapshot (who deleted, when), and what happens to the existing `versions:compact` command, which already snapshots and purges older rows and contradicts D13 for ordinary records.

18. **Compaction: merged diff at a cut-off revision, snapshot rewrite as an option (user decision, 2026-10-09).** The intent of `versions:compact` is: take the history, discard the versions older than a limit and replace them with a snapshot that compacts them. Today the command does none of it: no age or count limit, the snapshot is of the *live* state (`refresh()`), and the purge flag is passed down and never read. It is redefined as follows. Choose a cut-off revision R (by age or by number of revisions). Find the anchor: the nearest `SNAPSHOT` at or before R, found with the walk of C08 (*completed by D45 and D53: the anchor of an aggregate is a set of kind `baseline`; by D46: no revision before the cut-off stays a revert target; by D59: a stretch holding a non-atomic set is refused*).
    - **Default, merged diff (user's variant, agreed 2026-10-09).** The `DIFF` rows between the anchor and R are merged into one `DIFF` row, written in place at R, with the columns changed over that stretch (latest values in `contents`, earliest values in `original_contents`). The anchor is kept; whatever precedes the anchor is deleted. No row is labelled `SNAPSHOT` unless it really is one, and no state has to be reconstructed to write it. Example: `S0 d1 d2 d3 d4 d5` compacted at `d3` becomes `S0 D(d1..d3) d4 d5`. The merge never crosses a schema epoch (D13): it only spans the stretch between the anchor and R, and an anchor from a more recent epoch discards the older rows.
    - **Option, snapshot rewrite.** For whoever wants to forget the original values as well, the row at R is rewritten in place as a full `SNAPSHOT` of the state as of R (reconstructed with C08; for an aggregate: columns, owned translations per D10, relation membership), and everything before R is deleted. It carries the schema epoch of R.
    - **In both cases** the row at R keeps its revision coordinate (D16) and its unique `(version_set_id, sequence)`; the rows before the cut are removed with a real `DELETE`. Rewriting a row is the only sanctioned mutation of a version row, so it may be marked (for example `compacted_at`). A hard delete (D17) is not a compaction: it always writes a full snapshot. Manual, off by default, not scheduled; the history of live records is never aged on its own.
    - **Both variants need an anchor.** Compaction at R is allowed only when the C08 walk finds a `SNAPSHOT` at or before R (normally the creation snapshot). Otherwise it refuses and reports the record; nothing is deleted. Never write a "snapshot" made by merging diffs without a base.
    - **Invariant to enforce:** the earliest retained row of every versioned record is a full snapshot. It must hold at creation (the `Created` row is a snapshot), when versioning is enabled on a table that already holds records (a baseline for each record, otherwise the first version is a diff with no base; relates to C05), at every schema epoch change (D13 lazy baseline), and after a compaction. This was not guaranteed so far: versioning was effectively off on most tables until 2026-07-30, and builder-level inserts, `upsert` and imports that skip model events write no `Created` row.
    - **For the owner, the creation snapshot must already contain the initial translations and membership.** Translations are currently saved after the root (`savePendingTranslations` on `saved`), so the `Created` row of a `Content` would miss them unless the writes of the creation are grouped in one revision (the mandatory point of D10).
    - **Verification:** a check that reports every record whose earliest retained row is not a snapshot, and a test asserting that the state reconstructed at every retained revision at or after R is identical before and after a compaction, for both variants. States before R are no longer reachable by design.

19. **Columns of the version tables (user decision, 2026-10-09).** The versions table is append-only, so it loses what an editable table carries.
    - **`deleted_at`, `is_deleted` and `SoftDeletes` are removed from `core_versions`.** Verified: the soft delete of a *versioned model* is not stored in the version row's own `deleted_at`; it is written as an `updated` row whose JSON `contents` holds the model's `deleted_at` value, and the row's own column is always `NULL` (0 rows in the development database). Removing a version is a real `DELETE`, only through the purges of D17 and D18. Readers to adjust: `CompactVersions` (`whereNull('deleted_at')`) and the sequence-gap check in `ActiveVersionSet` (`withTrashed()`).
    - **`updated_at` is removed, `created_at` stays** (it orders previous and next versions and can be forced by the writer). Route chosen: `MigrateUtils::timestamps()` gets an optional parameter to omit `updated_at` (default unchanged, so no other table moves), and `Version` declares `public const UPDATED_AT = null`; otherwise Eloquent would still write `updated_at` on every insert and fail. Precedent for the model side: `MES\OperationQuantityAudit`. `core_versions_sets` is checked below.
    - **`compacted_at` is added** for the rewrite of D18.
    - **`core_versions_sets`, checked (2026-10-09), extension confirmed by the user (2026-10-09).** It has no soft delete already. It has `created_at` and `updated_at`, but no code updates a set after creation (the only write besides the insert is `deleteOrFail()` of an empty set in `ActiveVersionSet`), so `updated_at` goes away there too, with `UPDATED_AT = null` on `VersionSet`. Constraint to remember for D17 and D18: `reverted_from_set_id` is a self foreign key with `RESTRICT` on delete, so a purge or compaction that deletes a set which a retained `Revert` set points to would fail; the retained `Revert` sets must be repointed (for example to the cut-off set) or the key changed to `SET NULL`, to be decided when implementing (*decided by D58: `SET NULL`*). `core_versions.version_set_id` cascades on delete, so removing a set removes its version rows.
    - The vocabulary of `change_type` is settled in D20.

20. **Vocabulary of `change_type`: Eloquent lifecycle verbs (user decision, 2026-10-09).** `change_type` takes the names of the Eloquent lifecycle events instead of the permission verbs (`ActionEnum`: `insert`, `forceDelete`, ...) or the approvals verbs (`Operation`: `create`, `force_delete`), because it records what happened to the row, not what a user is allowed to do, and because the membership rows already reuse `created` / `deleted`, which are also the events of a custom pivot model. Values: `created`, `updated`, `trashed`, `restored`, `deleted`.
    - `trashed`: the root goes to the bin (replaces today's `updated` row carrying `deleted_at` in its JSON; `trashingVersions()` stops searching the JSON).
    - `restored`: the root leaves the bin. **"Restore" is reserved for the bin.**
    - `deleted`: the row is physically gone. For a root it is the tombstone of D17, whether it came from `forceDelete()` or from `delete()` on a model without a bin; for membership it is a detach. `forceDeleted` is not a separate value.
    - **A revert is not a `change_type`.** Rolling a document back to an earlier revision is a composite operation recorded at set level (`VersionSetKind::Revert` with `reverted_from_set_id`); the rows inside stay plain `updated`. **"Revert" is reserved for revisions:** `restoreToRevision()` is to be renamed `revertToRevision()` when implemented (the code already has `revertToVersion()` and `VersionSetKind::Revert`).
    - **A compaction is not a `change_type` either.** It is housekeeping on the history itself (D18): it changes no record, adds no revision and is marked by `compacted_at`.
    - A permission verb maps to a value with a pure function (`delete` gives `trashed` on a model with a bin, `deleted` on one without), so no second column is needed.
    - To do when implemented, not now: the enum, the two writes in `HasVersions` that currently emit `updated` for a soft delete and a restore, `trashingVersions()`, the create migration (enum column), a test per value, and the rename above. The `create`/`insert` and `force_delete`/`forceDelete` mismatch between the approvals and permission vocabularies is outside this work and is only noted.
    - **Confirmed over the earlier choice (user decision, 2026-10-20).** In a separate session (2026-08-05) the user had chosen to record a soft delete as an `updated` row on `deleted_at`, only to avoid altering the enum column, and that is what commit `83112c2` implements. D12 removes that cost (the create migrations are edited in place, no data migration), so D20 stands and that behaviour is to be replaced.

21. **What is outside the document and is neither versioned nor restored (agenda decision 3, partly).**
    - **`RecordOrigin` is import bookkeeping (user decision, 2026-10-09).** It is not versioned and a revert does not touch it. Verified: it is listed in `CorePermissions::excludedModels()`, so it has no CRUD permissions at all ("the origin row behind an imported record", a side effect of an action authorized elsewhere); no Filament page or HTTP route of Core or CMS writes it; its writers are the importers through `RecordOriginRegistry` (`updateOrInsert`). The model foresees a "manual attribution" (validation rules for manual writes, `external_id` null for manual origins) but no entry point for it was found (the search was not exhaustive). It is also the import identity registry: `unique(referable_type, source_key, external_id)` maps an external id to a local record, which is one more reason a revert must never rewind it (re-import idempotency). The census had treated `origin` as probably owned because of the shape of the relation (`morphOne`), which was the wrong criterion: the question is whether the data is part of the document, not whether it hangs from it.
    - **No orphan rows, ever (user decision, 2026-10-09).** Its rows are morph rows without a foreign key, so a plain hard delete would leave them behind. They are removed together with the record at the hard delete. Proposed mechanism (*confirmed by D29*): the origin data is carried inside the D17 snapshot and recreated if the record is recovered, so that a recovered record keeps its identity mapping and a later import does not duplicate it. The same check applies to every other morph or foreign-key-less child of a versioned root; it has not been done (*done since: D49*).
    - **Lock columns (user decision, 2026-10-09).** `locked_at`, `locked_user_id` and `locked_until` (written by `HasLocks::writeLockColumns`, without events and without bumping `lock_version`) are transient coordination state. For every model with `HasLocks` they are excluded from versioning, as `lock_version` already is (commit `1c3ecd18`), and declared outside the guarantee. Reason: a snapshot taken after a `refresh()` (D17 tombstone, D18 snapshot option) would otherwise carry them, and a revert or a recovery would bring back a stale lock or drop a live one. Left to verify: whether a revert on a record locked by another user is refused like an ordinary write (*settled by D39 and D48: refused, before any write*).
    - **Media (user decision, 2026-10-09).** For milestone 1 media is outside the restore guarantee, declared explicitly. `Core\Models\Media` already has its own history of metadata (`HasVersions`) and a bin with expiry; the files are not versioned, and whether the observer keeps them while a media is in the bin is not verified. To resume later as an ordered membership per collection (Drupal-style: the revert restores which media are attached, not the file), skipping media that no longer exist as already agreed for references.

22. **Switching versioning off discards the history; switching it on forces a snapshot at the first save (user decision, 2026-10-09).** When versioning is disabled for a table, its history is deleted: nothing survives a period in which writes were not captured, so no revision can claim a state that the live row has left. When it is enabled again, each record gets a forced full `SNAPSHOT` at its first save, which is the anchor required by D18 (and needs the complete image of C09) (*amended by D45: the baseline covers every stream, root, locales and membership, and is written by the first write to any of them; by D53: its set has kind `baseline`; by D60: never in a revert set*). A record not saved since the re-enable has no versions at all, so `currentRevision()` is `null` rather than a stale number.
    - **Confirmed by the user (2026-10-09): the destructive toggle needs an explicit user confirmation.** The settings UI (or a dedicated command) must name what is lost before disabling. A queued, chunked purge is still to be designed for large tables. Applies per table, so it must cover every model sharing it.
    - **DO NOT FORGET (user decision, 2026-10-09): the hard-delete snapshots of D17 survive the disabling.** When versioning is turned off for a table, only the edit history is discarded. The snapshots of records that were hard-deleted are kept, purely as a safety net, until their own expiry (the retention period still to be defined in D17); nothing else in the switch-off may touch them. The purge command of D17 must keep running for them even while versioning is off for that table.
    - **Confirmed by the user (2026-10-09): the forced initial snapshot is the image *after* the first save.** One row; the history of the record starts there and the state before the first edit is not kept.
    - D13 still holds for schema changes and descriptor changes: epoch, lazy baseline, history not deleted. The epoch no longer has to close on disable or enable, since disabling erases the history.

23. **Two concurrency counters, with separate jobs (user decision, 2026-10-09; resolves C02).**
    - **`lock_version`** protects the form: it covers what the form edits, the root and its translations (D10). A translation write increments the root's `lock_version`, at the same point where D10 creates the owner's version. Relation changes do not increment it, as long as the form does not show them.
    - **The aggregate revision** protects the restore and covers everything, relations included.
    - They never replace each other: `lock_version` rejects a stale save, the revision rejects a restore towards a superseded state.
    - **Open** (*answered by D40: the Vue form shows them; a changed membership is a conflict, per relation*)**:** whether the Vue UI and the generic CRUD API show relations in their forms; if they do, a concurrent change to a relation must be a conflict for that form. Not verified: whether `CrudService::update` with `lock_version` reloads the root together with its pending translations in one step.

24. **Multi-root relations are outside milestone 1 (user decision, 2026-10-09; resolves C06).** Only relations with exactly one authoritative root are versioned and restored. A symmetric relation such as `Content::related()` (`Content A <-> Content B`, table `relatables`), an ownership transfer or any other mutation touching more than one root is declared outside the guarantee: a revert does not touch it, and registering it as a versioned relation is rejected. The related contents are an editorial association, not the text of the document. A separate ordered multi-root protocol is a later work item.

25. **Closing the Important findings (user decision, 2026-10-09).** I03 stays resolved by milestone 1. I04 and I06 are closed by the decisions listed in their sections. I02 is closed as stated there: complete restore refuses on a missing reference, `$force` skips, reports and labels the result partial. **I01 (stable identities) is deferred**: milestone 1 uses local ids on a single connection (D12 discards existing history), so the `uuid` for roots, reference subjects and versions, and the persisted identifier of a history store, are taken up when a restore across connections or stores is actually needed. I05 is discussed separately.

26. **Restore security and side effects (user decision, 2026-10-09; part of I05, decisions 2 and 3).**
    - **A new permission verb `revert`.** It is generated for versioned models, distinct from `update` (a revert overwrites with old data and is more powerful), consistent with the word reserved by D20. Recovering a hard-deleted root needs `restore`. A revert is an ordinary write: it follows the approvals of the model like an `update` (with the actor attributed) and is refused if the record is locked by another user.
    - **Side effects.** Model events fire during a revert, so search indexing and re-embedding realign with the restored content after commit. Automatic translation does **not** run: verified in `HasTranslations`, the `updated` handler emits `TranslatedModelSaved($model, [], true)` when the default-locale translation changes, and `TranslateModelJob` would then retranslate the other locales with `force`, overwriting the ones just restored (conditional on `features.translation.enabled`, seeded off, and the model's own setting). A scoped revert context suppresses the translation dispatch; it is not a global `withoutEvents()`. The approvals subsystem does not create a new modification for the writes the revert performs internally.
    - **Who may read the history (decision 1 of I05)** was open here and is closed by D27.

27. **History visibility: a `history` permission (user decision, 2026-10-09; closes I05 decision 1).** Reading the history of a versioned record needs the new verb `history`, declared for versioned models like `revert` (D26), in addition to being able to read the record (`select`). `history` shows the revisions, who changed what and when, and the past values; it modifies nothing. `revert` implies `history`. The history is always reached through the root and its ACL, never by reading `core_versions` on its own. The snapshots of hard-deleted records (D17) are read and recovered only with `restore`. The SAO ticket timeline (`TicketTimelineService`) stays on the permission of the ticket for now; it reads `change_type`, `contents`, `original_contents` and `created_at`, so D18 (a compacted stretch becomes one entry) and D20 (new `change_type` values) require updating it when implemented.

28. **The hook for translation changes and the grouping of writes (user decision, 2026-10-09; designs the mandatory point of D10).**
    - **Facts.** Every translation write ends in events of the translation model: `setTranslation()` writes at once with `update()` or `create()` (used by `TranslateModelJob` and the importers), and the attribute setters (`$content->title = ...`) go to `pending_translations` and are written by `savePendingTranslations()` on the root's `saved` event with the same calls. Without a scope, `VersionWriter::write()` opens a set of its own for each row, so a save of a root with its translations would produce two revisions; a translation-only change leaves the root clean, so the root's `updated` does not fire.
    - **Representation.** One version row per changed locale, on the owner, with `relation_path = 'translations'` and `subject_key = {locale}`, in the same set as the owner's own row. `change_type` is the event of the translation (`created`, `updated`, `deleted`); `contents` is the difference of the fields, or the complete image for a snapshot. It reuses the normalized membership storage; reconstruction and compaction run the same function per `(relation_path, subject_key)`. A locale whose last row up to R is `deleted` does not exist in R. Not a JSON blob inside the owner's `contents`.
    - **The hook.** An observer on the translation models (`ITranslated`, owner found with `parentRelation()`) writes the row on the owner when a translation is created, updated or deleted. The owner's `lock_version` rises once per revision (D23). Translation models are not versioned on their own: `getVersionStrategy()` returns `false` for them and the seeder creates no setting for them.
    - **The scope.** `save()` and `delete()` of the base model (`Core\Overrides\Model`) open the revision scope before `saving` and close it after all `saved` handlers, only for versioned roots that have a translation or a registered relation. The save of the root, of its pending translations and of their version rows is one set and one transaction (this also closes the non-atomic save of C01). To verify with a targeted test before adopting: performance and regressions of wrapping `save()`.
    - **Explicit grouping.** A method such as `$owner->withinRevision(fn)` groups several writes in one revision, for the importers and for any loop over locales.
    - **Async translation.** `TranslateModelJob` runs as a revision of its own, with a system actor and `reason = "ai-translation"`: it is asynchronous and has another author, so it does not merge with the user's revision.
    - **Cases.** The root and its initial translations share the creation set, so the anchor of creation is complete. A hard delete (D17) snapshots every locale from the live table before the cascade. A translation-only change gives a set made only of translation rows and does not touch the root's `updated_at`; whether the root must be touched for caches and search is not decided.

29. **The hard-delete coordinator (user decision, 2026-10-12; designs the capture rule of D17 and the answer to C07).**
    - **Facts.** A hard delete arrives from: `forceDelete()` on a model with a bin; `delete()` on a model whose bin is switched off by setting (`Core\SoftDeletes\SoftDeletes::performDeleteOnModel()` force-deletes through the model); `delete()` on a model without a bin; the builder-level `ClearExpiredModels`; foreign-key cascades and cascading observers. The first three raise model events, and `HasVersions` already captures the image at `deleting` and writes a `Deleted` row at `deleted`, with only the root columns.
    - **One service, `HardDeleteCoordinator`**, with `forceDelete(Model $root)` and `purge(Builder $roots, chunk)`. In one transaction and in this order: (1) read the state *before* the deletion: the complete root image, the translations per locale, the membership, the `RecordOrigin` data; (2) write the snapshot set: the root's `deleted` row with the complete image, plus `SNAPSHOT` rows per locale (`relation_path = 'translations'`), per membership entry and for the origin (`relation_path = 'origin'`), the same scheme as D28; (3) delete the edit history of the root, setting `reverted_from_set_id` to null first because of the `RESTRICT` key; (4) delete the root, the cascades remove the rest (*amended by D44 and D54: read, delete, then snapshot and history removal; by D55: the row format of the snapshot set; by D58: the database sets `reverted_from_set_id` to null*).
    - **Callers.** The `deleting` hook of `HasVersions` delegates to the coordinator for the first three paths (*amended by D44: the coordinator wraps `delete()` and `forceDelete()` instead; the hooks are only a fallback*). `ClearExpiredModels` stops using the builder: it loads in chunks (`chunkById`) and calls `purge()`, which preloads translations and membership per chunk. If versioning is off for the table (D22) or the model is not versioned, the coordinator only deletes.
    - **Recovery.** `recover(rootType, rootId)` recreates the root with the same primary key, the translations per locale, the membership (a missing reference is skipped and reported, D25) and the origin; it writes a new anchor (`created`, `reason` "recovered") and consumes the snapshot set. It needs the `restore` permission (D27) and does not bring back comments, ratings or media files. A periodic purge removes the expired snapshots (config key to define, D17).
    - **Cascades from a parent that is not versioned** (deleting an `Entity` or a `Preset` removes its `Content` by foreign key) do not go through the coordinator: no snapshot, declared as `best-effort` (option a). Draining the children first (b) or refusing the deletion while versioned children exist (c) were not chosen for milestone 1.
    - **Not verified:** the cost of a large purge, and the order between the `deleting` hook and the cascading observers of `Content` (`ContentExtensionObserver`, `ExtendsContentTrait`) inside the same transaction.

30. **The owner's `updated_at` follows its translations (user decision, 2026-10-12; closes the open point of D28).**
    - **Why.** The incremental search sync (`ElasticsearchEngine`, `TypesenseEngine`, `SearchSyncCommand` select the roots with `updated_at > last indexed`) and the freshness fingerprint of the list in `CrudController` (id and `updated_at`) read the root's `updated_at` as the signal that something changed. The search document of a content includes its translations, but a translation-only change leaves the root row clean, so neither consumer would see it.
    - **Rule.** When a translation is created, updated or deleted, the observer of the translation models touches the owner's `updated_at` with a direct `UPDATE` of that single column, without model events (the same pattern as `HasLocks::writeLockColumns`). A deletion is covered, which a maximum computed at read time could not do. It does not rise `lock_version` (D23 has its own rule) and writes no version row (`updated_at` is not versionable). With several locales written in one operation the touch happens once, when the operation ends (*amended by D37 and D51: repeated touches of one owner are skipped within the same transaction*).
    - **It lives in the translation observer, not in the revision scope,** so it also works when versioning is off for the table.
    - **Rejected:** Eloquent `$touches` (`touch()` calls `save()` and re-raises all the events of the root, among them `TranslatedModelSaved` with `force` from the `updated` handler of `HasTranslations`; whether it would really retranslate was not verified), and a maximum of `updated_at` computed at read time (blind to deletions and to relations, spread over every reader, and `updated_at` of the translations is probably not indexed since `MigrateUtils::timestamps()` indexes only `created_at`; not verified on the real table). Indexing the root's `updated_at` in Elasticsearch needs nothing more, since it now reflects the translations.
    - **Confirmed by the user (2026-10-12): the same rule applies to relation-only changes.** A `sync()`, `attach()` or `detach()` of tags, categories, locations or contributors moves the owner's `updated_at` (today it does not), by the same quiet update, done once per transaction (D51); the mechanism is settled in D37 (the events of the pivot models, not adapters), and `related()` is not touched.

31. **Registered relations of `Content` in milestone 1 (user decision, 2026-10-12; answers the tenth open question of the design spec).**
    - **Four reference relations, membership only.** `categories` (`cms_categorizables`, key `(content_id, taxonomy_id)`), `tags` (`cms_taggables`, morph, key `(tag_id, taggable_id, taggable_type)`), `locations` (`cms_locatables`, key `(content_id, location_id)`) and `contributors` (`cms_contributables`, key `(content_id, contributor_id)`). Verified in the migrations: none has a column besides the key and the two timestamps, and none has an order. No pivot attribute is authoritative; the timestamps record when the link was made and are not restored (a re-link has its own). The identity of the subject is the local id, `subject_key = {id}` (D25); the composite key respects the rule of never relying only on a generated pivot id.
    - **One owned part, the translations** (D10, D28).
    - **`ContentReference` (the bibliography) is outside the guarantee in milestone 1.** It is not a pivot but a child table with its own surrogate id, `label`, `url`, `order_column`, a bin, and it is declared `IsPartOfParent`: by its shape a second owned part, part of the text of the document. No production code was found that writes it. Bringing it back would need a stable identity (the surrogate id is not stable if the row is recreated, and the bin complicates it) and a rule for the order. A revert does not touch it; it keeps its own history as it does today. To resume as a second owned part when someone writes it.
    - Everything else on `Content` is already declared outside the guarantee: `related()` (D24), `origin` (D21), media (D21), comments and ratings (D8), lock columns (D21).

32. **Tables of the version history are all named `core_versions_*` (user decision, 2026-10-12; extends D15).** The prefix of the main table is the prefix of every table tied to it, so they sort together in the database, even if this departs from the Laravel naming convention. Today: `core_versions` and `core_versions_sets`. The table of the schema epochs (D13) is therefore `core_versions_epochs`, not `core_version_epochs`; any later table of the history follows the same rule.

33. **The schema epoch: identity, fingerprint, detection, lazy baseline (user decision, 2026-10-12; designs D13).**
    - **Identity.** A table `core_versions_epochs` (D32) with `id`, `root_type`, `fingerprint`, `definition` (JSON with the columns and relations that were described) and `first_seen_at`, unique on `(root_type, fingerprint)`. Every version row carries `epoch_id`. The description is kept, and not only the hash, because classifying a revert (column added, dropped, required) needs to know which columns existed then. An epoch is reused when the same fingerprint comes back.
    - **What the fingerprint covers**, per versioned root type: the versionable columns of the root (name, type, nullable); the versionable columns of its translation model, which are part of the owner's state (D28); the registered relation descriptors (path, ownership, key columns). Left out: defaults, the non-versionable columns (`lock_version`, the lock columns) and the inner shape of JSON columns (a process rule, as in D13).
    - **Detection: at the first write per process and per class, not only at the end of a migration.** The fingerprint is computed from the schema and the code, compared with the latest epoch of the class, and the epoch is created with `firstOrCreate` guarded by the unique key, so concurrent processes do not duplicate it. A change that comes with a deploy and no migration (a new descriptor, a changed `$dontVersionable`) is seen too, which the `MigrationsEnded` listener alone would miss. The static caches must be cleared when workers restart after a deploy (Horizon, Octane); not verified.
    - **Lazy baseline without a cost on every write.** The check "the last row of this record belongs to another epoch" runs only for classes that have more than one epoch; with a single epoch, the common case, nothing is queried. When it differs, the first write of the record writes a complete `SNAPSHOT` of the root and of every locale present, in the same set, so every stream (the root and each `relation_path` with its `subject_key`) has an anchor inside the new epoch (*amended by D45: the membership is part of the baseline too; by D53 and D60: the set has kind `baseline`, never a revert set; by D57: the fingerprint includes the coverage of the descriptors*).
    - **At revert time** the epoch of the row is compared with the current one: equal is compatible; different, the two definitions are compared and each column is classified as in D13, and the revision carries `schema-mismatch` or `definition-mismatch`.
    - **Not verified:** the real cost of reading the schema per class (once per process should do); how `ModelCapabilityScanner` could list the versioned classes for a manual command, which is not needed; the tables shared by several models (`Entity`, `Taxonomy`, `Preset`), where each root type computes its own epoch.

34. **Wrapping `save()` and `delete()` in the revision scope: conditions and join rules (decided by Claude on the user's delegation, 2026-10-14; amends D28).**
    - **Facts, read in the code and not proven by a test.** `VersionSetManager::run()` opens a transaction and calls `lockExistingRoot()`, which rereads the root with `lockForUpdate()` and realigns the in-memory model to the database, including `lock_version`, and calls `unsetRelations()`. If a scope is already active, a new `run()` for another root throws `VersionSetRootMismatchException` and one for the same root but another PHP instance throws `DistinctVersionSetRootInstanceException`.
    - **The scope is opened by `save()` only when it is needed:** the root is versioned, it owns translations or has registered relations, and there is something to write (dirty attributes or pending translations) (*`delete()` and `forceDelete()` are wrapped for every versioned model, D44; the translation models and both sides of the relation subclasses open the transaction before their SQL, D52*). A `save()` with nothing to write opens nothing. The wrapper is defined inside `HasVersions`. The cost is one transaction and one `SELECT ... FOR UPDATE` per affected save.
    - **Join rules.** Scope active for the same instance: proceed. Scope active for another root or another instance: proceed without calling `run()`, the rows join the active set through the writer, which already attaches them to it (*replaced by D43 for another root: it gets a set of its own in the same transaction; a same root on another instance still joins*). The observer of the translation models writes through the writer when a scope is active; when none is (a translation written alone with `setTranslation`) it opens one for the owner instance it loaded.
    - **`lockExistingRoot()` must stop erasing the optimistic lock.** It compares the version carried by the instance with the one of the locked row and throws `StaleModelLockingException` when they differ, and it neither overwrites `lock_version` nor drops the loaded relations. Without this, the version that the Filament guard puts back on the record to condition the update would be lost inside a scope.
    - **Blast radius.** The versioning is on by default for every table, so every translatable model is affected (`Content`, `Tag`, those using `HasTranslatedDynamicContents`); how many they are was not counted.
    - **To verify with tests at implementation:** a save with nothing to write opens no transaction; a nested save of another root does not throw; a translation written alone opens its own scope; an error inside the `saved` handlers rolls back the version rows too; a stale save fails also inside a scope; the loaded relations survive the scope.

35. **Expiry of the hard-delete snapshots: setting, command, cron rows (user decision on the name, the rest decided by Claude on the user's delegation, 2026-10-14; closes the open points of D17 and D29).**
    - **Facts, read in the code.** `soft_deletes.expiration_days` is a runtime setting seeded in `CoreDatabaseSeeder` (group `soft_deletes`, 0 = never), not an environment key. `model:clear-expired` is scheduled nowhere in the code: the scheduler runs `PruneMediaDraftsCommand` and `ModelLockSweepCommand` from the code and, for everything else, the active rows of `core_cron_jobs`, using `command` and `schedule` (*corrected 2026-10-19: since `60c45575` of 2026-10-14 it also passes `parameters`, `onOneServer` and `withoutOverlapping`; the decision does not change*). Nothing seeds a row for `model:clear-expired`, so soft-deleted records are not purged unless an administrator creates that cron.
    - **Setting (user's name).** `hard_deletes.expiration_days`, group `hard_deletes`, integer, in the same style as `soft_deletes.expiration_days`: days before the snapshots of hard-deleted records are purged, 0 = never. Seeded at **30**, so that a manual hard delete has the safety net of D17 without leaving a snapshot, and its data, forever.
    - **Command.** `versions:purge-deleted` finds the sets that hold a `deleted` row of the root (no `relation_path`) older than the limit and removes them in chunks; the rows per locale, membership and origin are in the same set and go by cascade. It does not look at the versioning setting of the table (D22) and does nothing when the setting is 0.
    - **Scheduling only through `core_cron_jobs`**, nothing in the code. Two rows seeded in `CoreDatabaseSeeder::defaultCrons()`, both active, created only if the name does not exist so an administrator's edits survive: `clearExpiredModels` runs `model:clear-expired` daily, then `purgeDeletedVersions` runs `versions:purge-deleted` daily after it. With the settings at their seeded values the first is a no-op (0 = never) and the second only removes snapshots older than 30 days.
    - **Metadata of the snapshot:** nothing new. The actor is the `user_id` of the `deleted` row, the time is its `created_at`, the cause is the `reason` of the set (`expired` from `model:clear-expired`, `manual` otherwise), the title comes from the translation rows of the same set.
    - **Left as it is, as a separate change:** the two commands scheduled in the code (`PruneMediaDraftsCommand`, `ModelLockSweepCommand`). Moving them to `core_cron_jobs` would stop them on installations whose database is not reseeded.

36. **The revert context that suppresses automatic translation (user decision, 2026-10-14; settles the translation part of D26).**
    - **Facts, read in the code and not proven by a test.** `TranslatedModelSaved` is emitted at three points: the `created` and `updated` handlers of `HasTranslations` (the second with `force = true` when the default-locale translation changed) and the table action of Filament that asks for a translation on purpose (`HasTable`). The listener of the AI module (`HandleModelTranslationListener`) is not queued, so it runs while the scope of the revert is still active, and then checks `TranslationGate` and queues `TranslateModelJob`.
    - **The revert context is the active version set of kind `Revert`.** It already exists (`revertToVersion()` opens it), lives for the duration of the operation, resets in its `finally` even on an exception and nests. No static flag, no global `withoutEvents()`. `ActiveVersionSet` gets `isRevert()`.
    - **The event carries the information.** `TranslatedModelSaved` gets a `fromRevert` field, set at emission by the two handlers of `HasTranslations` from `isRevert()` (at emission, not when a listener reads it, so a queued listener would work too). The event is still emitted, so every other listener sees it. The table action of Filament is not a revert and does not change.
    - **The AI module respects it.** `HandleModelTranslationListener::shouldHandle()` returns false when `fromRevert`.
    - **Still active during a revert:** indexing and the recomputation of the embeddings (`ContentTranslationObserver`, search), which must realign with the restored text.
    - **Tests, written first:** with automatic translation on, a revert queues no `TranslateModelJob` (`Bus::fake`) while a normal save does; `fromRevert` is true only inside the scope; after a revert, also one that failed, a normal save queues the job again; the embeddings are recomputed. The behaviour of the event can be tested now by opening a set of kind `Revert` directly; the complete test on the revert of a content waits for the translations of D28.
    - **Not part of this decision:** the approvals during a revert (it follows the rules of an update, and its internal writes do not create a second modification), the last point of D26, to be settled with the code of the revert.

37. **How a relation change touches the owner's `updated_at` (user decision on `related`, the rest decided by Claude on the user's delegation, 2026-10-14; settles the mechanism of D30).**
    - **Facts, read in the code and not proven by a test.** Every relation of `Content` uses a custom pivot model with `->using(...)`, from both sides: `categories`, `locations`, `contributors`, `related` and their inverses in `Category`, `Tag`, `Location` and `Contributor`. With a custom pivot, `attach()`, `detach()`, `sync()` and `updateExistingPivot()` create or delete the pivot models one by one, so **the events of the pivot model fire**. The version-aware `BelongsToMany` adapters that D30 mentioned do not exist: no path of `Content` goes through `attachVersioned` and the like. Eloquent's `$touches` is excluded: `touch()` calls `save()` on the content, so it goes through `HasOptimisticLocking::performUpdate()` and raises `lock_version`, against D23.
    - **Mechanism: the events of the pivot models**, not adapters. It works with versioning off and covers any code that changes the relations with Laravel's methods (CRUD, importers, `HasTags`). A raw `DB::table(...)->insert()` or a foreign-key cascade is not covered and stays `best-effort` (D6).
    - **A shared helper in Core, `OwnerTimestamp::touch(class, key)`.** A direct `UPDATE` of the `updated_at` column alone, without model events or `lock_version`, skipped when the same owner was already touched in the same second by the same process, so a `sync()` of ten tags runs one `UPDATE` (*replaced by D51: skipped within the same transaction*). The observer of the translation models (D30) uses it too.
    - **The owner is found from the pivot.** Each pivot declares the owner class and key (`content_id` for `Categorizable`, `Locatable` and `Contributable`; for `Taggable` the polymorphic type and id, resolved with the morph map). A trait `TouchesOwnerOnChange` hooks `created`, `updated` and `deleted`.
    - **Not touched:** the reference record (category, tag, location, contributor) and `lock_version`.
    - **`related()` (`Relatable`) is not touched (user decision).** It lives on its own: the related contents are an editorial association, outside the guarantee already (D24), and a change of the link does not move the `updated_at` of either content.
    - **Tests, written first:** for each of the four relations, `attach`, `detach` and `sync` move the `updated_at` of the content (time frozen) and not its `lock_version`, also when changed from the inverse side; a `sync` of three tags runs one `UPDATE` of the content; no model event of `Content` fires; the reference record does not change; a change of `related()` does not move `updated_at`.

38. **`versions:compact` refuses to run until D18 is implemented (user decision, 2026-10-19; answers "what `versions:compact` does until it is redefined").**
    - **Facts, read in the code.** For every versioned record the command refreshed the live row and appended a `SNAPSHOT` version of it; the `purgeOldVersions` flag reaches `createVersion()`, which ignores it, so nothing was deleted. Each run added to every history a revision with no change, shown to the user like any other, and one more row per record. Nothing scheduled or called it.
    - **Decision.** The command keeps its name and signature, writes nothing and exits with an error that says compaction is being redefined. Its old body is removed: the implementation of D18 replaces it, it is not a repair of it. Test written first: with a versioned record present, both forms of the call fail and leave the number of version rows unchanged.
    - **Documentation aligned.** Core's `MODULE.md` no longer says that `createSnapshotVersion()` with its purge flag compacts the history.

39. **A revert on a locked record is refused unless the user holds the lease (user decision, 2026-10-19; answers "whether a revert on a record locked by another user is refused").**
    - **Facts, read in the code.** `LockedModelSubscriber` refuses `saving` and `deleting` of a locked record when the acting user is not the holder; a freeze has no holder. A revert writes through the root's `save()`, so a revert that changes a root column was refused already. But the guard sees one model's row: translations and pivot rows have no lock of their own, so a revert that leaves the root row unchanged (only the title differs, or the root column is already at the target value) walked past it. A test reproduced the second case.
    - **Rule (the user's): a lock means the record is being edited, and nothing else may change it unless the lock is forcibly removed.** The holder of the lease may revert, since a revert is one more edit of theirs; anybody else is refused; a freeze refuses everybody. The way through for others is `forceUnlock()`, behind the `unlock` permission. `Locked::withoutGuard()` and `core.locking.prevent_modifications` apply as for the guard.
    - **Where.** `HasVersions::revertToVersion()` checks `HasLocks::blocksWritesByCurrentUser()` inside the version set, after `lockExistingRoot()` has reread the row under `FOR UPDATE`, and throws `LockedModelException` before any write; the set rolls back. A lock taken after the model was loaded is therefore seen.
    - **Tests, written first:** refused when locked by another user and when frozen, with nothing written and no `Revert` set; refused when the root row would not change; refused when the lock was taken after loading; allowed to the holder; allowed after a forced unlock.

40. **A form that shows relations sends the membership it loaded; a changed membership is a conflict (decision proposed by Claude, confirmed by the user, 2026-10-19; answers "whether forms show relations" of D23).**
    - **Facts, read in the code.** The Filament content form shows no relation (title, slug, components, position, validity); the user notes the Filament forms are quick and incomplete, so the rule must hold for any form, including a future Filament one. The Vue content form (`laraplate-ui`, `ContentsFormView.vue`) does show them: in the editor lens, for users with `update`, tags, categories, locations and contributors are a second section of the same form. The save makes two requests: `crud.update` of the content, then `syncContentRelations` of only the relations the user changed against what was loaded, each replacing the whole membership of that relation. A tag added meanwhile by somebody else, in a relation the user also changed, is lost without a conflict; relations the user did not touch are safe.
    - **Rule: compare and set, per relation.** Together with the new membership of a relation, the form sends the membership it loaded. The server compares it with the current one inside the write and, when they differ, refuses with a conflict (409) and writes nothing. Precise: a tag added by somebody else does not block whoever edits the categories. No new counter: `lock_version` keeps protecting what the form edits of the record and its translations (D23, rule 1) and is still not raised by relation changes; the aggregate revision keeps protecting the revert. The aggregate revision is not the token here, because the first request of the same save already advances it and would refuse the second.
    - **Amends the design spec,** which said a membership-replacement command accepts an expected aggregate revision: it accepts the expected membership of the relation it replaces.
    - **Left to the UI repository, as separate work:** the Vue form sends no `lock_version`, so the fields of the record are overwritten without a conflict today; it must send it, handle the 409 of both checks, and preferably save record and relations in one atomic request instead of two (a failure of the second leaves a half-saved content).

41. **The state of a CMS relation is its membership; a pivot column enters it only when declared (decision proposed by Claude, confirmed by the user, 2026-10-19; confirms D31 and settles question 10 of section C).**
    - **Confirmed from D31, rechecked in the migrations and the models:** the four pivots hold the keys and the timestamps only, and no relation reads another pivot column (`withPivot`). The versioned state of each relation is the set of related ids (for tags with the morph type); the timestamps are metadata, not restored; `related()` stays out (D24).
    - **New: a future pivot column** (the role of a contributor, the main category, an order) becomes part of the state only when the relation descriptor declares it authoritative. Declaring it changes the descriptor and so opens a new epoch with a lazy baseline (D13); an undeclared column is neither captured nor restored.

42. **Membership changes are captured from the pivot events, one call one revision (decision proposed by Claude, confirmed by the user, 2026-10-19; answers finding 1 of the independent review of 2026-10-19).**
    - **Facts, read in the code.** Nothing writes membership rows today: `HasVersionedRelations` (`attachVersioned`, `detachVersioned`, `syncVersioned`, the link revert) is used by no model; `CrudService::update()` calls `$record->update($changes)` and then `syncModelRelations()`, whose `sync()` runs outside any version set; `HasTags` calls `attach`, `detach` and `sync` directly; the scope of D34 opens on `save()` only. With a custom pivot (`->using()`, every relation of `Content`), Laravel's `attach`, `detach` (also without ids: `detachUsingCustomClass` loads the pivots and deletes them one by one), `sync`, `toggle` and `updateExistingPivot` create, save or delete pivot models one by one, so the pivot events fire from both sides. They do not fire for a raw query on the pivot table or a foreign-key cascade. `Taggable` extends Laravel's `MorphPivot`; `Categorizable`, `Locatable` and `Contributable` extend `Core\Overrides\Pivot`.
    - **Rows come from the pivot events.** A trait on the pivot, the same one that touches the owner's `updated_at` (D37), finds the root from the keys the pivot declares; when the root registers that relation in its descriptor and versioning is on for its table (D22), it writes a membership row: `relation_path`, `subject_key`, `created` or `deleted`, empty contents (D41). Callers do not change: CRUD, `HasTags`, the importers and future code are covered.
    - **One call, one revision.** `Core\Overrides\Model` returns Core subclasses of `BelongsToMany` and `MorphToMany` (overriding `newBelongsToMany()` and `newMorphToMany()`); their mutators open `VersionSetManager::run()` for the parent when the parent is a versioned root that registers the relation, so the pivot rows of one `sync()` join one set. From the inverse side (`$category->contents()->sync(...)`) the parent is not the root: each pivot joins the set of its own root, one set per content touched (the rule of D43).
    - **Record and relations together.** A public operation that changes both wraps them in one `run()`: `CrudService::update()` per record, and the Filament save. The compare and set of D40 runs inside it, after the root is locked `FOR UPDATE`.
    - **Outside:** raw queries on a pivot table and foreign-key cascades (a hard-deleted tag takes its links with it) write no row and stay `best-effort` (D6); a revert that then meets the missing reference refuses, or skips it with `$force` (D25).
    - **`HasVersionedRelations`** loses `attachVersioned`, `detachVersioned` and `syncVersioned`, made redundant; its revert part stays and gets the checks of finding 7 of the independent review.
    - **Supersedes** the "version-aware adapters" and "Pivot bases join the scope" of the design spec's integration boundary, and D37's note that no adapter exists (now they do, as relation subclasses, not as separate methods).
    - **Tests, written first:** a `sync` of three tags from the content gives one set of three rows; a `sync` from a category over two contents gives two sets; `detach()` without ids writes the rows; a CRUD update with fields and relations gives one set; no row when versioning of the table is off; a raw query on the pivot writes nothing.

43. **One version set per root, all in the same transaction (decided by Claude on the user's delegation, 2026-10-19; answers finding 4 of the independent review; replaces the join rule of D34).**
    - **Facts, read in the code.** `ActiveVersionSet` holds one set with one root. A nested `run()` for another root throws `VersionSetRootMismatchException`, but `VersionWriter::write()` attaches a change of any model to the active set without checking that the model is its root, and `finish()` stamps the set with the active root. The join rule of D34 ("for another root, proceed without `run()`; the rows join the active set") therefore puts another root's rows into the wrong set. Cases: `ContentExtensionObserver::deleting` force-deletes the extender (a Shop `Product`) inside the content's delete, so its tombstone lands in the content's set and cannot be recovered, and D29's removal of the content's history then cascades to it; `Contributor::save()` saves the `User`; the row of the second root is not ordered by a lock on it; `fromRevert` (D36) would spread to it.
    - **Rule.** Inside the transaction opened by the outermost `run()`, the manager keeps one active set per root touched. When a nested `run()` is for another root, or a write belongs to another root, it opens that root's set: same transaction and connection, that root's row locked `FOR UPDATE` at that moment, a sequence of its own, options of its own (ordinary kind with the actor of the outer operation; `Revert` only when that root is itself reverted). `write()` resolves the root of each change, the model itself, the owner of a translation (D28) or the root found from a pivot (D42), and writes into that root's set, creating it if missing. A same root on another PHP instance joins the same set, as before.
    - **Consequences.** The extender's tombstone is in the extender's set and recoverable; removing the content's history touches only the content's sets; `isRevert()` and `fromRevert` are read from the set of the root that writes; the rows of each root follow that root's lock.
    - **Price:** two transactions locking A then B and B then A can deadlock; the database aborts one, the user sees an error and nothing is half written. No lock order is imposed.
    - **Tests, written first:** deleting a content that deletes its extender leaves the extender's tombstone in its own set and recoverable; removing the content's history keeps the extender's; `Contributor::save()` writes the user's version in a user set; a revert of A that writes B gives B an ordinary set and does not suppress B's translation.

44. **The hard-delete coordinator wraps the delete instead of running from a hook (decided by Claude on the user's delegation, 2026-10-19; answers finding 3 of the independent review; amends D29 "Callers").**
    - **Facts, read in the code.** Today `HasVersions` captures the image in memory at `deleting` and writes the `Deleted` row at `deleted`; the writer opens a set and a transaction of its own there, after a DELETE that is already committed when the caller had no transaction, so the tombstone and the delete are not atomic. `LockedModelSubscriber` listens on the wildcard `eloquent.deleting: *`, and Laravel runs the listeners of the model's own event before the wildcard ones, so any work done in the `deleting` hook of `HasVersions` happens before the lock guard can still refuse. D29 had the hook "delegate to the coordinator", whose last step deletes the root: from inside the `deleting` of that same delete it cannot, and the snapshot and the history removal could commit before the guard refused, leaving the record alive with its history gone. Five versioned models do not extend `Core\Overrides\Model` (`User`, `Role`, `Permission`, `Media`, `Fieldable`), and `Core\SoftDeletes\SoftDeletes` defines `delete()`.
    - **Rule.** `delete()` and `forceDelete()` of every versioned model, not only of roots with translations or relations, run inside `run()` for the root: one transaction, the root row locked `FOR UPDATE`. In order: (0) the lock rule of D39, refused before anything is read or written; (1) read the state before the deletion (root image, locales, membership, origin), as in D29 step 1; (2) perform the delete, so the model events fire, the guard and the cascading observers run (their writes go to their own roots' sets, D43) and the row is physically deleted or moved to the bin; (3) only when the row is really gone, write the snapshot set and then delete the root's edit history (`reverted_from_set_id` set to null first; *by D58 the database does it*), as in D29 steps 2 and 3; *while it runs, the delete's own writes stay out of the snapshot set, D54*; a move to the bin writes its `trashed` row instead. Any exception rolls the whole operation back, so a refused delete loses no history.
    - **Where the code goes.** The wrapper is defined in `HasVersions`. Where a class also uses `Core\SoftDeletes\SoftDeletes` (`Core\Overrides\Model`), the conflict on `delete()` and `forceDelete()` is resolved in that class with `insteadof`, keeping the bin's methods under an alias that the wrapper calls. The `deleting` and `deleted` hooks of `HasVersions` stay only as a fallback for a delete that reaches the events without the wrapper; what they write is labelled `best-effort`. A builder-level delete raises no event and is not covered (D29: `ClearExpiredModels` loads in chunks and calls `purge()`).
    - **Recovery, completing D29.** `recover()` refuses when the primary key of the snapshot is taken by a live row (SQLite can reuse the highest rowid), and a snapshot from an older epoch is applied with the classification of D13: compatible columns applied, dropped columns skipped and reported, refused when a required column has no value.
    - **Tests, written first:** a hard delete refused by the lock writes no snapshot and removes no history; a hard delete of a `User` (not a `Core\Overrides\Model`) writes its snapshot in the same transaction as the DELETE; an exception after the DELETE rolls back the delete, the snapshot and the history removal; a soft delete through the wrapper writes the `trashed` row in one set; the extender deleted by `ContentExtensionObserver` gets its own snapshot set (D43); `recover()` refuses on a taken key and reports a dropped column.

45. **A baseline is a complete image of the whole aggregate, and a revert needs one at or before its target (decided by Claude on the user's delegation, 2026-10-19; answers finding 2 of the independent review; amends D22 and D33).**
    - **The gap.** The creation set is complete: the root and its initial translations share it (D28), and every later link starts with its own `created` row (D42). The baselines are not: the forced snapshot after re-enabling (D22) covers the root only, the lazy baseline after an epoch change (D33) covers the root and the locales but not the membership, and nothing said what a stream with no row at or before R means. After switching versioning off and on, a record keeps its tags and translations, the first save writes a root snapshot only, and a complete revert to that revision would detach every tag and remove every translation, since "a translation added after R is removed".
    - **Rule 1, the baseline is whole.** A baseline set (D22 and D33) holds a complete image of every stream of the root: the root as a `SNAPSHOT` read back, each locale present as a `SNAPSHOT` read back, and one `created` row per current entry of each registered relation. It is written by the first write to any stream after the event (a root save, a translation write, a pivot change), in the set of that write and after it, consistent with D22's image after the first save (*completed by D52: a `best_effort` set is such an event; by D53: the set has kind `baseline`; by D60: a revert set never carries it*).
    - **Rule 2, a stream with no row at R.** It is absent at R only when R is at or after a complete anchor set of the root inside R's epoch (the creation set or a baseline). When no such set exists at or before R, the revert to R is refused (no complete anchor), never rebuilt as "empty".
    - **Rule 3, a locale's first row is a complete image.** The `created` row of a translation, and the row of a locale in a baseline, is always a `SNAPSHOT` read back after the write, whatever the owner's strategy (C09 applied to translation rows, D11). A membership `created` row needs no read back: it has no contents (D41).
    - **Tests, written first:** off and on, then a save, gives a baseline set with the root, every locale and every tag; a revert to that set leaves tags and translations in place; after an epoch change, the first pivot change writes the baseline; a revert to a revision before any complete anchor set is refused; a translation created under a DIFF owner has a `SNAPSHOT` first row.

46. **After a compaction, no revision before the cut-off is a revert target (decided by Claude on the user's delegation, 2026-10-19; answers finding 5 of the independent review; completes D18).**
    - **The gap.** Compaction runs per stream (the root, each locale, each relation entry), and each stream has its own anchor, so the sets before the cut-off R survive in pieces. Example: root `S0@1 d@2 d@3 d@4`, locale `en` `SNAP@1 d@2 SNAP@3 d@4`, compacted at R = 4 with the default variant: the root keeps `S0@1` and merges d2 to d4 at 4; `en` keeps `SNAP@3`, merges d4, and loses set 1. A revert to set 3 would rebuild the root as S0 (its d2 and d3 now live at 4), and a revert to set 1 would remove `en`. Neither state ever existed.
    - **Rule.** After a compaction at R, the revisions of that root before R are not revert targets, in both variants. The cut-off of a root is read from the rows themselves: the highest `version_set_id` among its rows with `compacted_at` set (D19). A revert to an earlier revision is refused with a reason that says the history before it was compacted. No new column or table.
    - **What the earlier sets still are.** The ones that keep an anchor row stay, because reconstruction from R onwards starts from those anchors; they are no longer revisions of their own. The history shown to the user lists them as one "compacted" entry, not as revert points, and the SAO ticket timeline reads them the same way (D27 already asks to update it for D18).
    - **Tests, written first:** the example above: reverts to sets 1 and 3 are refused, a revert to 4 and later rebuilds the state as it was; the history lists the compacted stretch as one entry; the same with the snapshot-rewrite variant.

47. **Where capture quality is stored and how it is known (decided by Claude on the user's delegation, 2026-10-19; answers finding 6 of the independent review).**
    - **The gap.** The spec defines `atomic`, `best_effort` and `incomplete` for capture and `partial`, `schema-mismatch` and `definition-mismatch` as labels that withhold "restore everything", with no column for them and no rule for computing them; without one, nothing decides which revisions are revertable.
    - **Only capture quality is stored, on the set.** A column `capture` on `core_versions_sets`, `atomic`, `best_effort` or `incomplete`. It is known only while writing:
        - `atomic` (*amended by D52: a transaction on the root's connection was open before the business SQL, whether `run()` opened it or joined it*): the set was opened by `run()` before the business SQL, by one of the paths that own the operation: the wrappers of `save()`, `delete()` and `forceDelete()` (D34, D44), the relation mutators (D42), `CrudService` and the Filament save, `withinRevision()`, the revert;
        - `best_effort`: the set was opened by the writer itself because no scope was active (the fallback of `VersionWriter::write()`), so the business statement had already run; the fallback hooks of D44 also write this;
        - `incomplete`: an `atomic` set is downgraded when, during it, the writer meets something it cannot capture: a relation that is not registered, a detected bypass, a missing version reference.
    - **The other labels are computed at revert time, never stored,** because they depend on the present: `partial` from the missing references (D25), `schema-mismatch` and `definition-mismatch` from the epochs (D13, D33).
    - **"Restore everything" to R** is offered only when every set of the root from its complete anchor set (D45; *by D53, the latest set of kind `baseline` at or before R*) up to R is `atomic`, and none of the computed labels applies. One `best_effort` or `incomplete` set in that stretch may hide a change that was not captured.
    - **Tests, written first:** a save through the wrapper gives an `atomic` set; a write reaching the writer with no scope gives `best_effort`; a revert to R is offered as complete with only atomic sets before it and refused as complete with one `best_effort` set between the anchor and R.

48. **Every check of a revert runs under the root's lock, on both entry points (decided by Claude on the user's delegation, 2026-10-19; answers finding 7 of the independent review; extends D39).**
    - **Facts, read in the code.** `HasVersions::revertToVersion()` has the lock check of D39 inside its set. `HasVersionedRelations::restoreToRevision()`, the aggregate revert (to be named `revertToRevision()`, D20), has none, and it compares `currentRevision()` with the expected revision, replays the membership and checks that the references exist before `runInRevertScope()`, so outside the `FOR UPDATE`: a write that lands between the check and the revert is overwritten, and a reference deleted in between is not seen.
    - **Rule.** On both entry points, everything that decides whether the revert may run happens inside the set, after `lockExistingRoot()`: the lock rule of D39; the expected revision, read as the root's current revision under that lock (every write to any stream of the root takes the same lock when it opens its set, D34, D42, D43, so the value cannot move); the replay of the target state and the check of the references (D25). A mismatch fails before any write.
    - **A revert is an operation of its own.** Started inside an active scope of the same root with other options (for example inside `withinRevision()`), it is refused, as `runNested()` already does with `VersionSetOptionsMismatchException`; the reason is now stated, not accidental. A revert of another root inside a scope opens that root's own set (D43).
    - **Tests, written first:** the aggregate revert of a record locked by somebody else is refused with nothing written; a write committed between the caller's read of the revision and the revert makes the revert fail with a conflict; a reference deleted after the caller prepared the revert is reported; a revert started inside `withinRevision()` of the same root is refused.

49. **The children without a foreign key go with the record at a hard delete, classified once (decided by Claude on the user's delegation, 2026-10-19; closes the open check of D21 and finding 8 of the independent review).**
    - **Facts, read in the migrations and the code.** Every polymorphic column of the schema was listed. Children of a record that are already removed at its hard delete: the tag links (`HasTags` detaches on `deleted` when the record no longer exists), the media and their files (`InteractsWithMedia` on `deleting`, on a hard delete only), the roles and permissions of a user (Spatie's `HasRoles` on a force delete). Children left behind today: `model_embeddings` (a hard delete removes the translations by foreign-key cascade, without events, so `ContentTranslationObserver` never deletes their embeddings), `core_modifications` of the record (no cleanup), and, for a user, `personal_access_tokens` and `notifications`. `RecordOrigin` is carried in the snapshot (D21, D29).
    - **Rule.** At a hard delete the coordinator (D44) removes, in the same transaction, the children a trait does not already remove: the embeddings of the record (derived data, recomputed after a recovery) and for a user the access tokens and the notifications. Its modifications are not removed (*amended on implementation, 2026-10-19*): an approved hard delete applies a modification whose votes are the record of who approved it, and removing it broke that flow (`OperationApplyTest`); the ones still pending are closed, since they can no longer be applied, and all stay as the approval record, like the votes. The origin keeps the treatment of D29.
    - **Not children, kept:** the approvals and disapprovals a user gave (the audit of who approved, with the approver shown as deleted), `outbox_events` (a transient log), and the ERP references to a source document (`stock_reservations`, `stock_movements`, `journal_entries`), which belong to the referencing document; whether a source document may be hard-deleted at all is a rule of ERP, outside this decision.
    - **So that a new one cannot slip in:** an architecture test lists every polymorphic column in the migrations and fails on one that is not classified as a child (and by what it is removed), a kept reference, or a transient log.
    - **Implemented on 2026-10-19, ahead of the coordinator.** The removal does not wait for D44: it lives where the child is known and runs on the `deleted` event when the row is really gone (the model no longer `exists`), so the coordinator will simply run it inside its transaction. The embeddings in `RemovesEmbeddingsWithRecord`, a trait used by `Searchable` (Scout's trait already owns `bootSearchable()`); the closing of the pending modifications in `HasApprovals`; the tokens and notifications in `UserObserver::deleted()`; the import origin in the listener `RemoveOriginOfDeletedRecord` on `eloquent.deleted: *`, pivots skipped, because an orphan origin maps an external id to a missing local id and the next import of that source fails on it (D29 still reads the origin into the snapshot first). The architecture test is `PolymorphicColumnsAreClassifiedTest`.
    - **Found on the way and fixed:** `UserObserver::deleted()` cleared `license_id` and called `save()` also after a hard delete; with the row gone the model no longer `exists`, so the save inserted the user again. It now does that only for a soft delete.
    - **Tests, written first:** a hard delete of a content leaves no embedding of it and no pending modification, and keeps its modifications; a hard delete of a user leaves no token and no notification; the approvals given by that user stay; the architecture test fails on an unclassified polymorphic column.

50. **`core_versions` is never read as an entity of its own (decided by Claude on the user's delegation, 2026-10-19; implements the rule of D27).**
    - **Facts, read in the code.** `CorePermissions::excludedModels()` excludes `VersionSet` but not `Version`, so `core_versions` gets the generic CRUD permissions: a role granted `core.versions.select` would list the raw history of every record, past the ACL of the root, which D27 forbids ("never by reading `core_versions` on its own").
    - **Rule.** `Version` joins the excluded models: no CRUD permission, no generic endpoint. The history is read only through the root, with `history` and the root's ACL (D27); internal readers such as `TicketTimelineService` query it in code and are unaffected.
    - **Tests, written first:** the permission refresh creates no `core.versions.*` permission and removes one left from before.
    - **Implemented on 2026-10-19:** `Version` is in `CorePermissions::excludedModels()`. A superadmin, who passes every permission check, can still reach the table; the rule binds every other role.
    - **Correction (second recheck, 2026-10-20).** The premise was false: `Version` has been in `PermissionsRefreshCommand::$MODELS_BLACKLIST` since `62061687` (2025-09-19), so no `core.versions.*` permission was generated. The change to `CorePermissions` is redundant but harmless and stays, so that both lists agree.

51. **`OwnerTimestamp` skips repeated touches within a transaction, not within a second (decided by Claude on the user's delegation, 2026-10-19; amends D37).**
    - **The gap.** D37 skipped a touch when the same owner had been touched in the same second by the same process. If the first touch belonged to a transaction that was rolled back, a second, real change in the same second was skipped and the owner's `updated_at` stayed behind, so the incremental search sync would miss it.
    - **Rule.** The helper remembers the owners it touched in the current outermost transaction of the connection and forgets them when that transaction commits or rolls back (Laravel's transaction events); a sync of ten tags still runs one `UPDATE`. Outside a transaction every touch is written.
    - **Savepoints too (second recheck, 2026-10-19).** The owners are remembered per transaction level and forgotten when that level rolls back, a savepoint included, not only when the outermost transaction ends.
    - **Tests, written first:** a sync of ten tags in one transaction runs one `UPDATE`; a touch inside a rolled-back transaction followed by a change in the same second moves `updated_at`; a new savepoint after a rolled-back one touches again.
    - **Implemented on 2026-10-19, with D30 and D37:** `Modules\Core\Support\OwnerTimestamp` (scoped, listening to `TransactionCommitted` and `TransactionRolledBack`) and the trait `TouchesOwnerOnChange` on the four pivots of `Content` (`Categorizable`, `Locatable`, `Contributable`, `Taggable` through the morph map) and on the five translation models; `TaxonomyTranslation` touches the shared `core_taxonomies` row by table and key, its owner class being abstract. `Relatable` is not touched.

## Decisions from the second recheck (2026-10-20)

The second independent recheck (2026-10-20) found one critical and seven important gaps; the user approved the resolutions below the same day. Its verdict was that a plan may be written once C1, I1, I2, I3, I4 and I7 are settled; I5 and I6 are settled as well, because both touch the create migrations of the first slice.

52. **Capture quality depends on whether a transaction was open before the business SQL (user decision, 2026-10-20; answers C1 of the second recheck; amends D47).**
    - **The gap.** D47 counts a set as `atomic` only when `run()` was opened for the root before the business SQL. A translation written alone (`TranslateModelJob`, the importers), a relation mutated from its inverse side, and a second root saved inside an outer transaction (D43) would all be `best_effort`, and one such set withdraws the complete revert of the record until the next baseline: a single automatic translation would be enough.
    - **Rule.** A set is `atomic` when a database transaction on the root's connection was open before the statement that produced its rows. The manager records it when it opens the set, whether the transaction is its own or an outer one it joins. The translation models' `save()`/`delete()` and both sides of the Core relation subclasses (D42) open the transaction before their SQL, with the per-root sets of D43 opened lazily inside it.
    - **The loss is bounded.** After a `best_effort` set, the next atomic write of the same root writes a whole-aggregate baseline (D45 rule 1, D53), so the complete revert comes back from that point on.
    - **A model without an aggregate is not affected.** A versioned model with no translations and no registered relations has only a scalar revert, which does not depend on capture quality; its `save()` gets no extra transaction.
    - **`incomplete` is not written yet** (minor M6 of the recheck): its triggers are not concrete. Milestone 1 writes only `atomic` and `best_effort`; the value stays in the enum.
    - **Tests, written first:** a translation written alone by the job gives an `atomic` set; a `sync()` from the inverse side gives one `atomic` set per affected root; a `best_effort` set followed by an atomic write produces a baseline; a plain versioned model's `save()` stays `best_effort` and its scalar revert still works.
    - **The transaction of the test harness does not count (implementation note, Claude, 2026-10-20).** `RefreshDatabase` runs every test inside a transaction, so "a transaction was open" would hold for every write of the suite and the `best_effort` test above could never fail. The open transaction is counted above the one the harness opened, as Laravel's testing `DatabaseTransactionsManager` already does for the after-commit callbacks (it treats level 1 as the outermost on the connections it wraps); the mechanism is fixed in the plan of Slice 3. Among the tests: one that fails if the harness transaction is counted. `OwnerTimestamp` (D51) has the same trait and its tests reset it between the setup and the change.

53. **A complete anchor is marked by the kind of its set (user decision, 2026-10-20; answers I1; completes D45 and D47).**
    - `VersionSetKind` gains `baseline`. The creation set of a root and the baselines of D22 and D45 are written with it. Rule 2 of D45 and the gate of D47 start from the latest `baseline` set at or before R.
    - **Layout of a baseline set:** the root image as one `SNAPSHOT` row (`created` at creation, `updated` for a later baseline), one `SNAPSHOT` row per locale (`relation_path = translations`, `subject_key = {locale}`), one `created` row per current membership.
    - Completeness is "as written". After a compaction, the cut-off of D46 decides which revisions remain revert targets.
    - **Tests, written first:** a created root's first set has kind `baseline`; a revert to an R with no `baseline` at or before it is refused.

54. **Writes caused by the hard delete do not enter the snapshot set (user decision, 2026-10-20; answers I2; amends D44).**
    - **The gap.** During step (2) of D44 the delete fires the model events: `HasTags` detaches on `deleted`, which the pivot trait of D42 would record as membership rows in the root's active set, and the fallback hooks of `HasVersions` would add a `Deleted` row. Both would pollute the set that `recover()` and the purge read.
    - **Rule.** While the coordinator deletes a root, the root's active set carries a flag, and the fallback hooks and the pivot capture skip that root. Only the rows of D29 step 2 go into the snapshot set. Cascades that reach other roots still open those roots' own sets (D43).
    - **Order (minor M3 of the recheck).** D44 also changes the order of D29, not only its callers: read the state, delete, then write the snapshot. The wording "snapshot first" in D17, C07 and the spec is superseded.
    - **Tests, written first:** the hard delete of a content with tags writes no membership row from the tag detach and no fallback `Deleted` row into its snapshot set.

55. **Row format of the snapshot set (user decision, 2026-10-20; answers I3; completes D29 and D35).**
    - Every row is `SNAPSHOT` with `change_type = deleted`; the image is in `original_contents` and `contents` is empty, as the shipped tombstone already writes it.
    - The root row has no `relation_path`. A locale row has `relation_path = translations` and `subject_key = {locale}`. A membership row has `relation_path` = the relation and `subject_key = {id}`, with the pivot columns declared authoritative (D41) in `original_contents`, otherwise empty. The origin row has `relation_path = origin`.
    - The snapshot set is read only by `recover()` and the purge (D35), never by the revision replay. The origin row is outside reconstruction and outside the epoch fingerprint.
    - **Tests, written first:** the snapshot set of a content with two locales and three tags holds exactly the rows above; `recover()` rebuilds the root, the locales and the membership from them.

56. **Two revert entry points, one implementation (user decision, 2026-10-20; answers I4; amends D48).**
    - **The gap.** `revertToVersion(id)` targets the id of a row, against the rule that a revert targets a revision. Once translations (D28) and membership (D42) write rows whose `versionable` is the root, the id of such a row would apply its fields to the root's columns. It also takes no expected revision.
    - **Rule.** `revertToRevision(int $set, int $expectedRevision, RevertMode $mode)` is the only implementation; `RevertMode` is `Scalar`, `Link`, `OwnedPart` or `Complete`, as in the spec's revert modes. `revertToVersion(int|string $versionId, int $expectedRevision)` stays as a shorthand: it resolves the row's set, refuses a row that is not one of the root's own columns (a non-null `relation_path`), and calls `revertToRevision($set, $expectedRevision, RevertMode::Scalar)`. Both entry points get every check of D48, because they share the implementation. After D14 the name no longer comes from the library.
    - **Tests, written first:** `revertToVersion()` on a translation or membership row is refused; on a root row it gives the same result as a scalar `revertToRevision()`; a stale expected revision is refused on both.

57. **Coverage is declared on each descriptor (user decision, 2026-10-20; answers I7; completes invariant 4).**
    - `RelationDescriptor` gains a `coverage`, `complete` or `partial`, declared by the module that registers the relation; it is `complete` only when that relation's mutation and revert paths are covered by tests. The default is `partial`.
    - The root's coverage is `complete` when every registered descriptor is. It is part of the epoch fingerprint (D33), so changing it starts a new epoch, and it is evaluated at revert time with the computed labels of D47: a complete revert is offered only with complete coverage.
    - **Tests, written first:** a root with one `partial` descriptor is refused a complete revert and allowed a link revert of the complete ones; changing a descriptor's coverage changes the fingerprint.

58. **`reverted_from_set_id` is set to null when its target is removed (user decision, 2026-10-20; answers I5; amends D19 and D29).**
    - The self foreign key becomes `nullOnDelete()` in the create migration. A `Revert` set whose target was purged or compacted shows that its target is no longer in the history. D29 step 3 no longer nulls the column first.
    - This is the only change a set undergoes after it is written, and the database makes it, not the application.
    - **Tests, written first:** removing the target set of a `Revert` set leaves that set with a null `reverted_from_set_id`.

59. **A compaction refuses a stretch that holds a non-atomic set (user decision, 2026-10-20; answers I6; amends D18 and D46).**
    - Before writing, the compaction reads the capture of every set of the stretch up to the cut-off; a `best_effort` or `incomplete` one refuses the whole compaction and lists the sets. Otherwise the merged row at R would make the stretch look `atomic` and pass the gate of D47.
    - **Tests, written first:** a stretch with one `best_effort` set is refused and nothing is written; an all-atomic stretch is compacted.

60. **A revert set never carries a baseline (decided by Claude on the user's delegation, 2026-10-20; found while checking the plan against D52–D59; completes D45, D52 and D53).**
    - **The gap.** D45 writes a baseline in the set of the first write after the event, D53 marks that set with the kind `baseline`, and D52 makes the next atomic write after a `best_effort` set carry one. When that write is a revert, the set would have to be `revert`, with its `reverted_from_set_id` (D16), and `baseline` at once, and the kind is one column.
    - **Rule.** The manager decides the kind when it opens the set of a root: an ordinary set is stored as `baseline` when a baseline is due for that root (its creation, the first write after re-enabling, D22, after an epoch change, D33, after a `best_effort` set, D52). A `Revert` set never carries one: the baseline stays due and the next atomic write that is not a revert writes it.
    - **Why it is safe.** Until that baseline exists, every revision from the `best_effort` set onwards is still refused as a complete revert by the gate of D47, since the latest `baseline` at or before it precedes the `best_effort` set; the revert itself runs with the modes the gate allows. The loss bounded by D52 lasts one write longer at most.
    - **The stored kind is not an option of the scope.** The options of the scope keep the ordinary kind; only the stored row says `baseline`. A nested `run()` with ordinary options therefore joins the set, and the option comparison of `runNested()` does not refuse it.
    - **The set written by `recover()` is a `baseline`** (D29): it is the new creation anchor of the recovered root.
    - **Tests, written first:** after a `best_effort` set, a scalar revert writes a `revert` set without baseline rows and the next save writes a `baseline` set; a complete revert to the revert set is refused and one to the baseline set is allowed; a nested `run()` with ordinary options inside a set stored as `baseline` joins it; the set written by `recover()` has kind `baseline`.

61. **A revert never moves a record into or out of the bin (decided by Claude on the user's delegation, 2026-10-20; found while planning Slice 2; completes D20).**
    - **The gap.** The state rebuilt at a revision (C08) holds the deleted-at column of that moment, because a model with no `$versionable` list versions every column but the excluded ones. A revert to a revision taken while the record was in the bin would trash a live record; a revert of a record in the bin to an earlier revision would take it out.
    - **Rule.** The deleted-at column is outside what a revert writes: the record stays where it is. "Restore" is reserved for the bin (D20), so leaving it is the bin's own operation, with its own permission and its own `restored` row; a revert is an operation on the content.
    - **Tests, written first:** a revert to a revision before a trash and a restore leaves `deleted_at` null; a revert of a record in the bin keeps it in the bin.

## Evidence verified in code (2026-10-09)

Verified by running a throwaway test (deleted afterwards) against the `HasVersions` + `Core\Models\Version::revert()` path, `DIFF` strategy, SQLite:

- **Column added after the version:** the restore keeps the current value of the new column. Compatible.
- **Column dropped after the version:** `revertToVersion()` fails with a raw `QueryException` (`no such column`). It neither skips nor reports the column, so a check of our own is required before the restore (basis of D13).
- **Multi-step `DIFF` is not a point-in-time restore.** With versions `{title:T1,subtitle:S1}` (snapshot), `{title:T2}`, `{subtitle:S2}`, `{title:T3}`, reverting to the third one yields `title=T3, subtitle=S2`, not `T2 / S2`: the restore applies only the columns the target version itself changed on top of the *live* row, and every other column keeps its current value. The replay loop merges each step over the live row instead of accumulating. This is a defect in the restore mechanism itself, independent of schema changes, and it invalidates "restore the state as of revision R" for `DIFF`. Registered as C08.
- **The `Created` row is not a complete image.** `createInitialVersion()` stores `filterVersionableImage($model->getAttributes())`, that is only the attributes assigned at insert. Real data confirms it: the creation snapshot of a `Content` holds `id, valid_to, entity_id, valid_from, order_column, presettable_id, shared_components` and lacks every column that took its value from a database default (for example `extended_type`, the lock columns). A `SNAPSHOT` written after a `refresh()` (as `createSnapshotVersion()` does) does hold them. A restore built on this anchor cannot know the original value of a defaulted column that changed after the target revision. The anchor invariant of D18 therefore also requires the creation row to be read back after the insert. Registered as C09.

### Write-path census of CMS `Content` (read-only code survey, partly unverified)

Filament, the generic `CrudService`, imports, the AI translation job and observers all go through Eloquent; relations are never versioned (`Content` declares no `versionedRelations()`); a translation edit versions the translation row but not the `Content`; saving a content with its translations is not atomic. Known bypasses: `ClearExpiredModels` (builder-level hard delete, no tombstone, silent foreign-key cascades), lock columns, `RecordOrigin`, media. Separate defect found on the way: a soft delete of a `Content` detaches all its tags (`HasTags`, `deleted` handler) and restore does not reattach them.

### State of the code when the discussion started

`subject_version_id` does not exist yet in migrations or code, no production model uses `HasVersionedRelations`, and `RelationOwnership::Owned` is skipped by `restoreToRevision()`.

## Critical findings

### C01 — Best-effort capture cannot satisfy the same-transaction invariant

**Affected sections:** `Non-goals`, `Capture flows / Unwrapped best-effort flow`, and decision 2.

**Evidence:** model version writes currently run from `updated` and `deleted` callbacks. Without an already active version-set transaction, `VersionWriter` opens a transaction only after the business query has completed.

**Failure scenario:** a pivot insert commits in autocommit mode and the process terminates before revision advancement and checkpoint persistence. Leaving the revision unchanged makes the existing revision describe a state that is no longer live. Advancing it in the business query instead creates a detectable revision gap but violates the current rule that no revision may exist without a history row.

**Violated invariants:** 2, 3, and 4.

**Proposed resolution:** a registered aggregate mutation must enter a Core-owned revision scope before its first SQL statement. Core opens a local transaction automatically when no outer transaction exists. A path that cannot be intercepted before SQL is untracked, invalidates complete coverage, and cannot be repaired retrospectively by an observer. Best-effort history may remain an audit facility but cannot qualify for complete aggregate restore.

**Resolution (decision, 2026-10-09):** closed by D6. Writes that go through Core's supported mutation paths run inside an aggregate revision scope that opens a local transaction when the caller has none, so business rows and history commit together; every other write is labelled `best-effort` and never offers "restore everything". Precondition found by the census: the scope must exist on the paths that lack it today, namely Filament saves, `CrudService::insert`, and the save of a root with its translations (the same point as D10). Closure still needs the design spec aligned and this evidence rechecked once implemented.

### C02 — The two concurrency tokens have contradictory consumers

**Affected sections:** `Goals`, `Concurrency`, and decision 1.

**Failure scenario:** Anna opens a scalar form at aggregate revision 10. Marco attaches a category, advancing only the aggregate revision to 11. If Anna's scalar update supplies expected aggregate revision 10 as required by `Concurrency`, it is rejected even though decision 1 introduced a separate `lock_version` specifically to avoid that rejection.

**Violated invariant:** the stated separation between editing conflicts and aggregate restore coordinates.

**Proposed resolution:** scalar forms use `lock_version`; aggregate restore and commands that replace aggregate membership use `aggregate_revision`; commands that modify both scalar state and authoritative relations explicitly carry both tokens. The API must not describe either token as a substitute for the other.

**Resolution (decision, 2026-10-09):** closed by D23. Three rules. (1) `lock_version` covers what the form edits: the root and, since D10, its translations. A translation write increments the root's `lock_version` at the same point where D10 creates the owner's version; relation changes (categories, tags) do not, as long as the form does not show them. (2) The aggregate revision stays separate and covers everything, relations included. (3) The two counters never replace each other: `lock_version` rejects a stale save, the revision rejects a restore towards a superseded state. Evidence of the gap closed by rule 1, deduced from the code and not reproduced with a test: `ContentTranslation` and `HasTranslations` do not touch `lock_version` and a translation-only change leaves the `cms_contents` row clean, so a form opened before a title edit would overwrite it without a conflict. Closure still needs the design spec aligned and this evidence rechecked once implemented.

### C03 — The revision head does not survive hard delete

**Affected sections:** `Root revision provider`, `Restore modes`, and decisions 3–4.

**Failure scenario:** a root reaches revision 12 and is hard-deleted. A later recreation or restore either starts again from the column default or has no row on which to perform the compare-and-swap. Monotonicity and uniqueness are lost.

**Violated invariants:** 2, 3, and 11.

**Proposed resolution:** either keep an authoritative aggregate head/tombstone outside the live root row, or explicitly make hard-deleted roots non-restorable. A root-local `aggregate_revision` column alone cannot support both hard delete and monotonic restore history.

**Resolution (decision):** closed by D16, D17, D29 and D44. There is no revision column on the root: the revision is the version set (D16), so nothing is lost with the row. A hard delete writes, in the transaction of the delete, a snapshot set of the whole aggregate and removes the edit history; the root is recoverable from it until the snapshot expires (D35), with the same key, refused when the key is taken (D44).

### C04 — The revision boundary and canonical checkpoint are undefined

**Affected sections:** `Proposed history representation`, `Root checkpoints`, and `Required tests`.

**Failure scenario:** one logical mutation saves the root, detaches two pivots, and attaches three. The draft does not say whether this produces one revision or six, when the revision is allocated, or which history row is the authoritative state after the mutation.

**Violated invariants:** 2, 3, 5, and 11.

**Proposed resolution:** one managed operation allocates at most one revision per affected root. An implicit standalone relation adapter wraps the entire public mutation such as `sync()`, not each internal pivot event. Every revision has exactly one canonical checkpoint protected by a database unique constraint on stable aggregate identity plus revision. Scalar, child, and pivot audit rows reference that checkpoint.

**Resolution (decision):** closed by D16, D18, D28, D42 and D43. One operation is one version set per affected root (D43), and a `sync()` is one call, one revision (D42); the unique `(version_set_id, sequence)` orders the rows. No canonical checkpoint per revision: the state at R is rebuilt from the nearest complete anchor (D18, C08, D45).

### C05 — Coverage can become false across configuration changes

**Affected sections:** `Coverage`, `Root revision provider`, `Retention`, and decision 2.

**Failure scenario:** versioning is disabled at revision 7, business data changes, and versioning is later re-enabled. The root still advertises revision 7 although checkpoint 7 describes an older state. Adding or changing a relation descriptor has the same problem: an old `complete` flag is interpreted against a newer aggregate definition.

**Violated invariants:** 3, 4, 12, and 13.

**Proposed resolution:** persist a history epoch and an aggregate-definition version or canonical hash. Disabling capture, detecting a bypass, or changing authoritative descriptors closes the current epoch. Re-enabling complete restore requires a new full baseline checkpoint. `complete` is always relative to the stored definition version, never a timeless boolean.

**Resolution (decision, 2026-10-09):** closed by D13 and D22. Disabling versioning deletes the history of the table and enabling it forces a snapshot at each record's first save, so a stale revision can no longer be advertised after a period without capture. A change of columns or of the relation descriptors opens a new epoch with a lazy baseline (D13); a restore towards an older epoch is labelled `schema-mismatch` or `definition-mismatch` and never offers "restore everything". `complete` is relative to the epoch's definition, not a timeless boolean. A bypassing write (case d of the discussion) remains undetectable in general and is labelled `best-effort` (D6). Closure still needs the design spec aligned and the evidence rechecked once implemented.

### C06 — Some relations affect more than one root

**Affected sections:** `Relation descriptors`, `Managed atomic flow`, and `Multi-connection policy`.

**Failure scenario:** removing a symmetric `Content A ↔ Content B` relation changes the authoritative vector of both contents. Advancing only A leaves B's revision stale; advancing both conflicts with the current one-root set and locking model. Moving an owned child between roots has the same shape.

**Violated invariants:** 2, 3, and 7.

**Proposed resolution:** the first milestone accepts only descriptors with exactly one authoritative root. Inverse views are explicitly non-authoritative. Symmetric relations, ownership transfers, and other multi-root mutations fail descriptor registration until a separate ordered multi-root protocol exists.

**Resolution (decision, 2026-10-09):** closed by D24, which takes the resolution proposed above. Milestone 1 accepts only descriptors with exactly one authoritative root; symmetric relations, ownership transfers and other multi-root mutations are outside the guarantee and fail descriptor registration. Closure still needs the design spec aligned and the evidence rechecked once implemented.

### C07 — Force delete is not centralized

**Affected sections:** decisions 3–4.

**Evidence:** production paths include Core CRUD deletion, the generic expired-model command, CMS media helpers, and module services. Builder-level force deletes can bypass per-model events entirely.

**Failure scenario:** a bulk force delete cascades registered pivot rows before Core drains and checkpoints them. No callback can reconstruct the destroyed memberships afterward.

**Violated invariants:** 3, 4, and 13.

**Proposed resolution:** complete aggregates use a mandatory deletion coordinator. Bulk and raw deletes on registered roots or subjects are rejected or explicitly invalidate coverage. Database cascades remain the final referential-integrity guard, not the normal capture mechanism.

**Resolution (decision, 2026-10-09):** closed by D6 and D17. Hard deletes that pass through Core go through one coordinator that writes the full snapshot first (D17) and removes history and root in one transaction. Builder-level deletes and database cascades that bypass Core stay `best-effort` and are declared so. `ClearExpiredModels` stops deleting at builder level without a snapshot (chunked is acceptable). Closure still needs the design spec aligned and this evidence rechecked once implemented.

### C08 — A `DIFF` restore does not reconstruct the state as of a revision

**Affected sections:** `Goals` ("restore the aggregate to the state represented by root revision `R`") and the scalar part of every restore.

**Evidence:** both restore entry points, `HasVersions::revertToVersion()` and `HasVersionedRelations::restoreScalarState()` (the scalar part of `restoreToRevision()`), call `Version::revert()`. In `Core\Models\Version::revertWithoutSaving()` the `DIFF` loop merges each earlier version over the *live* row instead of accumulating, so the result is the live row plus only the columns the target version itself changed. Verified with a throwaway test on 2026-10-09 (see "Evidence verified in code"): versions `{title:T1,subtitle:S1}`, `{title:T2}`, `{subtitle:S2}`, `{title:T3}`; reverting to the third gives `title=T3, subtitle=S2` instead of `T2 / S2`. The relation membership part of the restore is replayed correctly; only the scalar part is wrong.

**Failure scenario:** a user restores a content to "yesterday". Memberships come back as they were, scalar fields come back only if they were touched in that exact version, the rest stay as today. The restore control promises a state that never existed.

**Violated invariants:** the restore coordinate must denote a reconstructable state.

**Proposed resolution (requirement agreed in discussion, 2026-10-09):** the state as of revision R is reconstructed by walking the version rows back from R and stopping at the nearest `SNAPSHOT` (a `Created` row is one), then applying the following rows forward in revision order, last write wins per column, and writing the result once. Nothing before that snapshot is read or merged. The walk is driven by each row's own stored strategy, not by the model's current one, since a history mixes `DIFF` and `SNAPSHOT` rows. Both entry points use this one function. With D13 (lazy baseline after a schema change) the walk never crosses a schema epoch. Written in Core's own `Version` (D14), with no compatibility constraint towards the library.

**Resolution (decision, 2026-10-09):** the requirement stated above is agreed and sits in Core's own `Version` (D14): the state as of revision R is rebuilt from the nearest complete `SNAPSHOT` at or before R, driven by each row's stored strategy, and written once; `revertToVersion()` and `restoreToRevision()` (to be renamed `revertToRevision()`, D20) share the one function. Closure still needs the design spec aligned and a test reproducing the failing case of the evidence once implemented.

### C09 — The creation snapshot is not a complete image of the record

**Affected sections:** C08, D18 (anchor invariant).

**Evidence:** `HasVersions::createInitialVersion()` stores `filterVersionableImage($model->getAttributes())`, that is only the attributes assigned at insert. Verified on development data: the creation snapshot of a `Content` holds `id, valid_to, entity_id, valid_from, order_column, presettable_id, shared_components`, and lacks every column that took its value from a database default (for example `extended_type`, the lock columns). A `SNAPSHOT` written after a `refresh()` (`createSnapshotVersion()`) does contain them.

**Failure scenario:** a column with a database default is never assigned at insert, then changes after revision R. A restore to R starts from the creation anchor, which does not hold the column, and no later row can supply its original value in a forward replay; the restored document keeps today's value while claiming to be R.

**Violated invariants:** the anchor of C08 and D18 must be a complete image of the versionable columns.

**Proposed resolution:** read the row back after the insert (or fill the defaults) before writing the `Created` row; add the check "the earliest retained row is a complete image" to the verification of D18, comparing it with the column list of the table at that epoch (D13).

**Resolution (decision, 2026-10-09):** the creation row is read back after the insert so that it is a complete image (D18 anchor invariant); the forced snapshot at the first save after re-enabling (D22) is likewise complete. Closure still needs the design spec aligned and the check of D18 once implemented.

## Important findings

### I01 — Stable identities are incomplete

The example uses local numeric IDs, while decisions rely on immutable natural identity and cross-connection version references. CMS categories do not currently expose a suitable immutable natural key.

**Proposed resolution:** add immutable stable IDs for aggregate roots and reference subjects that participate in aggregate history; add a stable UUID to each version independently from its database primary key; identify a history store with a persisted UUID rather than a Laravel connection name. A cross-store locator is `(history_store_uuid, version_uuid)`.

**Deferred (decision, 2026-10-09):** see D25. Not part of milestone 1.
### I02 — Missing references cannot produce a complete restore

Decision 3 says missing shared subjects are skipped and reported, while goals and tests require reconstructability.

**Proposed resolution:** preflight a complete restore and fail before mutation when a required reference is missing. A separately requested partial link restore may skip and report. An `owned` child may be recreated only when its descriptor, authorization, and retained snapshot explicitly allow it.

**Resolution (decision, 2026-10-09):** closed by D2 and D25. A complete restore is refused when a required reference no longer exists; a restore run with `$force` skips and reports the missing subjects, and the result is labelled partial, never "restore everything". This is the behaviour `restoreToRevision()` already has (`MissingRestoreSubjectException` unless `$force`). Closure still needs the design spec aligned and the evidence rechecked once implemented.
### I03 — Normalize relation-vector items

JSON-only vectors cannot enforce foreign keys, indexed reverse lookup, or retention reachability. Full scans are particularly unsuitable for shared-subject deletion and cross-version retention.

**Proposed resolution:** use a canonical checkpoint header and normalized relation-item rows. JSON remains acceptable for descriptor-defined stable key payloads and pivot state, accompanied by deterministic hashes where indexed lookup is required.

### I04 — Checkpoints need full scalar state

DIFF rows are useful for audit but create complex and fragile checkpoint reconstruction and retention dependencies.

**Proposed resolution:** every canonical aggregate checkpoint stores the full scalar root state represented by the revision. Existing DIFF or SNAPSHOT record versions remain audit rows and can use their configured strategy independently.

**Resolution (decision, 2026-10-09):** closed by C08, C09 and D18. The restore reconstructs the state from the nearest complete `SNAPSHOT` at or before the target and applies the following rows forward; every retained history begins with a complete image. Audit rows keep their own strategy. Closure still needs the design spec aligned and the evidence rechecked once implemented.
### I05 — Restore security and side effects are unspecified

The predecessor spec required authorization, approval-aware application, scoped restoration mode, and after-commit reconciliation. The successor draft does not preserve those contracts or define who may read potentially sensitive history.

**Proposed resolution:** carry those contracts forward explicitly. Authorize history visibility and each restore mode; preserve approval attribution; never use global `withoutEvents()`; reconcile derived data after commit; preview shared-record impact before any exceptional deep restore.

**Resolution (decision, 2026-10-09):** closed by D26 and D27. Authorization: new verbs `history` (read the history, through the root and its ACL) and `revert` (implies `history`); recovery of a hard-deleted root needs `restore`. Approvals: a revert follows the model's approvals like an update, with the actor attributed. Side effects: model events fire so search and embeddings realign after commit; automatic translation is suppressed during a revert by a scoped context, never by a global `withoutEvents()`. Closure still needs the design spec aligned and the evidence rechecked once implemented.

### I06 — Review and epoch evidence are not durable

The target spec references finding `C2` without preserving the original finding list. Coverage evidence is also described but has no durable descriptor version or proof record.

**Proposed resolution:** keep this log as the finding ledger and store aggregate-definition identity with every checkpoint. Each review pass updates finding state and cites the spec revision that resolved it.

**Resolution (decision, 2026-10-09):** closed by D13, D22 and this ledger. The schema epoch carries a fingerprint of the definition (columns and versioned relations) and every version row stores its epoch; each review pass updates the state here. Closure still needs the design spec aligned and the evidence rechecked once implemented.
## Changes to carry into the design spec (list prepared 2026-10-09; sections A, B and C applied the same day, section D designed by D28 to D51)

Target: `2026-07-29-revision-centric-aggregate-history-design.md`. Grouped by what the spec said versus what the decisions say. Sections A, B and C were applied to the spec on 2026-10-09; the finding texts above keep the section names the spec had at the time of the review (for example "Root checkpoints", now "Anchors and reconstruction").

### A. Where the spec contradicts a decision (must change)

| Spec section | Says today | Decision | Change |
|---|---|---|---|
| Milestone 1, C03 | Hard-deleted roots are non-restorable; the SNAPSHOT tombstone is audit-only | D17 | A hard-deleted root is restorable from its full snapshot until the snapshot expires; history is dropped, snapshot kept |
| Milestone 1, storage; invariant 10; Owned-state restore | `subject_version_id` points to the child's own version for `owned` relations | D10 | Translations have no history of their own; their state lives in the owner's revision. The pointer applies only to an owned child with an independent history (none known) |
| Root revision provider; History representation; Root checkpoints | A durable aggregate head, a canonical checkpoint per revision with a full relation vector | Milestone 1, D16, D18 | State that the revision is the version set (no head in milestone 1) and that a "checkpoint" is the anchor of D18/C09, not a per-revision full copy |
| Invariant 11 "a restore never rewrites historical rows" | Absolute | D18 | Add the one sanctioned exception: compaction rewrites the row at the cut-off (`compacted_at`) |
| Terminology, Restore modes, Goals | "Restore" is used for both the bin and the revision | D20 | "Restore" is the bin only; "revert" is the operation on revisions (`revertToRevision()`) |
| Unwrapped legacy audit flow; invariant 13 | An unwrapped write closes the epoch; re-enable needs an atomic baseline | D6, D13, D22 | Best-effort revisions are labelled and never offer "restore everything"; disabling versioning drops the history; re-enabling forces a snapshot at the first save |
| Decisions still open (1 to 10) | Open | D6, D17, D18, D23, D24, D25 | Answer each (see section C below) |
| Prior decisions under review (2 to 4) | Reopened by C01 to C07 | D6, D17, D24 | Replace by the resolved text |
| Concurrency | `lock_version` for scalar forms | D23 | `lock_version` covers the root and its translations; a translation write increments it; relations do not |

### B. What the spec lacks (to add)

- **Capture quality and labels:** add `partial`, `schema-mismatch`, `definition-mismatch` next to `atomic`, `best_effort`, `incomplete` (D13, I02).
- **Schema epoch** per table with a definition fingerprint, a column on every version row, and the lazy baseline (D13); the walk back never crosses an epoch.
- **Reconstruction rule** of C08 and the complete-anchor rule of C09 (nearest complete `SNAPSHOT` at or before R, applied forward, written once; the anchor is read back after the insert).
- **Compaction** (D18): merged diff as default, snapshot rewrite as an option, anchor required, cut-off by age or count, manual and off by default.
- **Hard delete** (D17): coordinator, one transaction, snapshot first, atomic with history removal, second retention period, purge command, origin data carried in the snapshot, limits of a recovery (comments, ratings, media files).
- **Excluded from the guarantee**, declared: `RecordOrigin`, lock columns, media (milestone 1), comments and ratings, symmetric `related()` (D8, D21, D24).
- **Versioning setting**: translation models are not versioned; the child follows the root; switching off needs a user confirmation and keeps the hard-delete snapshots (D10, D22).
- **Permissions and side effects** (D26, D27): `history`, `revert`, approvals, locks, translation suppression during a revert.
- **Tables and columns:** `core_versions`, `core_versions_sets` (D15), no `deleted_at` / `is_deleted` / `updated_at`, `compacted_at` (D19), `change_type` values (D20), `reverted_from_set_id` foreign key versus purge and compaction.
- **Overtrue detached** (D14): what Core absorbs, attribution, config keys, requirement removed.
- **Required tests:** reproduction of the multi-step `DIFF` failure; an anchor with a defaulted column; each schema-drift case; compaction leaves every retained revision identical (both variants); the anchor invariant check; `lock_version` increments on a translation write; a revert does not trigger automatic translation; hard delete is atomic and leaves no orphan origin; `history` and `revert` permissions; the sequence-gap check without `withTrashed()`.
- **Review and approval gate:** nine Critical findings now, not seven; the two gaps outside the document (Filament page guard, renderable for `StaleModelLockingException`) still stand and now matter for D23.

### C. Answers to the "Decisions still open" of the spec

1. Candidate A, atomic scope with fail-closed unsupported writes: adopted as option C (atomic where Core writes, labelled `best-effort` elsewhere), D6.
2. Durable head across hard delete: no head in milestone 1; a hard-deleted root is restorable from its snapshot (D17).
3. Normalized relation items: confirmed (I03, milestone 1).
4. Globally stable identities: deferred (D25, I01).
5. Full scalar state on every canonical checkpoint, with DIFF/SNAPSHOT kept as audit strategies: D18, C08, C09.
6. Best-effort as audit-only: yes, permanently without "restore everything" (D6).
7. Bypasses: rejected or labelled `best-effort` as in D6, D17; disabling versioning is a purge (D22).
8. Revision provider for models without optimistic locking: the two counters stay independent (D23).
9. Retention reachability: anchors and the compaction rule (D18), hard-delete snapshots (D17).
10. CMS category semantics: categories, tags, locations and contributors are `reference` (D2); `related()` is outside the guarantee (D24); no pivot attribute is authoritative, membership only (D31, D41).

### D. Design work that does not exist yet and the spec must still produce

1. **The hook that creates the owner's version when only a translation changes, and the grouping of the writes of one operation into one revision** (D10, mandatory).
2. The aggregate revision scope on the paths that lack it today (Filament, `CrudService::insert`, root plus translations).
3. The hard-delete coordinator and the reworked `ClearExpiredModels`.
4. The epoch fingerprint and its detection (D33: at the first write per process and per class).
5. The scoped revert context that suppresses automatic translation.
6. The pivot attributes that are authoritative for each CMS relation: none, membership only (D31, D41).

### E. Not part of the spec but surfaced and still open

Nothing. Closed since: whether forms show relations (D40); whether a revert on a record locked by another user is refused (D39); the tag detach on soft delete (fixed: `HasTags` detaches only when the record no longer exists); what `versions:compact` does until it is redefined (D38); the config key and schedule of the hard-delete purge and the metadata kept in a snapshot (D35).

## Candidate resolution paths

*Historical (2026-08-04). Kept for the reasoning; the adopted design is the spec as aligned with D1–D51. The durable aggregate head and the canonical checkpoint per revision described here were not adopted (D16, D18).*

### A. Core-owned atomic revision scopes — recommended

Core intercepts every supported aggregate mutation before SQL and opens a local transaction if necessary. One canonical checkpoint is persisted with the business mutation. For the one-root milestone, an explicit version set owns one aggregate revision scope and all nested writes join it; without a version set, one supported public mutation call owns one implicit scope. Callers do not need to create a database transaction themselves.

This provides truthful restore semantics but requires registered mutation adapters and a fail-closed policy for raw or bulk writes.

### B. Detectable best-effort gaps

The business query advances the aggregate revision, and history follows afterward. A crash leaves a detectable missing revision. Restore remains available only up to the last intact checkpoint and the aggregate is visibly degraded until repaired.

This supports more legacy writes but cannot promise complete restore for those mutations.

### C. Database triggers or transactional outbox reconstruction

The database records mutations independently from Eloquent. This covers raw writes better but introduces database-specific trigger logic, makes semantic relation ownership harder to express, and still requires a later checkpoint builder.

This is not recommended as the generic multi-database baseline.

## Eloquent integration without rewriting every call site

*Historical (2026-08-04). Kept for the reasoning; the adopted design is the spec as aligned with D1–D51. The durable aggregate head and the canonical checkpoint per revision described here were not adopted (D16, D18).*

Candidate A does not require adding `DB::transaction()` around every existing caller. It requires a small number of framework boundaries that run before SQL:

1. Core's base model persistence boundary opens or joins a revision scope for a registered aggregate root or owned model. Existing `save()`, `create()`, `update()`, `delete()`, and `restore()` calls continue to use Eloquent.
2. A version-aware `BelongsToMany`/`MorphToMany` adapter wraps public relation mutations such as `attach()`, `detach()`, `sync()`, and `updateExistingPivot()`. The entire `sync()` is one scope; internal pivot saves join it instead of creating one revision per row.
3. Core's Pivot/MorphPivot bases join the active descriptor-owned scope and provide direct-pivot protection.
4. A deletion coordinator owns force-delete and relation draining for registered aggregates.
5. Query-builder bulk updates/deletes, application raw SQL, `saveQuietly()`, and database cascades cannot silently qualify as complete capture. They are prohibited on complete aggregates or require an explicit maintenance path that closes the current history epoch.

This concentrates refactoring in Core persistence boundaries and module relation declarations. It does not make arbitrary SQL issued by an operator or external process interceptable, and the design must not claim otherwise; direct database writes remain outside the completeness guarantee.

## Recommended target flow

*Historical (2026-08-04). Kept for the reasoning; the adopted design is the spec as aligned with D1–D51. The durable aggregate head and the canonical checkpoint per revision described here were not adopted (D16, D18).*

1. A scalar save, relation adapter, restore, or deletion coordinator enters an aggregate revision scope.
2. Core opens or joins the root connection transaction before the first business query.
3. Core locks the durable aggregate head and validates the relevant concurrency token.
4. The mutation executes.
5. If nothing authoritative changed, the scope exits without allocating a revision.
6. Otherwise Core allocates one root revision, writes audit versions, writes one full checkpoint, and writes normalized relation items.
7. If an explicit version set is active, all nested mutations of the one root share this scope and the checkpoint and audit rows carry its operation identity.
8. The local transaction commits business state and history together.
9. After-commit handlers reconcile caches, search, projections, and permitted external effects.

Any path unable to enter at step 1 is not complete aggregate versioning. It may retain scalar audit history, but it cannot advertise a complete restore point.

## Closure gate

Before this review can close:

1. the user selects or amends one candidate resolution path;
2. the design spec incorporates that path without contradicting its invariants;
3. every Critical finding is resolved or explicitly removed from the milestone by narrowing scope;
4. Important findings have accepted dispositions;
5. another reviewer rechecks the revised spec and this log;
6. only then may an implementation plan be written.
