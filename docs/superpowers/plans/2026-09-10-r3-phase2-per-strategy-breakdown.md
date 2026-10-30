---
status: completed
verified_on: 2026-09-15
verified_by: repo audit (declared files + tests present)
---
# R3 Phase 2 — Per-Strategy Retrieval Quality Breakdown — Implementation Plan (corpus-independent part)

> **Moving on delivery.** This subject names backend files only, so this document and its counterpart belong in `docs/superpowers/`. Keep working here: the move happens once the work is done, links and indexes repaired on both sides, so no reference is lost mid-flight. See `Where specs and plans live` in the stack `AGENTS.md`.


> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface the ensemble's per-strategy ranked lists and add a per-strategy retrieval-quality evaluator (keyword / vector / hybrid / fused / reranked), reusing the Phase 1 IR metrics. Deliver the plumbing + deterministic tests; the real ES measurement over the two-source corpus is a later data task.

**Architecture:** Additive `meta['per_strategy']` on `AdvancedSearchResult`; extract the Phase 1 `@k` math into a shared `IrMetrics` helper (behaviour-preserving); a new `ApplicationContentRetrievalStrategyEvaluationService` that calls the engine twice (reranker off/on) via an injectable seam and computes `@k` per strategy; a new command mirroring the Phase 1 one.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, Laravel Scout.

**Spec:** `docs/superpowers/specs/2026-09-10-r3-phase2-per-strategy-breakdown-design.md`

## Global Constraints

- Every PHP file `declare(strict_types=1);`; braces always; explicit param/return types; English comments/PHPDoc; prefer PHPDoc over inline comments.
- Binary relevance; cutoffs `k ∈ {1,3,5}`; `@k` math identical to Phase 1 (precision ÷k, recall ÷|expected|, binary nDCG with IDCG capped at min(|expected|,k)).
- The `EnsembleSearchService` change is **strictly additive** (one `meta` key). No change to fusion/ranking/reranker logic. Core has concurrent master development — touch only the `return new AdvancedSearchResult(...)` meta literal.
- The IrMetrics extraction is **behaviour-preserving**: Phase 1's committed baselines (CMS `Modules/CMS/docs/evaluations/application-content/2026-07-record-baseline.json`, SAO `Modules/SAO/docs/evaluations/application-content/2026-09-record-baseline.json`) and the Phase 1 unit/command tests must stay green with byte-identical artifacts.
- Per-strategy evaluation is **pre-authorization** (raw engine ranking) — deliberate; do not apply ACL filters in the new service/command. Mark it in the report (`pre_authorization: true`).
- Strategies reported: `keyword`, `vector`, `hybrid` (from `meta['per_strategy']` of the reranker-off call), `fused` (final hits of the reranker-off call), `reranked` (final hits of the reranker-on call).
- Reuse `ApplicationContentEvaluationDataset`/`ApplicationContentEvaluationCase` as-is; the case `authorization` block is ignored here.
- Run `php artisan test --compact <path>` from `/srv/http/laraplate-stack/laraplate`; `vendor/bin/pint` on touched files. Commit in the correct submodule (`Modules/Core` or `Modules/AI`), never touching parent/submodule pointers; commit trailer `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

### Task 1: Surface `per_strategy` on `AdvancedSearchResult.meta`

**Files:**
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (the `return new AdvancedSearchResult(...)` meta literal, ~lines 129-143)
- Test: `Modules/Core/tests/Integration/Search/EnsembleSearchServiceTest.php`

**Interfaces:**
- Produces: `AdvancedSearchResult.meta['per_strategy']` = `array<string strategyName, array<string id, array{id:string, score:float, raw_score:float, score_details:array, source:array, rank:int}>>` — the in-scope `$per_strategy` variable (built at lines 56-79, unmutated). Only strategies actually executed appear as keys (`keyword`/`vector`/`hybrid`).

- [x] **Step 1: Failing test.** In `EnsembleSearchServiceTest.php`, add a test that runs a plan with `retrieval.use_fulltext=true, use_vector=false, ranking.use_reranker=false` (the existing pattern at lines 83-98) and asserts `$result->meta` has key `per_strategy`, that it contains `keyword`, and that `keyword` is a non-empty array whose first entry has keys `id`, `score`, `rank`.

- [x] **Step 2: Run, confirm fail** (`meta['per_strategy']` absent).
Run: `php artisan test --compact Modules/Core/tests/Integration/Search/EnsembleSearchServiceTest.php`

- [x] **Step 3: Implement.** In the `meta:` array literal of the `return new AdvancedSearchResult(...)` (lines ~135-142), add:
```php
'per_strategy' => $per_strategy,
```
Nothing else changes (`$per_strategy` is already fully built and untouched after line 79).

- [x] **Step 4: Run, confirm pass**, and confirm the pre-existing EnsembleSearchService tests still pass (no behavioural change).

- [x] **Step 5: Pint + commit** in `Modules/Core`.
```bash
vendor/bin/pint Modules/Core/app/Search/Services/EnsembleSearchService.php Modules/Core/tests/Integration/Search/EnsembleSearchServiceTest.php
git -C Modules/Core add app/Search/Services/EnsembleSearchService.php tests/Integration/Search/EnsembleSearchServiceTest.php
git -C Modules/Core commit -m "feat(search): expose per-strategy ranked lists on AdvancedSearchResult.meta"
```

---

### Task 2: Extract shared `IrMetrics` helper (behaviour-preserving refactor of Phase 1)

**Files:**
- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/IrMetrics.php`
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php` (replace the inline `@k` block, lines 164-186, with a call to the helper)
- Test: `Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/IrMetricsTest.php`

**Interfaces:**
- Produces: `IrMetrics::atK(array $hitIds, array $expectedIds, array $cutoffs): array` returning `array{precision: array<int,float>, recall: array<int,float>, ndcg: array<int,float>}` — **per-case, pre-division** contributions (one relevant case), keyed by cutoff. Callers sum across cases and divide by their own denominator.

- [x] **Step 1: Failing test** `IrMetricsTest.php`: for `hitIds=['a','b','c']`, `expectedIds=['b']`, `cutoffs=[1,3,5]` → `precision=[1=>0.0, 3=>1/3, 5=>0.2]` (raw, pre-round), `recall=[1=>0.0,3=>1.0,5=>1.0]`, `ndcg=[1=>0.0, 3=>1/log(3,2)/1, 5=>same]`. Add edge cases: no hits (all 0), relevant past k, `|expected|>k` (e.g. 7 expected, 5 relevant in top-5 → precision@5=1.0, recall@5=5/7, ndcg@5=1.0), and IDCG guard.

- [x] **Step 2: Run, confirm fail** (class absent).

- [x] **Step 3: Implement `IrMetrics::atK`** by lifting the exact math from `ApplicationContentEvaluationService.php:164-186`:
```php
public static function atK(array $hitIds, array $expectedIds, array $cutoffs): array
{
    $precision = []; $recall = []; $ndcg = [];
    foreach ($cutoffs as $k) {
        $top = array_slice($hitIds, 0, $k);
        $relevant_in_top = 0; $dcg = 0.0;
        foreach ($top as $rank => $id) {
            if (in_array($id, $expectedIds, true)) {
                $relevant_in_top++;
                $dcg += 1.0 / log($rank + 2, 2);
            }
        }
        $ideal = min(count($expectedIds), $k);
        $idcg = 0.0;
        for ($i = 0; $i < $ideal; $i++) { $idcg += 1.0 / log($i + 2, 2); }
        $precision[$k] = $k > 0 ? $relevant_in_top / $k : 0.0;
        $recall[$k] = $expectedIds === [] ? 0.0 : $relevant_in_top / count($expectedIds);
        $ndcg[$k] = $idcg > 0.0 ? $dcg / $idcg : 0.0;
    }
    return ['precision' => $precision, 'recall' => $recall, 'ndcg' => $ndcg];
}
```

- [x] **Step 4: Refactor `ApplicationContentEvaluationService::metrics()`** to call the helper for the per-case contribution and accumulate, replacing lines 164-186. The accumulators (`$precision_sum[$k] += ...`) now read from `IrMetrics::atK($hit_ids, $expected, self::CUTOFFS)`. Keep the return-array spread and `ratio()`/`rounded()` division exactly as-is.

- [x] **Step 5: Run the affected tests** — the new helper test, the Phase 1 service unit test, and BOTH baseline gates (must stay green, byte-identical artifacts):
Run: `php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
Expected: all PASS. If a baseline artifact would change, the refactor altered behaviour — STOP and fix.

- [x] **Step 6: Pint + commit** in `Modules/AI`.

---

### Task 3: `ApplicationContentRetrievalStrategyEvaluationService`

**Files:**
- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentRetrievalStrategyEvaluationService.php`
- Test: `Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentRetrievalStrategyEvaluationServiceTest.php`

**Interfaces:**
- Consumes: `IrMetrics::atK` (Task 2); `AdvancedSearchResult` (Task 1, `meta['per_strategy']` + `ids()`); `ApplicationContentEvaluationDataset`/`Case`.
- Produces: `evaluate(ApplicationContentEvaluationDataset $dataset, string $source, string $driver, callable $retrieval): array` where `$retrieval` is `callable(ApplicationContentEvaluationCase $case, bool $useReranker): AdvancedSearchResult`. Returns a report: same envelope keys as Phase 1 plus `pre_authorization => true`, but `metrics` nested per strategy `{keyword, vector, hybrid, fused, reranked}` each `{precision_at_1..5, recall_at_1..5, ndcg_at_1..5}`; same `slices` nesting.

- [x] **Step 1: Failing unit test** with an injected retrieval seam returning canned `AdvancedSearchResult`s. Build a dataset with one case, `expectedHitIds=['cms.contents:2']`. The seam returns, for `useReranker=false`, a result whose `meta['per_strategy']` has `keyword` ranking `[1,2,3]` (ids `cms.contents:1..3`, so the relevant id at rank 2), `vector` ranking `[2,1,3]` (relevant at rank 1), `hybrid` ranking `[2,3,1]`, and final `ids()` = fused `[2,1,3]`; for `useReranker=true`, final `ids()` = `[2,1,3]`. Assert `metrics.keyword.precision_at_1=0.0`, `metrics.vector.precision_at_1=1.0`, `metrics.fused.recall_at_3=1.0`, `metrics.reranked.mrr`/`ndcg` as hand-computed. Use the injected-clock pattern (`ApplicationContentEvaluationServiceTest.php:79-83`).

- [x] **Step 2: Run, confirm fail** (class absent).

- [x] **Step 3: Implement.** Constructor `__construct(?Closure $clock = null)` (default `hrtime`-based, mirror Phase 1). In `evaluate()`, per case with non-empty `expectedHitIds`: call `$retrieval($case, false)` → `$off`; `$retrieval($case, true)` → `$on`. Build the five ordered id lists:
  - `keyword`/`vector`/`hybrid`: `array_map(fn($h) => $h['id'], array_values($off->meta['per_strategy'][$name] ?? []))` — already rank-ordered (the map is insertion-ordered by rank; if unsure, `usort` by `rank`).
  - `fused`: `$off->ids()`.
  - `reranked`: `$on->ids()`.
  For each strategy, accumulate `IrMetrics::atK($ids, $case->expectedHitIds, self::CUTOFFS)` into per-strategy sums, plus a per-strategy MRR/hit_rate if mirroring Phase 1's `hit_at_5`/`mrr`. Divide by `relevant_cases` via a `ratio()`/`rounded()` pair (copy the two private helpers from Phase 1, or hoist them — small, acceptable duplication if hoisting is out of scope). Assemble the nested report envelope (schema_version '1', source, driver, dataset versions, case_count, `pre_authorization => true`, per-strategy `metrics`, `latency_ms`, `slices`).

- [x] **Step 4: Run, confirm pass.** Cover: a case with empty `expectedHitIds` (skipped), and a strategy missing from `per_strategy` (e.g. vector absent) → that strategy's metrics are 0 / absent by a documented convention (pick: omit the strategy key when never executed; assert it).

- [x] **Step 5: Pint + commit** in `Modules/AI`.

---

### Task 4: Command `test:ai:evaluate-retrieval-strategies`

**Files:**
- Create: `Modules/AI/app/Console/EvaluateApplicationContentRetrievalStrategiesCommand.php`
- Test: `Modules/AI/tests/Feature/EvaluateApplicationContentRetrievalStrategiesCommandTest.php`

**Interfaces:**
- Consumes: the Task 3 service; `ApplicationContentRetrievalProviderRegistryInterface::providerFor($source)` → `ProvidesPermissionModel::permissionModel()` → `new $modelClass`; `ITextEmbedder::embed($case->query)`; `EnsembleSearchService`.

- [x] **Step 1: Failing feature test.** Mirror the Phase 1 command test. Because the real per-strategy run needs Elasticsearch (vector/hybrid), the command MUST take the engine retrieval through an **injectable seam** so the feature test binds a fake. Introduce a small interface (e.g. `Modules\AI\Services\ApplicationContent\Evaluation\Contracts\PerStrategyEngineRetriever` with `retrieve(Model $model, string $query, bool $useReranker): AdvancedSearchResult`) and a real implementation wrapping `ITextEmbedder` + `EnsembleSearchService` (embed the query, build the plan `retrieval=[use_fulltext=>true,use_vector=>true], ranking=[use_reranker=>$useReranker]`, call `EnsembleSearchService::search(model, query, plan, vector, page:1, perPage:<case limit>)`). Bind the interface in the AI service provider. The command resolves this interface + the model class + the service, builds the `callable(case, useReranker)` from it, and runs the service. Feature test binds a fake retriever returning canned results and asserts: (a) invalid `--source` → FAILURE; (b) a run writes a report whose `metrics` has the five strategy keys and per-strategy `@k` keys.

- [x] **Step 2: Run, confirm fail.**

- [x] **Step 3: Implement the command** — signature `test:ai:evaluate-retrieval-strategies {--dataset=} {--source=} {--output=} {--force}`, mirroring `EvaluateApplicationContentCommand` (dataset load+validate, driver from `config('scout.driver')`, atomic write with `JSON_PRESERVE_ZERO_FRACTION`, generic error swallow). Resolve the model via the provider's `permissionModel()` (error clearly if the provider doesn't implement `ProvidesPermissionModel`). Implement the retriever interface + real impl + provider binding.

- [x] **Step 4: Run, confirm pass.**

- [x] **Step 5: Affected-suite check + Pint + commit** in `Modules/AI`.
Run: `php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent Modules/AI/tests/Feature/EvaluateApplicationContentRetrievalStrategiesCommandTest.php`

---

## Out of scope (needs the corpus — later)

- Running `test:ai:evaluate-retrieval-strategies` on Elasticsearch over the indexed Naxos + The Hacker News corpus for the real numbers.
- A gold dataset anchored to that real corpus + a stored reference compared with per-metric tolerances (ES ranking is not bit-deterministic).

## RAG docs

Update `Modules/AI/docs/rag/MODULE.md` (the eval section from Phase 1) to add the per-strategy command, the `meta['per_strategy']` surface, and the pre-authorization semantics, when Task 4 completes.

## Delivery status (2026-09-15): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md` (section "Per-strategy retrieval quality breakdown") and `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`.

All 4 tasks verified against the code: `meta[per_strategy]` on `AdvancedSearchResult`, the shared `IrMetrics` helper, `ApplicationContentRetrievalStrategyEvaluationService`, and `test:ai:evaluate-retrieval-strategies` with its feature test.
