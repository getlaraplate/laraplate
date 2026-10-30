---
status: completed
verified_on: 2026-09-15
verified_by: repo audit (declared files + tests present)
---
# R3 Retrieval Quality Baseline — Implementation Plan (Phase 1)

> **Moving on delivery.** This subject names backend files only, so this document and its counterpart belong in `docs/superpowers/`. Keep working here: the move happens once the work is done, links and indexes repaired on both sides, so no reference is lost mid-flight. See `Where specs and plans live` in the stack `AGENTS.md`.


> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add real IR metrics (precision@k, recall@k, nDCG@k) to the application-content evaluation harness and give both registered sources (`cms.contents`, `sao.tickets`) a committed, deterministic ranking-quality baseline.

**Architecture:** Additive change to `ApplicationContentEvaluationService::metrics()` (no ranking code touched). CMS reuses its existing curated dataset + baseline test (regenerate the artifact). SAO gets a new curated dataset + baseline test anchored to the deterministic `SAO-1..SAO-8` dev corpus. The live Elasticsearch (vector/hybrid/rerank) baseline is produced on-demand by the existing `test:ai:evaluate-application-content` command and is out of the CI gate.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, Laravel Scout (database engine in the deterministic gate).

**Spec:** `docs/superpowers/specs/2026-09-09-r3-retrieval-quality-baseline-design.md`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`. Braces always. Explicit param/return types. Prefer PHPDoc over inline comments.
- Code, comments, PHPDoc, docs content in English.
- Binary relevance; metrics averaged over `relevant_cases` (cases with non-empty `expectedHitIds`), matching existing `hit_at_5`/`mean_reciprocal_rank`.
- Cutoffs: `k ∈ {1, 3, 5}` as a service constant `private const array CUTOFFS = [1, 3, 5];`. (Deviation from the spec's "5/10": case `limit` is typically ≤ 5, so k=10 never truncates; {1,3,5} is more informative. Recorded here.)
- `hit_at_5` is NOT renamed (spec suggested `hit_rate`): renaming churns two committed baselines for zero functional gain now that true @k metrics exist. Recorded deviation.
- New metric keys must appear in the return array of `metrics()` so they auto-propagate into `slices()` and the report envelope.
- Metric math must be division-safe: skip cases without ground truth; empty `hit_ids` → 0; `IDCG == 0` guarded.
- Dataset id formats are exact: hits `cms.contents:<contentId>` / `sao.tickets:SAO-<n>`; citations `/app/cms/contents/<contentId>` / `/app/sao/tickets/SAO-<n>`. Each `expected_hit_ids` entry MUST be prefixed `"<source>:"`. Case `authorization.permission` MUST start with `"evaluation."`. SAO cases MUST use locale `en` (the SAO descriptor's only supported locale).
- Run minimal tests with `php artisan test --compact <path>`; `vendor/bin/pint --dirty` before finishing each task.

---

### Task 1: IR metrics in ApplicationContentEvaluationService

**Files:**
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php`
- Test: `Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php`

**Interfaces:**
- Consumes: `ApplicationContentEvaluationCase::$expectedHitIds` (`list<string>`), the per-record `hit_ids` (rank-ordered `list<string>`) already assembled by `record()`.
- Produces: new report `metrics` keys `precision_at_1|3|5`, `recall_at_1|3|5`, `ndcg_at_1|3|5` (floats rounded to 4 dp via the existing `ratio()`/`rounded()` helper), present in top-level metrics and in every `slices` entry.

- [x] **Step 1: Write failing unit test for the new metrics.**

Add to `ApplicationContentEvaluationServiceTest.php` a test using the existing `evaluationCase(...)`, `evaluationHit(...)`, dataset and injected-clock helpers. Construct one relevant case whose expected id sits at rank 2, with 3 returned hits:

```php
it('computes precision, recall and nDCG at k', function (): void {
    $case = evaluationCase(
        id: 'rank2',
        query: 'q',
        expectedHitIds: ['cms.contents:200'],
        // one relevant id present at rank 2 of 3 returned hits
    );
    $dataset = new ApplicationContentEvaluationDataset(
        version: '1', providerVersion: 'p', corpusRevision: 'c',
        cases: [$case], source: 'cms.contents',
    );
    $results = ['rank2' => new ApplicationContentResult(hits: [
        evaluationHit('cms.contents:199'),
        evaluationHit('cms.contents:200'),
        evaluationHit('cms.contents:201'),
    ])];
    $tick = 0.0;
    $service = new ApplicationContentEvaluationService(clock: static function () use (&$tick): float {
        $c = $tick; $tick += 0.01; return $c;
    });
    $report = $service->evaluate($dataset, 'cms.contents', 'database', fn ($q, $a) => $results['rank2']);

    // relevant at rank 2: precision@1=0, @3=1/3, @5=1/3; recall@1=0, @3=1, @5=1;
    // nDCG@1=0, @3=@5 = (1/log2(3)) / (1/log2(2)) = 0.6309
    expect($report['metrics'])->toMatchArray([
        'precision_at_1' => 0.0, 'precision_at_3' => 0.3333, 'precision_at_5' => 0.3333,
        'recall_at_1' => 0.0, 'recall_at_3' => 1.0, 'recall_at_5' => 1.0,
        'ndcg_at_1' => 0.0, 'ndcg_at_3' => 0.6309, 'ndcg_at_5' => 0.6309,
    ]);
});
```

(Adjust the `evaluationCase`/`evaluationHit`/`ApplicationContentResult` construction to the exact existing helper signatures in the file.)

- [x] **Step 2: Run it, confirm it fails** (missing keys).

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php`
Expected: FAIL — `precision_at_1` etc. absent.

- [x] **Step 3: Implement the metrics.**

Add the constant and accumulators, compute per-k inside the existing `if ($case->expectedHitIds !== [])` block, and emit the keys in the return array. Core math:

```php
private const array CUTOFFS = [1, 3, 5];

// inside the relevant-case block, $hit_ids is rank-ordered, $expected = $case->expectedHitIds
foreach (self::CUTOFFS as $k) {
    $top = array_slice($hit_ids, 0, $k);
    $relevant_in_top = 0;
    $dcg = 0.0;
    foreach ($top as $rank => $id) {
        if (in_array($id, $expected, true)) {
            $relevant_in_top++;
            $dcg += 1.0 / log($rank + 2, 2);
        }
    }
    $ideal = min(count($expected), $k);
    $idcg = 0.0;
    for ($i = 0; $i < $ideal; $i++) {
        $idcg += 1.0 / log($i + 2, 2);
    }
    $precision_sum[$k] += $relevant_in_top / $k;
    $recall_sum[$k] += $relevant_in_top / count($expected);
    $ndcg_sum[$k] += $idcg > 0.0 ? $dcg / $idcg : 0.0;
}
```

Then in the return array add, for each k, `"precision_at_{$k}" => $this->ratio($precision_sum[$k], $relevant_cases)` and likewise recall/ndcg. Initialise `$precision_sum`/`$recall_sum`/`$ndcg_sum` as `array_fill_keys(self::CUTOFFS, 0.0)` next to the other accumulators.

- [x] **Step 4: Run the unit test, confirm pass.**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php`
Expected: PASS. Add edge-case tests in the same file: no returned hits (all @k = 0), relevant only at rank > 5 (precision/recall/ndcg@1/3/5 = 0 but `hit_at_5`/MRR > 0), two expected ids both in top-3 (recall@3 = 1.0), and a case with `expectedHitIds: []` (skipped, denominators unchanged).

- [x] **Step 5: Pint + commit** (in the `laraplate` → `Modules/AI` submodule).

```bash
vendor/bin/pint Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php
git add Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php
git commit -m "feat(eval): add precision@k, recall@k, nDCG@k to application-content evaluation"
```

---

### Task 2: Regenerate the CMS deterministic baseline

**Files:**
- Modify: `Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php`
- Modify (regenerate): `Modules/CMS/docs/evaluations/application-content/2026-07-record-baseline.json`

**Interfaces:**
- Consumes: the existing fixture `Modules/CMS/tests/Fixtures/application-content/cms-contents.json` (30 cases) and the 30 test-seeded Content records (ids 9101–9130) already created by the test.

The baseline test asserts `expect($report)->toBe($artifact)`. Task 1 adds keys to `metrics` (and every slice), so the artifact must be regenerated with the new values.

- [x] **Step 1: Add an explicit regeneration path to the baseline test.**

Guard the exact-match assertion so the artifact can be rewritten deterministically instead of hand-edited:

```php
if (getenv('APP_CONTENT_BASELINE_REGEN') === '1') {
    file_put_contents($artifact_path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
expect($report)->toBe(
    json_decode((string) file_get_contents($artifact_path), true, flags: JSON_THROW_ON_ERROR),
);
```

- [x] **Step 2: Regenerate the artifact.**

Run: `APP_CONTENT_BASELINE_REGEN=1 php artisan test --compact Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php`
Expected: PASS (it rewrites, then compares to what it wrote). Inspect the git diff of `2026-07-record-baseline.json`: the ONLY changes must be added `precision_at_*`/`recall_at_*`/`ndcg_at_*` keys in `metrics` and in each `slices` entry — no change to existing values (`hit_at_5`, MRR, etc. must be byte-identical). If any pre-existing metric value changed, stop: Task 1 altered behaviour it should not have.

- [x] **Step 3: Verify the gate without the regen flag.**

Run: `php artisan test --compact Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php`
Expected: PASS against the committed artifact.

- [x] **Step 4: Commit** (in `Modules/CMS`).

```bash
git add Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php Modules/CMS/docs/evaluations/application-content/2026-07-record-baseline.json
git commit -m "test(eval): regenerate CMS application-content baseline with @k metrics"
```

---

### Task 3: SAO curated dataset + deterministic baseline (greenfield)

**Files:**
- Create: `Modules/SAO/tests/Fixtures/application-content/sao-tickets.json`
- Create: `Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
- Create (generated): `Modules/SAO/docs/evaluations/application-content/2026-09-record-baseline.json`

**Interfaces:**
- Consumes: `SaoApplicationContentRetrievalProvider` (source `sao.tickets`) via the registry; the 8 deterministic dev tickets `SAO-1..SAO-8`.
- Mirrors: the CMS baseline test structure (RefreshDatabase, explicit corpus, injected clock, regen-guarded exact match).

- [x] **Step 1: Author the SAO dataset fixture.**

Create `sao-tickets.json` with the dataset envelope and ~8–12 cases anchored to the `SAO-1..SAO-8` titles (locale `en`, permission `evaluation.tickets.select`). Example (one case; author the rest against the seeded titles — e.g. `SAO-4` "Timeout intermittente API pagamenti", `SAO-1` "Login non funziona su Safari"):

```json
{
  "source": "sao.tickets",
  "data_classification": "synthetic",
  "version": "1",
  "provider_version": "sao-ticket-v1",
  "corpus_revision": "sao-dev-8-v1",
  "cases": [
    {
      "id": "payment-timeout",
      "query": "payment API timeout",
      "locale": "en",
      "limit": 5,
      "expected_hit_ids": ["sao.tickets:SAO-4"],
      "expected_citation_references": ["/app/sao/tickets/SAO-4"],
      "expect_authorized_empty": false,
      "expect_supported_answer": true,
      "expect_abstention": false,
      "slices": ["exact"],
      "authorization": { "permission": "evaluation.tickets.select", "filters": null }
    }
  ]
}
```

Include at least: two exact-title matches, two paraphrase matches, one multi-term, and one `expect_authorized_empty` case (query with no plausible ticket → `expected_hit_ids: []`, `expected_citation_references: []`, `expect_authorized_empty: true`, `expect_supported_answer: false`).

- [x] **Step 2: Write the SAO baseline test (seed corpus, regen-guarded).**

Create `SaoApplicationContentEvaluationBaselineTest.php` mirroring the CMS one. Seed exactly the 8 dev tickets so keys are deterministic `SAO-1..SAO-8` — reuse the dev seeder's construction: one project with `key_prefix = 'SAO'`, then 8 `Ticket::factory()->forProject($project)->create([... title ...])` in the same order as `DevSAODatabaseSeeder`. Then:

```php
$dataset = ApplicationContentEvaluationDataset::fromFile(
    module_path('SAO', 'tests/Fixtures/application-content/sao-tickets.json'),
);
$provider = app(ApplicationContentRetrievalProviderRegistryInterface::class)->providerFor('sao.tickets');
$tick = 0.0;
$service = new ApplicationContentEvaluationService(clock: static function () use (&$tick): float {
    $c = $tick; $tick += 0.01; return $c;
});
$report = $service->evaluate($dataset, 'sao.tickets', 'database-generated-fixture',
    static fn ($query, $authorization) => $provider->retrieve($query, $authorization));
$artifact_path = module_path('SAO', 'docs/evaluations/application-content/2026-09-record-baseline.json');
if (getenv('APP_CONTENT_BASELINE_REGEN') === '1') {
    file_put_contents($artifact_path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}
expect($report)->toBe(json_decode((string) file_get_contents($artifact_path), true, flags: JSON_THROW_ON_ERROR));
```

Make sure the SAO test corpus is created with syncing disabled where the dev seeder does so (`ModelObserver::disableSyncingFor(Ticket::class)`), matching `DevSAODatabaseSeeder`, so factory creation does not dispatch indexing jobs during the test.

- [x] **Step 3: Generate the SAO artifact, then verify the gate.**

Run: `APP_CONTENT_BASELINE_REGEN=1 php artisan test --compact Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
Then without the flag:
Run: `php artisan test --compact Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
Expected: PASS. Sanity-check the produced metrics are plausible (exact-title cases should give `precision_at_1`/`recall_at_1` near 1.0 on the database engine); if a case never matches, fix the query/expected id in the fixture, not the metric code.

- [x] **Step 4: Commit** (in `Modules/SAO`).

```bash
git add Modules/SAO/tests/Fixtures/application-content/sao-tickets.json Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php Modules/SAO/docs/evaluations/application-content/2026-09-record-baseline.json
git commit -m "test(eval): add SAO application-content ranking baseline with @k metrics"
```

---

### Task 4: Update AI service/command tests to assert @k

**Files:**
- Modify: `Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php`
- (The unit service test was already extended in Task 1.)

- [x] **Step 1: Extend the command feature test.**

In `EvaluateApplicationContentCommandTest.php` the test writes a dataset JSON, runs `test:ai:evaluate-application-content`, and asserts the report `metrics`. Add assertions for at least one `precision_at_*`, `recall_at_*`, `ndcg_at_*` key so the command-level report is covered. If the existing assertion is an exact `toBe`/`toEqual` on the metrics block, extend the expected block with the new keys; if it is `toMatchArray`, just add the new keys.

- [x] **Step 2: Run it, confirm pass.**

Run: `php artisan test --compact Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php`
Expected: PASS.

- [x] **Step 3: Full affected-suite check.**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
Expected: all PASS.

- [x] **Step 4: Pint + commit** (in `Modules/AI`).

```bash
vendor/bin/pint Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php
git add Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php
git commit -m "test(eval): assert @k metrics in evaluate-application-content command"
```

---

## Out of scope (Phase 1) — recorded for later

- **Live Elasticsearch baseline** (vector/hybrid/rerank ranking quality): produced on-demand with `test:ai:evaluate-application-content --source=... --dataset=... --output=...` against a dev-seeded + indexed corpus; compared to a stored reference with per-metric tolerances (not exact-match). Not a CI gate.
- **Phase 2** — per-strategy breakdown (surface `score`/`strategy`, compare keyword/vector/hybrid/reranked).
- **Phase 3** — metadata filtering and graph→retrieval, each measured against this baseline.

## RAG docs

Update `Modules/AI/docs/rag/` (or the AI README) to document the new evaluation metrics and the two committed baselines when Task 4 completes — the evaluation harness is developer-facing behaviour worth documenting.

## Delivery status (2026-09-15): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md` (section "Application content evaluation").

All 4 tasks verified against the code: the `@k` metrics in `ApplicationContentEvaluationService`, the regenerated CMS baseline, the SAO dataset with its gate test, and the command test asserting the new keys.
