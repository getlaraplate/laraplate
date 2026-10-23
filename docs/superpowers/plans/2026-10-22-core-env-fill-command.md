---
status: completed
created_on: 2026-10-22
---
# Core `env:fill` command and a complete `.env.example`. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** an installer can align a production `.env` with `.env.example` after an update by running `php artisan env:fill`, and `.env.example` lists every Laraplate-specific variable the module configs read, kept so by a test.

**Architecture:** three small classes in `Modules/Core/app/Support/Env` (`EnvValue`, `EnvEntry`/`EnvFile`, `ConfigCacheRefresher`) hold all the logic and are unit tested without prompts; `EnvFillCommand` is the prompt loop on top of them. The example is completed by a one-off generator whose output is reviewed, and a guard test in the `laraplate` root keeps it complete.

**Tech Stack:** PHP 8.5, Laravel 12, Laravel Prompts 0.3.26 (`suggest`, `text`, `password`, `confirm`), `vlucas/phpdotenv` (`Dotenv::parse`), Pest. No new dependency.

**Spec:** `../specs/2026-10-22-core-env-fill-command-design.md` (decisions D1 to D8, sections 4 to 7).

## Global Constraints

- Branch and pull request, never `master`; worktrees from `origin/master`. Code lives in `getlaraplate/laracore` (`Modules/Core`); the guard test, `.env.example`, docs index and this plan live in `laraplate`. Commit with `git -c user.name=swolley -c user.email=myentity@inwind.it commit`, no `Co-Authored-By`. Never bump a submodule pointer.
- `declare(strict_types=1)`, `final`, `readonly` where possible, explicit types, PHPDoc over inline comments, `#[Override]`. Pint from the laraplate root on explicit files: `vendor/bin/pint --format agent <files>` (`.ai/rules/modules.md`).
- Tests in `Modules/Core/tests` (Pest); no class declared in a test file (stubs in `Modules/Core/tests/Stubs/` with PSR-4 namespaces already registered). Run `php artisan config:clear` before tests.
- **No test may run the real `config:cache`.** A cached config makes the suite hit the real database and `tests/bootstrap.php` aborts later runs. The cache step goes through `ConfigCacheRefresher`, which tests replace with a recording stub; every command test asserts afterwards that `bootstrap/cache/config.php` does not exist.
- A value the user types is never printed back, logged or put in an exception message. Secret names are asked with the masked prompt.
- Scope of the example (spec D8): every `Modules/*/config/*.php` except files whose name starts with `scout`, `elastic`, `media-library`, `permission`, `swagger-ui` or `laravel-swagger`; Laravel's root `config/` is out.
- English in code and docs, no em-dashes.

## Review Focus

- A test that would run the real `config:cache` (Task 3).
- A secret typed at the masked prompt appearing in output, the summary or the file's backup/temp name (Task 3).
- Values with a space, `#`, `"`, `'`, `$`, backslash, `=`, unicode or a line break written and read back different (Task 1).
- A `.env` without a final newline, with CRLF line endings, with mode 0600, or with a multi-line quoted value, damaged by the append (Task 2).
- A name typed in lowercase, with a space, or already written in this run, accepted as if it were missing (Task 3).

---

### Task 1: `EnvValue`

**Files:** create `Modules/Core/app/Support/Env/EnvValue.php`; test `Modules/Core/tests/Unit/Support/Env/EnvValueTest.php`.

**Interfaces:**
- Produces: `EnvValue::format(string $value): string` (the text after `NAME=`), `EnvValue::isSecretName(string $name): bool`.

- [x] **Step 1: Failing tests:** `it('writes a plain value bare')` (`abc`, `http://localhost:8080`, `1.5`); `it('quotes and escapes what Dotenv would read differently')` and `it('round-trips through Dotenv::parse')` over a dataset: empty string, spaces, leading/trailing space, `#`, `a#b`, `"`, `'`, `$HOME`, `${X}`, backslash, `a=b`, backtick, `è日本`, `line1\nline2` (the break is written as `\n` in double quotes); each asserted as `Dotenv\Dotenv::parse('NAME=' . EnvValue::format($v))['NAME'] === $v`; `it('detects secret names')` for `API_KEY`, `DB_PASSWORD`, `GITHUB_CLIENT_SECRET`, `ACCESS_TOKEN`, `SOME_PASS`, `X_CREDENTIAL` and not for `APP_NAME`, `KEYBOARD_LAYOUT`, `PASSIVE_MODE` (match `KEY|SECRET|PASSWORD|PASS|TOKEN|CREDENTIAL` as whole underscore-separated words or suffixes: `_KEY`, `_SECRET`, `_PASSWORD`, `_PASS`, `_TOKEN`, `_CREDENTIAL`, or the name equal to one of them).
- [x] **Step 2: Run, expect FAIL** (`php artisan config:clear && php artisan test --compact Modules/Core/tests/Unit/Support/Env/EnvValueTest.php`).
- [x] **Step 3: Implement** both methods. Bare when the value matches `^[A-Za-z0-9_.:\/@%+,-]+$`; otherwise double-quoted with `\`, `"` and `$` backslash-escaped and line breaks written as `\n`.
- [x] **Step 4: Run, expect PASS**; pint on the two files.
- [x] **Step 5: Commit** in `Modules/Core` `feat: format and classify .env values`.

### Task 2: `EnvEntry`, `EnvFile`, `ConfigCacheRefresher`

**Files:** create `Modules/Core/app/Support/Env/EnvEntry.php`, `EnvFile.php`, `ConfigCacheRefresher.php`, `Modules/Core/tests/Stubs/RecordingConfigCacheRefresher.php`; test `Modules/Core/tests/Unit/Support/Env/EnvFileTest.php`.

**Interfaces:**
- Consumes: `EnvValue::format`.
- Produces: `EnvEntry(string $name, string $value, bool $commented, string $comment)` (`comment` = the comment lines directly above the entry, without the `#`, joined by a space; empty when none). `EnvFile::read(string $path): self` (throws `RuntimeException` when unreadable, `Dotenv\Exception\InvalidFileException` is wrapped in a `RuntimeException` that names the line but not the value); `entries(): list<EnvEntry>` (every `NAME=` and `# NAME=` line in file order); `definedNames(): list<string>` (active ones, read with `Dotenv::parse` so multi-line values are respected); `missingFrom(self $example): list<string>` (names of the example, active or commented, in example order and unique, that are not in `definedNames()`); `entry(string $name): ?EnvEntry`; `append(string $name, string $value): void` (atomic, updates the in-memory state). `ConfigCacheRefresher::refresh(): void` runs `config:clear` then `config:cache` through `Artisan::call`; the stub records the call in `public int $calls`.

- [x] **Step 1: Failing tests:** `it('reads active, commented, quoted and empty entries and the comment block above each')`; `it('ignores prose comments that are not NAME= lines')`; `it('counts an empty value as defined and a commented line as missing')`; `it('lists the missing names in example order, once each')`; `it('reads names after a multi-line quoted value correctly')`; `it('appends NAME=value and leaves every other byte of the file as it was')`; `it('adds the missing final newline first')`; `it('keeps CRLF endings when the file uses them')`; `it('keeps the file mode (0600)')`; `it('leaves the original untouched when the write fails')` (read-only directory); `it('refuses to append a name that is not NAME-shaped or is already defined')`; `it('wraps a parse error without the value')`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement.** Entry lines by regex `^\s*(#\s*)?([A-Z][A-Z0-9_]*)=(.*)$`; the example value shown as default is the entry value with one pair of surrounding quotes removed. Append: write the whole new content to `tempnam(dirname($path), '.env.')`, `chmod` it to `fileperms($path) & 0777`, `rename()` over the original, `unlink` the temp on failure.
- [x] **Step 4: Run, expect PASS**; pint.
- [x] **Step 5: Commit** in `Modules/Core` `feat: read and extend .env files atomically`.

### Task 3: `EnvFillCommand`

**Files:** create `Modules/Core/app/Console/EnvFillCommand.php` (discovered from the module's `Console` folder; confirm with `php artisan list env`); test `Modules/Core/tests/Feature/Console/EnvFillCommandTest.php`.

**Interfaces:**
- Consumes: Tasks 1 and 2; container binding of `ConfigCacheRefresher` (tests bind the stub).
- Produces: `php artisan env:fill {--file=} {--example=}`, exit `0` on completion, `1` on an unreadable file or in non-interactive mode.

- [x] **Step 1: Failing tests** (temp directory with a `.env` and an example; `afterEach` asserts `bootstrap/cache/config.php` is absent): `it('proposes only the names missing from the .env, in example order')`; `it('exits 0 with a message and no prompt when nothing is missing')`; `it('writes each answer at once and drops it from the suggestions')` (two answers, assert the file after the run and the suggestion list of the second prompt); `it('refuses a name that is not missing, lowercase or with a space, and asks again')`; `it('ends on an empty answer and asks no cache question when nothing was written')`; `it('offers the default of the example and the comment above it, and an empty answer writes NAME=')`; `it('asks secret names with the masked prompt and never prints the value')` (assert the output and the summary do not contain the typed secret); `it('asks to refresh the cache after a write and calls the refresher on yes, not on no')`; `it('defaults the cache question to yes in production and no elsewhere')`; `it('never asks the cache question for a --file other than the application .env')`; `it('exits 1 with the reason when the file is missing or read-only, and creates nothing')`; `it('exits 1 and writes nothing with --no-interaction')`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** the flow of spec 4.1 with `suggest` (label `Variable to set`, placeholder "Press Enter with no name to finish", hint "<n> missing"), then `text` (label the variable name, `default` the example value, `hint` the comment) or `password` for secret names, then `EnvFile::append`; the cache question is `confirm('Run config:clear and config:cache now?', default: app()->isProduction(), hint: ...)` with the hint of spec 4.4, asked only when the target path equals `base_path('.env')` and at least one write happened. The summary prints names written and whether the cache was refreshed, never values.
- [x] **Step 4: Run, expect PASS**; pint.
- [x] **Step 5: Commit** in `Modules/Core` `feat: add env:fill to align a .env with .env.example`.

### Task 4: Complete `.env.example` and the guard test

Starts only after the plan `2026-10-22-ai-standard-servers.md` has landed (spec section 5): the nine variables of the removed AI services are then gone from the config and are never added.

**Files:** create `tests/Support/ConfigEnvNames.php`, `tests/Unit/EnvExampleCoversConfigTest.php` in the `laraplate` root; modify `.env.example`; delete `Modules/AI/tests/Unit/EnvExampleListsAiServersTest.php` if the AI plan created it (commit in `Modules/AI`).

**Interfaces:**
- Produces: `Tests\Support\ConfigEnvNames::inScope(string $root): list<string>` (sorted unique names passed to `env('NAME'` in the files of the scope above) and `ConfigEnvNames::uncovered(string $root, string $examplePath): list<string>` (those with neither `NAME=` nor `# NAME=` in the example).

- [x] **Step 1: Failing tests:** `it('covers every Laraplate variable the module configs read')` (asserts `uncovered(base_path(), base_path('.env.example'))` is `[]`, and on failure the message lists the names and says to add each as `# NAME=default`); `it('reports a name a fixture config reads and the example lacks')` and `it('skips package configs and Laravel root config')` over a temp tree with `Modules/X/config/config.php` (`env('NEW_VAR')`), `Modules/X/config/scout.php` (`env('SCOUT_X')`) and `config/app.php` (`env('LARAVEL_X')`): only `NEW_VAR` is reported.
- [x] **Step 2: Run, expect FAIL** with the real list of missing names (`php artisan test --compact tests/Unit/EnvExampleCoversConfigTest.php`).
- [x] **Step 3: Generate the block** with a one-off script kept outside the repository (scratchpad): it parses the in-scope config files with `nikic/php-parser` (already in `vendor`), and for each missing name emits `# NAME=default` where `default` is the literal scalar default of the `env('NAME', default)` call (empty otherwise), the comment that sits above the config key as a `#` line, grouped under `# --- Core ---`, `# --- AI ---` and so on (modules in the order Core, CMS, AI, ERP, MES, SAO). Write the list `name  config path  module` to the scratchpad and **show it to the owner**, who may drop names whose config key no code reads any more.
- [x] **Step 4: Apply** the reviewed block at the end of `.env.example`, commented, grouped, with nothing existing changed or reordered.
- [x] **Step 5: Run, expect PASS**; also `php artisan test --compact tests/Unit` for the other guard tests; pint on the two PHP files.
- [x] **Step 6: Commit** in `laraplate` `chore: list every Laraplate variable in .env.example and keep it complete`; in `Modules/AI` `test: drop the narrow env example check` when the file existed.

### Task 5: Documentation and closing

**Files:** modify `Modules/Core/docs/rag/MODULE.md` (a section on `env:fill`), `Modules/Core/README.md` (the commands list), the section of the root `README.md` that says how to create the `.env` (`grep -n "env.example" README.md`), `Modules/Core/CHANGELOG.md`, the spec status, both `INDEX.md` lines, this plan.

- [x] **Step 1: Docs.** What the command does and when to run it (after every update, on the production `.env`), the loop and its exit, the masked prompt for secrets, the cache question and why its default differs, `--file` and `--example`, the rule that `.env.example` lists every Laraplate variable and the guard test that enforces it.
- [x] **Step 2: Spec and indexes.** Set the spec status to `Built` with the date; update both `INDEX.md` lines.
- [x] **Step 3: Close this plan:** tick every step, add `## Delivery status (date): shipped` with each divergence and its reason, `**Documented in:**` naming `Modules/Core/docs/rag/MODULE.md` and `Modules/Core/README.md` (enforced by `tests/Unit/ClosedPlansPointToDocumentationTest.php`), `status: closed`; run that test.
- [x] **Step 4: Run** `php artisan test --compact Modules/Core/tests/Unit/Support Modules/Core/tests/Feature/Console/EnvFillCommandTest.php tests/Unit` and ask the owner to run the complete suite; commit docs in `Modules/Core` `docs: document env:fill`, plan and specs in `laraplate` `docs: close the env:fill plan`.

## Delivery status (2026-10-23): built, pending the owner's merge and review of the variable list

Tasks 1 to 5 are built: the command and its classes in `Modules/Core` (laracore pull request 5), the guard test and the completed `.env.example` in the application (laraplate pull request 30, stacked on 29). Core Unit, Console and Support suites pass (953 tests). The owner is asked to run the complete suite. Divergences from the plan, each with its reason:

- **Task 1.** An empty value is written bare (`NAME=`), as spec 4.2 says and Dotenv reads it back empty, not `NAME=""`; the spec text was corrected.
- **Task 2.** The refresher sits behind a contract, `Contracts\RefreshesConfigCache` (a project rule: no `final` removed, mock through interfaces), and `ConfigCacheRefresherTest` is a Feature test (Core Unit tests run without an application).
- **Task 3.** The harness cannot see the options of a `suggest` prompt or the default of a `confirm`, so the proposals are asserted through `EnvFileTest` and the "N variables missing" line, and the cache default through the public `refreshesCacheByDefault()`.
- **Task 4.** The guard test also skips `fortify` and `laravel-impersonate` configs and ignores a variable named only in a comment; the 139 variables were generated from the configs pinned by `origin/master` and are listed for the owner's review in the shared notes folder. The block follows the existing "<Module> Module" grouping of the example, after its sections.
- **Final review.** A fresh reviewer found 16 issues. Fixed with a failing test first: a symlinked `.env` was replaced by a regular file; the append built on the copy read at start (an edit made meanwhile was reverted); a failed write raised a PHP warning that escaped with the value in its trace and left a temporary copy; the owner was lost for a superuser; accepting the default wrote an escaped `${APP_NAME}` and prose after an example variable was taken for its default; no terminal on standard input exited `0`. The minors were fixed afterwards on 2026-10-23, each with a failing test first, except what a prompt cannot do: a multi-line paste is still cut at its first line (the README says to edit the file for a PEM key). Fixed: CRLF is written as `\r\n`; a secret name is any with a secret word (`AWS_ACCESS_KEY_ID`); the temporary file is flushed to the disk (plain files) and its length compared in bytes (a formatter had turned that into a character count, which refused any multibyte value, found while fixing this); the writability check comes before the "nothing missing" exit; a quoted example value over several lines offers no default; the refresher fails on a non-zero exit instead of reporting the cache rebuilt; `EnvFile` is plain PHP again; the guard test reads `env("X")`, ignores `getenv()` and methods named `env`, and removes its temporary directories; module headers of the example are separated from the first variable's hint. Still true: the masked-prompt test cannot tell `password` from `text`.

**Documented in:** `Modules/Core/docs/rag/MODULE.md` (section Environment), `Modules/Core/README.md`.
