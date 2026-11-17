# Extended entity search and list design

**Date:** 2026-11-17
**Modules:** `Modules/Core` (generic contracts, CRUD search and list), `Modules/CMS` (implements them for `Content` and its extenders), `Modules/Shop` (first consumer: `Product`)
**Status:** Design agreed. No implementation started beyond the groundwork listed in §2.
**Related:** `2026-09-17-cms-content-extension-seam-design.md` (the seam; this spec settles its open item "Permissions on a mixed upcast list"); `2026-09-17-shop-module-design.md` (E16 mandatory content, E18 storefront visibility, E21 browse snapshot); `2026-10-20-shop-product-admin-design.md` (plan #2, the backoffice write surface). The storefront read endpoints of Shop plan #3 build on this spec.

---

## 1. Purpose

An extended entity, such as the Shop `Product`, is a model in its own table that owns a CMS `Content` through the
content-extension seam. The seam hides extended contents from every default read, and the generic CRUD surfaces have
no way to reach them:

- `/search/shop/products` answers 400, because `Product` is not `Searchable`.
- `/search/cms/contents` filtered on `extended_type = shop.product` returns nothing: the engine may return the hits, but
  the reload goes through `Content`, whose `HidesExtendedContent` scope drops them.
- `/select/shop/products` works, through `Product`, but nothing lists contents and products together.

This spec decides how a client searches and lists an extended entity, and how it lists the host entity together with
the entities that extend it. It also moves out of `Content` (942 lines) the parts that are generic and reusable: the
host side of the seam, the shape of an indexed relation, and the plain text of an Editor field (XS13 to XS15). Code
that only `Content` needs stays in `Content`.

## 2. Groundwork already done (2026-11-17)

These shipped as pull requests before this design was agreed. They are prerequisites, not part of the work below.

- getlaraplate/laracore#43:
  - every search path applies the model's default search filters (`ProvidesDefaultSearchFilters`), so Basic,
    Orchestrated, Auto and direct `AdvancedSearchService` callers agree on what is eligible;
  - the basic path applies the requested locale;
  - `ElasticsearchTranslator` translates the typed sub-fields of an `object` field, so `extension.*` of `Content` is a
    valid, filterable mapping.
- getlaraplate/laracms#22: the assistant retrieval provider keeps extended contents out of the engine query.
- getlaraplate/larashop#4: a product shares the primary key of its content (XS7), and `content_id` is immutable.

## 3. Scope and non-goals

**In scope:**
- `/search` and `/select` of an extended entity (`shop/products`), searched through the index of its host entity.
- An opt-in parameter on `/search` and `/select` of a host entity (`cms/contents`) that adds the extended rows the user
  may read, each returned as its extended model.
- The permission model of both surfaces.
- The generic Core contracts that let any host and extender take part, with Core knowing neither CMS nor Shop.
- The extraction of the generic parts of `Content` into Core (XS13, XS14, XS15).

**Non-goals:**
- Making an extender `Searchable` with its own index (rejected, XS1).
- Storefront categories, per-category presets, facets and the browse price snapshot (Shop plan #3, E20, E21).
- Facets and sort on mounted attributes of the extender (the seam's CRUD presentation already leaves them out).
- `with_extended` on `/detail`. A client reading one record knows which entity it asks for: a content through
  `/detail/cms/contents`, which keeps hiding extended contents, a product through `/detail/shop/products`. With the
  shared key (XS7) the id a product carries in a mixed list opens it there.
- Moving code out of `Content` that only `Content` uses: its relations, the per-locale document of its translated fields,
  approvals, statistics, rules and path. No class or trait is created to hold `Content`-only code (XS15).
- Moving the extender side of the seam (`ExtendsContentTrait`, `ContentExtensionCascade`) out of CMS.

## 4. Locked decisions

| # | Decision | Rationale |
|---|----------|-----------|
| XS1 | **An extender is searched through the index of its host, never through an index of its own.** `Product` stays non-`Searchable`; `/search/shop/products` runs on the `contents` index, restricted to its alias. | The product's text is its content's text. A second index would embed and index the same text twice, and every editorial change on the content (translations, approvals, versions, preset changes) would need a hook to reindex the product too. The `contents` document already carries `extended_type` and the extender's `extension` section, refreshed on every extender save by `ExtendsContentTrait`. A mixed list over two indexes would also need cross-index score fusion, which is not comparable. |
| XS2 | **An extended entity is read with its own permissions and ACL.** `/select` and `/search` of `shop/products` require `shop_products.select` and apply the ACL of `Product`, not the ACL of `contents`. The content's data is merged into the product as the seam already does (`Product::toArray()`, `IMountsRelatedAttributes`). | The client asked for products; the product's permission model governs. The merge already makes the content's fields read as the product's own. |
| XS3 | **A host opens to its extenders through a dedicated boolean request parameter, on `/select` and `/search` alike.** It is named `with_extended`. `true` includes every extender alias the user may read; there is no list of aliases. | `withExtended()` cannot be a filter scope: `ScopeGuard` runs a scope on a nested query and copies only its where conditions, while `withExtended()` adds none and removes a global scope. A filter also only narrows, while this widens. The seam spec already foresaw "an ACL-gated flag". |
| XS4 | **The extenders of a host come from the host, and only those the user may read are included.** The host declares its extenders (CMS: the `ContentExtenderRegistry`); for each one the user needs the `select` permission on the extender's table. Aliases the user may not read are left out silently. | The client should not need to know which modules extend contents. A type the user cannot read must not show up, and asking for it is not an error: the parameter means "everything I may see". |
| XS5 | **In the mixed list, an extended row passes the ACL of the host and the ACL of its own entity.** A product row in `cms/contents?with_extended=true` must satisfy the `contents` ACL (the entity requested) and the `Product` ACL (its own rows). | The type permission alone is not enough: a user restricted to the products of their company would otherwise see every company's products through the content list. This settles the seam spec's open item. |
| XS6 | **In the mixed list, extended rows are upcast to their extended model.** Each row carries its type in the existing `extended_type` attribute: `null` for a plain host row, the alias (`shop.product`) for an extended row, which an upcast row exposes through the seam's merge (`Product::toArray()` overlays the content keys it does not own). Requested columns and relations apply to the rows whose model has them, and are skipped for the others. | One list of objects the client can use as returned. Refusing a request because one included type lacks a column would make the parameter brittle; dropping columns silently per row is the lesser surprise, and `extended_type` tells the client which shape it holds without a new field. |
| XS7 | **An extender whose content is mandatory shares the primary key of its content.** `shop_products.id` = `content_id`, not generated, set by the model on create, guarded in the database (PostgreSQL `CHECK`, MySQL/MariaDB triggers), and `content_id` is immutable. Shipped in getlaraplate/larashop#4. | One id names a product on the content routes and on the product routes, so the mixed list holds one id space and a product found in the content list is opened by the same id. Possible only because the content is mandatory (E16). |
| XS8 | **An extender whose content is optional keeps its own key, and the host routes speak host ids.** In the mixed list such a row carries both its own id and its host id; the client uses the host id on host routes. | The seam allows optional content (`contentIsMandatory()` false, nullable `content_id`), so XS7 cannot be the generic rule. No such extender exists yet; the rule is recorded so the first one does not invent another. |
| XS9 | **A product is online for the public when its content is approved and currently valid and `is_published_in_shop` is true** (E18, unchanged). It is enforced as a Shop ACL on `shop_products` for the guest role, written as column conditions: `valid_from`/`valid_to` (mounted from the content) and `is_published_in_shop`, never as the `valid` scope. | Both axes are in the `contents` index (`valid_from`, `valid_to`, `extension.is_published_in_shop`), so the engine applies them and pages are not cut short. A scope never reaches a search engine, which is why the CMS guest ACL on contents also uses columns. |
| XS10 | **A filter or ACL condition on an extender translates to the host index by a fixed rule.** A mounted attribute (content-owned) maps to the host field of the same name; an own column maps to `extension.<column>` when the extender indexes it; anything else is not filterable in search. A request filter that cannot be translated is refused with 400, as any non-indexed filter is today. An ACL condition that cannot be translated is applied when the hits are reloaded, never dropped. | One rule for request filters and ACL. Request filters keep the existing contract (search filters only on indexed fields). The ACL fails closed: a condition the engine cannot apply still holds at reload, at the cost of a possibly short page, which is the existing behaviour for database-only filters. |
| XS11 | **Two Core contracts, one per role.** The **host** contract (implemented by CMS `Content`) exposes its type column, its registered extenders (alias to class), a query that includes extended rows, and the batched upcast. The **extender** contract (implemented by CMS `ExtendsContentTrait`, so every extender inherits it) exposes its host model, its alias and its host key. **Hiding extended rows is part of the host contract, not a default search filter:** Core derives `<type column> IS NULL` from the host, and `Content` drops its hand-written `defaultSearchFilters()` entry. `ProvidesDefaultSearchFilters` stays for fixed filters unrelated to extensions. The generic host implementation moves into Core (XS13). | `CrudService` lives in Core and must recognise both roles without knowing CMS. With the exclusion owned by the host contract, Core knows exactly which condition the XS3 parameter replaces; a generic default filter gives no such handle, and joining it with the alias condition would select nothing. |
| XS12 | **The XS3 parameter applies only when the requested model implements the host contract.** On any other model, an extender route such as `shop/products` included, it is ignored without an error. | The parameter means "include everything I may see"; where nothing extends the model there is nothing to include, which is the same silent outcome as an alias the user may not read (XS4). |
| XS13 | **The host side of the seam moves into Core as a generic implementation of the host contract.** A Core trait gives a host: the hiding global scope on its type column (today `HidesExtendedContent`, hard-wired to `extended_type`), the `withExtended()` scope, the registry of its extenders keyed by alias (today `ContentExtenderRegistry`, keyed per host instead of global), the batched upcast (today `ContentExtensionResolver`, reading the host key and relation from the extender contract instead of `content_id` and `content`), and the extension part of the search document and mapping (today `Content::extensionSearchDocument()` and `composeExtensionMapping()`), with the bulk import including extended rows. `Content` uses the trait and declares only its type column. Shop registers `shop.product` in the Core registry for `Content`. The extender side (`ExtendsContentTrait`, `ContentExtensionCascade`: save bridge, lifecycle cascade) stays in CMS. | Every one of these pieces is generic: it depends on a type column, an alias registry and the extender contract, never on what a content is. They are the part of `Content` worth extracting; the extender side holds behaviour tied to the content's lifecycle and is out of scope. |
| XS14 | **A Core helper declares an indexed relation once and yields both its search-document entry and its mapping field.** The declaration names the relation and its indexed properties; the common shape `id`, `name`, `slug`, `path` (filterable `id`, `slug`, `path`) is the default, and extra properties are added per relation (the address fields of `locations`). `Content` declares `contributors`, `categories`, `tags` and `locations` through it. | Today each of the four relations is written twice in `Content`, once as `only([...])` in `toSearchableArray()` and once as a `FieldDefinition` in `getSearchMapping()`, three of them with the same shape. One declaration removes the duplication and guarantees the document and the mapping agree. The indexed-relation concept already exists in Core (`FieldDefinition` `relation` option). |
| XS15 | **The plain text of an Editor field is a Core service next to the Editor field type, and every reader of Editor content uses it.** It turns an Editor.js document (`blocks`) into the plain text the search index, the reranker and the reading statistics need. Today it lives in the CMS cast `ReadingStatistics::plainText()` and in `Content::searchableComponentValue()`, while `CmsContentEvidenceProjector` walks `blocks`, `children`, `data` and `items` with its own code. `Content` search, `ReadingStatistics` and the evidence projector converge on the Core service where their output is the same; a reader whose output must differ keeps its own step on top of it. Only shared code moves: `Content`-only code stays (§3). | `FieldType::Editor` is a Core dynamic field type that any dynamic-content model can declare (`HasDynamicContents` validates its `blocks`), so its plain text is not CMS knowledge. Three CMS readers already convert it, in two implementations. |
| XS16 | **Extenders of the same host may share an `extension` field only with the same definition; a conflict fails loudly.** All extenders of a host write into one `extension` object of one index mapping. When the host composes the mapping, a field declared by two extenders with different definitions (type, filterable) raises an error naming both aliases and the field, instead of keeping the first registered definition as `composeExtensionMapping()` does today. Fields keep short paths (`extension.kind`), with no prefix per alias. The host module's test suite composes the mapping in the full application with every registered extender, so a conflict fails there before it ships. | An index holds one type per field: with the first definition silently kept, the second extender's values would be indexed under the wrong type or refused by the engine. Failing loudly keeps paths short and lets two extenders share a field when they mean the same thing; a prefix per alias would avoid the conflict but lengthen every filter path and the XS10 translation. |
| XS17 | **An extender's own columns never share a name with an attribute of its host, except the native keys.** No own column of an extender may be named like a column, a translated field or a dynamic component of the host entity it extends; `id`, the timestamps and the soft-delete columns are the only shared names. Enforced twice: a test in the extender's module checks its own columns against the host's columns and translated fields, and saving a preset field of the extender's entity refuses a name that is an own column of the extender. | The seam's merge routes an own column to the extender and every other key to the host, so a shared name makes the host's field unreachable through the extender, silently. XS10 relies on the same split to translate filters. Dynamic components are configured at runtime, so a check at write time is needed besides the test: a component `featured` added to the product preset would otherwise disappear behind `Product::$featured`. |

## 5. Components

### Core (generic, knows neither CMS nor Shop)

- The **host** contract (XS11) and its generic implementation (XS13), a trait used by CMS `Content`:
  - its type column (`extended_type`), declared by the host;
  - the registry of its extenders, alias to extender class, keyed per host;
  - the hiding global scope and a query that includes extended rows (`withExtended()`);
  - the batched upcast of a page of host rows to their extenders;
  - the `extension` part of the search document and mapping, and extended rows included in the bulk import.
- The **extender** contract (XS11), implemented by CMS `ExtendsContentTrait`:
  - its host model and alias;
  - its host key (`content_id`).
- Every search and list of a host excludes extended rows by default, from the host contract: `<type column> IS NULL`
  at the engine (where `DefaultSearchFilters::merge()` puts the model's default filters today) and through the host's
  hiding scope in the database.
- `CrudService::search()` and `list()`: when the model is an extender, resolve the host and run the flows of §6. When
  the model is a host and the request carries the XS3 parameter, replace the exclusion with the type condition of the
  mixed flows (XS12: on any other model the parameter is ignored).
- The filter and ACL translation of XS10.
- The indexed-relation helper of XS14.
- The Editor plain-text service of XS15, next to `FieldType::Editor`.
- The XS16 check when the host composes its extension mapping.
- The XS17 check on saving a preset field: the extender contract exposes its own columns and its entity, and a field
  named like one of them is refused on that entity's presets.

### CMS

- `Content` uses the Core host trait and declares its type column. It drops `extended_type => null` from
  `defaultSearchFilters()` (XS11), `extensionSearchDocument()`, `composeExtensionMapping()`, `withExtended()` and the
  registration of `HidesExtendedContent` (XS13). `HidesExtendedContent`, `ContentExtenderRegistry` and
  `ContentExtensionResolver` are replaced by their Core counterparts.
- `ExtendsContentTrait` implements the extender contract, so every extender takes part with no code of its own; it reads
  the registry and the upcast from Core.
- `Content` declares `contributors`, `categories`, `tags` and `locations` with the XS14 helper.
- `Content::searchableComponentValue()`, `ReadingStatistics::plainText()` and `CmsContentEvidenceProjector` use the XS15
  service.
- The `extension` section of the search document: fields of different extenders share the `extension` object; the
  composed mapping refuses a field declared with two definitions (XS16), and a CMS test composes it with every
  registered extender.

### Shop

- `Product::searchableExtension()` and `searchableExtensionMapping()` gain the fields its ACL needs in the engine:
  `company_id` (today not indexed, so a company restriction would only apply at reload) next to
  `is_published_in_shop`.
- The guest ACL of XS9, seeded like the CMS guest ACL on contents.
- `ShopServiceProvider` registers `shop.product` in the Core registry for `Content` instead of `ContentExtenderRegistry`.
- A test that the own columns of `Product` do not collide with the columns and translated fields of `Content` (XS17).

## 6. Flows

### `/search/shop/products`
1. Permission `shop_products.select`; resolve the ACL of `Product`.
2. Search the host (`Content`) index with `extended_type = shop.product` in place of the host default.
3. Translate the request filters and the `Product` ACL (XS10) into engine filters.
4. Reload the hits as products: `Product::whereKey($ids)` under the `Product` ACL, untranslated ACL conditions included.
   With the shared key (XS7) the hit ids are the product ids; an XS8 extender reloads by its host key instead.
5. Keep the engine order; serialize with the merged content.

### `/select/shop/products`
Unchanged: it already reads `Product` with its permission, its ACL and the merged content.

### `/search/cms/contents?with_extended=true`
1. Permission `contents.select`; resolve the `contents` ACL.
2. Allowed aliases: the host's extenders whose table the user may `select` (XS4).
3. Engine type condition: `extended_type` is null, or one of the allowed aliases; each alias branch also carries the
   translated ACL of its extender (XS10). The `contents` ACL applies to every row.
4. Reload: `Content::withExtended()->whereKey($ids)` under the `contents` ACL, then each extended row under its own
   entity's ACL (XS5), then the upcast (XS6).

### `/select/cms/contents?with_extended=true`
The same in SQL: `extended_type IS NULL`, or `extended_type = <alias>` and an extender row exists that satisfies the
extender's ACL, for each allowed alias; the `contents` ACL on every row; then the upcast.

## 7. Testing

- Core, with stub host and extender models (no CMS or Shop dependency):
  - an extender is searched through its host index and reloaded as itself;
  - a host excludes extended rows from the host contract alone, with no default search filter declared for it;
  - the XS3 parameter replaces that exclusion, not joins it, and is ignored on a model that is not a host (XS12);
  - a request filter on an own column that is not indexed is refused; on an indexed one it reaches the engine;
  - an ACL condition that cannot be translated still holds at reload;
  - the mixed flag includes only the extenders the user may read, and each extended row passes its own ACL;
  - columns and relations apply where the model has them, and every row carries its type.
- Core, the host trait with a stub host: hiding scope, `withExtended()`, registry per host, upcast through the extender
  contract's host key and relation, extension document and mapping, extended rows in the bulk import.
- Core, the XS14 helper: one declaration yields a document entry and a mapping field that agree; extra properties add to
  the default shape.
- Core, the XS15 service: an Editor.js document yields its plain text; a plain string passes through.
- Core, XS16: two stub extenders declaring a field with the same definition compose; with different definitions the
  composition fails naming both aliases.
- Core, XS17: a preset field of an extender's entity named like an own column of the extender is refused; any other name
  is accepted, and presets of other entities are unaffected.
- CMS: `Content` and `ExtendsContentTrait` satisfy the contracts; the generic content search still excludes extended
  contents after `defaultSearchFilters()` loses its `extended_type` entry; the search document and mapping of `Content`
  are unchanged by the XS14 helper (same keys, same mapping); reading statistics and evidence excerpts are unchanged
  by the XS15 service.
- Shop: `/search` and `/select` of products for a guest show only online products (XS9); a user restricted to a company
  sees only that company's products, in the product routes and in the mixed list.
- Contract: the same request through Basic, Orchestrated and Auto returns the same eligible rows, extending
  `SearchPathEligibilityContractTest`.

## 8. Open questions

- **Approval in XS9.** Whether "approved" needs a condition of its own on public reads, or the live record of a content
  under approval is already the approved state. To verify against `HasApprovals` before seeding the guest ACL.
