# CMS imports: one record across several sources

**Status:** Draft for written review

**Date:** 2026-10-30

**Destination module:** CMS (Core touched only for a migration helper, if D7 needs one)

**Related:** `2026-07-29-erp-import-source-reconciliation-design.md` (the ERP answer to the same question),
`Modules/CMS/docs/rag/IMPORTS.md` (provenance as it is today)

## Problem

A CMS installation imports from several sources at once: today `naxos_api@ilfoglio`, `naxos_api@avvenire`,
`naxos_api@liberta`, `naxos_api_event@liberta`, `feed`, `feed@nasa-news` and `spaceflight` write into the same
database. The import identity of a record is the pair source key plus external id (`core_record_origins`), which
lets the sources live side by side. Nothing decides what happens when two sources bring the same thing.

Each entity answers differently today, by accident rather than by design:

| Entity | How an import finds an existing record | Duplicates on 2026-10-30 |
|---|---|---|
| Tag | origin only (`TagUpserter`) | 5 slugs, each on two tags from two sources |
| Category | origin only (`CategoryUpserter`) | 10 names repeated across sources |
| Contributor | slug in the import locale, then name for configured names only, preferred over a stale origin (`ContributorMatcher`) | 0 |
| Location | origin, then slug, then name (`LocationMatcher`) | 0 |
| Content | origin only (`ContentUpserter`) | not measured |

The tag duplicates are the defect that started this spec: `giustizia`, `intelligenza-artificiale`, `libri`, `manovra`
and `unione-europea` each exist twice, with the same slug and no type. The difference in case between the pairs
is incidental: `Unione europea` is duplicated with identical spelling (tags 35 from ilfoglio and 227 from feed).

No constraint stops it. `cms_tags_translations` has `unique(tag_id, locale)` only; `(locale, slug)` is a plain
index, although the `cms_tags` migration comment says the unique constraints live there.

## Decisions

### D1. Each entity is either a shared vocabulary or owned by its source

| Entity | Kind | Why |
|---|---|---|
| Tag | shared | A flat label with no structure: "Libri" from two newspapers is one subject. |
| Location | shared | Already shared; a place is the same place whoever names it. |
| Contributor | shared, as today | Already shared by slug. Homonyms are a known risk, see D8. |
| Category | owned by the source | Each source's categories are its own section tree: "Cultura" exists under three different parents, "Musica" is both a news section and an event category. Merging them would mix hierarchies that do not correspond. |
| Content | owned by the source | The same story from two publishers is two articles. Syndication is out of scope. |

A source-owned record is found by its origin only, as today. Two sources with a category of the same name keep
two categories; that is the intended result, not a duplicate.

### D2. A tag's identity is its slug in a locale; the type is an attribute

One live tag per `(locale, slug)`. The type does not take part in the identity:

- it cannot be enforced by the database, since `type` is on `cms_tags` and `slug` on `cms_tags_translations`, and
  a nullable column in a unique key does not make rows unique on MySQL, MariaDB, PostgreSQL or SQLite;
- the duplicates found carry no type at all, so a per-type identity would not have caught them.

The type is one value for every locale: it stays on `cms_tags`, is never translated, and is stored as a slug
(`Tag::normalizeType()`, already merged). This replaces the "name within its type" identity that
`Modules/CMS/docs/rag/MODULE.md` describes today; that page changes with the implementation.

When a lookup by slug finds a tag whose type differs from the one asked for:

- a tag with no type takes the type asked for;
- a tag with a type keeps it, and the caller learns about the conflict: `HasTags` throws, as it already does for a
  `Tag` instance of the wrong type; the import keeps the tag, attaches it and logs the conflict on the run.

### D3. A shared record is matched by origin first, then by its natural key

For tags, the order is the one locations already follow:

1. the origin of this source (`ImportReferenceResolver`), so a repeated import updates what it created;
2. the natural key, here the slug in the import locale, including soft-deleted tags, which are revived as the
   upserter already does for an origin match;
3. otherwise a new tag.

A match at step 2 registers the new origin on the existing tag: `core_record_origins` already holds several
origins per record (the ERP spec relies on the same property).

Contributors keep their current order, where the natural key wins over an origin whose name changed, because
Naxos reuses external ids across tenants. That divergence is deliberate and stays documented in the matcher.

### D4. A record shared by several sources is not renamed by any of them

An import writes the name and slug of a shared record in its locale only when it creates the record or the record
has no translation in that locale. A record that already carries an origin from another source keeps its text, so
two sources do not overwrite each other on every run ("Libri" against "libri"). A rename in a source therefore
reaches a shared record only through an editor. A record with this source as its only origin is updated as today.

### D5. Every way of creating a tag goes through the same lookup

The identity of D2 applies to all writers, not only to `TagUpserter`:

- `Tag::findFromString()` / `findOrCreateFromString()` look up the slug of the given name in the default locale,
  then apply the type rule of D2 instead of filtering by type;
- the CSV `TagImporter` deduplicates by slug instead of name within type;
- `ContentImporter`, which calls `findOrCreateFromString()`, follows without change.

### D6. Saves from the backoffice and the API are validated, not only constrained

`Tag::getRules()` gains a rule: the slug of each translation is unique among the live translations of that
locale, the tag's own translation excepted. An editor who types an existing tag gets a validation message instead
of a database exception. Slugs are not suffixed (`-2`) on collision: for a tag, a colliding slug means the same tag.

### D7. The database guarantees at most one live translation per `(locale, slug)`

The constraint is required, not optional: two imports can run at the same time (two sources scheduled together, or
a run started by hand), and only the database stops them creating the same tag at the same moment. The import that loses the race catches the unique violation and
retries the lookup of D3.

`cms_tags_translations` is soft-deleted, so `unique(locale, slug)` would also block a slug held by a removed
translation, and `unique(locale, slug, deleted_at)` does not constrain live rows at all, because `deleted_at` is
null on every one of them. The constraint needs a column that is the slug on live rows and null on deleted ones
(for example a stored generated column), with `unique(locale, live_slug)`. The plan chooses the form after
checking it on every supported driver (`2026-07-09-database-guidelines-design.md`), and puts it in
`MigrateUtils` if no helper of that kind exists yet.

The same weakness affects the existing `unique(name, deleted_at)` and `unique(slug, deleted_at)` on
`cms_locations` and `unique(user_id, name, deleted_at)` on `cms_contributors`: they do not constrain live rows.
The plan records it; fixing them is not part of this spec, since their matchers already prevent duplicates.

### D8. Contributors stay as they are, with the homonym risk stated

Contributors are matched by slug, and by name only for the names listed in `cms.import.contributor_dedup_names`
and the default contributor. Two different people with the same name and slug become one contributor. No case
is known; if one appears, the fix is a source-provided disambiguation, not a looser match.

### D9. Existing data is rebuilt, not merged

The project is pre-stable: the constraint of D7 goes into the create migration of `cms_tags_translations`, and the
correction of the wrong comment into the create migration of `cms_tags`. The installation is rebuilt with
`migrate:fresh` and a new import. No merge command is written for the current five pairs.

## Out of scope

- Deduplicating categories or contents across sources (D1).
- A mapping from one source's categories to another's, or to a shared taxonomy.
- Approximate matching (case-insensitive names, edit distance, synonyms): identity is the exact slug.
- Moving tags to Core (mentioned in `2026-08-29-sao-application-content-provider-design.md`). D2 to D7 must
  survive that move unchanged.

## Acceptance

- Two sources importing a tag with the same slug in the same locale produce one tag with two origins.
- A source importing a tag that another source created does not change its name or slug.
- The CSV tag import, `findOrCreateFromString()` and the backoffice reuse an existing tag by slug and never create
  a second one; the backoffice shows a validation error for a colliding slug.
- Two parallel creations of the same slug leave one tag and no failed import.
- Categories with the same name from two sources stay two categories.
- `Modules/CMS/docs/rag/IMPORTS.md` and `Modules/CMS/docs/rag/MODULE.md` describe the identity of each entity.
