# Core Versioning Rebuild — Implementation Plan

> **For agentic workers:** execute task by task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** Implement the approved revision-centric aggregate history (decisions D1–D60) in Core, slice by slice, replacing the milestone-1 code where the design differs.

**Spec:** `docs/superpowers/specs/2026-07-29-revision-centric-aggregate-history-design.md` (approved 2026-10-20). **Decision ledger:** `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md` (D1–D60). Supersedes the plan `2026-08-06-core-versioned-relation-membership.md` and, for Core, `2026-07-21-core-version-set-foundation.md`.

**Architecture:** The order follows the second recheck (2026-10-20): first one schema-and-absorption block applied with a single `migrate:fresh` (D12), then reconstruction, the version-set manager, the capture wrappers, epochs and baselines, revert, purge and recovery, compaction last. This file details **Slice 1** task by task; every later slice gets its own detailed plan when it starts, linked from its tracking item below, and this file closes when all slices are delivered.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. Modules: `Core` (all slices), `ERP`, `MES`, `SAO` (import swap of Slice 1, timeline of Slice 9), `CMS` (D8 in Slice 2, pilot in Slice 10).

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; explicit types; braces on every control structure; PHPDoc over inline comments; `#[Override]` on overrides.
- Core stays generic: no CMS class or table names in Core contracts.
- No data migration: the create migrations are edited in place and the database is rebuilt with `migrate:fresh` (D12). Existing history is discarded.
- Tests first for every behaviour; run the narrowest files with `php artisan test --compact <path>` from `laraplate/`; `vendor/bin/pint --dirty --format agent` before each commit.
- The modules are git submodules: commit inside each module repository (`Modules/Core`, `Modules/ERP`, ...) and the docs inside `laraplate/`. Use `/usr/bin/git` (the `rtk` wrapper drops lines from git output).
- Removing the `overtrue/laravel-versionable` requirement changes the dependencies: confirm with the user immediately before running the composer step (Task 4).
- Enum case names of the absorbed `VersionStrategy` stay `DIFF` and `SNAPSHOT` (not TitleCase), so the 40 call sites only change their import.

## Disposition of the commits already shipped

Commits in `Modules/Core` unless noted.

- **Keep:** `1e9f471a` (D38, `versions:compact` refuses), `1aaef0d4` (D39, lock check of `revertToVersion()`), `2f4d58da`, `a0a2eb80` (D49, children without a foreign key), `3ae0f748` (D50), `17c6c81f` and CMS `605d946` (D30, D37, D51: `OwnerTimestamp` and `TouchesOwnerOnChange`, delivered ahead of Slice 4), `c05cf32` (the `Revert` set with `reverted_from_set_id`, D16), `ac2114d`, `602f87a` (`RelationOwnership`, `RelationDescriptor`, `versionedRelations()`; the descriptor gains `coverage` in Slice 4, D57).
- **Change:**
  - `83112c2` (delete semantics): the soft delete becomes `trashed` and the restore `restored` in Task 7 (D20). The hard-delete tombstone stays until Slice 4 replaces it with the snapshot set of D29/D55.
  - `19e86c1` (`restoreToRevision()`): it becomes `revertToRevision()` with `RevertMode` and the checks under the lock in Slice 6 (D48, D56). Its membership replay and `MissingRestoreSubjectException`/`RestoreReport` stay (D2, D25).
  - `86befdc` and the later RAG doc edits: Task 9.
- **Revert (replace):** `04d30ce`, `b131e6e`, `35e7b8f`, `80b40c8` (`attachVersioned`, `detachVersioned`, `syncVersioned` and their helpers) are removed in Slice 4, replaced by the capture from pivot events (D42). `versionedRelationMembership()` survives, with membership rows that carry pivot attributes only where a column is declared authoritative (D41).

## Slices

Each slice is delivered, reviewed and closed before the next starts.

- [x] **Slice 1 — Schema and library absorption** (D14, D15, D19, D20, plus the columns of D47/D52, D53 and D58): this file, Tasks 1–9.
- [x] **Slice 2 — Reconstruction and complete anchors** (C08, C09, D53 creation set of kind `baseline`, D21 lock columns excluded, D8 comments and ratings never versioned, D10 translation models not versioned, D61 a revert leaves the bin column alone): `docs/superpowers/plans/2026-10-20-core-versioning-slice-2-reconstruction.md`.
- [x] **Slice 3 — Version-set manager** (D34 `lockExistingRoot()` compares and keeps relations, D43 one set per root in one transaction, D52 capture recorded by the manager, counting the open transaction above the one `RefreshDatabase` wraps around each test; D60 the stored kind is decided when a root's set opens and is not part of the scope options): `docs/superpowers/plans/2026-10-22-core-versioning-slice-3-version-set-manager.md`.
- [x] **Slice 4 — Capture wrappers** (D34 `save()`/`delete()` scope, D28 translation observer and `withinRevision()`, D42 relation subclasses and pivot trait replacing the explicit methods, D41, D57 coverage, D24 multi-root rejected, then D44/D29 hard-delete coordinator with D54/D55): `docs/superpowers/plans/2026-10-23-core-versioning-slice-4-capture-wrappers.md`, in three parts (save scope and translations, relations, hard delete).
- [ ] **Slice 5 — Epochs and baselines** (D13, D32, D33, D45, D22 switching versioning off and on, D52 a `best_effort` set makes a baseline due, D60 never in a revert set): own plan.
- [ ] **Slice 6 — Revert** (D48 both entry points under the lock, D56 `RevertMode` and the `revertToVersion()` shorthand, rename to `revertToRevision()`, D36 revert context, D26/D27 `history` and `revert` permissions, D40 compare-and-set of membership): own plan.
- [ ] **Slice 7 — Purge and recovery** (D35 `versions:purge-deleted` and `hard_deletes.expiration_days`, D29 `recover()` writing a `baseline` set, D60): own plan.
- [ ] **Slice 8 — Compaction** (D18, D46, D59, the anchor check; replaces the D38 stub): own plan.
- [ ] **Slice 9 — SAO ticket timeline** (`TicketTimelineService` after D18 and D20): own plan or a task of Slice 8.
- [ ] **Slice 10 — CMS pilot** (`Content::versionedRelations()` for categories, tags, locations, contributors, D31): CMS-scoped plan, after Slice 6 passes review.

---

## Slice 1 — Schema and library absorption

**Outcome:** no file references `Overtrue\LaravelVersionable`, the package is no longer required, the tables are `core_versions` and `core_versions_sets`, append-only, with the `trashed`/`restored` change types, the `baseline` set kind, the `capture` column and `reverted_from_set_id` set to null on delete. Behaviour is otherwise unchanged; capture quality is stored but only written as its default until Slice 3.

### Task 1: Absorb the `VersionStrategy` enum

**Files:**
- Create: `Modules/Core/app/Enums/VersionStrategy.php`
- Create: `Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`
- Modify: every PHP file importing `Overtrue\LaravelVersionable\VersionStrategy` (39 files in Core, ERP, MES, SAO; list with the grep below)
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`getVersionableAttributes()` on Core's enum)

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

/**
 * The versioning library was absorbed into Core (D14). This guards against a new reference to it.
 */
it('leaves no reference to the absorbed versioning library', function (string $symbol): void {
    $offenders = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        $path = $file->getPathname();

        if ($file->getExtension() !== 'php' || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
            continue;
        }

        if (str_contains((string) file_get_contents($path), $symbol)) {
            $offenders[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $path);
        }
    }

    expect($offenders)->toBe([]);
})->with([
    'strategy enum' => ['Overtrue\LaravelVersionable\VersionStrategy'],
]);
```

- [x] **Step 2: Run it and see it fail**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`
Expected: FAIL, listing about 40 files.

- [x] **Step 3: Create the enum**

```php
<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * How a version row stores its change: only the changed columns, or the whole image.
 *
 * Absorbed from overtrue/laravel-versionable (MIT License, Copyright (c) 2019 overtrue).
 * The case names are kept as in the library so that existing call sites only change their import.
 */
enum VersionStrategy: string
{
    case DIFF = 'DIFF';
    case SNAPSHOT = 'SNAPSHOT';
}
```

- [x] **Step 4: Swap the imports and inline references**

From `laraplate/`:

```bash
grep -rl --include='*.php' 'Overtrue\\LaravelVersionable\\VersionStrategy' Modules | grep -v '/vendor/' | xargs sed -i -e 's#use Overtrue\\LaravelVersionable\\VersionStrategy;#use Modules\\Core\\Enums\\VersionStrategy;#' -e 's#\\Overtrue\\LaravelVersionable\\VersionStrategy#\\Modules\\Core\\Enums\\VersionStrategy#g'
```

Then remove the now redundant import from files inside the `Modules\Core\Enums` namespace, if any, and run Pint on the touched files to reorder imports (`.ai/rules/modules.md`: from the root, explicit file list).

**Found at execution (2026-10-20):** the library's `Versionable::getVersionableAttributes()` is typed on the library enum and `VersionChange` calls it with Core's, so the swap alone fails with a `TypeError`. `getVersionableAttributes()` is therefore absorbed into `HasVersions` in this task (body as in Task 3, Step 3), not in Task 3. The detached test skips its own file, which names the symbol in its dataset, and locates `Modules/` from `__DIR__` because the Unit suite has no application.

- [x] **Step 5: Run the test and the versioning suites**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers`
Expected: PASS. Then the touched ERP, MES and SAO test files: `php artisan test --compact` with the paths printed by `grep -rl 'Modules\\Core\\Enums\\VersionStrategy' Modules/{ERP,MES,SAO}/tests`.

- [x] **Step 6: Commit** in each touched module repository (`feat(core): absorb VersionStrategy enum (D14)` in Core; `refactor: import Core VersionStrategy` in ERP, MES, SAO).

### Task 2: Make `Version` a Core model

**Files:**
- Modify: `Modules/Core/app/Models/Version.php`
- Modify: `Modules/Core/app/Versioning/ActiveVersionSet.php` (sequence check without `withTrashed()`)
- Modify: `Modules/Core/app/Versioning/Exceptions/VersionSequenceMismatchException.php`
- Modify: `Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`
- Test: `Modules/Core/tests/Integration/Models/VersionModelTest.php`

- [x] **Step 1: Write the failing tests**

Add the dataset row `'version model' => ['Overtrue\LaravelVersionable\Version']` to the detached test, and to `VersionModelTest`:

```php
it('is a plain Core model without soft deletes', function (): void {
    expect(get_parent_class(Version::class))->toBe(Illuminate\Database\Eloquent\Model::class)
        ->and(class_uses_recursive(Version::class))->not->toContain(Illuminate\Database\Eloquent\SoftDeletes::class);
});
```

- [x] **Step 2: Run them and see them fail**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php Modules/Core/tests/Integration/Models/VersionModelTest.php`
Expected: FAIL (the parent is the library's `Version`, which uses `SoftDeletes`).

**Found at execution (2026-10-20):** the detached test matches a symbol only as a whole name (`Version` is a prefix of `Versionable`, still in use until Task 3). `HasVersions` imports the library's `Version` as the return type of `createVersion()`, so its import switches to `Modules\Core\Models\Version` in this task. The `#[Override]` attributes on `createForModel()`, `revertWithoutSaving()`, `previousVersions()` and `nextVersion()` go, since `Model` has no such methods, and `createForModel()` types `$time` as `DateTimeInterface|string|null`. In `VersionSetManagerTest` the soft-deleted extra row case becomes a hard delete that leaves nothing behind, and the soft-deleted confirmed row case a deleted one.

- [x] **Step 3: Absorb the members Core relies on**

In `Version.php` replace `use Overtrue\LaravelVersionable\Version as OvertrueVersion;` with `use Illuminate\Database\Eloquent\Model;` and `use Illuminate\Database\Eloquent\Relations\MorphTo;`, make the class `final class Version extends Model`, add the attribution to its docblock (`Parts absorbed from overtrue/laravel-versionable, MIT License, Copyright (c) 2019 overtrue.`) and add the members below unless the class already defines them. The library members Laraplate does not use (`Diff`, `user()`, the trashed-version methods, the timestamp-ordering toggles) are not absorbed.

```php
    /**
     * @var list<string>
     */
    #[Override]
    protected $guarded = [];

    /**
     * @var list<string>
     */
    #[Override]
    protected $with = ['versionable'];

    /**
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo('versionable');
    }

    #[Override]
    public function getIncrementing(): bool
    {
        return config('versionable.uuid') ? false : parent::getIncrementing();
    }

    #[Override]
    public function getKeyType(): string
    {
        return config('versionable.uuid') ? 'string' : parent::getKeyType();
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderOldestFirst(Builder $query): Builder
    {
        return $query->oldest()->oldest('id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOrderLatestFirst(Builder $query): Builder
    {
        return $query->latest()->latest('id');
    }

    public function revert(): bool
    {
        return $this->revertWithoutSaving()->save();
    }

    #[Override]
    protected static function booted(): void
    {
        static::creating(static function (self $version): void {
            if (config('versionable.uuid')) {
                $version->{$version->getKeyName()} = $version->{$version->getKeyName()} ?: (string) Illuminate\Support\Str::orderedUuid();
            }
        });
    }
```

If `Version` already has a `booted()`, merge the `creating` hook into it instead of adding a second method.

- [x] **Step 4: Replace the sequence check in `ActiveVersionSet::finish()`**

Without soft deletes every persisted row is visible, so one query suffices:

```php
        $persisted_sequences = $this->version_set->versions()
            ->reorder('sequence')
            ->pluck('sequence')
            ->map(static fn (mixed $sequence): int => (int) $sequence)
            ->values()
            ->all();
        $confirmed_sequences = $this->sequence === 0 ? [] : range(1, $this->sequence);

        if ($persisted_sequences !== $confirmed_sequences) {
            throw new VersionSequenceMismatchException($confirmed_sequences, $persisted_sequences);
        }
```

and reduce the exception to `__construct(array $expected, array $persisted)` with the message `'Confirmed version sequences [%s] do not match persisted [%s] sequences.'`. Update the tests that construct or assert that message (`grep -rn VersionSequenceMismatchException Modules/Core/tests`).

- [x] **Step 5: Run the tests**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models`
Expected: PASS.

- [x] **Step 6: Commit** `feat(core): make Version a Core model without the library base (D14)`.

### Task 3: Absorb the `Versionable` trait into `HasVersions`

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php`
- Modify: `Modules/Core/app/Services/Crud/CrudService.php` (`hasHistory()`)
- Modify: `Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`
- Test: `Modules/Core/tests/Integration/Helpers/HasVersionsTest.php`

- [x] **Step 1: Write the failing tests**

Replace the dataset of the detached test with the whole namespace, `'library' => ['Overtrue\LaravelVersionable']`, and add to `HasVersionsTest` (schema as in the existing tests of that file):

```php
it('exposes the first and latest versions without the library trait', function (): void {
    config()->set('versionable.version_model', Version::class);
    Schema::create(VersionedArticle::TABLE, function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    try {
        $article = VersionedArticle::query()->create(['title' => 'First']);
        $article->update(['title' => 'Second']);

        expect(class_uses_recursive(VersionedArticle::class))->not->toContain('Overtrue\LaravelVersionable\Versionable')
            ->and($article->firstVersion->contents['title'])->toBe('First')
            ->and($article->latestVersion->contents['title'])->toBe('Second')
            ->and($article->versions()->count())->toBe(2);
    } finally {
        Schema::dropIfExists(VersionedArticle::TABLE);
    }
});
```

- [x] **Step 2: Run them and see them fail**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php Modules/Core/tests/Integration/Helpers/HasVersionsTest.php`
Expected: FAIL (the trait still uses `Versionable`).

- [x] **Step 3: Replace the trait use with the absorbed members**

In `HasVersions`: remove the `use Versionable { history as private untypedHistory; latestVersions as private untypedLatestVersions; oldestVersions as private untypedOldestVersions; }` block, its import and the no-op `bootVersionable()`; the three typed methods call those aliases, so their bodies become `return $this->latestVersions();` in `history()`, `return $this->versions()->orderLatestFirst();` in `latestVersions()` and `return $this->versions()->orderOldestFirst();` in `oldestVersions()`, as in the library; replace `use Overtrue\LaravelVersionable\Version;` with `use Modules\Core\Models\Version;`; add `use Illuminate\Database\Eloquent\Relations\MorphOne;` and the members below, except `getVersionableAttributes()`, already absorbed in Task 1. Keep every other method `HasVersions` already defines (`createVersion()`, `createInitialVersion()`, `shouldBeVersioning()`, `getDontVersionable()`, `getVersionStrategy()`, `getVersionUserId()`, `revertToVersion()`). Add the attribution line to the trait docblock.

```php
    protected static bool $versioning = true;

    public static function getVersioning(): bool
    {
        return static::$versioning;
    }

    public static function disableVersioning(): void
    {
        static::$versioning = false;
    }

    public static function enableVersioning(): void
    {
        static::$versioning = true;
    }

    /**
     * @return MorphMany<Version, $this>
     */
    public function versions(): MorphMany
    {
        return $this->morphMany($this->getVersionModel(), 'versionable');
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function latestVersion(): MorphOne
    {
        return $this->morphOne($this->getVersionModel(), 'versionable')->orderLatestFirst();
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function lastVersion(): MorphOne
    {
        return $this->latestVersion();
    }

    /**
     * @return MorphOne<Version, $this>
     */
    public function firstVersion(): MorphOne
    {
        return $this->morphOne($this->getVersionModel(), 'versionable')->orderOldestFirst();
    }

    /**
     * @return list<string>
     */
    public function getVersionable(): array
    {
        return property_exists($this, 'versionable') ? $this->versionable : [];
    }

    public function getRefreshedModel(Model $model): Model
    {
        return $model->newQueryWithoutScopes()->findOrFail($model->getKey());
    }

    /**
     * @return class-string<Version>
     */
    public function getVersionModel(): string
    {
        return $this->versionModel ?? config('versionable.version_model', Version::class);
    }

    public function getUserForeignKeyName(): string
    {
        return $this->userForeignKeyName ?? config('versionable.user_foreign_key', 'user_id');
    }

    /**
     * @param  array<string, mixed>  $replacements
     * @return array<string, mixed>
     */
    public function getVersionableAttributes(VersionStrategy $strategy, array $replacements = []): array
    {
        $versionable = $this->getVersionable();
        $refreshed = $this->getRefreshedModel($this);

        $keys = match ($strategy) {
            VersionStrategy::DIFF => array_keys($this->getDirty()),
            VersionStrategy::SNAPSHOT => array_keys($refreshed->attributesToArray()),
        };

        $attributes = Arr::only($refreshed->getRawOriginal(), $keys);

        if ($versionable !== []) {
            $attributes = Arr::only($attributes, $versionable);
        }

        return Arr::except(array_merge($attributes, $replacements), $this->getDontVersionable());
    }
```

In `CrudService::hasHistory()` replace `Versionable::class` with `HasVersions::class` and fix the import.

**Found at execution (2026-10-20):** two anonymous test models (`CrudServiceTest`, `CrudServiceRequestScenariosTest`) used the library trait directly and now use `HasVersions`; the new `HasVersionsTest` case does not name the library, which the detached test would flag.

- [x] **Step 4: Run the tests**

Run: `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers Modules/Core/tests/Integration/Services/CrudServiceTest.php`, then the seeding tests that call `disableVersioning()` (`grep -rln "disableVersioning\|enableVersioning" Modules/Core/tests`).
Expected: PASS.

- [x] **Step 5: Commit** `feat(core): absorb the Versionable trait into HasVersions (D14)`.

### Task 4: Remove the package requirement

**Files:**
- Modify: `Modules/Core/composer.json` (line requiring `overtrue/laravel-versionable`)
- Modify: `composer.lock` (laraplate root, through composer)
- Modify: `Modules/Core/config/versionable.php` (drop the keys only the library read: `keep_versions`, `user_model`)

- [x] **Step 1: Confirm with the user** that the dependency may be removed now (dependency change, AGENTS.md). Confirmed on 2026-10-20. The update removed only the library and the `jfcherng/*` packages it pulled; `doctrine/dbal` stays, the root requires it directly.
- [x] **Step 2: Remove the requirement** from `Modules/Core/composer.json`, then from `laraplate/` run `composer update overtrue/laravel-versionable --no-interaction`, and check `composer why jfcherng/php-diff` prints nothing (it was needed only by the library's `Diff`).
- [x] **Step 3: Drop the dead config keys** `keep_versions` and `user_model` from `config/versionable.php`; Core's `ServiceProvider::registerConfig()` already merges the file under `versionable`, so the remaining keys keep working.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers` and `php artisan about` (the application boots without the package's provider).
- [x] **Step 5: Commit** `chore(core): drop overtrue/laravel-versionable (D14)` in Core and the lock file in `laraplate/`.

### Task 5: Rename the tables

**Files:**
- Modify: `Modules/Core/app/Enums/CoreTables.php:43-44`
- Modify: every literal `vend_versions` in code, tests and docs (`grep -rn "vend_versions" Modules docs tests`)
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetSchemaTest.php`

- [x] **Step 1: Write the failing test** in `VersionSetSchemaTest`:

```php
it('names the history tables core_versions and core_versions_sets', function (): void {
    expect(CoreTables::Versions->value)->toBe('core_versions')
        ->and(CoreTables::VersionSets->value)->toBe('core_versions_sets')
        ->and(Schema::hasTable('core_versions'))->toBeTrue()
        ->and(Schema::hasTable('core_versions_sets'))->toBeTrue();
});
```

- [x] **Step 2: Run it and see it fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Versioning/VersionSetSchemaTest.php`. Expected: FAIL (`vend_versions`).
- [x] **Step 3: Rename** `case VersionSets = 'core_versions_sets';` and `case Versions = 'core_versions';`, and replace the literals found by the grep.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models` and the SAO timeline test (`Modules/SAO/tests/Feature/Services/TicketTimelineServiceTest.php`). Expected: PASS.
- [x] **Step 5: Commit** `feat(core): rename history tables to core_versions* (D15)`.

### Task 6: Append-only history tables

**Files:**
- Modify: `Modules/Core/app/Helpers/MigrateUtils.php` (`timestamps()` gains `bool $hasUpdatedAt = true`)
- Modify: `Modules/Core/database/migrations/2019_05_31_042934_create_versions_table.php`
- Modify: `Modules/Core/database/migrations/2019_05_31_042933_create_version_sets_table.php`
- Modify: `Modules/Core/app/Models/Version.php`, `Modules/Core/app/Models/VersionSet.php` (`UPDATED_AT = null`, casts)
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetSchemaTest.php`

- [x] **Step 1: Write the failing test**

```php
it('keeps the history tables append-only', function (): void {
    expect(Schema::hasColumns('core_versions', ['created_at', 'compacted_at']))->toBeTrue()
        ->and(Schema::hasColumn('core_versions', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions', 'deleted_at'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions', 'is_deleted'))->toBeFalse()
        ->and(Schema::hasColumn('core_versions_sets', 'created_at'))->toBeTrue()
        ->and(Schema::hasColumn('core_versions_sets', 'updated_at'))->toBeFalse()
        ->and(Version::UPDATED_AT)->toBeNull()
        ->and(VersionSet::UPDATED_AT)->toBeNull();
});
```

- [x] **Step 2: Run it and see it fail.** Expected: FAIL (`updated_at` and `deleted_at` exist).
- [x] **Step 3: Add the flag to `MigrateUtils::timestamps()`** as the last parameter, `bool $hasUpdatedAt = true`, and guard the `updated_at` column with it: `if ($hasUpdatedAt && ! $schema->hasColumn($table_name, Model::UPDATED_AT))`. The default does not change.
- [x] **Step 4: Edit the create migrations.** Versions: `MigrateUtils::timestamps($table, hasCreateUpdate: true, hasUpdatedAt: false);` (no `hasSoftDelete`) and `$table->timestamp('compacted_at')->nullable()->comment('Set when a compaction rewrote the row (D18)');`. Sets: `MigrateUtils::timestamps($table, hasCreateUpdate: true, createdAtIndexName: 'vsets_created_IDX', hasUpdatedAt: false);`.
- [x] **Step 5: Models.** Add `public const UPDATED_AT = null;` to `Version` and `VersionSet`; remove `updated_at` (and `deleted_at` if present) from their `casts()`.
- [x] **Step 6: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Models Modules/Core/tests/Feature/Services/PresetVersioningServiceTest.php` and the `MigrateUtils` tests (`grep -rln "MigrateUtils::timestamps\|timestamps(" Modules/Core/tests/Unit`). Expected: PASS; fix the tests that asserted `updated_at`/`deleted_at` on versions.
- [x] **Step 7: Commit** `feat(core): make the history tables append-only (D19)`.

### Task 7: `trashed` and `restored` change types

**Files:**
- Modify: `Modules/Core/app/Enums/VersionChangeType.php`
- Modify: `Modules/Core/app/Models/Concerns/HasVersions.php` (`capturePendingVersionUpdate()`, `writePendingVersionUpdate()`, `writePendingVersionDelete()`, `trashingVersions()`)
- Test: `Modules/Core/tests/Integration/Versioning/DeleteVersioningStrategyTest.php`, `Modules/Core/tests/Integration/Versioning/VersionWriterCharacterizationTest.php`

- [x] **Step 1: Change the tests first.** In `DeleteVersioningStrategyTest` the soft-delete test expects `VersionChangeType::Trashed` (it replaces the `Updated` expectation of `83112c2`), and `trashingVersions()` still returns exactly the trashing row and not the restore. In `VersionWriterCharacterizationTest` the restore test expects `VersionChangeType::Restored`.
- [x] **Step 2: Run them and see them fail.** Run: `php artisan test --compact Modules/Core/tests/Integration/Versioning/DeleteVersioningStrategyTest.php Modules/Core/tests/Integration/Versioning/VersionWriterCharacterizationTest.php`. Expected: FAIL (`Trashed`/`Restored` undefined).
- [x] **Step 3: Extend the enum**

```php
enum VersionChangeType: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Trashed = 'trashed';
    case Restored = 'restored';
    case Deleted = 'deleted';
}
```

The enum column of the create migration is built from `cases()`, so it follows.

- [x] **Step 4: Write the new types.**
  - In `writePendingVersionDelete()` the soft-delete branch writes `type: VersionChangeType::Trashed` instead of `Updated`.
  - In `capturePendingVersionUpdate()` store a `type`: `Restored` when the model has a `deleted_at` column (`getVersionDeletedAtColumn()`), it is among the dirty keys, its raw original is not null and its new value is null; otherwise `Updated`.
  - In `writePendingVersionUpdate()` write `type: $pending['type']`.
  - `trashingVersions()` becomes `$this->versions()->getQuery()->where('change_type', VersionChangeType::Trashed)`, dropping the JSON search.
- [x] **Step 5: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers` and the SAO timeline test. Expected: PASS.
- [x] **Step 6: Commit** `feat(core): record trashed and restored change types (D20)`.

### Task 8: Columns and kinds for the later slices

**Files:**
- Create: `Modules/Core/app/Enums/VersionCaptureQuality.php`
- Modify: `Modules/Core/app/Enums/VersionSetKind.php`, `Modules/Core/app/Versioning/Data/VersionSetOptions.php`
- Modify: `Modules/Core/database/migrations/2019_05_31_042933_create_version_sets_table.php`
- Modify: `Modules/Core/app/Models/VersionSet.php` (fillable, cast)
- Test: `Modules/Core/tests/Integration/Versioning/VersionSetSchemaTest.php`, `Modules/Core/tests/Unit/Versioning/VersionSetOptionsTest.php` (create if missing)

- [x] **Step 1: Write the failing tests**

```php
it('sets reverted_from_set_id to null when the target set is removed', function (): void {
    $target = VersionSet::query()->forceCreate(['uuid' => (string) Str::uuid(), 'kind' => VersionSetKind::Change]);
    $revert = VersionSet::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'kind' => VersionSetKind::Revert,
        'reverted_from_set_id' => $target->getKey(),
    ]);

    $target->delete();

    expect($revert->fresh()->reverted_from_set_id)->toBeNull();
});

it('stores the capture quality of a set, best effort by default', function (): void {
    $set = VersionSet::query()->forceCreate(['uuid' => (string) Str::uuid(), 'kind' => VersionSetKind::Baseline]);

    expect($set->fresh()->capture)->toBe(VersionCaptureQuality::BestEffort)
        ->and($set->fresh()->kind)->toBe(VersionSetKind::Baseline);
});
```

and in `VersionSetOptionsTest`:

```php
it('allows a reverted-from set only on a revert', function (): void {
    expect(fn () => new VersionSetOptions(kind: VersionSetKind::Baseline, revertedFrom: 1))
        ->toThrow(InvalidArgumentException::class);
});
```

- [x] **Step 2: Run them and see them fail.** Expected: FAIL (`Baseline`, `VersionCaptureQuality` and `capture` undefined; the FK restricts the delete).
- [x] **Step 3: Enums.** `VersionSetKind` gains `case Baseline = 'baseline';`. Create:

```php
<?php

declare(strict_types=1);

namespace Modules\Core\Enums;

/**
 * Whether the business write and its history committed together (D47, D52).
 */
enum VersionCaptureQuality: string
{
    case Atomic = 'atomic';
    case BestEffort = 'best_effort';
    case Incomplete = 'incomplete';
}
```

- [x] **Step 4: Migration.** In the sets create migration add after `kind`:

```php
            $table->enum(
                'capture',
                array_map(static fn (VersionCaptureQuality $case): string => $case->value, VersionCaptureQuality::cases()),
            )->default(VersionCaptureQuality::BestEffort->value)->comment('Capture quality of the set (D47, D52)');
```

and replace the `reverted_from_set_id` foreign key block (including the Oracle branch) with `$table->foreign('reverted_from_set_id', 'vsets_reverted_FK')->references('id')->on($table_name)->nullOnDelete();` (D58; Oracle supports `ON DELETE SET NULL`).
- [x] **Step 5: Model and options.** `VersionSet`: add `'capture'` to `$fillable` and `'capture' => VersionCaptureQuality::class` to `casts()`. `VersionSetOptions`: replace the check `$kind === VersionSetKind::Change && $revertedFrom !== null` with `$kind !== VersionSetKind::Revert && $revertedFrom !== null`, message `'Only a revert version set can reference a reverted set.'`.
- [x] **Step 6: Run** `php artisan test --compact Modules/Core/tests/Integration/Versioning Modules/Core/tests/Unit/Versioning`. Expected: PASS.
- [x] **Step 7: Commit** `feat(core): add capture, baseline kind and SET NULL revert link (D52, D53, D58)`.

### Task 9: Documentation and closing of the slice

**Files:**
- Modify: `Modules/Core/docs/rag/MODULE.md` (versioning section)
- Modify: `Modules/Core/README.md` (the package mention near line 650)
- Modify: `docs/superpowers/specs/2026-08-04-revision-centric-aggregate-history-review.md` (status line: implemented D14, D15, D19, D20 and the schema of D52, D53, D58)
- Modify: this plan (tick Slice 1)

- [x] **Step 1: Rewrite the stale parts of the versioning section of `MODULE.md`:** the writer is synchronous through `VersionWriterInterface` from the `HasVersions` boot hooks (there is no `ModelVersioningRequested`/`CreateVersionJob`); the tables are `core_versions` and `core_versions_sets`, append-only; `VersionStrategy` is a Core enum and the library is no longer a dependency; a soft delete is `trashed`, a restore `restored`, a hard delete still the `deleted` tombstone until the coordinator; sets carry `kind` (`change`, `revert`, `baseline`) and `capture`; `reverted_from_set_id` is set to null when its target is removed. Keep the description of `HasVersionedRelations` and mark the explicit methods as replaced in Slice 4.
- [x] **Step 2: README.** Replace the `overtrue/laravel-versionable` dependency line with a note that versioning is part of Core (D14), keeping the existing style. Done with the convention of the other derived libraries: `Modules/Core/LICENSES/laravel-versionable.md` with the upstream license and commit, a line under "Third-party code", and the derived files pointing at it in their header.
- [x] **Step 3: Run** (2026-10-20: the whole regression is green per suite; `ClosedPlansPointToDocumentationTest` fails only on `2026-10-20-crm-module-extraction.md`, whose `Modules/CRM` docs are another work's) `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` (the pointer of the superseded plan must still resolve) and the full versioning set: `php artisan test --compact Modules/Core/tests/Unit/Versioning Modules/Core/tests/Integration/Versioning Modules/Core/tests/Integration/Helpers Modules/Core/tests/Integration/Models`. Ask the user to run the whole suite.
- [x] **Step 4: Commit** the docs in Core and in `laraplate/`; tick Slice 1 above.

---

**Documented in (when the plan closes):** `Modules/Core/docs/rag/MODULE.md`.
