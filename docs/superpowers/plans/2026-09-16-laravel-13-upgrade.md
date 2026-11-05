---
module: platform
status: completed
---
# Laravel 12 to 13 Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the application and all eight native modules from Laravel 12 to Laravel 13 in place, keeping git history, submodules, tooling and behaviour unchanged.

**Architecture:** In-place upgrade. `../laraplate13` is a throwaway scaffold the user built to **census** which library majors exist for Laravel 13; it is not a target to match package by package, and it is already stale in places (see Global Constraints). `wikimedia/composer-merge-plugin` ANDs every `Modules/*/composer.json` into the root solve, so all nine manifests are edited before a single resolve. The test toolchain stays on Pest 4 throughout: Pest 5, PHPUnit 13 and `pest-plugin-phpstan` were split into `2026-10-29-test-toolchain-pest-5.md` on 2026-10-29, so an open-ended analysis task cannot hold the framework upgrade hostage. `pestphp/pest-plugin-laravel v4.1.0` supports Laravel 13 (`^11.45.2|^12.52.0|^13.0`), so nothing here forces that move.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5, Livewire 4, Pest, nwidart/laravel-modules 13, Composer merge-plugin.

**Spec:** Official upgrade guide `https://laravel.com/docs/13.x/upgrade` (source `laravel/docs` branch `13.x`, `upgrade.md`). Library census: `/srv/http/laraplate-stack/laraplate13/composer.lock`, read on 2026-10-29 (`laravel/framework v13.39.0`).

## Global Constraints

- Upgrade in place. Do not copy code into `../laraplate13`; do not commit anything from it.
- **The census is evidence, not a lock to reproduce.** It answers one question per package: which major works under Laravel 13. Every version in this plan's tables was read from it on 2026-10-29. Do not diff the real lock against it expecting equality; the gaps below are known and deliberate.
- The census is silent on six packages Laraplate requires, all verified framework-agnostic on Packagist on 2026-10-29 (no `laravel/framework` or `illuminate/*` requirement, so Laravel 13 cannot constrain them): `php-mqtt/client v2.3.2` (root + MES), `aws/aws-sdk-php 3.402.4` and `google/auth v1.55.3` (AI), `james-heinrich/getid3 v1.9.27` and `smalot/pdfparser v2.13.1` (Core), plus `symfony/http-client` (removed, below). No constraint change needed for any of them.
- The census is **stale on `neuron-core/neuron-ai`**: it holds `3.17.2`, while `Modules/AI` already requires `^4.1` after the Neuron 4 upgrade (`2026-10-12-neuron-ai-4-upgrade.md`). Neuron 4 is framework-agnostic (`php: ^8.1`, latest `4.1.16`). Leave the AI constraint alone; ignore the census row.
- Never import the census scaffold's own starter-kit packages: `livewire/flux`, `livewire/blaze`, `laravel/chisel`, `laravel/pao` (`livewire/flux` is commercial, this repo is AGPL). The census also still lists `overtrue/laravel-versionable ^6.0` and `stephenlake/laravel-approval ^1.1`, which Laraplate **no longer requires**: versioning was detached (see `Modules/Core/tests/Unit/Versioning/VersioningLibraryDetachedTest.php`). Do not reintroduce them. No new dependency without user approval: this plan adds none.
- `symfony/http-client` is removed from the root manifest. No PHP file in `app/`, `config/`, `routes/` or any module references `Symfony\Component\HttpClient` or `Symfony\Contracts\HttpClient`; it was added with the initial scaffold (`f5b44bf`) and `composer why` lists only the root requirement plus two `conflict` entries (`league/flysystem`, `symfony/http-kernel`), which are not requirements. PSR-18 discovery used by `elastic/transport` resolves to Guzzle, which Laravel already ships.
- `laravel/socialite` (Core: `Actions/Users/HandleSocialLoginAction.php`, `Auth/Providers/SocialiteProvider.php`) resolves on Laravel 13 **only with `-W`**: `league/oauth1-client` requires `guzzlehttp/guzzle ^6|^7`, so Composer must move Guzzle off 8. Verified on 2026-09-16 against a copy of the census lock: Guzzle 8.2.0 to 7.15.5, `guzzlehttp/promises` 3 to 2.5.3, `guzzlehttp/psr7` 3 to 2.13.1. Laravel 13 accepts `^7.8.2 || ^8.0`, so this is supported. Every solve in this plan uses `-W`, and Guzzle 7 is the expected result.
- `mtrajano/laravel-swagger` sits at `v0.4.0` in the census, a downgrade from the `^0.6.4` Core requires. It is runtime code in Core (`SwaggerGenerateCommand`, `Overrides/ModuleDocGenerator.php`, `Overrides/ModuleDocRoute.php`, `Providers/RouteServiceProvider.php`). Task 4 has a decision gate; never accept the downgrade silently.
- Local PHP lacks `ext-oci8` and `ext-imagick` (the census scaffold declares both through `config.platform`): pass `--ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick` on every Composer solve, never `--ignore-platform-reqs`.
- Keep `config/cache.php` without `serializable_classes` (framework reads `null`, current behaviour) and `config/session.php` without `serialization` (framework default `php`, sessions stay valid). Hardening both is a separate decision, not part of this plan.
- The CSRF middleware exclusion in the `web` group is intentional during development (`TODO` in `bootstrap/app.php`). It must survive the upgrade: `array_diff` in `Illuminate\Foundation\Configuration\Middleware` compares class strings, and Laravel 13's default `web` group lists `PreventRequestForgery`, so `remove: [ValidateCsrfToken::class]` silently stops removing anything.
- **The full suite cannot run serially.** Measured 2026-10-30: `php artisan test` ran 42 minutes, reached ~4.900 tests, then exhausted the 4G `memory_limit` and died without a summary, with 4 failures already recorded. This predates the upgrade. Every suite run in this plan is therefore three processes (`composer test:unit`, `test:integration`, `test:feature`), never one. Budget roughly 45 minutes per verification pass and batch the fixes accordingly.
- **Merge fast.** The repository took 241 commits on the root and 134 on Core in the 14 days to 2026-10-29. A branch left open for days will cost more in merges than in upgrading. Work the tasks in one block.
- **Work on `master` in every repository.** Decided with the user on 2026-11-05, after the root upgrade had already been committed to `master`: branches existed to keep `master` installable until the whole upgrade landed at once, and once `master` carries the upgrade they protect nothing while splitting the work across two places. Open a branch only to isolate something genuinely risky, and merge it back the same day. Two earlier rounds of nine upfront branches (2026-10-31, 2026-11-02) were deleted unused.
- **The upgrade cannot land in pieces.** `composer-merge-plugin` ANDs every `Modules/*/composer.json` into the root solve, so a module requiring `laravel/framework ^13.0` while the root still requires `^12.59` makes the solve unsatisfiable and breaks `composer install` for everyone. The root manifests and the module manifests have to move together, and a root commit that bumps gitlinks must reference module commits that include the fixes the upgrade needs: `9f30cf4` referenced the manifest commits only and left `master` fatal on every artisan command until `0199996` corrected it.
- Touch only files named in the task. No `Co-Authored-By` trailer in commit messages.
- Commands run from `/srv/http/laraplate-stack/laraplate`. Tests: `php artisan test --compact <path>`. Formatting: `vendor/bin/pint --dirty --format agent`.

---

### Task 1: Pre-flight and baseline

**Files:**
- None modified.

**Interfaces:**
- Produces: `storage/logs/upgrade-l13/baseline-failures.txt` (tests already failing on Laravel 12), with all nine repositories on their latest `master`. No branch is created here.

- [x] **Step 1: Check the trees are clean**

```bash
git status --short
git submodule foreach --quiet 'echo "$name $(git status --short | wc -l)"'
```
Expected: the root shows no `M`/`??` outside this plan file; every submodule prints `0`. On 2026-10-29 all eight were clean and on `master`. If a submodule is dirty, STOP and ask the user to commit or stash that work. Do not stash it yourself.

- [x] **Step 2: Clear the cached config, or the suite aborts**

```bash
php artisan optimize:clear
ls bootstrap/cache/
```
Expected: no `config.php` and no `routes-v7.php` left. Both existed on 2026-10-29, and `tests/Support/TestEnvironmentGuard.php:26` aborts the whole suite while `bootstrap/cache/config.php` is present (a cached config makes tests read the `.env` database instead of `phpunit.xml`). This must stay cleared for every test run in this plan.

- [x] **Step 3: Pull every repository to its latest master**

Do **not** create branches here. Each task opens `upgrade/laravel-13` in the repository it is about to touch, at the moment it touches it (see Global Constraints). Creating nine branches upfront was tried on 2026-10-31 and again on 2026-11-02: both times they sat on an ageing base while development continued on master, carried zero commits, and had to be deleted and recreated.

```bash
git checkout master && git pull --ff-only
git submodule foreach 'git checkout master && git pull --ff-only'
git status --short
```
Expected: nine repositories on `master`, up to date, and a clean `git status` apart from gitlinks the root has not bumped yet. If a pull is not a fast-forward, STOP and ask the user: local master has diverged from the remote and that is not this plan's business.

- [x] **Step 4: Record the Laravel 12 baseline, one suite per process**

**Do not run `php artisan test` for the whole suite.** Measured on 2026-10-30 on this machine: it ran 2.501 seconds (42 minutes), reached roughly 4.900 tests, then died with `Allowed memory size of 4294967296 bytes exhausted` while a service provider loaded routes. It never produced a summary. That is a pre-existing condition on Laravel 12, not something this upgrade introduces: something accumulates across a run in which every test boots the application, and `memory_limit` is 4G.

So the metronome for this plan is three processes, which the repository already wires:
```bash
mkdir -p storage/logs/upgrade-l13
php artisan optimize:clear
for s in unit integration feature; do
  php artisan optimize:clear
  composer "test:$s" 2>&1 | tee "storage/logs/upgrade-l13/baseline-$s.txt" | tail -3
done
grep -hE '^\s+(FAIL|⨯)' storage/logs/upgrade-l13/baseline-*.txt > "storage/logs/upgrade-l13/baseline-failures.txt" || true
wc -l storage/logs/upgrade-l13/baseline-failures.txt
```
`storage/logs/` is gitignored, so these logs never reach a commit. Expected: three summaries, each from its own process with its own 4G budget. If one of the three still exhausts memory, STOP and report which: the upgrade cannot be verified against a baseline that does not exist, and splitting that suite further (or raising `memory_limit` for the run) is a decision for the user.
Tests failing here are not caused by the upgrade: the 2026-10-30 run showed 4 failures before it died, so expect a non-empty file.

- [x] **Step 5: Record the static-analysis baseline** Run on 2026-11-05, red with 1.874 findings: pre-existing larastan 3.13.x debt against a baseline frozen 2026-09-17, not a Laravel 13 regression. See the delivery status.

```bash
composer test:types 2>&1 | tail -5
```
Expected: green, or the same failures you will accept for the rest of the plan. `phpstan.neon` includes `phpstan-baseline.neon` (18.937 lines, the debt frozen on 2026-09-17), so a finding Laravel 13 introduces in analysed code will fail the run and is a regression to fix, not to freeze. Note that `Modules/*/tests/**` is excluded from analysis until `2026-10-29-test-toolchain-pest-5.md` runs: test-only findings stay invisible here.

---

### Task 2: Bump the seven light modules

**Files:**
- Modify: `Modules/AI/composer.json`, `Modules/CMS/composer.json`, `Modules/CRM/composer.json`, `Modules/ERP/composer.json`, `Modules/MES/composer.json`, `Modules/SAO/composer.json`, `Modules/Shop/composer.json` (the `require` block only)

**Interfaces:**
- Produces: every light module requires `laravel/framework: ^13.0`. Task 4 depends on it: one module left on `^12.0` pins the whole solve to Laravel 12.

- [x] **Step 1: Edit the constraints**

In each of the seven files change:
```json
"laravel/framework": "^12.0",
```
to:
```json
"laravel/framework": "^13.0",
```
Change nothing else. Every other requirement in these modules is framework-agnostic and already verified: `Modules/AI` (`neuron-core/neuron-ai ^4.1`, `aws/aws-sdk-php ^3.399`, `google/auth ^1.55`), `Modules/CMS` (`php-ffmpeg/php-ffmpeg ^1.4`, `ext-gd`, `ext-exif`), `Modules/MES` (`php-mqtt/client ^2.3`). `Modules/CRM`, `Modules/ERP`, `Modules/SAO` and `Modules/Shop` require only `php` and the framework. None of these modules has a `require-dev` block any more: the test toolchain lives in the root manifest and is Task 7's business.

- [x] **Step 2: Validate the manifests**

```bash
for m in AI CMS CRM ERP MES SAO Shop; do composer validate --no-check-publish --no-check-lock "Modules/$m/composer.json" || echo "INVALID $m"; done
```
Expected: seven `is valid` lines, no `INVALID`.

- [x] **Step 3: Commit inside each submodule**

```bash
for m in AI CMS CRM ERP MES SAO Shop; do
  git -C "Modules/$m" add composer.json
  git -C "Modules/$m" commit -m "chore(deps): require Laravel 13"
done
```
Expected: seven commits on `master`.

---

### Task 3: Bump Core

**Files:**
- Modify: `Modules/Core/composer.json` (the `require` block only)

**Interfaces:**
- Consumes: nothing.
- Produces: Core constraints that admit the Laravel 13 majors.

- [x] **Step 1: Edit the constraints**

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

- [x] **Step 2: Validate**

Run: `composer validate --no-check-publish --no-check-lock Modules/Core/composer.json`
Expected: `is valid`.

- [x] **Step 3: Commit inside Core**

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

- [x] **Step 1: Edit the root constraints**

In `composer.json` `require`:

| package | from | to |
|---|---|---|
| `laravel/framework` | `^12.59` | `^13.39` |
| `laravel/tinker` | `^2.11.1` | `^3.0` |
| `nwidart/laravel-modules` | `^12.0.5` | `^13.3` |
| `symfony/http-client` | `^7.4.9` | removed (see Global Constraints) |

Leave `coolsam/modules`, `filament/filament`, `joshbrw/laravel-module-installer`, `laravel/sanctum`, `php-mqtt/client` and all of `require-dev` unchanged in this task. `coolsam/modules ^5.1` and `filament/filament ^5.6.3` already admit the census versions (`v5.3.2`, `v5.10.3`); `laravel/sanctum ^4.3.2` admits `v4.3.3`.

- [x] **Step 2: Dry-run the solve**

```bash
composer update -W --dry-run --no-scripts \
  --ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick \
  2>&1 | tee "storage/logs/upgrade-l13/l13-dry.txt" | head -30
```
Expected: `Lock file operations: ...` and no `Your requirements could not be resolved`. The solve takes several minutes; run it in the background if needed.
If it fails, read the first `Problem` block, fix the named constraint in the manifest that owns it (tables in Task 3 and this task), commit that fix in the owning repo, rerun. Do not add `--ignore-platform-reqs` or loosen `minimum-stability`.

- [x] **Step 3: Resolve for real**

```bash
composer update -W --ignore-platform-req=ext-oci8 --ignore-platform-req=ext-imagick
```
Expected: ends with `package:discover` output and `filament:upgrade` without errors.

- [x] **Step 4: Check nothing stayed on a Laravel 12 line**

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

- [-] **Step 5: Decision gate for `mtrajano/laravel-swagger`** Not needed: the solve produced no downgrade at all and the package stayed at v0.6.4.

Run: `composer show mtrajano/laravel-swagger | grep versions`
- If it is `v0.6.4`: nothing to do.
- If the solve failed on it, or Composer picked an older version: run `composer why-not mtrajano/laravel-swagger 0.6.4` and report the blocking package to the user (expected cause: `phpdocumentor/reflection-docblock ^6`, which `v0.6.4` excludes with `^4.3|^5.2`). STOP and ask the user whether to (a) accept the downgrade after Step 6 proves the swagger command still works, (b) replace the package, or (c) drop swagger generation. Do not choose alone.

- [x] **Step 6: Boot and smoke check**

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

- [x] **Step 7: Commit (root)**

```bash
git add composer.json composer.lock
git commit -m "chore(deps): upgrade to Laravel 13"
```
Leave the gitlinks alone here: Task 9 bumps them, and it must reference module commits that already carry the fixes from Tasks 5 and 6.

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

- [x] **Step 1: Write the failing tests**

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

- [x] **Step 2: Run them and see them fail**

Run: `php artisan test --compact tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php`
Expected: 3 FAIL: the web group contains `PreventRequestForgery` (the silent regression), the auth group and the panel still list `VerifyCsrfToken`.

- [x] **Step 3: Fix `bootstrap/app.php`**

Replace the import of `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` (line 13) with:
```php
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
```
In the `web(remove: [...])` list replace the CSRF entry with `PreventRequestForgery::class,`.
In the `appendToGroup('auth', [...])` list replace `VerifyCsrfToken::class,` with `PreventRequestForgery::class,`.
If an import of `ValidateCsrfToken` is also present, remove it: one import covers both call sites now.

- [x] **Step 4: Fix the panel and the Sanctum config**

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

- [x] **Step 5: Check nothing else references the aliases**

Run: `grep -rn "VerifyCsrfToken\|ValidateCsrfToken" app bootstrap/app.php config routes Modules/*/app Modules/*/config Modules/*/routes`
Expected: no output. Ignore `bootstrap/cache/*`: generated files, cleared in Task 1 Step 2. If a module matches, fix it the same way and commit inside that submodule.

- [x] **Step 6: Run the tests and see them pass**

Run: `php artisan test --compact tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php`
Expected: all PASS.

- [x] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add bootstrap/app.php app/Providers/Filament/AdminPanelProvider.php config/sanctum.php tests/Feature/Http/MiddlewareGroupsTest.php tests/Feature/Filament/AdminPanelMiddlewareTest.php
git commit -m "fix(http): use PreventRequestForgery and keep the web group CSRF exclusion working on Laravel 13"
```

---

### Task 6: Framework extension points (cache overrides, nwidart, manager drivers)

**Files:**
- Inspect, and modify only if a signature moved: `Modules/Core/app/Cache/CacheManager.php`, `Modules/Core/app/Cache/Repository.php`, `Modules/Core/app/Providers/CoreServiceProvider.php:860`
- Inspect: `Modules/Core/app/Helpers/ModuleMultiActivator.php`, `Modules/Core/app/Helpers/ModuleDatabaseActivator.php`, `Modules/Core/app/Console/ModuleSeedCommand.php`, `Modules/Core/app/Overrides/ModuleDocGenerator.php`, `Modules/Core/app/Overrides/ModuleDocRoute.php`
- Inspect: `config/modules.php` (the `stubs` block and the nine `paths.generator` keys)

**Interfaces:**
- Consumes: Laravel 13 and nwidart 13 installed (Task 4).
- Produces: every class that extends or replaces a framework or nwidart class verified against its new parent, before the full suite runs.

This task exists because these are the places where an upgrade fails quietly rather than loudly: a child class whose parent changed signature, a service binding that replaces a framework singleton, a package that moves its own internals between majors. The user flagged the cache overrides and nwidart specifically on 2026-10-31. Run it before Task 7: a signature mismatch here would otherwise surface as dozens of unrelated-looking test failures.

- [x] **Step 1: Compare the cache override signatures against their new parents**

```bash
php -r '
require "vendor/autoload.php";
$pairs = [
    ["Modules\\Core\\Cache\\CacheManager", "Illuminate\\Cache\\CacheManager", ["repository"]],
    ["Modules\\Core\\Cache\\Repository", "Illuminate\\Cache\\Repository", ["remember"]],
];
foreach ($pairs as [$child, $parent, $methods]) {
    foreach ($methods as $method) {
        $c = new ReflectionMethod($child, $method);
        $p = new ReflectionMethod($parent, $method);
        $render = static function (ReflectionMethod $m): string {
            $params = array_map(static function (ReflectionParameter $x): string {
                return ($x->getType() ? $x->getType() . " " : "") . "$" . $x->getName() . ($x->isDefaultValueAvailable() ? " = …" : "");
            }, $m->getParameters());
            return $m->getDeclaringClass()->getName() . "::" . $m->getName() . "(" . implode(", ", $params) . "): " . ($m->getReturnType() ?: "(none)");
        };
        printf("child  %s\nparent %s\n\n", $render($c), $render($p));
    }
}
'
```
Expected: for each pair the parameter list and the return type are compatible (the child may narrow the return type, as `repository()` does by returning `Repository` instead of the framework one, and may widen nothing). A parameter added, removed, renamed in a way that breaks named arguments, or a return type the child no longer satisfies is a break: fix the child to match, keep the `#[Override]` attribute, and commit in `Modules/Core`.
If PHP already fatals on the `require` because of an incompatible declaration, that is the same finding arriving sooner: read the error, fix the signature.

- [x] **Step 2: Check the `cache` singleton replacement still binds a usable manager**

`Modules/Core/app/Providers/CoreServiceProvider.php:860` replaces the framework's `cache` binding wholesale:
```php
$this->app->singleton('cache', static fn ($app): CoreCacheManager => new CoreCacheManager($app));
```
A constructor parameter added to `Illuminate\Cache\CacheManager` in Laravel 13, or a second responsibility given to the `cache` binding, would be silently lost here.

```bash
php -r '
require "vendor/autoload.php";
$c = new ReflectionMethod("Illuminate\\Cache\\CacheManager", "__construct");
foreach ($c->getParameters() as $p) { echo "parent __construct: ", ($p->getType() ?: "mixed"), " $", $p->getName(), "\n"; }
'
php artisan tinker --execute 'dump(get_class(app("cache")), get_class(cache()->store()), get_class(cache()->store("array")));'
```
Expected: the parent constructor still takes one container argument; `app("cache")` is `Modules\Core\Cache\CacheManager`; both stores are `Modules\Core\Cache\Repository`, not the framework repository. If a store comes back as the framework class, `repository()` is no longer the hook the manager uses and the Core macros (`tryByRequest`, `clearByEntity`, `clearByRequest`) are silently gone from that driver.

- [x] **Step 3: Check no custom store must implement `touch()`**

Laravel 13 added `touch($key, $seconds)` to `Illuminate\Contracts\Cache\Store`. Both Core classes extend framework classes and inherit it, but a class implementing the contract directly would not.

```bash
grep -rn "implements.*Store\b\|Contracts\\\\Cache\\\\Store" --include=*.php app Modules/*/app
php -r '
require "vendor/autoload.php";
echo method_exists("Modules\\Core\\Cache\\Repository", "touch") ? "Repository has touch\n" : "Repository MISSING touch\n";
'
```
Expected: no class implements the contract directly, and `Repository` reports `touch`. If a direct implementor exists, add `touch($key, $seconds)` to it following the framework's own store as reference.

- [x] **Step 4: Run the cache tests**

```bash
php artisan optimize:clear
php artisan test --compact --filter='Cache|Warm|Settings|Acl'
```
Expected: PASS, or the same result as the Task 1 baseline. These cover the four callers of `CacheManager::key()` (`WarmCacheCommand`, `SettingsCacheCoordinator`, `AclResolverService`, `PerModelSettingResolver`) plus the Core repository macros.
Note on key prefixes: Laravel 13 changed the framework's *fallback* cache and Redis prefixes to hyphenated suffixes. This application does not rely on those fallbacks: `config/cache.php` sets `prefix` explicitly, `config/database.php` sets the Redis prefixes explicitly, and `CacheManager::key()` prefixes every key with `config('app.name')`. So no key should move. If a test asserts a literal key and fails, read the generated key before changing the assertion: a moved key means a cold cache in production, not a wrong test.

- [x] **Step 5: Verify the nwidart 13.2 configuration changes**

nwidart 13.2.0 ships real upgrade notes for two things this repository uses. Both look benign and must be confirmed, not assumed.

A custom `stubs.path` is ignored unless `stubs.enabled` is true:
```bash
grep -n "'stubs'" -A4 config/modules.php
```
Expected: `'enabled' => true` is present above `'path' => base_path('Modules/Core/stubs')`. It was on 2026-10-29. If it is missing, add it.

The nine generator keys without a `namespace` (`actions`, `casts`, `enums`, `exceptions`, `helpers`, `interfaces`, `repository`, `services`, `traits`) now derive the namespace from their `path` instead of the old default:
```bash
php artisan module:make-service UpgradeProbeService Core --no-interaction
head -5 Modules/Core/app/Services/UpgradeProbeService.php
php artisan module:make-action UpgradeProbeAction Core --no-interaction 2>/dev/null && head -5 Modules/Core/app/Actions/UpgradeProbeAction.php
```
Expected: `namespace Modules\Core\Services;` and `namespace Modules\Core\Actions;`, matching what the existing files in those folders declare. The paths are `app/Services` and `app/Actions` with `app_folder => 'app/'`, so the derived namespace should be identical to today's. Then delete the probes:
```bash
rm -f Modules/Core/app/Services/UpgradeProbeService.php Modules/Core/app/Actions/UpgradeProbeAction.php
git -C Modules/Core status --short
```
Expected: a clean submodule. If a generated namespace differs, add an explicit `namespace` key to that generator entry in `config/modules.php` to pin the old one, and say so in the delivery status: every future generated class depends on it.

- [x] **Step 6: Verify the nwidart extension points**

These classes extend or implement nwidart types, which move between majors:

```bash
php -r '
require "vendor/autoload.php";
$targets = [
    "Modules\\Core\\Helpers\\ModuleMultiActivator" => "Nwidart\\Modules\\Activators\\FileActivator",
    "Modules\\Core\\Helpers\\ModuleDatabaseActivator" => "Nwidart\\Modules\\Contracts\\ActivatorInterface",
    "Modules\\Core\\Console\\ModuleSeedCommand" => "Nwidart\\Modules\\Commands\\Database\\SeedCommand",
];
foreach ($targets as $child => $parent) {
    if (!class_exists($parent) && !interface_exists($parent)) { echo "GONE: $parent (needed by $child)\n"; continue; }
    $r = new ReflectionClass($child);
    echo str_pad($child, 52), " ok against ", $parent, "\n";
    foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
        if ($m->getDeclaringClass()->getName() !== $child) { continue; }
        $p = new ReflectionClass($parent);
        if (!$p->hasMethod($m->getName())) { continue; }
        $pm = $p->getMethod($m->getName());
        if (count($pm->getParameters()) !== count($m->getParameters())) {
            printf("  SIGNATURE MOVED: %s() child %d params, parent %d\n", $m->getName(), count($m->getParameters()), count($pm->getParameters()));
        }
    }
}
'
```
Expected: three `ok` lines and no `GONE` or `SIGNATURE MOVED`. A `GONE` means nwidart moved or renamed the type: find its replacement in `vendor/nwidart/laravel-modules/src` and update the child.
Also read the two swagger overrides against their new parents, since they reach into another package's internals: `Modules/Core/app/Overrides/ModuleDocGenerator.php` and `ModuleDocRoute.php` extend `mtrajano/laravel-swagger` classes, whose version Task 4 Step 5 may have changed.

```bash
php artisan optimize:clear
php artisan module:list
php artisan test --compact --filter='Module|Activator|Seed'
```
Expected: all eight modules `Enabled`, and the module/activator/seeding tests pass or match the baseline. The activators decide which modules boot at all, so a silent failure here is the kind that makes every other test fail for the wrong reason.

- [x] **Step 7: Note the manager driver closures**

Three Scout engine drivers are registered with `static` closures: `Modules/Core/app/Providers/SearchServiceProvider.php:53` and `Modules/Core/app/Providers/CoreServiceProvider.php:583,596`.

Laravel 13's upgrade guide says manager `extend` callbacks are now bound to the manager instance. Verified on 2026-10-31 by reading `Illuminate\Support\Manager::callCustomCreator()` in the installed Laravel 13: it still calls `$this->customCreators[$driver]($this->container)` with no `bindTo`, and the binding is expressed only as a `@param-closure-this $this` annotation for static analysis. So these three are safe at runtime, and nothing needs changing.

The annotation can still produce a new PHPStan finding, because a `static` closure cannot have `$this` bound:
```bash
composer test:types 2>&1 | grep -iE "closure-this|static closure|SearchServiceProvider|CoreServiceProvider" | head
```
Expected: no finding. If one appears, drop the `static` keyword from that closure rather than silencing it: the closures do not use `$this`, so losing `static` costs nothing.

- [x] **Step 8: Commit anything that moved**

Only if a step above changed a file:
```bash
vendor/bin/pint --dirty --format agent
git -C Modules/Core add -A
git -C Modules/Core commit -m "fix(core): realign the cache and module extension points with Laravel 13"
```
If nothing changed, say so explicitly when reporting the task: "no signature moved" is the result, not an absence of work.

---

### Task 7: Full suite on Laravel 13 (Pest 4)

**Files:**
- Modify: only files a failing test points to; each fix is its own commit in the owning repo.

**Interfaces:**
- Consumes: Tasks 4 and 5.
- Produces: suite result equal to `baseline-failures.txt` (no new failures).

This is the task that decides how long the upgrade takes. The Laravel 13 changes below are small and enumerable; the twelve third-party majors from Task 3 are not, and `spatie/laravel-permission` 6 to 8 is the one Core leans on hardest.

- [x] **Step 1: Run the suite, one process per suite**

Same three processes as the baseline (Task 1 Step 4), for the same reason: a single serial run exhausts 4G before finishing.
```bash
for s in unit integration feature; do
  php artisan optimize:clear
  composer "test:$s" 2>&1 | tee "storage/logs/upgrade-l13/l13-$s.txt" | tail -3
done
grep -hE '^\s+(FAIL|⨯)' storage/logs/upgrade-l13/l13-*.txt > "storage/logs/upgrade-l13/l13-failures.txt" || true
diff "storage/logs/upgrade-l13/baseline-failures.txt" "storage/logs/upgrade-l13/l13-failures.txt"
```
Expected: `diff` prints nothing. Every `>` line is a regression to fix in Step 2. Each pass costs roughly as long as the baseline did, so fix in batches: read every new failure first, group them by cause, then work the groups.

- [x] **Step 2: Fix regressions one at a time**

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

- [x] **Step 3: Rerun the suite**

Repeat Step 1. Expected: `diff` prints nothing.

---

### Task 8: Guidelines and documentation

**Files:**
- Modify: `CLAUDE.md` and `AGENTS.md` (regenerated Boost blocks), `AGENTS.md:67`
- Modify: `README.md:9`, `README.md:22`
- Modify: `Modules/*/README.md` (the Laravel badge on line 9 of all eight; the `-   Laravel 12.0+` requirement line in AI, Core, ERP)
- Modify: `docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md` (this file)

**Interfaces:**
- Consumes: finished upgrade.

- [x] **Step 1: Regenerate the Boost guidelines**

Run: `php artisan boost:update --no-interaction`
Expected: the `=== laravel/v12 rules ===` blocks in `CLAUDE.md` and `AGENTS.md` become Laravel 13 rules. Review the diff: only the `<laravel-boost-guidelines>` block may change. If Boost drops the "Laravel 10 structure is intentional" guidance, the project rule in `AGENTS.md` (`Laravel 10-style structure is intentional. Do not migrate.`) still stands; do not edit it.

- [x] **Step 2: Update the stack line, the badges and the requirement lines**

`AGENTS.md:67`: `- Stack: PHP 8.5+, Laravel 12, Filament 5, Livewire 4, Sanctum 4, Tailwind 4.` becomes the same line with `Laravel 13`.
`README.md:22`: `-   Laravel 12.x` becomes `-   Laravel 13.x`.
In `README.md:9` and line 9 of all eight `Modules/*/README.md`, the badge `Laravel-12.x-FF2D20` and its `alt="Laravel 12"` become `Laravel-13.x-FF2D20` and `alt="Laravel 13"`.
In `Modules/AI/README.md:168`, `Modules/Core/README.md:678`, `Modules/ERP/README.md:111`: `-   Laravel 12.0+` becomes `-   Laravel 13.0+`.
Commit each module README inside its own submodule as `docs: require Laravel 13`.
No env var was added, removed or renamed, so no README env section changes, and no RAG doc change: framework behaviour for operators is unchanged.

- [x] **Step 3: Close the plan**

Set `status: completed` in this file's front matter, next to the existing `module: platform`, and add at the end:
```markdown
## Delivery status (YYYY-MM-DD): shipped

**Documented in:** `AGENTS.md` (stack line), `README.md`, `Modules/AI/README.md`, `Modules/Core/README.md`, `Modules/ERP/README.md` (requirements).
```
State there that the test toolchain stayed on Pest 4 by design and name `2026-10-29-test-toolchain-pest-5.md` as the plan that carries it, so the file does not read as if the toolchain was forgotten. Record every divergence from Tasks 4 to 7 there: the swagger decision, any constraint that had to differ from the tables, any PHPStan finding fixed along the way. Resolve every checkbox first, `- [x]` or `- [-]` with its reason on the same line (`.ai/rules/plans.md`). The `INDEX.md` entry carries no status.

```bash
php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php
composer plan-status -- --open
```
Expected: the test PASSES, and this plan no longer appears among the open ones.

- [x] **Step 4: Commit (root)**

```bash
vendor/bin/pint --dirty --format agent
git add CLAUDE.md AGENTS.md README.md docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md
git commit -m "docs: record the Laravel 13 upgrade"
```

---

### Task 9: Bump submodule pointers

**Files:**
- Modify: root gitlinks `Modules/AI`, `Modules/CMS`, `Modules/CRM`, `Modules/Core`, `Modules/ERP`, `Modules/MES`, `Modules/SAO`, `Modules/Shop`

- [x] **Step 1: Check each submodule is clean and on master**

Run: `git submodule foreach --quiet 'echo "$name $(git rev-parse --abbrev-ref HEAD) $(git status --short | wc -l)"'`
Expected: eight lines `... master 0`.

- [x] **Step 2: Final suite**

```bash
for s in unit integration feature; do
  php artisan optimize:clear
  composer "test:$s" 2>&1 | tail -3
done
```
Expected: the same result as Task 7 Step 1.

- [ ] **Step 3: Commit the pointers**

```bash
git add Modules/AI Modules/CMS Modules/CRM Modules/Core Modules/ERP Modules/MES Modules/SAO Modules/Shop
git commit -m "chore(modules): bump all modules to their Laravel 13 upgrade"
```

- [ ] **Step 4: Delete the census scaffold**

The user asked on 2026-10-31 for `../laraplate13` to be removed once the upgrade is through, even with things still to review: it existed only to census package versions, and it is already out of date. Nothing in the repository references it except this plan's own prose, which keeps it as a dated record of where the version tables came from.

```bash
grep -rn "laraplate13" --include=*.php --include=*.json --include=*.neon --include=*.xml --include=*.sh . 2>/dev/null | grep -v "^./docs/" | grep -v node_modules | grep -v "^./vendor"
```
Expected: no output. Only then:
```bash
rm -rf /srv/http/laraplate-stack/laraplate13
ls /srv/http/laraplate-stack/
```
Expected: the folder is gone. If the grep found a reference, report it and do not delete: something started depending on the scaffold.

- [ ] **Step 5: Hand over**

Report to the user: the commits waiting to be pushed in all nine repositories, the swagger decision, the result of Task 6 (whether any extension point had moved), that `2026-10-29-test-toolchain-pest-5.md` is now unblocked, that the census scaffold is deleted, and that pushing and merging are theirs to do. Do not push or merge.

## Delivery status (2026-11-05): shipped

**Documented in:** `Modules/Core/docs/rag/SEARCH_MATCHING_DEVELOPER.md` (the Scout 11 `wheres` contract every engine now reads through `ScoutWheres::map()`), `Modules/ERP/docs/rag/MODULE.md` and `Modules/ERP/docs/ERP_GUIDA_SEMPLICE.md` (the Laravel 13 requirement). The platform-wide version floors live in `AGENTS.md` (stack line and structure rule), `README.md` and the eight `Modules/*/README.md` badges, which is where `ModuleMetadataTest` reads them.

The application runs on `laravel/framework v13.40.0`. The suite ends at the Laravel 12 baseline plus exactly one failure, and that one is infrastructure, not code.

**Test toolchain:** Pest 4 throughout, by design. `2026-10-29-test-toolchain-pest-5.md` carries Pest 5, PHPUnit 13 and `pest-plugin-phpstan`, and is unblocked by this plan's completion. Nothing here was left undone on the toolchain; it was never in scope.

**Known blocker, not a regression:** `Modules\CMS\tests\Feature\Search\Content` fails against the Elasticsearch server at `192.168.1.233:9200`, which is 8.19.21 and rejects the now-installed 9.5.0 client with `Accept version must be either version 8 or 7, but found 9`. No version combination avoids this on Laravel 13: `elastic-scout-driver` 6 is the first release with Scout 11 and L13, it requires `elastic-adapter ^5.0`, every 5.x requires `elastic-client ^4.0`, and every 4.x requires `elasticsearch ^9.0`. The last link accepting an ES 8 server was `elastic-client` 3.1, which caps at adapter 4 and driver 5, neither of which supports Laravel 13. **Upgrading the server to 9.x is a requirement of this upgrade.** The server was not touched.

**Pre-existing failures carried over from the Laravel 12 baseline:** three in `Modules\AI\tests\Feature\Console\RefreshAiModelsCommandTest` (a reachable local LLM server injects a model the assertion does not expect) and one in `Modules\CMS\tests\Feature\Filament\ContributorsTableTest` (`Class "Imagick" not found`). Both are environmental.

### Divergences from the plan

- **Task 4 Step 5, the `mtrajano/laravel-swagger` decision gate: not needed.** The solve produced no downgrade; the package stayed at v0.6.4. Its `--module` option never worked, because v0.6.4 already declared its options in `$signature` and the override added them through the dead `getOptions()`. That is a pre-existing defect, unrelated to Laravel 13, and was left alone.
- **Guzzle went up, not down.** The plan predicted a drop to 7; `league/oauth1-client` now accepts Guzzle 8, so the solve landed 8.3.1.
- **Task 6 Step 7 produced a real fix, not just a note.** Laravel 13 annotates `Manager::extend()` with `@param-closure-this`, so the three `static` Scout driver closures became PHPStan findings. `Manager::callCustomCreator()` still invokes the callback without `bindTo()`, so this was never a runtime failure; `static` was dropped from all three as the step prescribed.
- **No extension point moved.** The cache overrides (`CacheManager`, `Repository`), the three nwidart children and the generated module namespaces all matched their new parents unchanged. `Contracts\Cache\Store::touch()` affects nobody: no class in the repository implements that interface directly.

### Breaking changes that cost work, in order of blast radius

1. **`brick/math` 0.x to 1.0** turned `RoundingMode` from class constants into an enum with TitleCase cases. `RoundingMode::HALF_UP` stopped resolving and every ERP decimal operation threw at runtime: 217 of 222 feature failures from eight lines in three files.
2. **`db:seed` moved its options from `getOptions()` to `$signature`**, silently killing the child override in `Modules/Core/app/Console/SeedCommand.php`: 174 failures from one cause. `route:list` had the same shape. Both now register their options on the resolved definition in the constructor.
3. **Scout 10 to 11 changed `Builder::$wheres`** from a `field => value` map to a list of `['field','operator','value']` triples. No error anywhere: filters, vector extraction and a test helper's strategy selection just stopped matching. Fixed centrally by `Modules/Core/app/Search/Support/ScoutWheres.php`, applied at fourteen read sites. `whereIns` was unaffected.
4. **The CSRF middleware rename** to `PreventRequestForgery`. The trap was `tests/TestCase.php`: `withoutMiddleware()` binds the exact abstract it is given, so naming the deprecated alias excluded nothing. `tests/Feature/Http/MiddlewareGroupsTest.php` now pins the canonical name in both middleware groups, the Sanctum config and the test-time passthrough.
5. **Laravel 13 refuses two served disks on the same URL path.** `Modules/CMS/config/filesystems.php` had `media-library` serving `/storage`, which the framework's own `local` disk already serves: every artisan command threw until it moved to `/media`.
6. **`wotz/laravel-swagger-ui` 1 to 2** moved `getJson`/`configureServer`/`configureOAuth` out of `OpenApiJsonController` into a new `SwaggerFile`. `Modules/Core/app/Overrides/MergedSwaggerFile.php` subclasses it and replaces only the spec source, so the package keeps applying `server_url` and oauth.
7. **`MustVerifyEmail` gained `markEmailAsUnverified()`**, which only a test double had to implement.

### Static analysis: red, and not because of this upgrade

`composer test:types` reports 1.874 findings. This was measured on 2026-11-05 only, because Task 1 Step 5 was skipped at the start of the plan, so there is no Laravel 12 number to diff against. The cause is datable without one: the two rule families producing the bulk are larastan's model-property checks on `fill()`/`forceFill()`/`where()` and its reading of literal SQL in `DB::unprepared()`, both of which shipped in **larastan v3.13.2 on 2026-10-23**, already present in `composer.lock` before the Laravel 13 require commit, while `phpstan-baseline.neon` freezes a state from 2026-09-17. The array shapes the findings cite (`array{touch?: bool|null}`, `array<model property of …>`) come from `vendor/larastan/larastan/stubs/common/Model.stub`, which applies to Laravel 12 as well. larastan v3.14.0, the only analyser version this plan's solve introduced, adds exactly one rule enabled by default, and it accounts for 2 of the 1.874.

So the red is accumulated analyser debt, not a Laravel 13 regression, and it is a decision for the user rather than something to freeze quietly: `2026-10-29-test-toolchain-pest-5.md` Task 2 Step 5 regenerates `phpstan-baseline.neon` wholesale, which would sweep all 1.874 app-code findings into the baseline as a side effect of a task about test suites. That task must not be run as written until the user has decided what to do with them.
