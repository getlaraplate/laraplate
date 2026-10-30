# Measured Retrieval Tuning (L1) — Design


**Status:** Implemented 2026-10-01 (mechanism shipped with an L0-equivalent profile; the switch is the `search.adaptive_tuning` setting and the blend the `search.reranker.weight` setting; see the plan's delivery status for divergences). The measured profile is still to be produced manually with `test:ai:tune-retrieval`.
**Date:** 2026-09-15
**Author:** swolley + Claude
**Related:**
- `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`
- `Modules/AI/docs/rag/MODULE.md` (evaluation commands and baselines)
- The two R3 designs, `2026-09-09-r3-retrieval-quality-baseline-design.md` and
  `2026-09-10-r3-phase2-per-strategy-breakdown-design.md`, still at the stack root today and
  moving into this repository with their plans.

## Goal

Replace the hand-picked constants that shape retrieval ranking with a **committed, measured
profile**, selectable per query class, switchable from Settings. When the switch is off the system
behaves exactly as it does today (L0). When it is on, the same pipeline runs with parameters that
were chosen by running the evaluation harness, not by intuition.

This is deliberately **not** runtime learning. Nothing reads user behaviour, nothing changes
between two identical requests, and no evaluation artifact is consulted in the request path.

## Context: the numbers we are replacing

Today `FallbackSearchPlanner` emits fixed values, and `EnsembleSearchService` hardcodes the rerank
blend:

| Parameter | Current value | Chosen by |
|-----------|---------------|-----------|
| `keyword_weight` / `vector_weight` / `hybrid_weight` | 0.35 / 0.35 / 0.30 (0.30 / 0.40 / 0.30 for short queries) | intuition |
| `rrf_k` | 60 | the RRF paper's example |
| `rrf_weight` | 0.25 | intuition |
| `agreement_boost` | 0.15 | intuition |
| `rerank_top_k` | 30 | intuition |
| rerank blend | hardcoded `0.4 / 0.6` in `rerankTopK()` | intuition |
| query classification | `mb_strlen($query) < 20` and "contains a digit" | intuition |

The measurement machinery to do better already exists: curated relevance datasets,
`test:ai:evaluate-application-content` (end-to-end ordering) and `test:ai:evaluate-retrieval-strategies`
(per-strategy ordering, reranker off and on), both emitting precision@k, recall@k, nDCG@k and MRR,
with committed baselines gated in CI.

Two configuration keys are declared and read by nobody: `search.features.ensemble`
(`SEARCH_ENSEMBLE_ENABLED`) and `search.reranker.weight` (`SEARCH_RERANKER_WEIGHT`). This design
wires the second one (it becomes the rerank blend) and deletes the first.

## Levels

| Level | Behaviour | This design |
|-------|-----------|-------------|
| **L0** | fixed constants, two query rules | the fallback, and the default |
| **L1** | measured profile per query class, committed to the repo | **in scope** |
| **L2** | parameters learned from user behaviour at runtime | out of scope, and blocked: no search telemetry exists |

## Design decisions (locked)

1. **The switch is a Setting, not an env var.** A boolean `Setting` named
   `search_adaptive_tuning`, group `search`, default `false`, read through
   `PerModelSettingResolver::boolean()` (already cached per group and invalidated by
   `SettingObserver`). Rationale: the user asked for an operator-facing switch, and Settings is the
   existing mechanism with approval flow, Filament UI and cache invalidation. Env vars would need a
   deploy to flip.

2. **Off means byte-identical L0.** When the setting is false (or the profile is missing, invalid,
   or unreadable) the plan is returned untouched. This is asserted by a test comparing the emitted
   plan against the L0 plan, so the fallback can never silently drift.

3. **The profile is a committed config file, never a database row and never an evaluation report.**
   `Modules/Core/config/search_tuning.php` holds a `version` plus one parameter set per query
   class. A profile change is a reviewable diff gated by the baseline tests. Putting tuned weights
   in the database would make ranking unreproducible across environments; reading an evaluation
   report at runtime would make it undebuggable.

4. **The profile shapes fusion and reranking, never strategy selection.** It may set
   `ensemble.*` and `ranking.rerank_top_k` / `ranking.rerank_blend`. It must not touch
   `retrieval.use_fulltext` / `use_vector`: whether vector retrieval is possible remains a
   capability question (engine support, `VECTOR_SEARCH_ENABLED`, `ITextEmbedder` bound), not a
   tuning question. This keeps the rule crisp and applies uniformly whether the plan came from
   `FallbackSearchPlanner` or from the AI `SearchOrchestratorAgent`.

5. **Query classification reuses `SearchQueryAnalyzer`.** It already tokenizes, classifies each
   token (word, numeric, UUID, email, acronym, structured identifier, short token) and counts
   stopwords. A new `QueryClass` enum derives from that analysis; no second tokenizer is written.

6. **Applied as a post-planner step, not inside a planner.** `AdvancedSearchService` calls
   `RetrievalTuningProfile::apply($plan, $analysis)` right after `safePlan()` and
   `applyEngineCapabilities()`. One insertion point, both planners covered, trivially skippable.

7. **The tuner never writes configuration.** `test:ai:tune-retrieval` runs a parameter grid through the
   existing evaluation services and prints a ranked table plus a ready-to-paste PHP profile block.
   A human reads the numbers and commits the change.

8. **Every tuned response says so.** `AdvancedSearchResult->meta['tuning']` carries
   `{applied, profile_version, query_class}`. Without this a support question about "why did this
   rank here" is unanswerable.

## Query classes

Derived from the existing analysis, in this precedence:

| Class | Condition | Why it deserves its own weights |
|-------|-----------|--------------------------------|
| `identifier` | at least one protected token (code, UUID, email, numeric) | lexical must dominate; semantic similarity on a code is noise |
| `short_keyword` | 1-2 meaningful tokens, none protected | short queries are names and labels: exact-first |
| `multi_term` | 3-5 meaningful tokens, fewer than 2 stopwords | mixed, the ambiguous middle |
| `natural_language` | 6+ meaningful tokens, or at least 2 stopwords | phrasing differs from the corpus: semantic recall matters |

A class with no entry in the profile falls back to the profile's `default`, which itself falls back
to L0 values.

## Profile shape

```php
// Modules/Core/config/search_tuning.php
return [
    'version' => '2026-09-15.1',
    'default' => [
        'keyword_weight' => 0.35, 'vector_weight' => 0.35, 'hybrid_weight' => 0.30,
        'rrf_k' => 60, 'rrf_weight' => 0.25, 'agreement_boost' => 0.15,
        'rerank_top_k' => 30, 'rerank_blend' => 0.60,
    ],
    'classes' => [
        'identifier'       => ['keyword_weight' => 1.0, 'vector_weight' => 0.0, 'hybrid_weight' => 0.0],
        'short_keyword'    => ['keyword_weight' => 0.55, 'vector_weight' => 0.20, 'hybrid_weight' => 0.25],
        'multi_term'       => [],
        'natural_language' => ['keyword_weight' => 0.25, 'vector_weight' => 0.45, 'hybrid_weight' => 0.30],
    ],
];
```

The values above are **placeholders committed as L0-equivalent-by-default**: the real numbers are
whatever `test:ai:tune-retrieval` measures on the curated datasets. The shipped profile must not be a
guess dressed as a measurement, so the first commit of this file carries the tuner's report path in
a comment.

Validation on load: every weight in `[0, 1]`, weights not all zero, `rrf_k >= 1`,
`rrf_weight` and `agreement_boost` in `[0, 1]`, `rerank_top_k >= 1`, `rerank_blend` in `[0, 1]`.
A violation logs a warning once and disables tuning for the request.

## Architecture

```text
AdvancedSearchService::search()
    IQueryIntentParser::parse()
    ISearchPlanner::safePlan()            -> L0 plan
    applyEngineCapabilities()
    RetrievalTuningProfile::apply()       <-- NEW: only when the Setting is on
        SearchQueryAnalyzer (existing)  -> QueryClass
        config('search_tuning')          -> parameter set
        merge into plan.ensemble + plan.ranking
    resolveVector() / TextMatchOptionsResolver::resolve()
    EnsembleSearchService::search()
        rerankTopK() now reads ranking.rerank_blend (default 0.60 = today's behaviour)
```

New Core pieces: `Search/Enums/QueryClass.php`, `Search/Services/RetrievalTuningProfile.php`,
`config/search_tuning.php`, a `search_adaptive_tuning` setting row.
New AI piece: `Console/TuneRetrievalCommand.php` (`test:ai:tune-retrieval`).

## The tuner

```bash
php artisan test:ai:tune-retrieval --source=cms.contents --dataset=<path> \
    --grid=default --metric=ndcg_at_5 --output=<report.json>
```

- Reuses `PerStrategyEngineRetrieverInterface` (already an injectable seam) so a test can drive it
  without Elasticsearch.
- For each candidate parameter set it re-fuses **already retrieved** per-strategy hit lists rather
  than re-querying the engine. The per-strategy orderings do not depend on fusion parameters, so
  one retrieval pass per case feeds the whole grid. This is what makes a grid affordable.
- Reports per class and per parameter set: `ndcg_at_{1,3,5}`, `precision@k`, `recall@k`, MRR, plus
  the delta against the currently committed profile.
- Prints the winning profile as a PHP block ready to paste, and refuses to write any file other
  than its own report.

Because fusion is re-computed offline, the tuner needs the fusion math to be callable without a
full search. `EnsembleSearchService::fuseStrategies()` is extracted into a `RankFusion` value
service, with `EnsembleSearchService` delegating to it. This is a behaviour-preserving refactor
covered by the existing ensemble tests.

## Safety and reversibility

| Risk | Mitigation |
|------|-----------|
| A bad profile degrades production search | the Setting flips it off without a deploy; the baseline gate blocks the commit in CI |
| Tuned values overfit the curated datasets | the tuner keeps a deterministic share of the cases out of the selection (`--holdout`, 0.3 by default) and declares no winner when the best candidate loses to the committed profile on them; a class gets its own override only with enough selection cases (`--min-class-cases`) and a margin over the overall winner (`--class-margin`); the report carries the per-class and aggregate metrics and what was withheld. A gain no bigger than one flipped case of the sample (`--noise-margin`, `auto` is `1 / selection cases` with a floor of 0.01) is noise: no winner, and a class override must clear the same margin sized on its own cases. A profile with measured values must cite a report that passed the held-out validation and the noise check and holds the same values (`report` key, `CommittedRetrievalTuningProfileTest`). The CI baseline gate is only a wiring check: the suite runs Scout on the `collection` driver, where a profile cannot move the numbers (amended 2026-10-01; the first version of this row promised a gate that rejects a one-class win) |
| Silent drift between L0 and "tuning off" | a test asserts the emitted plan with tuning off equals the L0 plan exactly |
| Unexplainable ranking | `meta['tuning']` names the profile version and the query class on every response |
| Profile file missing in a deployment | load failure falls back to L0 and logs once |

## Testing

- `QueryClass` derivation: one case per class plus precedence (a protected token wins over length).
- `RetrievalTuningProfile`: off means identical plan; on means only `ensemble` and `ranking` keys
  change; invalid profile falls back to L0 and logs; unknown class falls back to `default`.
- `RankFusion` extraction: existing `EnsembleSearchServiceTest` stays green unchanged.
- `rerank_blend`: default 0.60 reproduces today's scores exactly.
- Baseline gates (`CmsApplicationContentEvaluationBaselineTest`,
  `SaoApplicationContentEvaluationBaselineTest`) must stay byte-identical with tuning **off**, and
  a new gate asserts the committed profile does not regress them with tuning **on**.
- `test:ai:tune-retrieval`: fake retriever seam, asserts the grid ranks candidates by the chosen metric
  and that no file other than the report is written.

## Out of scope

- Any use of user behaviour (clicks, dwell, conversions). That is L2 and is blocked until search
  telemetry exists; see the note below.
- Per-tenant or per-user profiles.
- Tuning text-matching thresholds (`minimum_should_match`, `fuzzy_token_limit`). They are a
  separate axis with their own doc; the same tuner can be extended later.

## Prerequisite for any future L2

Nothing in the stack records what users searched or what they opened. Before L2 is even debatable,
a search telemetry table must exist (query, resolved class, plan version, returned ids with ranks,
opened id). Logging it costs one table and blocks nothing; deciding L2 without it is guesswork.
