---
created_on: 2026-11-24
module: core, ai, cms, crm, erp, mes, sao, shop
status: open
---
# PHPStan cleanup: typed model columns, the translation contract, the module backlog. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** `composer check` reports only what is wrong in the code: every model declares its columns, so a column read is typed, `checkModelProperties` is back on and the `property.notFound` rules on models are gone; `HasTranslations` honours `ITranslatableModel`; the per-module backlog left after the Larastan 3.15 upgrade is fixed in the code.

**Architecture:** An artisan command in Core writes, once per model, `@property` tags for its columns only, into the model's own class docblock, with the types ide-helper infers from the schema and the casts. From then on they are maintained by hand, and an app test fails when a column has no tag or a tag names neither a column, a relation nor an accessor. The translation trait gains what the contract asks for, and an app test fails when a model uses the trait without the contract.

**Tech Stack:** PHP 8.5, Laravel 13, Larastan 3.15 / PHPStan 2.3, barryvdh/laravel-ide-helper 3.8 (already a dev dependency), Pest 5. No new dependency.

**Spec:** none written; the decisions were taken with the owner on 2026-11-24 and are recorded below. Measurements behind them are in the PR bodies of getlaraplate/laraplate#81 and #82.

## Decisions

- **D1. Models declare their columns.** Each model carries `@property` (or `@property-read` for a column the model never writes) for every column of its table, in its own class docblock, maintained by hand with the migrations. Generated once by Task 2, never regenerated over hand edits. Only columns: relations, relation counts and accessors are left to Larastan, which reads them from the methods. This does not reverse the 2026-09-16 decision, which was against ide-helper *rewriting* model files (`--write` regenerates the whole docblock and loses human comments); `ide-helper:models --nowrite` keeps writing the gitignored `_ide_helper_models.php` for the IDE.
- **D2. The analyser sees the columns.** Once D1 has landed in every module, `checkModelProperties: true` returns and the two `property.notFound` rules on `\Models\` classes leave `phpstan.neon`, with their comments.
- **D3. The translation trait honours its contract.** `HasTranslations` implements all of `ITranslatableModel`; every model that uses it declares `implements ITranslatableModel`. Nothing checks the interface at runtime today (only `@phpstan-assert` in AI), so no behaviour depends on the change.
- **D4. No new baseline entries.** The backlog is fixed in the code. A rule in `phpstan.neon` only when a check conflicts with how the code must be written, scoped by message and path, its reason next to it (as #81 and #82 did). Entries the fixes make stale are removed from `phpstan-baseline.neon` and nothing else changes in it.

Already done in this cleanup, not part of the tasks: laraplate#81 (`checkModelProperties` off, 877 stale entries), laracore#65 (trait typing), laracore#66 (`user_class()` answers Core's User), laracrm#6/#7, larames#8, larashop#8, laracms#27, larasao#15, laraplate#82 (named rules for trigger DDL and trait guards).

## Global Constraints

- One branch and one PR per repository touched by a task, from a worktree on `origin/master` (never the shared checkout). Submodule pointers are bumped by the owner, never by an agent.
- Generate (Task 3) against the development database built by `php artisan migrate:fresh` from `.env` (MySQL), never the test database: ide-helper reads column types from the live schema.
- Run `php artisan config:clear` before tests if `bootstrap/cache/config.php` exists. Tests with `XDEBUG_MODE=off`.
- Pint from the laraplate root with an explicit file list (`.ai/rules/modules.md`). A file whose Pint changes go beyond the task's lines gets a separate `style:` commit first.
- PHPStan is measured report by report, never by the total: `composer check` before and after, compared by file, line, identifier and message; a PR body lists what disappeared and what appeared.
- Tests that declare classes put them in the module's `tests/Stubs/` (or `tests/Support/` in the app).
- `composer test:type-coverage` stays at 100%.

## Review Focus

1. **A table shared by several models** (a `Taxonomy` hierarchy: `ContactRole`, `OpportunityStage`, CMS `Category`): columns are declared once, on the topmost model of the table, and inherited; the drift test must accept that and not demand a copy on each subclass (Task 4 case `it accepts columns declared on a parent model of the same table`).
2. **A model whose class docblock already has human text or tags** (`@phpstan-use`, `@use`, descriptions): Task 2 appends the column block and leaves every existing line in place (Task 2 test `it keeps the existing docblock lines`).
3. **A model with `#[Attribute]` lines above the class**: the docblock stays above the attributes (Task 2 test `it writes the docblock above the class attributes`).
4. **A column added later by a migration** (create migrations are edited in place before 1.0): the drift test names the model, the table and the missing column (Task 4 test `it names the model and the column that has no tag`).
5. **A test stub model** under `tests/Stubs/` with a temporary table: outside the generator and the drift test (both read only `app/Models` and `Modules/*/app/Models`).

---

### Task 1: The translation contract

**Files:**
- Modify: `Modules/Core/app/Models/Concerns/HasTranslations.php` (`setTranslation()` return type, new `getOriginalTranslation()`)
- Modify: `Modules/Core/app/Models/Taxonomy.php` (implements)
- Modify: `Modules/CMS/app/Models/{Content,Contributor,Tag,Comment}.php` and any other model the test of Step 1 names
- Create: `tests/Feature/TranslatableModelsImplementTheContractTest.php` (app)

**Interfaces:**
- Produces: `HasTranslations::getOriginalTranslation(): ?Model` (the earliest translation, `created_at` then `id`, the behaviour `Comment` has today) and `HasTranslations::setTranslation(string $locale, array $data): static`. `Comment::getOriginalTranslation(): ?CommentTranslation` stays as a covariant override.

- [x] **Step 1: Write the failing test** in the app: for every class under `app/Models` and `Modules/*/app/Models` that is an Eloquent model and has `HasTranslations` in `class_uses_recursive()`, assert `is_a($class, ITranslatableModel::class, true)`; the failure message lists the classes that miss it. Test name: `it requires every model with translations to implement the translation contract`.
- [x] **Step 2: Run it.** `php artisan test --compact tests/Feature/TranslatableModelsImplementTheContractTest.php` — Expected: FAIL listing Taxonomy and the CMS models.
- [x] **Step 3: Align the trait** (Core): `setTranslation()` returns `static`; add `getOriginalTranslation()` as described in Interfaces. Run the Core suite; `HasTranslationsTest` gets a case `it returns the earliest translation as the original`.
- [x] **Step 4: Declare the contract** on `Taxonomy` (Core) and on each CMS model the test names. Run Core, CMS, CRM, ERP suites (CRM and ERP inherit through `Taxonomy`).
- [x] **Step 5: Run Step 1's test again.** Expected: PASS.
- [x] **Step 6: Measure PHPStan** report by report: the `class.missingImplements` reports on `HasTranslations` (8 in the baseline, 2 open) are gone; remove the 8 baseline entries.
- [x] **Step 7: Commit and open PRs** in Core, CMS, then the app (test plus baseline), each body naming the others as dependencies.

### Task 2: The column-declaration command

**Files:**
- Create: `Modules/Core/app/Console/DeclareModelColumnsCommand.php` (moved from the app on the owner's request, 2026-11-24: Core is the layer every module builds on, and it already registers ide-helper)
- Test: `Modules/Core/tests/Feature/Console/DeclareModelColumnsCommandTest.php` (throw-away models written to a temp directory, no stubs)

**Interfaces:**
- Produces: `php artisan models:declare-columns {--module=* : Module names; app models when absent} {--from= : An ide-helper models file} {--dry-run : Print the docblocks, write nothing}`. It runs `ide-helper:models --nowrite`, reads the `@property` lines of each class in `_ide_helper_models.php`, keeps those whose name is a column of the model's table (`Schema::getColumnListing()`), skips a model whose parent model has the same table (D1, Review Focus 1), and writes them into the model file.

- [x] **Step 1: Write the failing tests** against stub models: `it writes a property tag for each column and none for relations`, `it keeps the existing docblock lines` (a stub with a description and `@phpstan-use`), `it writes the docblock above the class attributes` (a stub with `#[ObservedBy]`), `it creates a docblock when the class has none`, `it skips a model whose parent owns the same table`, `it replaces nothing when run twice` (idempotent: second run leaves the file byte-identical), `it writes nothing with --dry-run`.
- [x] **Step 2: Run them.** Expected: FAIL, command not found.
- [x] **Step 3: Implement the command.** The column block goes at the end of the class docblock, after one ` *` separator line, sorted in table column order; existing `@property` lines for the same names are replaced, all other lines kept.
- [x] **Step 4: Run the tests.** Expected: PASS. Pint, type coverage.
- [x] **Step 5: Commit and open the PR** (getlaraplate/laracore, after the move).

### Task 3: Declare the columns, module by module

**Files:** every model under `Modules/<Name>/app/Models` and `app/Models`.

**Interfaces:**
- Consumes: `models:declare-columns` from Task 2, merged.

One step per repository, in this order: Core first (others extend its models), then Shop, AI, CRM, CMS, MES, SAO, ERP, the app. Each step: `php artisan migrate:fresh`, `php artisan models:declare-columns --module=<Name>`, read the diff (types that look wrong are corrected by hand and listed in the PR body), run the module suite, measure PHPStan with `checkModelProperties` still off, PR.

- [x] **Step 1: Core**
- [x] **Step 2: Shop**
- [x] **Step 3: AI**
- [x] **Step 4: CRM**
- [x] **Step 5: CMS**
- [x] **Step 6: MES**
- [x] **Step 7: SAO**
- [x] **Step 8: ERP**
- [-] **Step 9: app (`App\Models\User`)**: nothing to declare, it uses Core `User`'s table, declared by the Core step.

### Task 4: The drift test

**Files:**
- Create: `tests/Feature/ModelsDeclareTheirColumnsTest.php` (app)

- [ ] **Step 1: Write the tests:** `it declares every column of every model` (columns of the table, migrated by `RefreshDatabase`, against the `@property`/`@property-read` names of the class and its parent models), `it names the model and the column that has no tag`, `it accepts columns declared on a parent model of the same table`, `it refuses a tag that is neither a column, a relation nor an accessor` (a name with no method of that name and no `get…Attribute`/`Attribute` accessor). Read the tags with `ReflectionClass::getDocComment()` and a regex on `@property(-read)?\s+\S+\s+\$(\w+)`.
- [ ] **Step 2: Run against a stub with a missing tag** to see the failure message, then against the real models. Expected: PASS once Task 3 is merged everywhere.
- [ ] **Step 3: Commit and open the app PR.**

### Task 5: Turn the column checks back on

**Files:**
- Modify: `phpstan.neon` (`checkModelProperties: true`, delete the two `property.notFound` rules on `\Models\` and their comment, rewrite the `checkModelProperties` comment to point at D1)
- Modify: `phpstan-baseline.neon` (remove entries that no longer match)

- [ ] **Step 1: Measure** `composer check` before and after the neon change, report by report.
- [ ] **Step 2: List stale baseline entries** with a probe config that sets `reportUnmatchedIgnoredErrors: true`; remove exactly those, verify the remaining entries are byte-identical.
- [ ] **Step 3: Fix what the change reports as real** (a column read that is not in the schema, a wrong type now visible): each in the module that owns it, one PR per module, before the neon PR merges. Report the rest by category in the PR body; it is the backlog of Task 6.
- [ ] **Step 4: Commit and open the app PR.**

### Task 6: The module backlog

One step per module, smallest first. Each step: list the module's reports from `composer check --error-format=json`, fix them in the code (D4), run the module suite and type coverage, measure report by report, PR. Reports that need a behaviour decision are not guessed: they are listed for the owner in the PR body.

- [x] **Step 1: Shop** (nothing left once Tasks 1-5 merged)
- [x] **Step 2: MES** (larames#10; the production order snapshots typed by hand)
- [x] **Step 3: CRM** (laracrm#9) (includes `SalesPipelineService` reading `object` properties, the `sync()` arguments in the contact pages)
- [x] **Step 4: CMS** (laracms#30; 7 reports wait for Core: IOwnerAuthorizer variance, BatchSeeder command accessor)
- [ ] **Step 5: SAO**
- [ ] **Step 6: ERP** (includes the three `->comment()` calls after `->constrained()` in `create_companies_table`, `create_parties_table`, `create_party_bank_accounts_table`, as laracrm#6 did)
- [ ] **Step 7: AI**
- [ ] **Step 8: Core** (includes `HasFilamentFormDataSanitizer`: split the create and edit overrides so no page calls a parent that does not exist)

### Task 7: Close the plan

- [ ] **Step 1: Document** the convention in `docs/static-analysis.md` (new, app): what a model declares and why (D1), the command, the drift test, the named rules in `phpstan.neon` and how to add one (D4). Add the `ITranslatableModel` requirement to the translations section of `Modules/Core/docs/rag/MODULE.md`.
- [ ] **Step 2: Close** with `status: completed`, a `## Delivery status (date):` section with the `**Documented in:**` line, every box resolved; update `INDEX.md`.
