# R3 — Retrieval Quality Baseline (Design)

> **Moving on delivery.** This subject names backend files only, so this spec and its plan belong in `docs/superpowers/`. They stay here while the work is in flight and move once it is done, links and indexes repaired on both sides. See `Where specs and plans live` in the stack `AGENTS.md`.

**Status:** Implemented 2026-09-15; see the delivery status of `docs/superpowers/plans/2026-09-09-r3-retrieval-quality-baseline.md`. Was: approved design, Phase 1 scoped.
**Date:** 2026-09-09
**Program goal:** R3 (retrieval quality) of the RAG assistant program — improve application-content ranking from raw lexical/engine baseline toward metadata → hybrid → rerank → graph, **measured**.

## Goal

Make the ranking quality of application-content retrieval (CMS + SAO) **measurable** with real IR metrics over curated relevance-judgment datasets, so R3 improvements (metadata filtering, graph-as-retrieval-signal) are driven by numbers instead of guesses (the program's "measure first" principle).

## Context — what already exists

The evaluation scaffolding is already in place and drives the **real** ranking stack (no rebuild needed):

- `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php` — evaluates a dataset by calling a retrieval closure once per case; emits a payload-free JSON report (schema_version, driver, versions, case_count, metrics, latency_ms, slices).
- `ApplicationContentEvaluationCase` — carries binary ground truth: `expectedHitIds` (relevant record-id set, e.g. `cms.contents:1`) + `expectedCitationReferences`, plus behavioral `expect*` booleans, `locale`, `limit`, `slices`, `authorization`.
- `ApplicationContentEvaluationDataset` — strict typed loader; JSON keys exactly: `version`, `provider_version`, `corpus_revision`, `source`, `data_classification` (must be `synthetic`), `cases`.
- `Console/EvaluateApplicationContentCommand.php` — `test:ai:evaluate-application-content --dataset= --source= --output= --force`; resolves the registered provider and runs the **real** pipeline `provider->retrieve()` → `AdvancedSearchService` → `EnsembleSearchService` (keyword + vector + hybrid, RRF fusion, `IReranker`) with a DB `LIKE` lexical fallback. No chat model. Deterministic test seam: injectable `clock` closure + injected retrieval callable.

Registered application-content sources today: `cms.contents` (CMS) and `sao.tickets` (SAO).

### Gaps this design closes (Phase 1)

- **No committed relevance datasets** for `cms.contents` or `sao.tickets` (the only JSON under `docs/rag/evaluations/` is an unrelated security report).
- **No real IR metrics.** Today `metrics()` emits only `mean_reciprocal_rank` (first relevant hit) and `hit_at_5` — a misnomer: it flags a relevant id at *any* rank, not at a k cutoff. There is no recall, no precision@k, no nDCG, no rank distribution.
- **No committed baseline report + threshold gate** to diff future runs against.

Deferred to later phases: per-strategy breakdown (Phase 2) and metadata/graph improvements (Phase 3).

## Architecture

Reuse the existing service/case/dataset/command. Phase 1 is additive and touches **no ranking code**: IR metrics are computed from the already-recorded ordered `hit_ids` against `expectedHitIds`.

```
curated dataset JSON (per source, anchored to dev seeders)
        │  test:ai:evaluate-application-content --source --dataset --output
        ▼
provider->retrieve()  →  AdvancedSearch → Ensemble(keyword|vector|hybrid, RRF, rerank)  →  ordered hits
        ▼
ApplicationContentEvaluationService.record()   (hit_ids in rank order)   ← unchanged
        ▼
metrics()  →  MRR (existing) + precision@k + recall@k + nDCG@k (new)   →  JSON report
        ▼
committed baseline report  +  threshold gate (future runs diffed against it)
```

## Components (Phase 1)

### 1. IR metrics in `ApplicationContentEvaluationService::metrics()`

Binary relevance from `expectedHitIds`, evaluated on the rank-ordered `hit_ids`. For each case with a non-empty `expectedHitIds`, at each configured cutoff `k`:

- **precision@k** = |relevant ∩ hit_ids[0:k]| / k
- **recall@k** = |relevant ∩ hit_ids[0:k]| / |expectedHitIds|
- **nDCG@k** (binary gains) = DCG@k / IDCG@k, with DCG@k = Σ_{i=0..k-1} rel_i / log2(i+2), rel_i ∈ {0,1}; IDCG@k = Σ_{i=0..min(|relevant|,k)-1} 1 / log2(i+2)
- **mrr** — keep the existing reciprocal rank of the first relevant hit.

Cutoffs: `k ∈ {1, 3, 5}` (a service constant; case `limit` is typically ≤ 5, so 10 never truncates). Metrics are averaged over the cases that carry ground truth (same denominator convention as the existing MRR). `hit_at_5` is kept unchanged (a coarse any-rank coverage signal) rather than renamed, to avoid churning committed baselines. Behavioral rates (authorized-empty, supported, abstention, unavailable, citation precision) and latency/slices are unchanged. `schema_version` is NOT bumped: the change is purely additive (new metric keys only) and backward-compatible.

Rationale for binary (not graded) relevance: reuses the existing `expectedHitIds` schema, keeps judgments deterministic and cheap, and is sufficient for a baseline and for comparing strategies. Graded relevance can be added later if a metric demands it.

### 2. Curated relevance datasets (per source, anchored to dev seeders)

Committed JSON datasets, one per source, anchored to the existing dev seeders so the corpus (and therefore the `expectedHitIds`) is reproducible:

- `Modules/AI/docs/rag/evaluations/application-content/cms-contents.json`
- `Modules/AI/docs/rag/evaluations/application-content/sao-tickets.json`

Each ~15–30 gold cases: a natural-language `query`, its `expectedHitIds` (real ids from the seeded corpus), optional `expectedCitationReferences`, `locale`, and `slices` (e.g. `category:exact-title`, `category:paraphrase`, `category:multi-term`) to cut metrics by query type. `data_classification` = `synthetic`; `corpus_revision` records which dev-seed revision the ids belong to, invalidating the dataset when the corpus changes.

### 3. Baseline report + gate

- A committed **baseline report** produced by running the command against a freshly dev-seeded + indexed corpus, stored next to its dataset.
- **Deterministic gate at the unit layer** (program pattern): tests drive `metrics()` through the existing clock/retrieval seam with fixed inputs and assert the IR formulas on known cases — this is the CI gate.
- The **real baseline over Elasticsearch is on-demand** (needs ES + embeddings), not CI. Because the reranker/RRF are not bit-deterministic, comparison against the baseline is **threshold-based** (tolerance per metric), never exact-match.

## Data flow & determinism

The command exercises the real stack once per case; the service reads only the ordered `hit_ids` it already records. Determinism is layered: unit tests are exact (closure-injected results); the live baseline is tolerance-gated. Corpus reproducibility comes from the dev seeders + `corpus_revision`.

## Error handling

Dataset loading is already strict (exact keys, size cap, unique ids, `synthetic` classification). New metric code must be null/empty-safe: cases without ground truth are skipped (existing convention); empty `hit_ids` yield 0 for precision/recall/nDCG; `IDCG=0` (no relevant) is guarded to avoid division by zero.

## Testing

- Unit tests for each new formula (precision@k, recall@k, nDCG@k) with hand-computed expected values, including edge cases: no hits, all relevant at top, relevant only past k, ground-truth larger than k.
- A test that each committed dataset loads and validates.
- The existing command/service tests keep passing (metrics block is additive; existing values unchanged).

## Phases 2–3 (out of scope here, tracked)

- **Phase 2 — per-strategy contribution.** Surface `score`/`strategy` (today `record()` drops them and the ensemble's internal `per_strategy` never surfaces) to compare keyword vs vector vs hybrid vs reranked on the same datasets, proving the added components earn their place.
- **Phase 3 — measured improvements.** Metadata filtering and/or graph→retrieval signal, each measured against the Phase 1 baseline.

## Session fixes folded in as "done"

The vector/hybrid pipeline these metrics measure was made operational in this program along the way (all committed/pushed): vector dimension 384, `createIndex` mapping application, `IndexInSearchJob` collection fix, listener `isEmbeddable`, queue `retry_after`, rate-limit `retryUntil` + `maxExceptions`, embed-failure keyword-only degrade, and `ai:embeddings:repair`.
