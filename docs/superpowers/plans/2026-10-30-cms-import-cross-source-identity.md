---
created_on: 2026-10-30
module: core, cms
---
# CMS imports: one tag across several sources. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** One live tag per `(locale, slug)` whoever writes it (an import from any source, the CSV import, `findOrCreateFromString()`, the backoffice), enforced by the database, with the type as an untranslated attribute.

**Architecture:** Core gains `MigrateUtils::liveUniqueIndex()`, a unique index that ignores soft-deleted rows on every supported driver. CMS uses it on `cms_tags_translations`, gives `Tag` an identity lookup (`findByIdentity()`) that every writer goes through, validates slugs on saves, and teaches `TagUpserter` to match by origin then slug, to leave a tag shared by several sources alone, and to survive a concurrent creation. Categories, contents, contributors and locations do not change.

**Tech Stack:** PHP 8.5, Laravel 12, Filament 5, Pest. No new dependency.

**Spec:** `../specs/2026-10-30-cms-import-cross-source-identity-design.md` (decisions D1 to D9).

## Global Constraints

- One branch per repository that changes: `feat/live-unique-index` in `Modules/Core` (laracore), `feat/tag-identity-across-sources` in `Modules/CMS` (laracms), docs in the root (laraplate). Each in a git worktree, never in the shared checkout; one PR each; the owner merges and bumps submodule pointers.
- Pre-stable: schema changes go into the create migrations, no alter migration. The rebuild (`migrate:fresh` and a new import) is the owner's step (Task 6).
- The type is never translated: it stays on `cms_tags`, stored through `Tag::normalizeType()`.
- Identity is the exact slug after slugging; no case-insensitive name match, no edit distance, no synonyms.
- Categories, contents, contributors and locations keep their current matching (D1, D3, D8). The `unique(..., deleted_at)` constraints of `cms_locations` and `cms_contributors` do not constrain live rows either (spec D7); they are recorded here and left as they are.
- No new base folder: CMS has no `app/Rules`, so validation uses closures over a `Tag` static method.
- Tests run on sqlite (`phpunit.xml`); the MySQL and Oracle branches of Task 1 are checked by the owner's `migrate:fresh` on MySQL and are otherwise untested, which the PR says.
- Pint from the laraplate root with an explicit file list (`.ai/rules/modules.md`). Narrowest `php artisan test --compact <path>`; the full suite is the owner's. If `bootstrap/cache/config.php` exists, `config:clear` first.
- Commits with `-c user.name=swolley -c user.email=myentity@inwind.it`, no `Co-Authored-By` trailer.

## Review Focus

1. **A source deletes a tag another source still brings.** `ImportTagDto::$deletedAt` must not soft-delete a tag that has an origin from another source; only the origin of the deleting source matters. Test in Task 4.
2. **A source sends a slug in another form** (`Unione_Europea`, `unione europea`). The upserter slugs the DTO slug before lookup and storage, so it meets `unione-europea`. Test in Task 4.
3. **The slug belongs to a soft-deleted tag.** Its translation is still live, so a new tag would hit the constraint: every lookup includes trashed tags and the writer restores the match. Tests in Tasks 2 and 4.
4. **The same slug in two locales.** Identity is per locale: `jazz` in `it` on one tag and `jazz` in `en` on another is allowed; `findByIdentity()` looks in one locale only. Test in Task 2.
5. **An editor saves a tag without changing its slug.** The validation ignores the tag's own translation. Test in Task 3.

---

### Task 1: Core, a unique index that ignores soft-deleted rows

**Files:**
- Modify: `Modules/Core/app/Helpers/MigrateUtils.php` (new public static method next to `fuzzyIndex()`)
- Modify: `Modules/Core/docs/rag/MODULE.md` (where it lists the `MigrateUtils` helpers)
- Test: `Modules/Core/tests/Integration/Helpers/MigrateUtilsTest.php`

**Interfaces:**
- Produces: `MigrateUtils::liveUniqueIndex(string $table, array $columns, string $name, ?ConnectionInterface $connection = null): void`, `$columns` a `list<string>`. Called after `Schema::create()`, like `fuzzyIndex()`. The table must have `deleted_at`.

- [ ] **Step 1: Write the failing tests**

```php
it('liveUniqueIndex refuses a second live row with the same key', function (): void {
    Schema::create('mu_live', function (Blueprint $table): void {
        $table->id();
        $table->string('locale');
        $table->string('slug');
        $table->softDeletes();
    });
    MigrateUtils::liveUniqueIndex('mu_live', ['locale', 'slug'], 'mu_live_locale_slug_UN');

    DB::table('mu_live')->insert(['locale' => 'it', 'slug' => 'jazz']);

    expect(fn () => DB::table('mu_live')->insert(['locale' => 'it', 'slug' => 'jazz']))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('liveUniqueIndex lets deleted rows share the key with each other and with one live row', function (): void {
    // same table setup
    DB::table('mu_live')->insert([
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => now()],
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => now()],
        ['locale' => 'it', 'slug' => 'jazz', 'deleted_at' => null],
        ['locale' => 'en', 'slug' => 'jazz', 'deleted_at' => null],
    ]);

    expect(DB::table('mu_live')->count())->toBe(4);
});
```

- [ ] **Step 2: Run them, expect FAIL** (`Call to undefined method ...liveUniqueIndex`)

Run: `php artisan test --compact Modules/Core/tests/Integration/Helpers/MigrateUtilsTest.php`

- [ ] **Step 3: Implement `liveUniqueIndex()`**

Validate every identifier with `assertIdentifier()`. Per driver:
- `pgsql`, `sqlite`: a partial index, `CREATE UNIQUE INDEX <name> ON <table> (<columns>) WHERE deleted_at IS NULL`.
- `oracle`: a function-based index, `CREATE UNIQUE INDEX <name> ON <table> (CASE WHEN deleted_at IS NULL THEN <col> END, ...)`, one `CASE` per column, so a deleted row has an all-null key.
- default (`mysql`, `mariadb`): add a stored generated column `is_live`, `tinyint` nullable, `IF(deleted_at IS NULL, 1, NULL)`, if the table lacks it, then `unique([...$columns, 'is_live'], $name)`.

Wrap identifiers with the connection's grammar, as `createPostgresFuzzyIndex()` does. Document the method in `Modules/Core/docs/rag/MODULE.md`, with the reason it exists: `unique(..., deleted_at)` does not constrain live rows, because `deleted_at` is null on all of them.

- [ ] **Step 4: Run the tests, expect PASS**; then the whole `MigrateUtilsTest.php`.
- [ ] **Step 5: Pint, PHPStan on the file, commit** (`feat(migrate-utils): unique index over live rows only`), push, PR in laracore.

### Task 2: CMS, the constraint and the identity lookup of a tag

**Files:**
- Modify: `Modules/CMS/database/migrations/2025_01_17_232315_create_tags_translations_table.php`
- Modify: `Modules/CMS/database/migrations/2024_11_30_111403_create_tags_table.php` (the comment that places unique constraints in translations)
- Modify: `Modules/CMS/app/Models/Translations/TagTranslation.php`, `Modules/CMS/app/Models/Tag.php`
- Test: `Modules/CMS/tests/Feature/Models/TagTest.php`, `Modules/CMS/tests/Feature/Import/CmsImportersTest.php` (its `findFromString('Sport', 'topic')` assertion follows the new semantics)

**Interfaces:**
- Consumes: `MigrateUtils::liveUniqueIndex()` (Task 1).
- Produces:
  - `TagTranslation::slugFromName(string $name): string`, the slugger `HasSlug::generateSlug()` uses (`config('cms.slugger')`) applied to the trimmed name.
  - `Tag::findByIdentity(string $name, ?string $locale = null): ?Tag`: the tag, trashed included, whose live translation in `$locale` (default `LocaleContext::getDefault()`) has the slug `slugFromName($name)`.
  - `Tag::adoptType(?string $type): bool`: with a normalized `$type` null or equal to the tag's, true; with a tag without type, sets it (unsaved) and returns true; otherwise false, tag unchanged.
  - `Tag::slugTakenInLocale(string $slug, string $locale, ?int $except_tag_id = null): bool`: a live translation of another tag has that slug in that locale.
  - `Tag::findFromString(string $name, ?string $type = null): ?Tag` (changed): `findByIdentity()` excluding trashed tags; with a type, the tag only if its type equals the normalized type. A null type means any type, no longer "no type".
  - `Tag::findOrCreateFromString(string $name, ?string $type = null): Tag` (changed): `findByIdentity()`; a trashed match is restored; then `adoptType()`, saving when it set the type, throwing `InvalidArgumentException` when it returns false; no match creates the tag as today.

- [ ] **Step 1: Write the failing tests** in `TagTest.php`:

```php
it('refuses a second live translation with the same slug in a locale', ...);
    // two tags, both setTranslation('it', ['name' => 'Jazz', 'slug' => 'jazz']): the second throws UniqueConstraintViolationException
it('lets two locales share a slug on different tags', ...);
    // tag A it 'jazz', tag B en 'jazz': both saved; findByIdentity('Jazz', 'en') is B
it('finds a tag by the slug of a name, whatever its case', ...);
    // findOrCreateFromString('Libri') then findOrCreateFromString('libri'): same id, one tag
it('restores a trashed tag instead of creating a second one', ...);
    // create 'Jazz', delete(); findOrCreateFromString('Jazz') returns the same id, not trashed
it('gives a tag without type the type asked for', ...);
    // findOrCreateFromString('Jazz'), then findOrCreateFromString('Jazz', 'genre'): same id, type 'genre'
it('refuses a type different from the one the tag has', ...);
    // 'Jazz' with type 'genre', then findOrCreateFromString('Jazz', 'era'): throws InvalidArgumentException
it('finds from string of any type when no type is given', ...);
    // 'Jazz' type 'genre': findFromString('Jazz') is the tag, findFromString('Jazz', 'era') is null
```

- [ ] **Step 2: Run them, expect FAIL.**

Run: `php artisan test --compact Modules/CMS/tests/Feature/Models/TagTest.php`

- [ ] **Step 3: Implement.** In the translations create migration, after `Schema::create()`: `MigrateUtils::liveUniqueIndex($table_name, ['locale', 'slug'], "{$table_name}_locale_slug_UN")`, replacing the plain `locale_slug_IDX` index it duplicates. Fix the `cms_tags` comment to point at that constraint. Add the model methods of the Interfaces block. `findFromStringOfAnyType()` keeps its name-or-slug match in every locale: it is a search, not an identity lookup.
- [ ] **Step 4: Run `TagTest.php`, `CmsImportersTest.php`, `tests/Feature/Models`, `tests/Feature/Controllers/ContentRelationsSyncTest.php`, expect PASS.** A test that relied on "null type means no type" is updated to the D2 semantics, never deleted.
- [ ] **Step 5: Pint, commit** (`feat(tags): one live tag per locale and slug`).

### Task 3: CMS, slug validation on saves

**Files:**
- Modify: `Modules/CMS/app/Models/Tag.php` (`getRules()`), `Modules/CMS/app/Filament/Resources/Tags/Schemas/TagForm.php` (the `slug` field)
- Test: `Modules/CMS/tests/Feature/Filament/TagSlugValidationTest.php` (create), `Modules/CMS/tests/Feature/Models/TagTest.php`

**Interfaces:**
- Consumes: `Tag::slugTakenInLocale()` (Task 2).
- Produces: a closure rule on `translations.*.slug` in `getRules()` (create and update), reading the item's `locale` from the validated data and ignoring `$this->id` on update; the same check on the form's `slug` field for `LocaleContext::get()` (the locale the form edits) and the edited record.

- [ ] **Step 1: Write the failing tests**

```php
it('refuses in the form a slug another tag has in the edited locale', function (): void {
    // existing tag with slug 'jazz' in the default locale; CreateTag fillForm name 'Jazz', slug 'jazz', call('create')
    // ->assertHasFormErrors(['slug']); Tag::query()->count() stays 1
});
it('saves a tag whose slug is unchanged', function (): void {
    // EditTag on a tag with slug 'jazz', fillForm name 'Jazz!' (slug unchanged), call('save') ->assertHasNoFormErrors()
});
it('rejects through the model rules a translation slug already taken in its locale', ...);
    // getRules()['create'] validated with translations [['locale' => 'it', 'name' => 'Jazz', 'slug' => 'jazz']] fails on translations.0.slug
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement the two rules.** The message is Laravel's `validation.unique` for the attribute; no new translation key.
- [ ] **Step 4: Run `TagSlugValidationTest.php`, `TranslatedRecordFormsTest.php`, `TagTypeFieldTest.php`, expect PASS.**
- [ ] **Step 5: Pint, commit** (`feat(tags): validate the slug of a tag in its locale`).

### Task 4: CMS, `TagUpserter` matches across sources

**Files:**
- Create: `Modules/CMS/app/Import/Support/TagMatcher.php` (sibling of `LocationMatcher`)
- Modify: `Modules/CMS/app/Import/Upserters/TagUpserter.php`, `Modules/CMS/app/Import/Support/ExternalReferenceLocator.php`
- Modify: `Modules/CMS/docs/rag/IMPORTS.md` (a section on identity across sources: which entities are shared and how each is matched, D1 to D4), `Modules/CMS/docs/rag/MODULE.md` (replace "matched by its name within its type" in the tagging section with D2)
- Test: `Modules/CMS/tests/Feature/Import/TagUpserterTest.php` (create)

**Interfaces:**
- Consumes: `TagTranslation::slugFromName()`, `Tag::adoptType()` (Task 2), the constraint (Task 2).
- Produces:
  - `TagMatcher::findExisting(string $slug, string $locale, ?ImportConnectionContext $context = null): ?int`: the tag id, trashed included, of the live translation with that slug and locale, on the context's connection.
  - `ExternalReferenceLocator::hasOriginFromAnotherSource(Model $referable, string $source_key): bool`: a `core_record_origins` row for the record with a different `source_key`.

`upsert()` becomes, in order:
1. `$slug = TagTranslation::slugFromName($dto->slug)`.
2. Origin of this source (unchanged), else `TagMatcher::findExisting($slug, $this->locale, $context)`, else a new tag. A trashed match is revived as today.
3. Type: a new tag takes `$dto->type`; an existing one goes through `adoptType($dto->type)`. False keeps the tag's type and logs `Log::warning('Tag type conflict during import', [...])` with tag id, slug, both types, source type and external id.
4. Name and slug in `$this->locale` are written when the tag is new, has no translation in that locale, or `hasOriginFromAnotherSource()` is false.
5. A new tag and its translation are created inside `transaction()` on the tag's connection. On `UniqueConstraintViolationException` the matcher runs once more and its tag is used (a concurrent import created it); a second failure propagates.
6. `$dto->deletedAt` soft-deletes the tag only when `hasOriginFromAnotherSource()` is false.
7. Origin registration and `remember()` as today.

- [ ] **Step 1: Write the failing tests**, with `ImportTagDto` from two `sourceType`s (`naxos_api@a`, `feed`):

```php
it('reuses the tag another source created with the same slug and adds its origin', ...);
    // a: 'Unione europea'/'unione-europea'; feed: same slug, other externalId → same id; RecordOrigin count for the tag is 2
it('does not rename a tag another source brought', ...);
    // a: 'Libri'; feed: 'libri' same slug → name stays 'Libri'
it('renames a tag only its own source brought', ...);
    // a: 'Libri', then a again (same externalId) with 'Libri e riviste' → name updated
it('slugs the slug the source sends before matching', ...);
    // a: slug 'unione-europea'; feed: slug 'Unione_Europea' → same id, stored slug 'unione-europea'
it('revives a trashed tag that has the slug', ...);
    // tag with slug 'jazz' soft-deleted, no origin of feed; feed imports 'jazz' → same id, not trashed
it('keeps the type a tag has and logs the conflict', ...);
    // a: type 'genre'; feed: type 'era' → type stays 'genre'; Log::spy() then Log::shouldHaveReceived('warning')->once()
it('does not delete a tag another source still brings', ...);
    // a and feed both brought 'jazz'; a again with deletedAt → tag not trashed
it('uses the tag a concurrent import created', ...);
    // a TagTranslation::creating listener, registered once, inserts another tag with live slug 'jazz' in the same locale;
    // feed imports 'jazz' → returns that tag's id; Tag::query()->count() is 1
```

- [ ] **Step 2: Run, expect FAIL.**

Run: `php artisan test --compact Modules/CMS/tests/Feature/Import/TagUpserterTest.php`

- [ ] **Step 3: Implement** `TagMatcher`, `hasOriginFromAnotherSource()` and the `upsert()` order above. Register `TagMatcher` the way `LocationMatcher` is resolved.
- [ ] **Step 4: Run `TagUpserterTest.php` and the whole `tests/Feature/Import`, expect PASS.**
- [ ] **Step 5: Update `IMPORTS.md` and `MODULE.md`; Pint, PHPStan on the touched files; commit** (`feat(import): one tag across several sources`).

### Task 5: CMS, the CSV tag import on the same identity

**Files:**
- Modify: `Modules/CMS/app/Import/TagImporter.php`
- Test: `Modules/CMS/tests/Feature/Import/CmsImportersTest.php`

**Interfaces:**
- Consumes: `Tag::findByIdentity()`, `Tag::adoptType()` (Task 2), `ExternalReferenceLocator::hasOriginFromAnotherSource()` (Task 4) or the same query through `RecordOriginRegistry`.

`import()` looks up `Tag::findByIdentity($slug !== '' ? $slug : $name, $this->locale())`, restores a trashed match, applies `adoptType()` (false: keep and log, as Task 4), and writes the translation under rule 4 of Task 4. The row outcome stays `Created` / `Updated`.

- [ ] **Step 1: Write the failing tests**

```php
it('reuses by slug a tag of another type or of no type', ...);
    // existing 'Sport' without type; CSV row name 'sport', type 'topic' → one tag, type 'topic', outcome Updated
it('does not rename from a CSV a tag another source brought', ...);
    // tag 'Libri' with a naxos origin; CSV row 'libri' → name stays 'Libri'
```

- [ ] **Step 2: Run, expect FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run `CmsImportersTest.php` and `tests/Feature/Import`, expect PASS.**
- [ ] **Step 5: Pint, commit** (`feat(import): the CSV tag import matches by slug`), push, PR in laracms covering Tasks 2 to 5.

### Task 6: Rebuild and closure

**Files:**
- Modify: this plan (delivery status), `../specs/2026-10-30-cms-import-cross-source-identity-design.md` (status), `docs/superpowers/plans/INDEX.md` only if the line no longer describes the file

- [ ] **Step 1 (owner): merge the laracore and laracms PRs, bump the pointers, run `php artisan migrate:fresh` with the usual seeding on MySQL, then the imports of every source.** It checks the MySQL branch of Task 1.
- [ ] **Step 2: Measure** with the duplicate query of the spec (live translations grouped by `LOWER(name)` and locale with more than one tag): no tag pair; categories unchanged by design.
- [ ] **Step 3: Close the plan**: every box resolved, `status: completed` in the front matter, `## Delivery status (date): ...` with `**Documented in:** Modules/CMS/docs/rag/IMPORTS.md, Modules/CMS/docs/rag/MODULE.md, Modules/Core/docs/rag/MODULE.md`; the spec's status set to implemented. Commit and PR in laraplate.
