---
created_on: 2026-11-12
module: ai
---
# Permission patterns in documentation front matter. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each one when it finishes, in the same work block.

**Goal:** A user document declares who may read it with short-form permission requirements (`cms_contents.*`, `*.delete`), several requirements mean any of them, no row is added to the `permissions` table, and the user corpus carries the front matter that makes it indexable.

**Architecture:** A pure helper derives, from the caller's concrete permissions, the requirement strings they satisfy. `DocumentationRetrievalContext::permissionFilter()` passes that list to a `terms` query next to the existing "no requirement" branch. The index, the mapping and `required_permissions_count` do not change. A non-blocking indexing warning names requirements that match no known permission.

**Tech Stack:** PHP 8.5, Laravel 13, Pest. No new dependency.

**Spec:** `../specs/2026-11-12-documentation-permission-patterns-design.md`.

## Global Constraints

- Branch `feat/documentation-permission-patterns` in `Modules/AI` (code, tests, module docs); the spec, this plan and the amendment of the 2026-07-16 spec are in the root `laraplate` repo. One PR per repo; submodule pointers are bumped by the owner, never by an agent.
- The front matter of the user documents (Task 6) goes in separate branches and PRs in `Modules/Core`, `Modules/AI` and `Modules/SAO`.
- No migration, no new `permissions` row, no change to the index mapping.
- Spatie wildcard configuration is not touched; matching is done by the helper, not by `hasPermissionTo`.
- Tests that declare classes use `Modules/AI/tests/Stubs/`, never the test file. No test is deleted; the ones that encode AND move to OR.
- Run Pint from the root with an explicit file list. Run `php artisan test --compact <path>` on the narrow paths while working; the full AI module suite must pass before the PR is opened. If `bootstrap/cache/config.php` exists the suite aborts: `config:clear` first.
- Do not write to the remote Elasticsearch from an agent: the indexing in Task 7 is run by the owner.

## Review Focus

1. **Fail-closed.** A requirement that is malformed, misspelled or matches nothing satisfies nobody. No code path may treat an unparseable requirement as "no requirement" (that would make the page public).
2. **Case and shape.** `forceDelete` is not `forcedelete`; `erp_*` is a literal that matches nothing; a permission name that does not have three segments produces no short form.
3. **OR does not reach the count-zero branch by accident.** `required_permissions: []` (public to every authenticated user) and "all requirements unmatched" are different: only the first is visible to everyone.
4. **Tenant, locale, audience, classification and module filters are untouched** and still AND-ed with the permission clause.

---

### Task 1: The pattern helper

**Files:**
- Create: `Modules/AI/app/Services/Documentation/PermissionRequirementPatterns.php`.
- Test: `Modules/AI/tests/Unit/Services/Documentation/PermissionRequirementPatternsTest.php`.

- [x] **Step 1: Test first** — `satisfiedBy()`: the four short strings per three-segment name (`default.cms_contents.delete` gives `cms_contents.delete`, `cms_contents.*`, `*.delete`, `*.*`) plus the original name; a name with other than three segments is kept and gives no short form; case-sensitive (`forceDelete`); `erp_*` and `*_contents` never produced; result sorted and unique; empty input gives an empty list.
- [x] **Step 2: Implement** `final readonly class PermissionRequirementPatterns` with `public static function satisfiedBy(array $permissions): array`. No state, no I/O.
- [x] **Step 3:** run the tests (PASS), Pint, commit: `feat(ai): derive the documentation requirements a set of permissions satisfies`.

---

### Task 2: The retrieval filter

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DocumentationRetrievalContext.php` (`permissionFilter()`).
- Modify: `Modules/AI/tests/Stubs/Documentation/FakeDocumentationSearch.php` (the filter it models).
- Test: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/InAppDocumentationRetrievalTest.php`, `DocumentationRetrievalScopeTest.php`, and a new case file for the OR behaviour.

- [x] **Step 1: Test first** — the filter holds `required_permissions_count = 0` plus a `terms` query on `metadata.required_permissions` over `PermissionRequirementPatterns::satisfiedBy($effectivePermissions)`; with no permissions only the count-zero branch is present; the filter has no `terms_set` any more. Through `FakeDocumentationSearch`: `cms_contents.*` is returned to a holder of `default.cms_contents.update` and not to a holder of only `default.erp_orders.update`; `*.delete` and `cms_contents.select` are returned to a holder of either; both entries misspelled returns to nobody; one valid and one misspelled returns to the audience of the valid one; `required_permissions: []` is returned to every authenticated user.
- [x] **Step 2: Implement.** Replace the `terms_set` with the `terms` query. The fake store models the same rule by calling the helper, so the unit tests and the real filter share one definition.
- [x] **Step 3: Existing assertions.** The tests that encode `terms_set` or AND are updated, not deleted; the assertions on `cms.content.edit` and `cms.content.view` keep working because original names are kept in the list.
- [x] **Step 4:** run the tests (PASS), including `InAppDocumentationRetrievalTest` and the assistant evaluation tests; Pint; commit: `feat(ai): documentation requirements are short-form patterns and mean any of them`.

---

### Task 3: Known permissions and the unmatched-requirement audit

**Files:**
- Create: `Modules/AI/app/Services/Documentation/PermissionRequirementAudit.php`.
- Test: `Modules/AI/tests/Unit/Services/Documentation/PermissionRequirementAuditTest.php`.

- [x] **Step 1: Test first** — given the known permission names and a list of documents with requirements, `unmatched()` returns `{document, requirement}` pairs for the requirements that no known permission satisfies (`T.O`, `T.*`, `*.O`, `*.*` and long names are matched through `PermissionRequirementPatterns::satisfiedBy()` applied to the known names); a valid pattern is not reported; a document with `required_permissions: []` is not reported; the result is stable (sorted by document, then requirement).
- [x] **Step 2: Implement.** The class takes the known names as an argument (the caller reads the `permissions` table, read-only); it does no I/O itself.
- [x] **Step 3:** run the tests (PASS), Pint, commit: `feat(ai): find documentation requirements that match no permission`.

---

### Task 4: The indexing warning

**Files:**
- Modify: `Modules/AI/app/Services/DocumentationService.php` (the user-profile branch of `profileDocuments()` and the result it returns to the command).
- Modify: `Modules/AI/app/Console/IndexDocumentationCommand.php`.
- Test: `Modules/AI/tests/Integration/IndexDocumentationCommandTest.php`, `DocumentationServiceTest.php`.

- [x] **Step 1: Test first** — indexing the user profile with a document requiring `cms_contenst.*` prints a warning naming the document and the requirement, the exit code stays `SUCCESS` and the document is still indexed; a valid pattern prints nothing; the developer profile prints nothing; `--diff --dry-run` reports the same warning and writes nothing; `--profile=all` reports only the user part.
- [x] **Step 2: Implement.** The service reads the known permission names once per run (`Modules\Core\Models\Permission`, names only) and hands the unmatched pairs back with the existing run summary; the command prints them after the counts, at most 20 per run with a "and N more" line, like `reportDiff()`.
- [x] **Step 3:** run the tests (PASS), Pint, commit: `feat(ai): warn about documentation requirements that match no permission`.

---

### Task 5: Documentation of the rule

**Files:**
- Modify: `Modules/AI/docs/rag/MODULE.md` (front matter reference for user documents: `required_permissions` grammar, OR rule, fail-closed, the warning).
- Modify: `Modules/AI/README.md` only if an env var or command option changed (none expected; state the judgment when finishing).
- Modify: `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md` line that describes `safeDocuments()` if it still says AND.

- [x] **Step 1: Write** the grammar, three examples (`cms_contents.*`, `*.delete`, a list of two read as "either"), the reading rule "adding a requirement widens the audience", and what a misspelled requirement does.
- [x] **Step 2: Check** the docs against the code: `grep -rn "terms_set\|every required" Modules/AI/docs docs/rag`, nothing stale.
- [x] **Step 3:** commit: `docs(ai): the permission patterns of documentation front matter`.

---

### Task 6: Front matter of the user documents

**Files:**
- Modify: the 10 `*_USER.md` and shared documents of `Modules/Core`, `Modules/AI` and `Modules/SAO` (one branch and PR per repo).

- [x] **Step 1: List** each user document with the permission it should require, chosen per page with the product owner before editing. Nothing is written until the list is agreed.
- [x] **Step 2: Add** `locale`, `canonical_source`, `safe_source_label` (convention of `Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json`, for example `Core · Adaptive search matching · ...`), `version`, `policy_classification: user_safe`, `policy_classification_version: in-app-docs-v1`, `required_permissions`, `tenant_scope: global`. The locale of the documents (English) against the interface language (Italian) is decided with the owner in step 1.
- [x] **Step 3: Verify** that every document is admitted and no requirement draws the Task 4 warning. Done read-only by the agent through `sourceNames()` and `unmatchedPermissionRequirements()` on the three roots against the real `permissions` table: 5, 4 and 1 user documents admitted, no unmatched requirement. The owner's `--diff --dry-run` is part of Task 7.
- [ ] **Step 4:** run the module suites of the three repos that changed; commit per repo: `docs(<module>): front matter for the user documentation index`.

---

### Task 7: First indexing of the user corpus (owner-gated)

- [ ] **Step 1:** the owner runs `php artisan ai:index-rag-docs --profile=user`. The store creates `laraplate_rag_user_docs` with the correct mapping.
- [ ] **Step 2:** the owner asks one question per audience with `ai:help`-equivalent user retrieval (a user with and without the relevant permission) and confirms that a page restricted to a permission is visible only to the holders.
- [ ] **Step 3:** if the user profile should have an evaluation report, run `test:ai:evaluate-documentation` for it; otherwise record that it has none.

---

## Out of scope

- Prefix globs for module scoping (`erp_*`), negative requirements, any change to who holds which permission (spec "Out of scope").
- Shrinking the derived list with a cached terms aggregation of the corpus: only if the administrator's payload shows up in profiling (spec "Derivation").
