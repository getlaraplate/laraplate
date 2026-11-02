# AI retrieval improvements: design

**Status:** Proposed (2026-11-02), awaiting review. Plan: `docs/superpowers/plans/2026-11-02-ai-retrieval-improvements.md`.
**Related:** `2026-10-30-vector-search-owned-by-ai-design.md` (the profile these checks build on), `2026-10-05-embedding-model-switch-design.md` (probe, verifier, repair).

## 1. Purpose

A review of the retrieval pipeline against the practices of comparable production RAG setups found a handful of ideas. Some are missing, some are half present. This spec keeps the ones worth building. Each is independent of the others, and none assumes a vendor, a model or a hosting choice.

## 2. What exists today

Facts read from the code on 2026-11-02. "Not confirmed" means the code was not read far enough to say.

| Area | Today |
|---|---|
| Dates in the query | `SimpleQueryIntentParser` and `FallbackSearchPlanner` return `date_range => null`; no consumer reads it. |
| Relevance judge | None. A cross-encoder reranker exists (`CrossEncoderService`); measured on the current dataset it gave no gain. |
| Text analysis | Per-locale analyzers (`locale_properties`). Exact match is a `phrase` clause over the same stemmed fields; there is no unstemmed sub-field. |
| Keyword query cleaning | `SearchQueryAnalyzer` and `SimpleQueryIntentParser` drop language stopwords. Request words ("find me", "articles about") are kept. |
| kNN | Filters sit inside the kNN clause. A minimum similarity exists only for documentation retrieval (`MinimumSimilarity`); application content has none. |
| Chunk context | Documentation chunks carry a heading breadcrumb. For application content `EmbeddingService::chunksFor` flattens newlines and tabs to spaces and splits the concatenated `$embed` text: no per-chunk context header, and the structure-aware splitter sees no line structure. |
| Media analysis | `AnalyzeMediaJob` reuses an analysis by exact file `content_hash`; no near-duplicate match. |
| Embedding checks | `ai:embeddings:probe` measures vector length only; nothing compares the same text across servers or precisions. |
| Profiles | `EmbeddingModelProfile` has no language declaration and no similarity floor. |
| Analytics | `RagQueryRecorder` writes scalar documents; tokens per role are not confirmed. |
| Data egress | Servers are modelled (`AiServers`); the settings do not say whether a role sends text outside the installation. |

## 3. Decisions

1. **Agnostic.** No vendor, model or hosting assumption in code, config defaults or docs. Behaviour comes from profile attributes and settings.
2. **Independent and safe by default.** Anything that costs a model call or changes stored data (judge, near-duplicate images) is off by default. Index-changing items apply on the next rebuild and are flagged by the mapping comparator or the content hash.
3. **Dates.** Rules first: language-aware patterns for a year, a month and year, and relative periods. The LLM intent parser is an optional second source. The result is an interval applied as a filter on a date field the model declares; models without one are unaffected. "Recent" uses widening windows (configurable list, default 90 and 365 days, then unbounded) and stops at the first window that yields enough sources. Dates passed explicitly in the request win over dates read from the text.
4. **Judge.** An optional stage between retrieval and answer. It reads title, date and a bounded excerpt of each candidate and returns the relevant ones plus an optional next query; rounds and minimum accepted sources are settings; on failure the unjudged candidates are used; each round is logged. Off by default, and turned on only after an on/off comparison with the existing evaluation commands shows a gain. The settings text says that a model below a mid-size instruction-tuned one usually makes it worse.
5. **Drift guard.** The probe can save a reference: a fixed set of texts embedded by a profile, vectors stored per profile. A later probe of the same profile (another server, runtime or precision) compares by cosine and fails below a configurable floor. Length checks cannot catch a precision or runtime mix; this can.
6. **Declared languages.** Every role profile may declare `languages` (locale codes, or `*`). The installation's languages are its enabled locales. A mismatch is a warning in the settings page and the probe, never an error; undeclared means unknown and warns nothing. The probe may embed one sentence in two languages as a cross-language sanity check.
7. **Unstemmed sub-field.** Text fields get a sub-field analysed without stemming, queried together with the stemmed one so a proper name is not matched through its stem. Comparison with the evaluation commands is part of the task.
8. **Request words.** A per-locale list of request words is removed from the keyword branch only (the vector branch keeps the full question), and never when it would leave the query empty.
9. **Similarity floor.** `minSimilarity` is an optional attribute of the embedding profile, not a global setting, because score scales change with the model and a stale floor after a switch is a silent bug. `VectorProfile` carries it; engines apply it in the kNN clause; the tuning command recommends a value.
10. **Chunk context.** A model may declare a one-line context header (type, title, date, sections, authors, tags) prepended to every chunk of application content, and line structure is kept until the splitter has run. The embedded text changes, so content hashes change; the next save, a reindex (its bulk path skips by hash) and the model switch re-embed, while `ai:embeddings:repair` does not, since it never compares hashes.
11. **Near-duplicate images.** Opt-in perceptual fingerprint stored with the media analysis; an image within a configurable distance reuses the description of the earlier one.
12. **Observability.** Query analytics gain tokens in and out per role and per question. The settings show, per role, whether text leaves the installation, derived from the profile's server (a configured self-hosted server or not).

## 4. Out of scope

Named deployment presets, warm-up of servers that scale to zero (timeouts stay as they are), answer-model quality thresholds beyond the settings hint, cost estimation from price lists.

## 5. Testing

Unit and feature tests per item in the module that owns it. Retrieval behaviour changes (dates, judge, unstemmed field, request words, similarity floor, chunk context) are compared before and after with the evaluation commands; the numbers go in the plan's delivery status.

## 6. Documentation

AI `README.md`, `docs/SEARCH_AND_TRANSLATION.md`, `docs/rag/MODULE.md`; Core `docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` and `docs/rag/SEARCH_MATCHING_DEVELOPER.md`; env or setting changes in the affected module README.

## 7. Open points

1. How a model declares its date field (property on the model, like `$embed`, or a method). Proposed: a property.
2. Where the judge sits for application-content search, which today has no answer stage; proposed first home is the documentation answer pipeline, application content follows if a consumer appears.
3. Whether the drift reference lives on disk or in the database. Proposed: a JSON file under `storage/app`, since vectors are large and per installation.
