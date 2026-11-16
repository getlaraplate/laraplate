# Permission patterns in documentation front matter

**Status:** Proposed

**Date:** 2026-11-12

**Program:** documentation RAG, user corpus. Follows `2026-07-16-in-app-ai-assistance-security-design.md`, which fixed the rule that a page is retrieved only when the caller holds every permission the page requires.

## Problem

A user document declares who may read it with `required_permissions` in its front matter. Today each entry is an exact permission name, `default.cms_contents.delete`, and the Elasticsearch filter is a `terms_set` over that list: the page is visible when every required name is among the caller's effective permissions.

That works for a page tied to one precise permission and not for what the guides actually need to say:

- "visible to anyone who can delete something" (`*.delete`);
- "visible to anyone who works with contents" (`cms_contents.*`).

Two things are ruled out by the product owner:

- no new rows in the `permissions` table, and no pseudo-permissions, whose only job would be to gate documentation;
- no rewrite of the retrieval filter that would let a misspelled requirement widen visibility.

Spatie wildcards do not help. They work on the grant side: a user who holds `cms_contents.*` satisfies a check for `cms_contents.delete`. A wildcard on the requirement side does not match anything held, so the existing `terms_set` cannot express it.

## Decision summary

1. A requirement is written in a **short form** `<table>.<operation>`. Each of the two segments is either a literal name or `*`. Examples: `cms_contents.delete`, `cms_contents.*`, `*.delete`, `*.*`.
2. The connection segment is dropped. Every permission name today is `default.<table>.<operation>`, there is one connection, and no table exists in two connections, so the short form identifies exactly one permission per literal pair.
3. The **long form** `default.cms_contents.delete` stays valid and behaves as today. Nothing already indexed changes meaning.
4. Matching is done on the application side, on the caller's side of the comparison. From the caller's concrete effective permissions the retrieval context derives the **set of requirement strings those permissions satisfy**, and passes that set to a plain `terms` query on `metadata.required_permissions`. The index and the mapping are unchanged.
5. Several requirements in one document mean **any of them**: the page is visible when the caller satisfies at least one. This reverses the AND rule of `2026-07-16-in-app-ai-assistance-security-design.md` (see "Why OR"). A document with no requirement stays visible to every authenticated user, through the existing `required_permissions_count = 0` branch.
6. A requirement that is misspelled, malformed or names a permission that does not exist is satisfied by nobody: the page is visible to no one. This is the accepted behaviour (fail-closed), not an error.

## Matching rule

A concrete permission `default.T.O` satisfies exactly these requirement strings:

| Requirement | Meaning |
|---|---|
| `T.O` | that permission |
| `T.*` | any operation on table `T` |
| `*.O` | operation `O` on any table |
| `*.*` | any permission at all |
| `default.T.O` | the long form, unchanged |

Rules:

- Literal segments are compared **case-sensitively** and exactly. Operations such as `forceDelete` and `refreshEInvoice` contain capitals, so no case folding.
- `*` stands for a whole segment only. `erp_*`, `*_contents` and `cms_*.delete` are not patterns: they are literal names that no permission has, so they match nothing.
- No comma lists inside a requirement. A document that fits two audiences lists two requirements (OR).
- Only names with exactly three segments feed the derivation. A name with any other shape is passed through unchanged (it can still satisfy a long-form requirement that spells it exactly) and produces no short-form strings.

## Derivation

`effectivePermissions` is unchanged (the exact names, from `getAllPermissions()`, which is what `AssistantAccessContextFactory` already builds). A new pure helper, `Modules\AI\Services\Documentation\PermissionRequirementPatterns`, exposes:

```php
/** @param list<string> $permissions @return list<string> sorted, unique */
public static function satisfiedBy(array $permissions): array;
```

For every three-segment name it adds the four short strings above; it keeps every original name as well. `DocumentationRetrievalContext::permissionFilter()` replaces the `terms_set` with a `terms` query on `metadata.required_permissions` over that list, next to the unchanged `required_permissions_count = 0` branch. `required_permissions_count` stays in the mapping and in the metadata: it now only marks "no requirement", and `InAppDocumentationRetrieval::safeDocuments()` keeps checking that it matches the list.

## Why OR

- Real access rules in the application are "A or B": the history of a record needs `update` or `approve` (`RelationAuthorizer::maySeePendingChanges()`). A guide that explains such a feature has the same audience.
- With wildcards the AND is rarely needed: `cms_contents.*` already means "anything on contents", and holding `delete` without `select` is not a real case.
- Cost of deciding now is nil: the user index does not exist yet and no document declares a permission. Later it would mean a rebuild and a review of every page written under the other meaning.
- Accepted trade-off: adding a requirement to a document widens its audience instead of narrowing it, and a typo in one of several requirements drops only that audience, silently. The indexing warning below covers the second point; the first is a reading rule for authors, stated in the documentation.

Why derive from the caller and not from the corpus:

- It needs no query to learn which requirements the corpus contains, so there is no extra Elasticsearch round trip and no cache to invalidate when documents are re-indexed.
- It cannot lag behind an index run: a requirement added to a document is matched the moment the document is written.
- It is a pure function of data the context already holds, so the unit tests need no store.

Size: a user with N permissions yields at most N + (distinct tables) + (distinct operations) + 1 strings. The administrator, who holds all 2048 permissions, sends about 2.2 thousand terms (about 60 KB). The default `terms` limit of Elasticsearch is 65536. If that payload ever shows up in profiling, the alternative is to intersect the list with the distinct requirement values of the corpus (one terms aggregation, cached); the matching rule does not change.

## What does not change

- Index mapping: `required_permissions` stays a `keyword`, `required_permissions_count` an `integer`. Pages indexed before this change under the long form keep their requirements, now read as OR: only a page with two or more requirements changes meaning, and none exists in the user corpus yet.
- `DocumentAudiencePolicy`: it still demands a list of non-empty strings. It does not validate the grammar, because a malformed requirement is fail-closed anyway and rejecting it would turn a harmless typo into a failed index run.
- `InAppDocumentationRetrieval::safeDocuments()`: still checks `required_permissions_count`.
- The developer profile, which does not use permissions.
- Module scoping: it stays on `metadata.module` and `cross_cutting_user`. A requirement is about what a person may do, not about where they are.

## Visibility of typos

A misspelled requirement is silent: the page simply never comes back. To make that findable without blocking anything, the indexing command prints a **warning** after the run when a requirement of the user profile matches no known permission. "Known" means the distinct short-form strings obtainable from the `permissions` table (read-only): every `T.O`, every `T.*`, every `*.O`, `*.*`, and the long names. The warning lists the document and the requirement. It does not change the exit code and does not stop the document from being indexed.

## Tests

- `PermissionRequirementPatternsTest` (unit): the four strings per permission; long names kept; wrong-shape names passed through without short forms; case sensitivity (`forceDelete`); `erp_*` and `*_contents` not produced and not matched; result sorted and unique; empty input.
- `DocumentationRetrievalContextTest`: the `terms` query receives the derived list; the empty-permission case still has only the count-zero branch.
- The fake store of the existing retrieval tests (`FakeDocumentationSearch`) models the filter and must move from AND to OR with the code. Cases: a page requiring `cms_contents.*` is returned to a caller holding `default.cms_contents.update`, not to one holding only `default.erp_orders.update`; a page requiring `*.delete` and `cms_contents.select` is returned to a caller holding either; a page with both entries misspelled is returned to nobody; a page with one valid and one misspelled entry is returned to the audience of the valid one.
- The indexing warning: a typo is reported, a valid pattern is not, the exit code is unchanged.

## Documentation to update with the code

- `Modules/AI/docs/rag/MODULE.md` and the document front matter reference: the short form, the matching rule, the fail-closed behaviour.
- The 10 user documents (Core, AI, SAO) get their front matter in separate changes, one per repository, once this lands; the permission each page needs is chosen per page with the product owner.

## Out of scope

- Prefix globs for module scoping (`erp_*`): add only if a real page needs "any ERP permission"; it needs an explicit decision on the segment grammar.
- Negative requirements ("visible unless the user can ...").
- Any change to who holds which permission.
