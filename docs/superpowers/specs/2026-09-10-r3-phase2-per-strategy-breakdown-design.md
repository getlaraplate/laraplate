# R3 Phase 2 — Per-Strategy Retrieval Quality Breakdown (Design)

> **Moving on delivery.** This subject names backend files only, so this spec and its plan belong in `docs/superpowers/`. They stay here while the work is in flight and move once it is done, links and indexes repaired on both sides. See `Where specs and plans live` in the stack `AGENTS.md`.

**Status:** Implemented 2026-09-15; see the delivery status of `docs/superpowers/plans/2026-09-10-r3-phase2-per-strategy-breakdown.md`. Was: approved design (corpus-independent part scoped).
**Date:** 2026-09-10
**Program goal:** R3 (retrieval quality). Phase 1 gave IR metrics + deterministic baselines but only end-to-end on the lexical database engine (near-degenerate). Phase 2 measures ranking quality **per strategy** (keyword / vector / hybrid / fused / reranked) to show whether vector/hybrid/rerank actually beat keyword.

**Spec (Phase 1, prior):** `docs/superpowers/specs/2026-09-09-r3-retrieval-quality-baseline-design.md`

## Goal

Produce a per-strategy retrieval-quality report: for each query with known relevant ids, `precision@k` / `recall@k` / `nDCG@k` computed separately for the **keyword**, **vector**, **hybrid**, **fused** (pre-rerank) and **reranked** (post-rerank) orderings — so the contribution of each component is measurable, guiding Phase 3 (metadata / graph) with numbers.

## Context — what the engine already computes

`EnsembleSearchService::search()` runs keyword, vector and hybrid as independent Scout branches and builds an internal `per_strategy` map — for each strategy, the full ranked list over the fetch window as `{id → {id, score, raw_score, rank, …}}` (rank = 1-based). It then RRF-fuses them, applies the reranker to the top-K of the fused set, paginates, and returns an `AdvancedSearchResult` whose hits carry the **final fused/reranked** score only. The `per_strategy` map is **discarded** after fusion; only strategy *names* survive in `meta.strategies`.

Consequences that shape the design:
- keyword and vector can be isolated via plan flags, but **hybrid always co-executes** with them — so re-running per strategy cannot cleanly isolate hybrid, and triples retrieval cost. Surfacing the already-computed `per_strategy` map is the clean, single-pass source.
- **reranked** = the final ordering with the reranker on; **fused** (pre-rerank) is not retained, so it needs a second call with the reranker off.
- The evaluation cannot go through `provider->retrieve()`: that returns **projected, post-ACL, single-strategy** evidence and drops per-id score/strategy. Per-strategy evaluation must call `EnsembleSearchService` **directly** (raw, pre-ACL).

## Why a dedicated service/command (not extending Phase 1)

Phase 1's command evaluates what the authorized end user sees (projected, post-ACL, one final ordering) and emits single metrics. Phase 2 evaluates the **raw engine ranking pre-authorization** across **many** strategies and emits a strategy×metric matrix. Different retrieval seam, opposite ACL semantics, different output shape → a separate command. What is **shared** (DRY): the `@k` formulas, the dataset schema, and the report envelope.

## Architecture

1. **Surface `per_strategy`** from `EnsembleSearchService::search()` into `AdvancedSearchResult.meta['per_strategy']` — additive: `array<string strategyName, list<array{id: string, score: float, rank: int}>>`, each list in ranked order. No change to fusion/ranking logic. (Additive `meta` field; Core has concurrent master development, so keep it strictly additive.)

2. **Extract a shared IR-metrics helper** — the `precision@k` / `recall@k` / `nDCG@k` math currently inline in `ApplicationContentEvaluationService::metrics()` moves to one reusable unit (binary relevance, ordered ids vs an expected set, cutoffs `k ∈ {1,3,5}`). Phase 1 service and Phase 2 service both call it; Phase 1 behaviour and its committed baselines stay byte-identical (pure refactor, guarded by the existing baseline gates).

3. **`RetrievalStrategyEvaluationService`** — per dataset case: embed the query (`ITextEmbedder`), then call `EnsembleSearchService::search()` **twice** with an explicit plan forcing keyword+vector+hybrid: once with `ranking.use_reranker=false` (yields `meta.per_strategy` for keyword/vector/hybrid **and** the fused final ordering) and once with `use_reranker=true` (yields the reranked final ordering). Compute `@k` per strategy via the shared helper against `expected_hit_ids`. Aggregate into a strategy×metric report. Injectable `clock` and an injectable retrieval seam (a callable returning the two results) so it is unit-testable without Elasticsearch.

4. **Command** `test:ai:evaluate-retrieval-strategies --source=<source> --dataset=<file> --output=<file>` — mirrors the Phase 1 command's CLI and report-writing (atomic, `--force`), resolves the model class from the source via the provider's `ProvidesPermissionModel::permissionModel()` (`Content` / `Ticket`), and runs the real engine (Elasticsearch) with no chat model.

5. **Dataset** — reuse `ApplicationContentEvaluationDataset` / `ApplicationContentEvaluationCase` as-is (`query`, `expected_hit_ids`, `locale`, `limit`, `slices`). The `authorization` block is **ignored** here (pre-ACL benchmark); `expected_hit_ids` keep the `<source>:` prefix.

## Report shape

Same envelope as Phase 1 (schema_version, source, driver, versions, case_count, latency_ms, slices) but `metrics` is nested per strategy:

```
metrics: {
  keyword:  { precision_at_1, …, recall_at_1, …, ndcg_at_1, …, hit_rate },
  vector:   { … },
  hybrid:   { … },
  fused:    { … },
  reranked: { … }
}
```

Averaged over the cases carrying ground truth, same denominator convention as Phase 1. Slices (locale, category) carry the same nested shape.

## ACL

Evaluated **pre-authorization**: `EnsembleSearchService`/`AdvancedSearchService` do not ACL-filter (that happens later in the provider's rehydrate step). This is deliberate — Phase 2 is a **ranking** diagnostic, not an access-control one — and the eval corpus is fully authorized anyway. Recorded explicitly in the report (`data_classification` / a `pre_authorization: true` marker).

## Determinism & testing (the corpus-independent, CI part)

- Unit tests drive `RetrievalStrategyEvaluationService` through its injectable retrieval seam with a **stub** returning a known `per_strategy` map + known fused/reranked orderings, and assert the per-strategy `@k` values by hand. No Elasticsearch, no corpus.
- A unit test for the shared IR helper (moved from Phase 1, plus the Phase-1 edge cases).
- A test that `EnsembleSearchService` now exposes `meta['per_strategy']` with the expected shape (extend the existing `EnsembleSearchServiceTest`, which already builds custom plans).
- Phase 1's baseline gates must stay green after the helper extraction (pure refactor).

## Out of scope here (needs the corpus)

- **Real measurement on Elasticsearch** over the indexed Naxos + The Hacker News corpus via `test:ai:evaluate-retrieval-strategies` — the actual numbers that show whether vector/hybrid/rerank beat keyword.
- A **gold dataset anchored to that real corpus** (stable ids), and a stored reference report compared with per-metric tolerances (not exact-match; ES ranking is not bit-deterministic).
These are done once the two-source corpus is indexed and embedded; this design's implementation delivers the plumbing + deterministic tests so that step is a data task, not a code task.
