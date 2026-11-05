# Agent Rules (caveman)

## Source Of Truth

- Use existing rules. Do not recreate unless missing.
- Main rules:
  - `CLAUDE.md`
  - `.cursor/rules/00-master.mdc`
  - `.cursor/rules/laravel-boost.mdc`
  - Relevant `.cursor/rules/*.mdc`
  - `.kiro/steering/*.md` only for Kiro specs or when Cursor rules miss needed context.
- Specs:
  - Read `docs/superpowers/specs/INDEX.md` first when present.
  - Read `docs/superpowers/plans/INDEX.md` first when present.
  - Read `.cursor/plans/INDEX.md` first when present.
  - Read `.kiro/specs/INDEX.md` first when present.
  - Open only relevant specs/plans after using indexes or search.
- Module context:
  - `Modules/*/README.md`
  - `Modules/*/.cursor/rules/module-context.mdc`
  - `Modules/*/.cursor/mcp.json`

## Load Policy

- Cursor uses `.cursor/rules/*.mdc` frontmatter to decide rule inclusion.
- Codex starts from `AGENTS.md`, then loads only relevant rules/specs/plans.
- `README.md` and `INDEX.md` files are navigation aids, not extra law.
- Do not bulk-load all specs/plans. Use `rg` and indexes, then open the smallest relevant section/file.
- If two rule files duplicate each other, keep the stricter or more local rule.

## MCP

- Root MCP config:
  - `.mcp.json`
  - `.cursor/mcp.json`
- Module MCP config:
  - `Modules/*/.cursor/mcp.json`
- Existing module example:
  - `Modules/AI/.cursor/mcp.json`
- Before work in a module, check its local MCP config.
- If an MCP is not exposed as an active tool in this Codex session, read config as reference and say the tool is unavailable.
- Do not assume root MCP covers module-specific MCP.

## Always

- Chat in Italian.
- Code, PHPDoc, comments, docs content in English unless user asks otherwise.
- Rules use caveman syntax to save tokens: short bullets, few words, no fluff. Never trade clarity, precision, completeness.
- Follow existing code style and sibling files.
- Touch only files related to current task.
- No new dependencies without user approval.
- No new base folders without user approval.
- Create docs only when user asks.
- Feature implementations must update affected user/operator and developer RAG docs when the behavior is worth documenting. Use the docs of the module where the change was made; cross-module work updates each affected module's own docs. Added, removed, renamed, or behavior-changing env vars must be documented in the affected module README using the existing style. Patch-only fixes, formatting, narrow tests, and internal refactors can skip RAG docs, but state that judgment when finishing.
- Specs and plans are dated decisions, not documentation. A spec stays true forever about one moment; module docs describe the present and go stale when code moves.
- Nothing ships documented only in a spec or plan. The behaviour of a shipped feature belongs in `Modules/{Module}/docs/` and `docs/rag/`.
- Closing a plan means adding a `**Documented in:**` line naming the module docs that describe the behaviour now. Enforced by `tests/Unit/ClosedPlansPointToDocumentationTest.php`.
- Mark a finished plan with `status: completed` in its front matter and a `## Delivery status (date): ...` section, always both. A plan replaced by a later one gets `status: superseded` and names its successor there. Record divergences from the plan there: the file is the only place saying an artifact was deliberately not built.
- `INDEX.md` lines in `docs/superpowers/{plans,specs}` describe a file: name, what it covers. No status, dates of delivery or progress: plan-status reads them from the plan, and a copy drifts.
- Do not tick a plan's checkboxes from the artifact names it imagined. Read what the code does and map it. Names can survive a design the implementation replaced.
- Tick a task's checkbox when that task finishes, in the same work block. Not at the end of the plan, not next month. A plan whose boxes lag is worse than one with none: it reports work as outstanding that is done, and the drift is only ever found by the user.
- A plan is finished when its boxes are ticked, it carries a delivery status, its documentation exists, and it lives in the repository that owns its subject. See `Where specs and plans live` in the stack `AGENTS.md`: a finished plan at the stack root whose subject is only the backend moves here, spec included, with links and indexes repaired.

## Project

- Laravel modular app.
- Stack: PHP 8.5+, Laravel 13, Filament 5, Livewire 4, Sanctum 4, Tailwind 4.
- Laravel 13 structure. `bootstrap/app.php` configures routing, middleware groups and exception handling through `Application::configure()`; there is no `app/Console/Kernel.php` and no `app/Exceptions/Handler.php`. `app/Http/Kernel.php` still exists but is a 20-line stub of placeholder comments that nothing references: do not add to it, and do not take it as evidence of the old structure.
- Modules use `nwidart/laravel-modules`.
- `Core` is foundation. Other modules reuse Core contracts/services.
- Module dependencies must be explicit in `module.json`.
- Table names are prefixed by lowercase module name.
- Native modules (Core, CMS, AI, CRM, ERP, MES, SAO, Shop) share one schema, one connection.
- Cross-module foreign keys and cross-module `whereHas`/`join` are allowed.
- Multiple connections only at driver level (read replica, `sticky`).
- `core.model_connections` and `erp.model_connections` are frozen. Add no entries.
- Do not extend `ConnectionScoped*` or `ErpConnectionContext` to new code.
- Still derive queries and transactions from the owning model. No implicit `DB::`.
- Details: `docs/database-connection-affinity-audit.md`.

## PHP

- Every PHP file: `declare(strict_types=1);`.
- Always braces for control structures.
- Explicit param types and return types.
- Prefer constructor property promotion.
- Use `#[Override]` when overriding.
- Use `final`, `readonly`, `static` where existing rules require.
- Never remove `final`, `readonly`, `static` to make tests easier.
- Need mocking? Use interfaces or Mockery.
- Prefer PHPDoc over inline comments.
- Keep comments rare and useful.

## Laravel

- Laravel way first.
- Use `php artisan make:* --no-interaction` for framework files.
- Controllers thin.
- Business logic in services/repositories/actions matching local pattern.
- Use Form Requests for validation.
- Use policies/gates/Sanctum for auth.
- Eloquent first. Avoid raw SQL unless justified.
- Prevent N+1 with eager loading.
- Use queues for heavy work.
- Use `config()` outside config files, not `env()`.
- Prefer named routes and `route()`.

## Testing

- Every code change needs automated test proof.
- Prefer Pest and feature tests unless unit test fits better.
- Keep tests inside module `tests/` for module work.
- Use factories/states for setup.
- Never declare classes, traits, interfaces, or enums inside test files; put them in that module's `Modules/{Module}/tests/Stubs/` (or module equivalent like `tests/Support/`) with PSR-4 namespaces registered in that module's `composer.json` `autoload-dev`.
- Run minimal relevant tests:
  - `php artisan test --compact path/or/filter`
- `tests/bootstrap.php` runs `Tests\Support\TestEnvironmentGuard`: the suite aborts if `bootstrap/cache/config.php` exists (a cached config makes tests use the `.env` database; fix with `php artisan config:clear`) or if the database is not sqlite or a local host (127.0.0.1, localhost, ::1).
- Opt out of the remote-database check only knowingly with `ALLOW_REMOTE_TEST_DATABASE=1`.
- Never leave `php artisan optimize` or `config:cache` on a machine that runs tests.
- Before done after code changes:
  - `vendor/bin/pint --dirty`

## Performance

- Cache hot data with Laravel cache helpers.
- Use queues for slow work.
- Add indexes when query pattern needs.
- Use transactions for multi-step consistency.
- Prefer portable Eloquent/query builder over vendor SQL.

## Security

- Validate input.
- Authorize actions.
- Keep CSRF/XSS protections.
- Log useful context, never secrets.
- Fail safe with clear expected error handling.

## Frontend

- Tailwind v4 syntax only.
- Use `@import "tailwindcss"`.
- Livewire components need one root element.
- Validate and authorize Livewire actions.
- Use `wire:key` in loops.
- If UI change not visible, mention `npm run build`, `npm run dev`, or `composer run dev`.

## Before Work

- Read relevant Cursor/Kiro/module rules first.
- If working in `Modules/{Name}`, read:
  - `Modules/{Name}/README.md`
  - `Modules/{Name}/.cursor/rules/module-context.mdc`
  - `Modules/{Name}/.cursor/mcp.json` when present
- Check sibling files before editing.
- For Laravel ecosystem docs, use Laravel Boost docs tool when available.
- If tool unavailable, rely on local rules and installed code.

## Before Final

- Summarize changed files.
- State tests/format commands run.
- If not run, say why.

@RTK.md

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
