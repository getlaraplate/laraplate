---
module: platform
---
# Pest 5, PHPUnit 13 and the Module Suites Under Analysis Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the test toolchain to Pest 5 and PHPUnit 13, install `pestphp/pest-plugin-phpstan` and put the eight module test suites back under static analysis with the PHPStan baseline regenerated, then use what Pest 5 actually adds to shorten a 1h43m suite: test impact analysis and time-balanced shards.

**Architecture:** Three independent gates, in order, each with its own green signal: the runner upgrade (the suite passes on Pest 5), the analysis surface (`composer test:types` passes with the module suites included), and the documentation. Keeping them apart matters because the second gate is open-ended by nature: `phpstan.neon` records that the module suites produced 23.067 of 33.282 errors while the Pest extension was missing, and nobody knows yet how many of those were artefacts and how many are real findings in test code that has never been analysed.

**Tech Stack:** PHP 8.5, Laravel 13, Pest 5, PHPUnit 13, PHPStan 2 (level 9), larastan.

**Spec:** No external spec. The decisions this plan executes are recorded in two places in the repository: the `excludePaths` comment in `phpstan.neon` (which says to delete those two lines once the plugin is installed) and the `includes` comment on `phpstan-baseline.neon` (which says to shrink the debt by category and regenerate, never to add to it). The user approved adding `pestphp/pest-plugin-phpstan` on 2026-09-16.

## Global Constraints

- **Unblocked on 2026-11-05**: the Laravel 13 upgrade reached a booting application on `laravel/framework v13.40.0`, so `pest-plugin-laravel` 5's `^13.23.0` requirement is satisfiable. Originally blocked on it: (`2026-09-16-laravel-13-upgrade.md`). `pestphp/pest-plugin-laravel v5.0.1` requires `laravel/framework ^13.23.0`, so none of this can land while the application is on Laravel 12. Do not start Task 1 before that plan is closed. This plan was split out of it on 2026-10-29 precisely so a framework upgrade is not held hostage to an open-ended analysis task.
- Target versions, read from the library census `/srv/http/laraplate-stack/laraplate13/composer.lock` on 2026-10-29: `pestphp/pest v5.3.4`, `pestphp/pest-plugin-laravel v5.0.1`, `pestphp/pest-plugin-stressless v5.0.2`, `pestphp/pest-plugin-type-coverage v5.0.3`, `peckphp/peck v0.3.0`. The census does not hold `pestphp/pest-plugin-phpstan`; Packagist has `v5.2.x`, requiring `pestphp/pest ^5.0.0` and `phpstan/phpstan ^2.2.5` (installed: 2.3.4).
- No new dependency beyond `pestphp/pest-plugin-phpstan` without user approval.
- `php artisan optimize:clear` before every test run. `tests/Support/TestEnvironmentGuard.php:26` aborts the whole suite while `bootstrap/cache/config.php` exists, and Artisan commands recreate it.
- Never add to `phpstan-baseline.neon` by hand to silence something. Regenerating it wholesale (Task 2) is a deliberate, reported act, not a silencing.
- Do not delete or weaken a test to make analysis pass. If a finding in a test file is real, fix the test; if it is an artefact of the extension, report it rather than excluding the path again.
- ~~All work on branch `toolchain/pest-5`~~ **superseded on 2026-11-05: all work is on `master`**, in the root repository and in every module repository, because the user decided during the Laravel 13 upgrade that a submodule only gets a branch when it needs one and that this stack does not carry a branch per task. Module repositories are only touched if a finding lives in a module's files, each in its own submodule commit. No `Co-Authored-By` trailer in commit messages.
- The library census at `/srv/http/laraplate-stack/laraplate13/composer.lock` **no longer exists**: it was deleted on 2026-11-05 as the last step of the Laravel 13 upgrade, at the user's request. The versions quoted above stay as the dated record of what it held; every one of them was checked against Packagist on 2026-11-05 before Task 1 and the installed set is equal or newer.
- Commands run from `/srv/http/laraplate-stack/laraplate`. Formatting: `vendor/bin/pint --dirty --format agent`.

---

### Task 1: Pest 5 and PHPUnit 13

**Files:**
- Modify: `composer.json` (`require-dev`), `composer.lock`
- Modify: `phpunit.xml` (only if Pest asks to migrate the schema)

**Interfaces:**
- Consumes: a closed Laravel 13 upgrade.
- Produces: `pestphp/pest 5.x` and `phpunit/phpunit 13.x` installed, suite result unchanged from the Laravel 13 baseline.

- [x] **Step 1: Record the starting point**

```bash
php artisan optimize:clear
mkdir -p storage/logs/toolchain-pest5
php artisan test --compact 2>&1 | tee "storage/logs/toolchain-pest5/before.txt" | tail -5
grep -E '^\s+(FAIL|⨯)' "storage/logs/toolchain-pest5/before.txt" > "storage/logs/toolchain-pest5/before-failures.txt" || true
```
`storage/logs/` is gitignored. Expected: the suite summary. Any failure here predates this plan and is the bar every later run is compared against, not a regression to fix.

- [x] **Step 2: Bump the dev constraints**

In `composer.json` `require-dev`:

| package | from | to |
|---|---|---|
| `pestphp/pest` | `^4.7` | `^5.3` |
| `pestphp/pest-plugin-laravel` | `^4.1` | `^5.0` |
| `pestphp/pest-plugin-stressless` | `^4.0` | `^5.0` |
| `pestphp/pest-plugin-type-coverage` | `^4.0` | `^5.0` |
| `peckphp/peck` | `^0.1.3` | `^0.3.0` |

Leave `nunomaduro/collision ^8.9.4` as is: Pest 5 requires `^8.9.5`, which that constraint already admits. Do not add `pestphp/pest-plugin-phpstan` yet: it is Task 2's first step, so that a red suite and a red analysis never arrive together.

- [x] **Step 3: Resolve**

```bash
composer update -W --ignore-platform-req=ext-oci8 'pestphp/*' peckphp/peck 'phpunit/*' 'sebastian/*'
```
Expected: `pestphp/pest v5.3.x`, `phpunit/phpunit 13.x`. If the solve fails on a package the census does not hold, STOP and report it: do not remove packages without approval.

- [x] **Step 4: Migrate the PHPUnit configuration if asked**

```bash
php artisan optimize:clear
vendor/bin/pest --compact tests/Unit/ClosedPlansPointToDocumentationTest.php
```
If the output warns that the XML configuration validates against a deprecated schema, run `vendor/bin/pest --migrate-configuration`, then review the `phpunit.xml` diff: only schema and attribute changes are allowed, and the `Unit`, `Integration` and `Feature` suites must survive with the same names. `composer test:unit`, `test:integration` and `test:feature` select them by name and must keep working:
```bash
composer test:unit 2>&1 | tail -3
```
Expected: the Unit suite runs and reports a count, not "no tests found".

- [ ] **Step 5: Run the suite and compare**

```bash
php artisan optimize:clear
php artisan test --compact 2>&1 | tee "storage/logs/toolchain-pest5/pest5.txt" | tail -5
grep -E '^\s+(FAIL|⨯)' "storage/logs/toolchain-pest5/pest5.txt" > "storage/logs/toolchain-pest5/pest5-failures.txt" || true
diff "storage/logs/toolchain-pest5/before-failures.txt" "storage/logs/toolchain-pest5/pest5-failures.txt"
```
Expected: `diff` prints nothing. Every `>` line is a PHPUnit 13 regression: a removed assertion, a removed annotation, a changed data-provider or mock API. Fix one at a time with superpowers:systematic-debugging, rerun that file, `vendor/bin/pint --dirty --format agent`, and commit each fix in the repository that owns the test.

**Result on 2026-11-05: two regressions, one cause, and it was not PHPUnit.** The solve also moved `symfony/console` and `symfony/finder` from 7.4.23 to 8.1.11, pulled in by PHPUnit 13. Symfony Console 8.0 removed `Application::add()` in favour of `addCommand()`, and the two `Modules\Core\tests\Feature\Console\ModelMakeCommandTest` cases that build a bare `Symfony\Component\Console\Application` died with `Call to undefined method`. `Illuminate\Console\Application` still defines `add()` as an alias of its own `addCommand()`, which is why `CoreServiceProvider::registerConsoleCommandOverrides()` kept working and no production path broke; it was changed to `addCommand()` anyway, since the alias now names a method that no longer exists upstream. `php artisan list --internals` still lists the module commands through the `ListCommand` override, and the 371 tests of `Modules/Core/tests/Feature/Console` pass.

The rest of the Symfony Console 8.0 removals were checked against the repository and none applies: no `Command::getDefaultName()` or `getDefaultDescription()` caller, nothing extending the now-final `AsCommand`, no class implementing `OutputInterface` directly (which gained `isSilent()`), no `ProgressIndicator` and no `Command::setCode()` caller. Symfony Finder 8.0 removes nothing.

Suite on Pest 5, three processes: unit **1794 passed** (291s), integration **1 risky, 14 skipped, 3432 passed** (1097s), feature **6217 passed with 7 failed** before the fix and the five baseline failures after it. Integration is numerically identical to the Laravel 12 and Laravel 13 baselines.

Step 5 is left open: the confirming feature run after the `addCommand()` fix was still in flight when work stopped on 2026-11-05. Its log is `storage/logs/toolchain-pest5/pest5-feature2.txt` (gitignored) and the bar it has to match is the five lines of `storage/logs/upgrade-l13/l13-failures.txt`. The two fixed cases were rerun on their own and pass, so what is unconfirmed is only that nothing else moved.

- [x] **Step 6: Type coverage still measures**

```bash
composer test:type-coverage 2>&1 | tail -5
```
Expected: a coverage percentage, and the `--min=100` gate behaves as before. The type-coverage plugin changed major version in Step 3, so this is the one script whose output format can move without any test failing.

**Result on 2026-11-05: the gate did not behave as before, because it had never behaved at all.** `pest-plugin-type-coverage` v5 crashed with `The "" directory does not exist`. Its `ConfigurationSourceDetector` calls `realpath()` on each `<source>` include directory, and `phpunit.xml` listed `Modules/*/app`: PHPUnit expands a glob in a `<testsuite>` `<directory>` but not in a `<source>` one, so `realpath()` returned false and v5 stringified it to `""`. v4 skipped the empty entry instead of failing, which is why nothing had ever reported it.

So `composer test:type-coverage` was measuring the three files under `app/` and printing `Total: 100.0 %`. Measured both surfaces to be sure: `app` alone is 100% over 3 files; `app` plus the eight module directories is **99.4%** over 547, with **58 files at 75%** (AI 5, CMS 6, Core 9, CRM 1, ERP 9, MES 17, SAO 11) — roughly a hundred missing parameter types and a few untyped class constants.

`phpunit.xml` now lists the eight module directories one by one, so coverage and type coverage both see module code, and `tests/Unit/ModuleSourcePathsAreAnalysedTest.php` fails if a module is added without its line or if any entry stops resolving. `--min=100` was left untouched and is therefore now red at 99.4%: a gate that measured 3 of 547 files was not a gate, and lowering the threshold to keep the colour green would re-hide what it just revealed. Closing those 58 files spans seven module repositories and is not a runner upgrade, so it is Task 5 rather than something done under cover of this one.

- [x] **Step 7: Commit (root)**

```bash
git add composer.json composer.lock phpunit.xml
git commit -m "chore(deps): upgrade the test runner to Pest 5 and PHPUnit 13"
```

---

### Task 2: The module suites under analysis

**Files:**
- Modify: `composer.json` (`require-dev`), `composer.lock`
- Modify: `phpstan.neon` (the commented include on line 10, and the two `Modules/*/tests/*` entries in `excludePaths` with the comment above them)
- Modify: `phpstan-baseline.neon` (regenerated)

**Interfaces:**
- Consumes: Pest 5 installed (Task 1).
- Produces: `composer test:types` green with `Modules/*/tests/**` analysed, and a reported before/after debt count.

- [ ] **Step 1: Install the plugin**

Add to `composer.json` `require-dev`:
```json
"pestphp/pest-plugin-phpstan": "^5.2",
```
Then:
```bash
composer update -W --ignore-platform-req=ext-oci8 'pestphp/*' 'phpstan/*'
composer show pestphp/pest-plugin-phpstan | grep versions
```
Expected: `v5.2.x` or newer 5.x.

- [ ] **Step 2: Record the debt before changing anything**

```bash
composer check:debt -- --quiet
```
Expected: a single number, the errors the baseline is holding. The header of `phpstan-baseline.neon` records 9.851 frozen on 2026-09-17, with the module suites excluded. Write both numbers down: Step 6 reports the movement, and Task 3 records it in the delivery status.

- [ ] **Step 3: Enable the extension**

The repository has no `phpstan/extension-installer`, so the plugin's `extra.phpstan.includes` is not picked up. In `phpstan.neon` line 10 replace:
```neon
    # - vendor/pestphp/pest-plugin-phpstan/extension.neon
```
with:
```neon
    - vendor/pestphp/pest-plugin-phpstan/extension.neon
```
Run: `composer test:types 2>&1 | tail -5`
Expected: still green. The module suites are still excluded at this point, so this step only proves the include resolves and the extension loads. A failure here is a wiring problem, not a findings problem: fix it before Step 4, where the two are hard to tell apart.

- [ ] **Step 4: Put the module suites back under analysis**

In `phpstan.neon` `excludePaths`, delete these two entries together with the comment block above them that explains them and says to delete them when the plugin is installed:
```neon
        - Modules/*/tests/*
        - Modules/*/tests/**
```
Keep the `Modules/*/stubs/*` and `Modules/*/stubs/**` entries: their reason is a deliberate `final` extension that works only under BypassFinals, unrelated to Pest.

```bash
composer test:types 2>&1 | tail -30
```
Expected: errors, possibly thousands. What must be **absent** is the identifier `method.internalClass`: `phpstan.neon` recorded 11.793 of them, one for every ordinary `expect(...)->toBe(...)` call read as reaching into Pest's internals. Check explicitly:
```bash
vendor/bin/phpstan analyse --memory-limit=4G --error-format=json 2>/dev/null \
  | php -r '$d=json_decode(stream_get_contents(STDIN),true); $c=[]; foreach(($d["files"]??[]) as $f){ foreach($f["messages"] as $m){ $c[$m["identifier"]??"(none)"]=($c[$m["identifier"]??"(none)"]??0)+1; } } arsort($c); $i=0; foreach($c as $k=>$n){ printf("%-45s %d\n",$k,$n); if(++$i>=15) break; }'
```
Expected: `method.internalClass` absent or negligible. If it is still in the thousands, the extension is not actually applying to these paths: STOP and report, rather than regenerating a baseline that freezes artefacts.

- [ ] **Step 5: Regenerate the baseline**

```bash
vendor/bin/phpstan analyse --memory-limit=4G --generate-baseline=phpstan-baseline.neon
composer test:types 2>&1 | tail -5
```
Expected: `test:types` green against the new baseline. Then update the comment at the top of `phpstan.neon` so it stops describing a baseline that no longer exists: replace the "9.851 errors that existed on 2026-09-17" sentence with the new count and today's date, keeping the rest of the wording (it is the instruction not to add to the baseline, which still stands).

- [ ] **Step 6: Report the movement**

```bash
composer check:debt
git diff --stat phpstan-baseline.neon
```
Report to the user: the debt before (Step 2), the debt after, and how much of the growth is `Modules/*/tests/**`. Growth confined to the test suites is the expected outcome of analysing code that was never analysed. Growth in `app/` or `Modules/*/app/` is not: those are findings the Laravel 13 upgrade or the new package stubs exposed in production code, and they are worth fixing rather than freezing. If any appear, list them to the user and ask whether to fix them now or open a separate plan. Do not decide alone.

- [ ] **Step 7: Full gates and commit**

```bash
php artisan optimize:clear
php artisan test --compact 2>&1 | tail -5
composer test:lint
composer test:types
composer test:typos
composer test:licenses
```
Expected: the suite matches Task 1 Step 5, every gate green.

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock phpstan.neon phpstan-baseline.neon
git commit -m "chore(phpstan): analyse the module test suites with the Pest extension"
```

---

### Task 3: Test impact analysis and sharding

**Files:**
- Modify: `composer.json` (the `scripts` block)
- Create: `shards.json` (generated by Pest, committed)
- Modify: `.gitignore` if Pest writes TIA state into the repository

**Interfaces:**
- Consumes: Pest 5 installed (Task 1).
- Produces: a `test:tia` script that runs only the tests a change touches, and time-balanced shards for the three suites.

This is the reason to be on Pest 5 at all. The suite costs 1h43m serially (measured 2026-11-05: unit 344s, integration 1314s, feature 4511s), so the interesting release is not the runner upgrade but what shortens that loop.

Two facts settled before writing this task, so nobody re-derives them:
- `--parallel` and sharding already existed on Pest 4, and `composer test:pest:parallel` and `test:coverage` already use `--parallel`. Parallelism is not what Pest 5 adds.
- TIA needs a coverage driver, and Pest accepts Xdebug in coverage mode: `Pest\Support\Coverage::isAvailable()` returns true for PCOV, phpdbg, or Xdebug 3.1+ with `coverage` in `xdebug_info('mode')`. This machine reports `xdebug.mode=debug,coverage`, so TIA runs here. PCOV would be faster for graph generation and is not packaged for this distribution; do not install system extensions as part of this plan.

- [ ] **Step 1: Generate the TIA graph once and time it**

```bash
php artisan optimize:clear
mkdir -p storage/logs/toolchain-pest5
S=$(date +%s); vendor/bin/pest --tia --compact > storage/logs/toolchain-pest5/tia-first.txt 2>&1; echo "first run: $(( $(date +%s) - S ))s exit=$?"
```
Expected: a full run plus graph construction, so slower than the 1h43m baseline on this hardware, once. Record the number. If it fails for a missing coverage driver, re-read the facts above before changing anything: the driver is present, so a failure means something else.

- [ ] **Step 2: Prove the second run is short**

```bash
S=$(date +%s); vendor/bin/pest --tia --compact > storage/logs/toolchain-pest5/tia-warm.txt 2>&1; echo "warm run: $(( $(date +%s) - S ))s exit=$?"
tail -3 storage/logs/toolchain-pest5/tia-warm.txt
```
Expected: far fewer tests selected and a far shorter wall time, with nothing changed since Step 1. Then touch one file with known coverage and confirm the selection follows it:
```bash
touch Modules/Core/app/Cache/Repository.php
vendor/bin/pest --tia --compact 2>&1 | tail -3
```
Expected: the cache tests are selected and most of the suite is not. If the selection is the whole suite every time, TIA is not actually anchored: report the two timings and stop rather than adding a script that pretends to be fast.

- [ ] **Step 3: Decide what TIA state is committed**

```bash
git status --short
cat .gitignore | grep -iE "pest|tia|cache" 
```
Pest writes its TIA baseline and graph somewhere; whatever appears in `git status` after Steps 1 and 2 is that state. Decide, and record the decision in the delivery status: a committed baseline lets CI reuse it across runs, an ignored one makes every CI job rebuild the graph. Commit the `.gitignore` change or the state file, not both.

- [ ] **Step 4: Add the scripts**

In `composer.json` `scripts`, next to the existing `test:*` entries:
```json
"test:tia": "vendor/bin/pest --tia --compact",
"test:shards": "vendor/bin/pest --update-shards"
```
`--update-shards` writes `shards.json` with per-test timing so shards are balanced by measured duration instead of by count. Generate it once:
```bash
composer test:shards
head -20 shards.json
```
Expected: a file with timing data. Commit it: without it, `--shard=1/4` splits by count and the slowest shard decides the wall time, which on a suite where feature is 75 of 103 minutes defeats the purpose.

- [ ] **Step 5: Verify a shard actually runs a quarter**

```bash
S=$(date +%s); vendor/bin/pest --shard=1/4 --parallel --compact > storage/logs/toolchain-pest5/shard1.txt 2>&1; echo "shard 1/4 parallel: $(( $(date +%s) - S ))s exit=$?"
tail -3 storage/logs/toolchain-pest5/shard1.txt
```
Expected: roughly a quarter of the tests, and a wall time well under the full run. Note that `--parallel` forks workers that each get their own `memory_limit`, which is also the mechanism that makes the full suite completable at all: the serial run exhausts 4G. Record whether `--parallel` alone (no shard) now completes the whole suite, because if it does, that is the single most useful outcome of this plan.

- [ ] **Step 6: Commit**

```bash
git add composer.json shards.json .gitignore
git commit -m "chore(tests): add test impact analysis and time-balanced shards"
```

---

### Task 4: Documentation and close

**Files:**
- Modify: `AGENTS.md` (the Testing section)
- Modify: `docs/superpowers/plans/2026-10-29-test-toolchain-pest-5.md` (this file)
- Modify: `docs/superpowers/plans/INDEX.md`

**Interfaces:**
- Consumes: Tasks 1, 2 and 3.

- [ ] **Step 1: Record that module tests are analysed**

The `Testing` section of `AGENTS.md` tells agents what the tooling does. Add one bullet after the existing `vendor/bin/pint --dirty` line:
```markdown
- Module test suites are under PHPStan analysis (`pestphp/pest-plugin-phpstan`). A new test must pass `composer test:types`, not only the suite.
```
This is the behaviour change a future agent needs: before this plan, anything under `Modules/*/tests/` was invisible to `composer test:types`.

- [ ] **Step 2: Close the plan**

Set `status: completed` in this file's front matter, next to `module: platform`, and add at the end:
```markdown
## Delivery status (YYYY-MM-DD): shipped

**Documented in:** `AGENTS.md` (Testing section), `phpstan.neon` (the regenerated baseline's header records the new count and date).
```
Record there: the debt before and after, whether any finding landed in `app/` or `Modules/*/app/` and what was decided about it, and any PHPUnit 13 regression that needed a code change. Resolve every checkbox first, `- [x]` or `- [-]` with its reason on the same line (`.ai/rules/plans.md`).

- [ ] **Step 3: Index and verify**

Add one line to `docs/superpowers/plans/INDEX.md` describing this file (name and coverage, no status: `plan-status` reads the front matter).

```bash
php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php
composer plan-status -- --open
```
Expected: the test PASSES, and this plan no longer appears among the open ones.

- [ ] **Step 4: Commit (root)**

```bash
git add AGENTS.md docs/superpowers/plans/2026-10-29-test-toolchain-pest-5.md docs/superpowers/plans/INDEX.md
git commit -m "docs: record the Pest 5 toolchain upgrade"
```

---

### Task 5: Close the type-coverage gap the source fix revealed

Added on 2026-11-05, out of Task 1 Step 6. Independent of Tasks 2, 3 and 4 and of this plan's green signals: it can be done later or declined, and `--min=100` stays red at 99.4% until it is. Not started.

**Files:**
- Modify: the 58 files reported at 75% by `composer test:type-coverage`, each in the module repository that owns it (AI 5, CMS 6, Core 9, CRM 1, ERP 9, MES 17, SAO 11).

**Interfaces:**
- Consumes: the `<source>` fix in `phpunit.xml` from Task 1 Step 6.
- Produces: `composer test:type-coverage` green at 100% over 547 files instead of over 3.

- [ ] **Step 1: List the gap per module**

```bash
php -d memory_limit=4G vendor/bin/pest --type-coverage 2>&1 | grep -v '\.\.\. 100%'
```
The codes are the plugin's own: `pa<line>` a parameter without a type, `co<line>` an untyped class constant, `rt<line>` a missing return type.

- [ ] **Step 2: Annotate, module by module, one commit per repository**

Add the real type, never `mixed` to buy the percentage: a parameter that genuinely accepts anything is a finding about the signature, not about the annotation. A closure parameter whose type cannot be written is reported, not silenced. Run that module's suite after its commit, because a narrowed parameter type can change behaviour where a caller was passing something looser.

- [ ] **Step 3: Verify and commit the pointers**

```bash
composer test:type-coverage 2>&1 | tail -3
```
Expected: `Total: 100.0 %` and exit 0.
