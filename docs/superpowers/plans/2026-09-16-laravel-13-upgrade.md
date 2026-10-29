---
module: platform
---
# Laravel 12 to 13 Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the application and all eight native modules from Laravel 12 to Laravel 13 in place, keeping git history, submodules, tooling and behaviour unchanged.

**Architecture:** In-place upgrade. `../laraplate13` is a throwaway scaffold the user built to **census** which library majors exist for Laravel 13; it is not a target to match package by package, and it is already stale in places (see Global Constraints). `wikimedia/composer-merge-plugin` ANDs every `Modules/*/composer.json` into the root solve, so all nine manifests are edited before a single resolve. Framework upgrade and test-runner upgrade (Pest 5 / PHPUnit 13) are separate tasks so a red suite has one cause.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest, nwidart/laravel-modules 13, Composer merge-plugin.

**Spec:** Official upgrade guide `https://laravel.com/docs/13.x/upgrade` (source `laravel/docs` branch `13.x`, `upgrade.md`). Library census: `/srv/http/laraplate-stack/laraplate13/composer.lock`, read on 2026-10-29 (`laravel/framework v13.39.0`).

## Global Constraints

- Upgrade in place. Do not copy code into `../laraplate13`; do not commit anything from it.
- **The census is evidence, not a lock to reproduce.** It answers one question per package: which major works under Laravel 13. Every version in this plan's tables was read from it on 2026-10-29. Do not diff the real lock against it expecting equality; the gaps below are known and deliberate.
- The census is silent on six packages Laraplate requires, all verified framework-agnostic on Packagist on 2026-10-29 (no `laravel/framework` or `illuminate/*` requirement, so Laravel 13 cannot constrain them): `php-mqtt/client v2.3.2` (root + MES), `aws/aws-sdk-php 3.402.4` and `google/auth v1.55.3` (AI), `james-heinrich/getid3 v1.9.27` and `smalot/pdfparser v2.13.1` (Core), plus `symfony/http-client` (removed, below). No constraint change needed for any of them.
- The census is **stale on `neuron-core/neuron-ai`**: it holds `3.17.2`, while `Modules/AI` already requires `^4.1` after the Neuron 4 upgrade (`2026-10-12-neuron-ai-4-upgrade.md`). Neuron 4 is framework-agnostic (`php: ^8.1`, latest `4.1.16`). Leave the AI constraint alone; ignore the census row.
- Never import the census scaffold's own starter-kit packages: `livewire/flux`, `livewire/blaze`, `laravel/chisel`, `laravel/pao` (`livewire/flux` is commercial, this repo is AGPL). The census also still lists `overtrue/laravel-versionable ^6.0` and `stephenlake/laravel-approval ^1.1`, which Laraplate **no longer requires**: versioning was detached (see `Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`). Do not reintroduce them. No new dependency without user approval. Exception approved by the user on 2026-09-16: `pestphp/pest-plugin-phpstan` (Task 7).
- `symfony/http-client` is removed from the root manifest. No PHP file in `app/`, `config/`, `routes/` or any module references `Symfony\Component\HttpClient` or `Symfony\Contracts\HttpClient`; it was added with the initial scaffold (`f5b44bf`) and `composer why` lists only the root requirement plus two `conflict` entries (`league/flysystem`, `symfony/http-kernel`), which are not requirements. PSR-18 discovery used by `elastic/transport` resolves to Guzzle, which Laravel already ships.
- `laravel/socialite` (Core: `Actions/Users/HandleSocialLoginAction.php`, `Auth/Providers/SocialiteProvider.php`) resolves on Laravel 13 **only with `-W`**: `league/oauth1-client` requires `guzzlehttp/guzzle ^6|^7`, so Composer must move Guzzle off 8. Verified on 2026-09-16 against a copy of the census lock: Guzzle 8.2.0 to 7.15.5, `guzzlehttp/promises` 3 to 2.5.3, `guzzlehttp/psr7` 3 to 2.13.1. Laravel 13 accepts `^7.8.2 || ^8.0`, so this is supported. Every solve in this plan uses `-W`, and Guzzle 7 is the expected result.
- `mtrajano/laravel-swagger` sits at `v0.4.0` in the census, a downgrade from the `^0.6.4` Core requires. It is runtime code in Core (`SwaggerGenerateCommand`, `Overrides/ModuleDocGenerator.php`, `Overrides/ModuleDocRoute.php`, `Providers/RouteServiceProvider.php`). Task 4 has a decision gate; never accept the downgrade silently.
- Local PHP lacks `ext-oci8` and `ext-imagick` (the census scaffold declares both through `config.platform`): pass `--ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick` on every Composer solve, never `--ignore-platform-reqs`.
- Keep `config/cache.php` without `serializable_classes` (framework reads `null`, current behaviour) and `config/session.php` without `serialization` (framework default `php`, sessions stay valid). Hardening both is a separate decision, not part of this plan.
- The CSRF middleware exclusion in the `web` group is intentional during development (`TODO` in `bootstrap/app.php`). It must survive the upgrade: `array_diff` in `Illuminate\Foundation\Configuration\Middleware` compares class strings, and Laravel 13's default `web` group lists `PreventRequestForgery`, so `remove: [ValidateCsrfToken::class]` silently stops removing anything.
- **Merge fast.** The repository took 241 commits on the root and 134 on Core in the 14 days to 2026-10-29. A branch left open for days will cost more in merges than in upgrading. Work the tasks in one block.
- Every submodule works on branch `upgrade/laravel-13` inside that submodule; the root repo uses `upgrade/laravel-13` too. Touch only files named in the task. No `Co-Authored-By` trailer in commit messages.
- Commands run from `/srv/http/laraplate-stack/laraplate`. Tests: `php artisan test --compact <path>`. Formatting: `vendor/bin/pint --dirty --format agent`.

---

### Task 1: Pre-flight and baseline

**Files:**
- None modified.

**Interfaces:**
- Produces: `storage/logs/upgrade-l13/baseline-failures.txt` (tests already failing on Laravel 12), branches `upgrade/laravel-13` in the root and in the eight submodules.

- [ ] **Step 1: Check the trees are clean**

```bash
git status --short
git submodule foreach --quiet 'echo "$name $(git status --short | wc -l)"'
```
Expected: the root shows no `M`/`??` outside this plan file; every submodule prints `0`. On 2026-10-29 all eight were clean and on `master`. If a submodule is dirty, STOP and ask the user to commit or stash that work. Do not stash it yourself.

- [ ] **Step 2: Clear the cached config, or the suite aborts**

```bash
php artisan optimize:clear
ls bootstrap/cache/
```
Expected: no `config.php` and no `routes-v7.php` left. Both existed on 2026-10-29, and `tests/Support/TestEnvironmentGuard.php:26` aborts the whole suite while `bootstrap/cache/config.php` is present (a cached config makes tests read the `.env` database instead of `phpunit.xml`). This must stay cleared for every test run in this plan.

- [ ] **Step 3: Create the branches**

```bash
git switch -c upgrade/laravel-13
git submodule foreach 'git switch -c upgrade/laravel-13'
```
Expected: nine `Switched to a new branch 'upgrade/laravel-13'` lines.

- [ ] **Step 4: Record the Laravel 12 baseline**

```bash
mkdir -p storage/logs/upgrade-l13
php artisan test --compact 2>&1 | tee "storage/logs/upgrade-l13/baseline-l12.txt" | tail -5
grep -E '^\s+(FAIL|⨯)' "storage/logs/upgrade-l13/baseline-l12.txt" > "storage/logs/upgrade-l13/baseline-failures.txt" || true
```
`storage/logs/` is gitignored, so these logs never reach a commit. Expected: the summary line with pass/fail counts. The suite is 1.357 files; this run is the slow one, and every later comparison is against this file. Tests failing here are not caused by the upgrade.

- [ ] **Step 5: Record the static-analysis baseline**

```bash
composer test:types 2>&1 | tail -5
```
Expected: green, or the same failures you will accept for the rest of the plan. `phpstan.neon` includes `phpstan-baseline.neon` (18.937 lines, the debt frozen on 2026-09-17), so new errors fail the run. Task 7 regenerates it.

---

### Task 2: Bump the seven light modules

**Files:**
- Modify: `Modules/AI/composer.json`, `Modules/CMS/composer.json`, `Modules/CRM/composer.json`, `Modules/ERP/composer.json`, `Modules/MES/composer.json`, `Modules/SAO/composer.json`, `Modules/Shop/composer.json` (the `require` block only)

**Interfaces:**
- Produces: every light module requires `laravel/framework: ^13.0`. Task 4 depends on it: one module left on `^12.0` pins the whole solve to Laravel 12.

- [ ] **Step 1: Edit the constraints**

In each of the seven files change:
```json
"laravel/framework": "^12.0",
```
to:
```json
"laravel/framework": "^13.0",
```
Change nothing else. Every other requirement in these modules is framework-agnostic and already verified: `Modules/AI` (`neuron-core/neuron-ai ^4.1`, `aws/aws-sdk-php ^3.399`, `google/auth ^1.55`), `Modules/CMS` (`php-ffmpeg/php-ffmpeg ^1.4`, `ext-gd`, `ext-exif`), `Modules/MES` (`php-mqtt/client ^2.3`). `Modules/CRM`, `Modules/ERP`, `Modules/SAO` and `Modules/Shop` require only `php` and the framework. None of these modules has a `require-dev` block any more: the test toolchain lives in the root manifest and is Task 7's business.

- [ ] **Step 2: Validate the manifests**

```bash
for m in AI CMS CRM ERP MES SAO Shop; do composer validate --no-check-publish --no-check-lock "Modules/$m/composer.json" || echo "INVALID $m"; done
```
Expected: seven `is valid` lines, no `INVALID`.

- [ ] **Step 3: Commit inside each submodule**

```bash
for m in AI CMS CRM ERP MES SAO Shop; do
  git -C "Modules/$m" add composer.json
  git -C "Modules/$m" commit -m "chore(deps): require Laravel 13"
done
```

---

### Task 3: Bump Core

**Files:**
- Modify: `Modules/Core/composer.json` (the `require` block only)

**Interfaces:**
- Consumes: nothing.
- Produces: Core constraints that admit the Laravel 13 majors.

- [ ] **Step 1: Edit the constraints**

Apply exactly these changes in `Modules/Core/composer.json` `require`. The "to" column is the version the census holds on 2026-10-29; the "why" column is why the current floor cannot stay.

| package | from | to | why |
|---|---|---|---|
| `laravel/framework` | `^12.0` | `^13.0` | target |
| `babenkoivan/elastic-scout-driver` | `^4.0` | `^6.0` | v4 has no Scout 11 / L13 line |
| `babenkoivan/elastic-scout-driver-plus` | `^5.1` | `^7.0` | v7 requires driver `^6.0` |
| `elasticsearch/elasticsearch` | `^8.19` | `^9.5` | pulled by elastic-client 4 |
| `hedii/laravel-gelf-logger` | `^10.1` | `^13.0` | 13.0.0 is the first L13 release, and it is L13-only |
| `laravel/scout` | `^10.25` | `^11.9` | census |
| `spatie/eloquent-sortable` | `^4.5.2` | `^5.0` | 5.0.1 is the first L13 release |
| `spatie/laravel-permission` | `^6.25` | `^8.3` | census (L13 support starts at 7.2.1) |
| `staudenmeir/laravel-adjacency-list` | `^1.25.2` | `^1.26.2` | 1.26 requires `illuminate/database ^13.0` |
| `typesense/typesense-php` | `^5.2` | `^6.0` | census |
| `wotz/laravel-swagger-ui` | `^1.2` | `^2.1` | 2.1.0 is the first L13 release |
| `yajra/laravel-oci8` | `^12.11` | `^13.14` | v13 is L13-only |

Leave unchanged, already L13-compatible at the current floor: `doctrine/dbal`, `filament/spatie-laravel-media-library-plugin` (L13 from 5.4.0), `lab404/laravel-impersonate`, `laravel/fortify` (from 1.34.1), `laravel/horizon` (from 5.44.0), `laravel/socialite` (from 5.24.3), `livewire/livewire` (from 4.2.0), `matanyadaev/laravel-eloquent-spatial` (from 4.7.0), `spatie/fork`, `spatie/laravel-medialibrary` (from 11.21.0), `james-heinrich/getid3`, `smalot/pdfparser`, every `ext-*`. Leave `mtrajano/laravel-swagger: ^0.6.4` as is: Task 4 decides.

- [ ] **Step 2: Validate**

Run: `composer validate --no-check-publish --no-check-lock Modules/Core/composer.json`
Expected: `is valid`.

- [ ] **Step 3: Commit inside Core**

```bash
git -C Modules/Core add composer.json
git -C Modules/Core commit -m "chore(deps): require Laravel 13 and its compatible package majors"
```

---

### Task 4: Bump root and resolve the lock

**Files:**
- Modify: `composer.json` (`require`), `composer.lock`

**Interfaces:**
- Consumes: Tasks 2 and 3 manifests.
- Produces: `composer.lock` on `laravel/framework 13.x`, with `pestphp/pest 4.x` still in place (Task 7 moves it).

- [ ] **Step 1: Edit the root constraints**

In `composer.json` `require`:

| package | from | to |
|---|---|---|
| `laravel/framework` | `^12.59` | `^13.39` |
| `laravel/tinker` | `^2.11.1` | `^3.0` |
| `nwidart/laravel-modules` | `^12.0.5` | `^13.3` |
| `symfony/http-client` | `^7.4.9` | removed (see Global Constraints) |

Leave `coolsam/modules`, `filament/filament`, `joshbrw/laravel-module-installer`, `laravel/sanctum`, `php-mqtt/client` and all of `require-dev` unchanged in this task. `coolsam/modules ^5.1` and `filament/filament ^5.6.3` already admit the census versions (`v5.3.2`, `v5.10.3`); `laravel/sanctum ^4.3.2` admits `v4.3.3`.

- [ ] **Step 2: Dry-run the solve**

```bash
composer update -W --dry-run --no-scripts \
  --ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick \
  2>&1 | tee "storage/logs/upgrade-l13/l13-dry.txt" | head -30
```
Expected: `Lock file operations: ...` and no `Your requirements could not be resolved`. The solve takes several minutes; run it in the background if needed.
If it fails, read the first `Problem` block, fix the named constraint in the manifest that owns it (tables in Task 3 and this task), commit that fix in the owning repo, rerun. Do not add `--ignore-platform-reqs` or loosen `minimum-stability`.

- [ ] **Step 3: Resolve for real**

```bash
composer update -W --ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick
```
Expected: ends with `package:discover` output and `filament:upgrade` without errors.

- [ ] **Step 4: Check nothing stayed on a Laravel 12 line**

This replaces a diff against the census: the census is a different application, so equality is not the goal. What matters is that no installed package still declares Laravel 12 only.

```bash
php -r '
$lock = json_decode(file_get_contents("composer.lock"), true);
foreach (array_merge($lock["packages"], $lock["packages-dev"]) as $p) {
    $constraints = [];
    foreach ($p["require"] ?? [] as $name => $constraint) {
        if ($name === "laravel/framework" || str_starts_with($name, "illuminate/")) { $constraints[$name] = $constraint; }
    }
    if ($constraints === []) { continue; }
    $joined = implode(" ", $constraints);
    if (!preg_match("/(?<![0-9.])13(\.|\||,|\s|$)/", $joined)) {
        printf("%-50s %-12s %s\n", $p["name"], $p["version"], $joined);
    }
}
'
```
Expected: no output. Any line is a package that does not claim Laravel 13 support: check its latest release on Packagist, raise the constraint in the manifest that owns it, and resolve again.
Then confirm the headline versions:
```bash
composer show laravel/framework laravel/socialite babenkoivan/elastic-scout-driver-plus laravel/sanctum guzzlehttp/guzzle | grep -E '^name|^versions'
composer why symfony/http-client
```
Expected: framework `v13.x`; socialite `v5.31.x`; driver-plus `v7.0.x`; sanctum `v4.3.x`; Guzzle `7.x` (expected, see Global Constraints); `symfony/http-client` reported as not required by anything (if a package now requires it, that is fine: it just stops being a root requirement).

- [ ] **Step 5: Decision gate for `mtrajano/laravel-swagger`**

Run: `composer show mtrajano/laravel-swagger | grep versions`
- If it is `v0.6.4`: nothing to do.
- If the solve failed on it, or Composer picked an older version: run `composer why-not mtrajano/laravel-swagger 0.6.4` and report the blocking package to the user (expected cause: `phpdocumentor/reflection-docblock ^6`, which `v0.6.4` excludes with `^4.3|^5.2`). STOP and ask the user whether to (a) accept the downgrade after Step 6 proves the swagger command still works, (b) replace the package, or (c) drop swagger generation. Do not choose alone.

- [ ] **Step 6: Boot and smoke check**

```bash
php artisan --version
php artisan about --only=environment
php artisan route:list --except-vendor --path=api | head -20
php artisan module:list
php artisan optimize:clear
```
Expected: `Laravel Framework 13.x`; routes listed; all eight modules `Enabled`. The final `optimize:clear` matters: the Artisan calls above rebuild `bootstrap/cache`, and the test guard aborts the suite while a cached config exists.
Then run the swagger command tests:
```bash
php artisan test --compact --filter=Swagger
```
Expected: PASS, or the same result as in `baseline-failures.txt`.

- [ ] **Step 7: Commit (root)**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): upgrade to Laravel 13"
```

---

### Task 5: CSRF middleware rename (`PreventRequestForgery`)

**Files:**
- Modify: `bootstrap/app.php:13` (import), `:59`, `:81`
- Modify: `app/Providers/Filament/AdminPanelProvider.php:24`, `:147`
- Modify: `config/sanctum.php` (the `validate_csrf_token` entry)
- Test: `tests/Feature/Filament/AdminPanelMiddlewareTest.php`
- Test: `tests/Feature/Http/MiddlewareGroupsTest.php` (create)

**Interfaces:**
- Consumes: Laravel 13 installed (Task 4).
- Produces: all CSRF references point to `Illuminate\Foundation\Http\Middleware\PreventRequestForgery`; the `web` group keeps CSRF disabled as before.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/Http/MiddlewareGroupsTest.php`:
```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Router;

/**
 * @return list<string>
 */
function middlewareGroup(string $group): array
{
    return app(Router::class)->getMiddlewareGroups()[$group] ?? [];
}

it('keeps request forgery protection out of the web group during development', function (): void {
    // Laravel 13 lists PreventRequestForgery in the default web group, and group removal
    // compares class strings: removing a deprecated alias silently removes nothing.
    expect(middlewareGroup('web'))
        ->not->toContain(PreventRequestForgery::class)
        ->not->toContain(ValidateCsrfToken::class)
        ->not->toContain(VerifyCsrfToken::class);
});

it('protects the auth group against request forgery', function (): void {
    expect(middlewareGroup('auth'))
        ->toContain(PreventRequestForgery::class)
        ->not->toContain(VerifyCsrfToken::class);
});
```

Append to `tests/Feature/Filament/AdminPanelMiddlewareTest.php`:
```php
it('protects the admin panel with the non-deprecated forgery middleware', function (): void {
    expect(Filament::getPanel('admin')->getMiddleware())
        ->toContain(Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class)
        ->not->toContain(Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
});
```

- [ ] **Step 2: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php`
Expected: 3 FAIL: the web group contains `PreventRequestForgery` (the silent regression), the auth group and the panel still list `VerifyCsrfToken`.

- [ ] **Step 3: Fix `bootstrap/app.php`**

Replace the import of `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` (line 13) with:
```php
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
```
In the `web(remove: [...])` list replace the CSRF entry with `PreventRequestForgery::class,`.
In the `appendToGroup('auth', [...])` list replace `VerifyCsrfToken::class,` with `PreventRequestForgery::class,`.
If an import of `ValidateCsrfToken` is also present, remove it: one import covers both call sites now.

- [ ] **Step 4: Fix the panel and the Sanctum config**

`app/Providers/Filament/AdminPanelProvider.php`: replace
`use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;` (line 24) with `use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;`
and `VerifyCsrfToken::class,` in `->middleware([...])` (line 147) with `PreventRequestForgery::class,`.

`config/sanctum.php`: replace
```php
'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
```
with
```php
'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
```

- [ ] **Step 5: Check nothing else references the aliases**

Run: `grep -rn "VerifyCsrfToken\|ValidateCsrfToken" app bootstrap/app.php config routes Modules/*/app Modules/*/config Modules/*/routes`
Expected: no output. Ignore `bootstrap/cache/*`: generated files, cleared in Task 1 Step 2. If a module matches, fix it the same way and commit inside that submodule.

- [ ] **Step 6: Run the tests and see them pass**

Run: `php artisan test --compact tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php`
Expected: all PASS.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add bootstrap/app.php app/Providers/Filament/AdminPanelProvider.php config/sanctum.php tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php
git commit -m "fix(http): use PreventRequestForgery and keep the web group CSRF exclusion working on Laravel 13"
```

---

### Task 6: Full suite on Laravel 13 (Pest 4)

**Files:**
- Modify: only files a failing test points to; each fix is its own commit in the owning repo.

**Interfaces:**
- Consumes: Tasks 4 and 5.
- Produces: suite result equal to `baseline-failures.txt` (no new failures).

This is the task that decides how long the upgrade takes. The Laravel 13 changes below are small and enumerable; the twelve third-party majors from Task 3 are not, and `spatie/laravel-permission` 6 to 8 is the one Core leans on hardest.

- [ ] **Step 1: Run the suite**

```bash
php artisan optimize:clear
php artisan test --compact 2>&1 | tee "storage/logs/upgrade-l13/l13-pest4.txt" | tail -5
grep -E '^\s+(FAIL|⨯)' "storage/logs/upgrade-l13/l13-pest4.txt" > "storage/logs/upgrade-l13/l13-failures.txt" || true
diff "storage/logs/upgrade-l13/baseline-failures.txt" "storage/logs/upgrade-l13/l13-failures.txt"
```
Expected: `diff` prints nothing. Every `>` line is a regression to fix in Step 2.

- [ ] **Step 2: Fix regressions one at a time**

For each new failure use superpowers:systematic-debugging. Check these causes first, in this order:
1. The third-party majors from Task 3: `spatie/laravel-permission` 6 to 8, `laravel/scout` 10 to 11, `elastic-scout-driver` 4 to 6 (and `-plus` 5 to 7), `elasticsearch` 8 to 9, `typesense` 5 to 6, `gelf-logger` 10 to 13, `swagger-ui` 1 to 2, `eloquent-sortable` 4 to 5, `yajra/laravel-oci8` 12 to 13. Read each package's `UPGRADE.md` or release notes under `vendor/<package>/` before changing any code.
2. `upsert` now throws `InvalidArgumentException` on an empty `uniqueBy` (callers in `Modules/Core/app/Seeding/SeedReconciler.php` already pass `[$column]`).
3. `Str` factories are reset between tests; UUID/ULID fakes must be set in each test.
4. `Js::from` no longer escapes unicode.
5. Eager-loaded relations are restored when model collections are unserialized (queued jobs).
6. Manager `extend` closures are bound to the manager (`$this` inside them changed).
7. Instantiating a model while it boots throws `LogicException`.
8. Polymorphic pivot table names are now pluralized for custom pivot classes: set `$table` explicitly on the pivot model.
9. The cache `Store` contract gained `touch()`: `Modules/Core/app/Cache/CacheManager.php` and `Repository.php` extend the framework classes and inherit it; a custom store implementing the interface directly would need it.

Each fix: the failing test already exists (the regression), change the code, rerun that test, `vendor/bin/pint --dirty --format agent`, commit in the owning repo as `fix(<scope>): <what> for Laravel 13`.

- [ ] **Step 3: Rerun the suite**

Repeat Step 1. Expected: `diff` prints nothing.

---

### Task 7: Pest 5 / PHPUnit 13 and toolchain

**Files:**
- Modify: `composer.json` (`require-dev`), `composer.lock`, `phpunit.xml` (only if Pest asks to migrate it), `phpstan.neon`, `phpstan-baseline.neon`

**Interfaces:**
- Consumes: green Laravel 13 suite (Task 6).
- Produces: dev toolchain at the census versions (`pestphp/pest v5.3.x`, `phpunit/phpunit 13.x`, `peckphp/peck v0.3.x`, `pestphp/pest-plugin-phpstan v5.2.x`) with the Pest PHPStan extension active and the module test suites back under analysis.

- [ ] **Step 1: Bump the dev constraints**

In `composer.json` `require-dev`:

| package | from | to |
|---|---|---|
| `pestphp/pest` | `^4.7` | `^5.3` |
| `pestphp/pest-plugin-laravel` | `^4.1` | `^5.0` |
| `pestphp/pest-plugin-stressless` | `^4.0` | `^5.0` |
| `pestphp/pest-plugin-type-coverage` | `^4.0` | `^5.0` |
| `peckphp/peck` | `^0.1.3` | `^0.3.0` |
| `pestphp/pest-plugin-phpstan` | (new) | `^5.2` |

`pestphp/pest-plugin-phpstan` requires `pestphp/pest ^5.0.0` and `phpstan/phpstan ^2.2.5`, so it cannot land before this task. Leave `nunomaduro/collision ^8.9.4` as is: Pest 5 requires `^8.9.5`, which that constraint already admits.

- [ ] **Step 2: Resolve**

```bash
composer update -W --ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick \
  'pestphp/*' peckphp/peck 'phpunit/*' 'sebastian/*' 'phpstan/*'
```
Expected: pest `v5.x`, phpunit `13.x`, `pestphp/pest-plugin-phpstan v5.x`. If the solve fails on a package the census does not have, STOP and report it: do not remove packages without approval.

- [ ] **Step 3: Migrate the PHPUnit configuration if asked**

```bash
php artisan optimize:clear
vendor/bin/pest --compact tests/Unit/ClosedPlansPointToDocumentationTest.php
```
If the output warns that the XML configuration validates against a deprecated schema, run `vendor/bin/pest --migrate-configuration` and review the `phpunit.xml` diff: only schema and attribute changes are allowed, and the `Unit`, `Integration` and `Feature` suites must stay.

- [ ] **Step 4: Enable the Pest PHPStan extension and put the module tests back under analysis**

The repo has no `phpstan/extension-installer`, so the plugin's `extra.phpstan.includes` is not picked up. In `phpstan.neon` line 10, replace:
```neon
    # - vendor/pestphp/pest-plugin-phpstan/extension.neon
```
with:
```neon
    - vendor/pestphp/pest-plugin-phpstan/extension.neon
```
Then delete these `excludePaths` entries and the comment above them that says to delete them when the plugin is installed:
```neon
        - Modules/*/tests/*
        - Modules/*/tests/**
```
Keep the `Modules/*/stubs/*` entries: their reason (a deliberate `final` extension under BypassFinals) is unrelated.

```bash
composer test:types 2>&1 | tail -20
```
Expected: errors, and many of them. The comment in `phpstan.neon` records that the module suites produced 23.067 of 33.282 errors without the plugin, 11.793 of them `method.internalClass` from ordinary `expect(...)->toBe(...)` calls. Those must be gone now. What remains are real findings in test code that was never analysed.

- [ ] **Step 5: Regenerate the PHPStan baseline**

The baseline (`phpstan-baseline.neon`, 18.937 lines) was frozen on 2026-09-17 against a different analysis surface: Laravel 13 stubs changed, the Pest extension changed how test files read, and Step 4 added the module suites. Regenerate it:
```bash
vendor/bin/phpstan analyse --memory-limit=4G --generate-baseline=phpstan-baseline.neon
composer test:types 2>&1 | tail -5
```
Expected: `test:types` green against the new baseline. Then check the debt did not quietly grow:
```bash
composer check:debt
git diff --stat phpstan-baseline.neon
```
Report the before and after counts to the user. A baseline that grew by the module suites is expected; one that grew in `app/` or `Modules/*/app/` means Laravel 13 introduced findings worth fixing rather than freezing, and those belong in Step 6 or in their own commit.

- [ ] **Step 6: Full suite and quality gates**

```bash
php artisan optimize:clear
php artisan test --compact 2>&1 | tail -5
composer test:lint
composer test:types
composer test:typos
composer test:licenses
```
Expected: the suite matches Task 6 (compare as in Task 6 Step 1). Fix PHPUnit 13 deprecations and removals the way Task 6 Step 2 fixes regressions. Rector or PHPStan findings introduced by the new package stubs are fixed in the owning repo, one commit per concern.

- [ ] **Step 7: Commit (root)**

```bash
git add composer.json composer.lock phpunit.xml phpstan.neon phpstan-baseline.neon
git commit -m "chore(deps): upgrade test toolchain to Pest 5 and PHPUnit 13, analyse the module test suites"
```

---

### Task 8: Guidelines and documentation

**Files:**
- Modify: `CLAUDE.md` and `AGENTS.md` (regenerated Boost blocks), `AGENTS.md:67`
- Modify: `README.md:9`, `README.md:22`
- Modify: `Modules/*/README.md` (the Laravel badge on line 9 of all eight; the `-   Laravel 12.0+` requirement line in AI, Core, ERP)
- Modify: `docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md` (this file)

**Interfaces:**
- Consumes: finished upgrade.

- [ ] **Step 1: Regenerate the Boost guidelines**

Run: `php artisan boost:update --no-interaction`
Expected: the `=== laravel/v12 rules ===` blocks in `CLAUDE.md` and `AGENTS.md` become Laravel 13 rules. Review the diff: only the `<laravel-boost-guidelines>` block may change. If Boost drops the "Laravel 10 structure is intentional" guidance, the project rule in `AGENTS.md` (`Laravel 10-style structure is intentional. Do not migrate.`) still stands; do not edit it.

- [ ] **Step 2: Update the stack line, the badges and the requirement lines**

`AGENTS.md:67`: `- Stack: PHP 8.5+, Laravel 12, Filament 5, Livewire 4, Sanctum 4, Tailwind 4.` becomes the same line with `Laravel 13`.
`README.md:22`: `-   Laravel 12.x` becomes `-   Laravel 13.x`.
In `README.md:9` and line 9 of all eight `Modules/*/README.md`, the badge `Laravel-12.x-FF2D20` and its `alt="Laravel 12"` become `Laravel-13.x-FF2D20` and `alt="Laravel 13"`.
In `Modules/AI/README.md:168`, `Modules/Core/README.md:678`, `Modules/ERP/README.md:111`: `-   Laravel 12.0+` becomes `-   Laravel 13.0+`.
Commit each module README inside its own submodule as `docs: require Laravel 13`.
No env var was added, removed or renamed, so no README env section changes, and no RAG doc change: framework behaviour for operators is unchanged.

- [ ] **Step 3: Close the plan**

Set `status: completed` in this file's front matter, next to the existing `module: platform`, and add at the end:
```markdown
## Delivery status (YYYY-MM-DD): shipped

**Documented in:** `AGENTS.md` (stack line), `README.md`, `Modules/AI/README.md`, `Modules/Core/README.md`, `Modules/ERP/README.md` (requirements).
```
Record every divergence from Tasks 4 to 7 there: the swagger decision, the PHPStan baseline before and after counts, any constraint that had to differ from the tables. Resolve every checkbox first, `- [x]` or `- [-]` with its reason on the same line (`.ai/rules/plans.md`). The `INDEX.md` entry carries no status.

```bash
php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php
composer plan-status -- --open
```
Expected: the test PASSES, and this plan no longer appears among the open ones.

- [ ] **Step 4: Commit (root)**

```bash
vendor/bin/pint --dirty --format agent
git add CLAUDE.md AGENTS.md README.md docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md
git commit -m "docs: record the Laravel 13 upgrade"
```

---

### Task 9: Bump submodule pointers

**Files:**
- Modify: root gitlinks `Modules/AI`, `Modules/CMS`, `Modules/CRM`, `Modules/Core`, `Modules/ERP`, `Modules/MES`, `Modules/SAO`, `Modules/Shop`

- [ ] **Step 1: Check each submodule is clean and on the branch**

Run: `git submodule foreach --quiet 'echo "$name $(git rev-parse --abbrev-ref HEAD) $(git status --short | wc -l)"'`
Expected: eight lines `... upgrade/laravel-13 0`.

- [ ] **Step 2: Final suite**

```bash
php artisan optimize:clear
php artisan test --compact 2>&1 | tail -5
```
Expected: the same result as Task 7 Step 6.

- [ ] **Step 3: Commit the pointers**

```bash
git add Modules/AI Modules/CMS Modules/CRM Modules/Core Modules/ERP Modules/MES Modules/SAO Modules/Shop
git commit -m "chore(modules): bump all modules to their Laravel 13 upgrade"
```

- [ ] **Step 4: Hand over**

Report to the user: the branches to merge (root plus eight submodules, submodules first), the swagger decision, the PHPStan baseline change, and that pushing and merging are theirs to do. Do not push or merge.
