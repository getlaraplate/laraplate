# Search variants, perspective analysis and content findability: design

**Status:** Draft (2026-11-27), not approved, no plan yet.
**Date:** 2026-11-27
**Spans:** `Modules/Core` (search), `Modules/CMS` (tags, contents, suggestions), `Modules/AI` (web search and model drivers, perspective analysis). The Vue half lives at the stack root: `docs/superpowers/specs/2026-11-27-content-search-improvement-ui-design.md`.
**Related:** `2026-11-02-ai-retrieval-improvements-design.md` (judge, evaluation-first rule), `2026-07-17-application-content-retrieval-design.md` (provider contract), `2026-10-30-cms-import-cross-source-identity-design.md` (tag identity D2, D5), `2026-09-17-cms-content-extension-seam-design.md` (extended contents), `Modules/AI/docs/rag/query-analytics-privacy-review.md` (precedent for a query log).

## 1. Purpose

Three needs, one shared mechanism.

1. **Analysis from several points of view.** In an office test, a project manager asked the in-app assistant about the war in Iran. They wanted the answer split by perspective (political, economic, social) and judged that too few articles were retrieved. The same request made to an external agent produced five or six related queries, with states, politicians and events the person had not named, searched each, then merged and compared the results.
2. **Searches that find more.** A person who types "bici elettrica" should also reach contents written as "e-bike". The vocabulary gap is measured from real searches, closed by query variants at runtime, and closed at the root by keywords added to the contents.
3. **An editor who knows what to fix.** The CMS editor gets the searches that do not find, the contents that are hard to find, and suggestions they can accept in one click.

The shared mechanism is **one query run as several variants, fused by rank**. Where the variants come from differs: a table built offline for normal search, a model at request time for analysis.

## 2. What exists today

Facts read from the code on 2026-11-27.

| Area | Today |
|---|---|
| Search route | `/search/{module}/{entity?}` → `CrudService::search()`. `Basic` runs `searchWithScout()`, `Orchestrated` runs `searchWithAdvanced()`, `Auto` (default) runs orchestrated when the engine supports it. |
| Orchestrated search | `AdvancedSearchService`: intent parser, planner, keyword/vector/hybrid ensemble, rerank, retries, date filter. The intent slot `query.expanded` **replaces** the user's query as one string. |
| Matching | `TextMatchOptionsResolver` derives `minimum_should_match` from the token count: 2 tokens are AND 100 %, 6 tokens 65 %. A longer expanded string makes the user's own words optional. |
| Assistant retrieval | `ApplicationContentToolProvider` → `CmsApplicationContentRetrievalProvider` → `AdvancedSearchService`, bypassing `CrudService`. Default `application-content.max_results` 8, cap 50, timeout 2 s. |
| Default search filters | `ProvidesDefaultSearchFilters` (CMS `Content`: `extended_type IS NULL`) is applied only in `searchWithScout()`. The orchestrated path and the assistant retrieval skip it, so extended contents (shop products) come back from the engine and are dropped at rehydration. Tracked as a separate fix, prerequisite of this spec (section 4, P0). |
| Locale | Keyword search queries only the fields of the requested locales (`ElasticsearchLocaleTextFields`). Vectors are language-agnostic; results are restricted to documents that have the requested locale. |
| Tags | Attached to the record (`taggables` morph pivot) of `Content`, `Category`, `Contributor`, `Location`. Name and slug translated (`TagTranslation`). `type` optional, a slug, untranslated. Identity is `(locale, slug)`; a tag with no type adopts the first type given (D2). |
| Tags in the index | A `nested` array with one `name`. The free-text query skips nested fields and the embedding text leaves tags out. **A tag does not help a text search today.** |
| Tag type UI | Filament `TagForm` offers the types in use and the typed value. No icons. |
| Search logging | None for application search. The documentation RAG has its own query log, reviewed in `query-analytics-privacy-review.md`. |
| Judge | `RelevanceJudgeStage` runs only on the documentation path, off by default. A cross-encoder (`CrossEncoderService`) exists; measured as a reranker it gave no gain. |

## 3. Decisions

1. **Every search path applies the same eligibility constraints.** Default search filters, ACL, validity and locale are identical for Basic, Orchestrated and Auto, and for every internal caller of `AdvancedSearchService`. Paths may differ in recall and ranking, never in what may be returned. A contract test compares the paths on the same data and filters.
2. **Variants, not expansion.** A variant is a separate query with its own matching rules. The original query always runs and weighs most. Variants add candidates; they never replace the original.
3. **Protections against steering.** A variant can bring the results somewhere the person did not ask for, whether it comes from the web or from a model. Every variant execution applies all of:
   - the original query always runs and has the highest weight;
   - **anchoring:** a hit found only by a variant enters only if it scores above a threshold against the original query, with the embedder or the cross-encoder. With neither available, variants do not run and the search is the original alone;
   - a **cap per variant:** no variant fills more than a set share of a page;
   - **transparency:** `meta` names the variants run and, per hit, the queries that found it;
   - an **evaluation gate:** each feature ships off and is turned on only after an on/off comparison with the evaluation commands shows a gain (the rule of `2026-11-02`, decision 3).
4. **Analysis is server-side.** The in-app assistant does nothing on its own initiative. The perspective analysis is a backend pipeline started by an explicit choice of the person (an analysis mode of the chat request). Automatic routing to it is a later phase, turned on only after evaluation on data collected from the explicit mode. External agents reaching Laraplate through MCP with a valid user plan their own queries; this spec does not change them.
5. **Perspectives are optional and per content type.** No seeded list: the CMS is headless and its use is unknown. An installation may configure perspectives per `Entity`. When perspectives are configured for the types in scope, the model picks the relevant ones and may add at most one, marked as proposed. When none are configured, the model proposes up to four, marked as proposed. A coverage summary is always returned.
6. **Search keywords are tags.** A suggested keyword is a tag with `type = search`, attached to the record like any tag, with a name per locale. A term valid in every language is one tag with the same name in each locale. Making tag names searchable is part of this spec, so ordinary tags start helping search too.
7. **The API does not hide tags by type.** `/app` and `/api/v1` return every tag with its `type`. The documentation states that `search` tags are search aids not meant to be displayed; a site that does not want them filters by type.
8. **No stored default type.** `null` keeps its meaning (unclassified, adopts the first type given, D2). The display falls back to a generic tag icon.
9. **Standard tag types are an extensible list.** The CMS registers `search`, `people`, `locations`, `organizations`, `topics`, `events`. Any module may register more at boot, each with a label and icons, in a code registry like `ContentExtenderRegistry`. The type field stays free text.
10. **No human gate on queries.** Which queries are sent to an external search engine is decided by automatic gates (section 5, P5). People act only where they already work: an editor accepting a suggestion on a content.
11. **Every view of the editor page leads to an action on a content or shows the effect of one.** No decorative counters, no graph in this version.
12. **Core stays AI-agnostic.** Core defines the contracts it needs (web search, term proposal) with null implementations; AI binds the real ones. CMS consumes Core contracts only and never depends on AI.

## 4. Approaches

**Overall build order** (the editor half). Three were considered:

- **A. Start from searches:** registry first, then job, variants, suggestions, page. Everything comes from real searches, but the page stays empty until data accumulates.
- **B. Start from contents:** no registry; a suggest button and a content scan. Immediate, but it describes contents the way the web does, not the way this site's users search, and it loses the "searches that do not find" view and runtime variants.
- **C. Registry now, the rest in parallel. Chosen.** The registry ships first, small and without UI, so data starts accumulating. Meanwhile the tag suggest button and the isolated contents list, which need no registry. Then the job, variants and the rest of the page when the data is enough.

**Where variants are executed.** Three were considered:

- extending `RankFusion` with one strategy per variant (its weight renormalization knows only keyword, vector, hybrid);
- a wrapper service that calls `AdvancedSearchService` once per variant (duplicates date filtering, retries, timings and cost accounting per call);
- **a second fusion level inside `AdvancedSearchService`. Chosen.** The existing ensemble runs once per variant unchanged; an outer reciprocal rank fusion merges the lists. Date filter, retry and cost are decided once.

**Where search keywords are stored.** Three were considered: a `search_keywords` field per translation (simple, but terms neutral across languages repeat and tags stay useless to search); tags only where a tag already exists (does nothing, tags are not searchable today); **tags of type `search` plus searchable tag names. Chosen** (decision 6).

**Perspective analysis.** An agentic path where the assistant's model calls the content tool as often as it likes was set aside: the assistant acts only as the backend directs (decision 4).

## 5. Parts

Phases in build order. Each part is off by default behind a seeded setting unless stated otherwise.

### P0. Prerequisite: default filters on every path

`AdvancedSearchService` applies `ProvidesDefaultSearchFilters` itself, merged with the caller's filters the way `searchWithScout()` merges them, without applying them twice on the Basic path. This is tracked as its own task; this spec depends on it and adds the cross-path contract test of decision 1.

### P1. Search registry (Core)

Purpose: know what people search, in which language, and how much they find.

- **What is recorded:** one row per `(normalized query, locale, model, day)`: number of searches, number of searches that returned fewer than `low_results_threshold` hits, sum of hit counts. No user, no IP, no session.
- **Normalization:** lowercase, trimmed, spaces collapsed, the request words of the locale removed (`SearchQueryAnalyzer::withoutRequestWords()`).
- **What is never recorded:** a query holding a protected token as classified by `SearchQueryAnalyzer` (email, UUID, structured identifier, number).
- **Who records:** the `/search` route only, after the response, off the request path. Queries written by the assistant or by the analysis pipeline are not recorded.
- **Retention:** daily rows older than `retention_days` are deleted by a scheduled job.
- **Settings:** `search.registry.enabled` (false), `search.registry.retention_days`, `search.registry.low_results_threshold`.
- **Privacy review:** like the documentation RAG log, the field set is recorded in a privacy review document approved by the owner before the registry is turned on anywhere.

### P2. Multi-variant execution (Core)

- The intent carries `variants`: a list of `{query, source}`, `source` being `table`, `model` or `perspective:<key>`. `query.expanded` stays for compatibility.
- `CoreSearchStrategyResolver` reads variants from the variant table of P5 (fast, no model). `AiSearchStrategyResolver` may add model variants in `deep`.
- For each variant the existing ensemble runs with the variant's own `TextMatchOptions`. Variants run keyword only; the vector runs on the original query only.
- An outer reciprocal rank fusion merges the original list and the variant lists, original weight highest, then anchoring and the per-variant cap of decision 3.
- The date interval is chosen once on the original query and applied to every variant.
- The quality retry runs only after fusion, when the fused result is still poor.
- Pagination: each list is fetched with a window of `page × perPage`, fused, then sliced, as the ensemble already does.
- `meta.search.variants` lists `{query, source, hits}`; each hit carries `found_by`.
- `SearchCostGuard`: a request counts once, whatever the number of variants.
- **Settings:** `search.variants.enabled` (false), the original and variant weights, the per-variant cap, the anchoring threshold.

### P3. Searchable tags and tag types (CMS)

**Searchable tag names.**
- `Content` indexes a new locale-keyed object of tag names (for example `tag_names.it`, `tag_names.en`) with the locale analyzers, next to the existing nested `tags` used for filters and facets.
- Each locale holds only the tags with an explicit translation in that locale, no fallback, so a term written in Italian never answers an English search.
- The keyword query reaches the new field through `ElasticsearchLocaleTextFields` like `title`, with a lower boost than the title.
- Tag names stay out of the embedding text (`embedHeader()` unchanged).
- The other engines (Typesense, database) get the equivalent where their translator supports locale objects; where one does not, the builder records the gap.
- An index rebuild is required; the mapping comparator flags it.

**Tag types.**
- A `TagTypeRegistry` in the CMS: code, populated at boot, keyed by the type slug. Each entry: slug, translation key of the label, Heroicon name, Lucide icon name.
- The CMS registers:

| Type | Heroicon | Lucide |
|---|---|---|
| `search` | `magnifying-glass` | `Search` |
| `people` | `users` | `Users` |
| `locations` | `map-pin` | `MapPin` |
| `organizations` | `building-office` | `Building2` |
| `topics` | `hashtag` | `Hash` |
| `events` | `calendar` | `Calendar` |

- A type not in the registry shows the first letter of the type in upper case. A tag with no type shows the generic tag icon (`tag`, `Tag`).
- The registry is exposed read-only to the clients so Filament and the Vue apps share one list.
- Filament `TagForm` type select: with no search, the types in use and every standard type; with a search, the types in use and the standard types that contain the typed text, then the typed value as a new type; no duplicates.
- Filament `TagsTable`: the type column shows the icon before the type; tag badges elsewhere in Filament show the icon before the name.
- `people` tags are persons mentioned, not `Contributor`; `locations` tags are places mentioned, not `Location` records. The CMS documentation says so.

### P4. Tag suggestions on demand and isolated contents (CMS, Core contracts, AI drivers)

**Suggest tags for one content and one locale.**
- A `/app` route returns suggestions for a content in a locale. Nothing is stored until the editor accepts.
- Sources: two or three web search providers queried with the **title only** and the locale; a model reading the title and a bounded excerpt of the text, which also proposes a type from the registry.
- Existing tags first, matched by slug in that locale; then new terms, marked new. Each suggestion names its sources.
- Accepting attaches an existing tag or creates one through the shared tag lookup (D5) with the name in **that** locale. The builder makes sure creation in a non-default locale respects the `(locale, slug)` identity.
- Core contract `IWebSearchProvider` with a null implementation; AI provides drivers for official search APIs only, configured as settings with keys stored like the other AI provider keys. Which providers is left to the plan (section 9).
- **Settings:** `cms.tag_suggestions.enabled` (false), providers, rate limit per person.

**Isolated contents.**
- Contents, extended ones excluded, with no tags or no categories, and contents whose tags have no translation in the selected locale.
- Served by the existing `/select` route if its filters can express it; otherwise by a dedicated read route.

### P5. Variant job, suggestions per content, unanswered searches (Core, CMS)

**Candidate queries** (nightly, Core). From the registry: queries searched at least `min_searches` times on at least `min_days` distinct days, with many low-result searches, not matching the denylist, with no protected token. Only these may leave the system. Master switch `search.variants.external.enabled` (false).

**Variant table** (Core). For each candidate:
1. the web search provider returns titles and snippets in the query locale;
2. terms are extracted, optionally with the model through a Core contract with a null default;
3. a term survives only if it occurs in the corpus of that model and locale and passes anchoring against the original query;
4. survivors are stored as active variants `{query, locale, model, variant, source, evidence}` and used by P2. No human review.

**Suggestions per content** (CMS). When a variant finds a content that is pertinent to the original query but the original words do not find it, the CMS stores a suggestion: add the **user's words** to that content in that locale, as a `search` tag or as an existing tag with the same slug. Each suggestion keeps the query, the locale, the evidence and a status: pending, accepted, dismissed.

**Unanswered searches** (CMS read route). Low-result queries of the last period per locale with one of three outcomes:
- *contents exist but are not found:* the contents and their pending suggestions;
- *no content exists:* a content gap, an idea for a new content;
- *a pertinent content lacks this locale:* a missing translation.

**Effect of a change.** For a query whose suggestion was accepted, the result counts per day before and after the acceptance, read from the registry.

### P6. Perspective analysis (AI, Core contract, CMS)

- **Start:** the chat request carries an explicit analysis mode. It counts as one `deep` request for `SearchCostGuard`.
- **Perspectives:** an optional Core contract on application content providers, next to `ProvidesPermissionModel`, by which a provider declares the perspectives of its source. The CMS reads them from a seeded setting keyed by `Entity`, empty by default. The AI pipeline knows no perspective by name.
- **Plan:** the model picks or proposes perspectives (decision 5) and writes two or three queries per perspective, naming the people, places, organizations and events it relates to the request. When perspectives are configured, the first selection may be done with embeddings of their descriptions; the model is the fallback.
- **Retrieve:** per perspective, the queries run as variants through P2 against the provider, with a cap per perspective instead of the global cap of 50. Extended contents are excluded (P0).
- **Coverage:** a perspective with fewer than `min_per_perspective` articles gets one more round, with queries built from the entities of the articles already found. The fresh facts are in the corpus, not in the model.
- **Relevance:** before the answer, the candidates of each perspective may be filtered by the cross-encoder. Off by default and turned on only after an on/off comparison, since the cross-encoder gave no gain as a reranker.
- **Answer:** a summary per perspective, citing only retrieved articles, then a comparison across perspectives, then the coverage summary (articles per perspective, proposed perspectives marked).
- **Settings:** `features.analysis.enabled` (false), queries per perspective, cap per perspective, `min_per_perspective`, maximum rounds.

## 6. Out of scope

- A graph on the editor page.
- Standard tag types for e-commerce; the Shop module registers its own if it wants them.
- Automatic routing to the analysis mode (decision 4, later phase).
- The perspective analysis as an MCP tool; it is added when the MCP server exists.
- Hiding `search` tags from the API.
- TypeSafe Jev or another third-party decision model; reconsidered after it leaves early access.
- The fix of P0 itself, tracked separately.

## 7. Testing and evaluation

- Unit and feature tests per part in the module that owns it.
- The cross-path contract test of decision 1.
- P2: tests that the original always runs, that anchoring drops an unanchored variant hit, that the cap holds, that no variant runs without a scorer, that the date filter and the cost are counted once.
- P3: a tag translated only in Italian matches an Italian search and not an English one.
- Retrieval behaviour (P2, P3, P6 relevance) is compared on and off with the existing evaluation commands; the application content evaluation and the assistant end-to-end evaluation gain cases for analysis requests with expected coverage per perspective. The numbers go in the plan's delivery status.

## 8. Documentation

- Core: `SEARCH_RETRIEVAL_PIPELINE.md` (variants, fusion, eligibility rule, registry), Core README settings.
- CMS: tag types and searchable tag names, `search` tags in the API, suggestions, the difference between `people`/`locations` tags and `Contributor`/`Location`.
- AI: web search drivers, perspective analysis, settings.
- The privacy review record of P1.

## 9. Open points

1. Which web search providers to support first, with their cost and terms. Official APIs only; the owner decides at plan time.
2. The privacy review of P1 needs the owner's approval before the registry is turned on.

## 10. Left to the builder

Table and column names, setting keys beyond the switches named here, default weights, thresholds and caps (as settings), the route names and payloads of P4 and P5, how `TagForm` merges the option lists, how Filament renders a letter in place of an icon, how the analysis mode is carried by the chat request.
