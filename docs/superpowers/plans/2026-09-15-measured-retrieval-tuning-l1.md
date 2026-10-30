---
status: completed
created_on: 2026-09-15
---
# Measured Retrieval Tuning (L1) — Implementation Plan

> **Moving on delivery.** This subject names backend files only, so this document and its counterpart belong in `laraplate/docs/superpowers/`. Keep working here: the move happens once the work is done, links and indexes repaired on both sides, so no reference is lost mid-flight. See `Where specs and plans live` in the stack `AGENTS.md`.

> **For agentic workers:** implement task by task, in order. Every task is test-first: write the
> failing test, run it, implement, run it green, `vendor/bin/pint --dirty`, commit inside the
> owning submodule.

**Goal:** make ranking parameters come from a committed, measured profile selected per query class,
switchable from Settings, with L0 as the exact fallback.

**Architecture:** a post-planner step in `AdvancedSearchService` merges a config profile into
`plan.ensemble` and `plan.ranking`; the fusion math is extracted so an offline tuner can re-fuse
recorded per-strategy orderings without re-querying the engine.

**Tech Stack:** PHP 8.5, Laravel 12, nwidart modules, Pest.

**Spec:** `docs/superpowers/specs/2026-09-15-measured-retrieval-tuning-l1-design.md` (this repository)

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`; braces always; explicit parameter and
  return types; PHPDoc over inline comments; English identifiers and comments.
- `Modules/{Core,AI,CMS,SAO}` are independent submodules on `master`: commit each task inside its
  own submodule. The spec and this plan live in the stack-root repo.
- **Tuning off must be byte-identical to today.** Any task that changes an emitted plan or a score
  while the setting is false is wrong, and Task 4's equality test exists to catch exactly that.
- The tuner writes its own report and nothing else. It never edits `config/search_tuning.php`.
- Run the narrowest tests: `php artisan test --compact <path>`; `vendor/bin/pint --dirty` before
  finishing each task.

---

### Task 1: `QueryClass` enum and derivation

**Files:**
- Create: `Modules/Core/app/Search/Enums/QueryClass.php`
- Modify: `Modules/Core/app/Search/Services/SearchQueryAnalyzer.php` (expose the derivation, or add
  `QueryClass::fromAnalysis(SearchQueryAnalysis $analysis)` reading the existing counts)
- Test: `Modules/Core/tests/Unit/Search/QueryClassTest.php`

**Interfaces:**
- Consumes: `SearchQueryAnalysis` (meaningful token count, protected token count, stopword signal).
- Produces: `QueryClass::{Identifier, ShortKeyword, MultiTerm, NaturalLanguage}`.

- [x] **Step 1: Failing test.** One case per class, using the analyzer on real strings:
      `INV-1042 fattura` → `Identifier`; `Mario Rossi` → `ShortKeyword`;
      `fatture fornitori scadute` → `MultiTerm`;
      `come faccio ad annullare una fattura già inviata` → `NaturalLanguage`.
      Add the precedence case: a protected token wins even in a long query.
- [x] **Step 2: Run, confirm fail** (enum absent).
- [x] **Step 3: Implement** the enum with `fromAnalysis()`, in the precedence order of the spec.
      Do not add a second tokenizer: read what the analyzer already produced.
- [x] **Step 4: Run, confirm pass.**
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 2: `search_adaptive_tuning` setting

**Files:**
- Modify: `Modules/Core/database/seeders/CoreDatabaseSeeder.php` (seed the row, group `search`)
- Test: `Modules/Core/tests/Feature/Settings/SearchTuningSettingTest.php`

**Interfaces:**
- Produces: a `Setting` row `{name: 'search_adaptive_tuning', value: false, type: Boolean,
  group_name: 'search'}`, read via `PerModelSettingResolver::boolean('search_adaptive_tuning', false)`.

- [x] **Step 1: Failing test.** After seeding, the resolver returns `false`; after flipping the row
      and clearing the group cache, it returns `true`.
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement** the seeder entry following the existing `seedSoftDeletedModel()` shape
      (name, value, encrypted false, choices null, `SettingTypeEnum::Boolean`, group, description).
      Diverged: seeded as runtime setting `search.adaptive_tuning` in `runtimeSettingDefinitions()`,
      read as `config('core.search.adaptive_tuning')` through the settings overlay (Core c3bfeec4 rule).
- [x] **Step 4: Run, confirm pass**, and confirm the seeder suite is still green.
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 3: Profile config and `RetrievalTuningProfile`

**Files:**
- Create: `Modules/Core/config/search_tuning.php`
- Create: `Modules/Core/app/Search/Services/RetrievalTuningProfile.php`
- Test: `Modules/Core/tests/Unit/Search/RetrievalTuningProfileTest.php`

**Interfaces:**
- Consumes: `config('search_tuning')`, `QueryClass`, the setting from Task 2.
- Produces: `apply(array $plan, QueryClass $class): array` returning the plan with `ensemble` and
  `ranking` merged, plus `meta.tuning = {applied, profile_version, query_class}`.

- [x] **Step 1: Failing test.** Cover: setting off → returned plan `===` input plan; setting on →
      only `ensemble`, `ranking` and `meta.tuning` differ, `retrieval` untouched; unknown class →
      `default` entry; invalid profile (weight 1.7) → input plan returned, warning logged once;
      missing config → input plan returned.
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement.** The config ships with the L0 values in `default` so the first commit
      is a no-op by construction; per-class entries start empty and are filled by Task 7's report.
      Validation exactly as the spec lists. Keep the class `final readonly` with the resolver and
      a config repository injected.
- [x] **Step 4: Run, confirm pass.**
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 4: Wire into `AdvancedSearchService`

**Files:**
- Modify: `Modules/Core/app/Search/Services/AdvancedSearchService.php` (after
  `applyEngineCapabilities()`, before `resolveVector()`)
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (carry `plan.meta.tuning`
  into `AdvancedSearchResult->meta['tuning']`)
- Test: `Modules/Core/tests/Integration/Search/AdvancedSearchTuningTest.php`

**Interfaces:**
- Produces: `AdvancedSearchResult->meta['tuning']`.

- [x] **Step 1: Failing test.** With the setting off, assert the plan handed to a spy
      `EnsembleSearchService` is **equal to** the plan `FallbackSearchPlanner` produced (the
      anti-drift assertion). With it on, assert the ensemble weights match the profile and that
      `meta['tuning']['query_class']` is the expected class.
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement** the single call site plus the meta passthrough.
      The text-match resolution moved before `resolveVector()` so the class is derived from the
      analysis it already carries (`ResolvedTextMatch->analysis`), with no second analyzer pass.
- [x] **Step 4: Run, confirm pass**, and run the whole `tests/Integration/Search` directory: no
      existing assertion may change.
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 5: Extract `RankFusion` (behaviour-preserving)

**Files:**
- Create: `Modules/Core/app/Search/Services/RankFusion.php`
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (delegate
  `fuseStrategies()`, `minMaxNormalizeScores()`, `renormalizeWeightsForExecutedStrategies()`)
- Test: `Modules/Core/tests/Unit/Search/RankFusionTest.php`

**Interfaces:**
- Produces: `RankFusion::fuse(array $perStrategy, array $weights, float $agreementBoost, int $rrfK,
  float $rrfWeight): list<array{id, score, ...}>` — pure, no model, no engine, no container.

- [x] **Step 1: Failing test.** Hand-computed fusion for two strategies and three ids, including
      the agreement bonus and the weight renormalization when one strategy is absent.
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement** by lifting the existing methods verbatim. No formula change.
- [x] **Step 4: Run** the new test **and** the full `EnsembleSearchServiceTest`: every pre-existing
      assertion must pass untouched. Then run both application-content baseline gates: the
      artifacts must stay byte-identical. Added before the refactor: two characterization tests
      pinning the exact fused and reranked order and scores over a three-strategy fixture
      (`FusionFixtureSearchModel`), since the baseline gates run on the `collection` Scout driver
      and so exercise the lexical fallback, not fusion.
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 6: Configurable rerank blend, and remove the dead key

**Files:**
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (`rerankTopK()` reads
  `ranking.rerank_blend`, default `config('search.reranker.weight')`, itself defaulting to 0.60)
- Modify: `Modules/Core/config/search.php` (document `reranker.weight` as the blend; delete
  `features.ensemble`)
- Modify: `Modules/Core/README.md` (env block: `SEARCH_RERANKER_WEIGHT` becomes real, the commented
  `SEARCH_ENSEMBLE_ENABLED` line goes away)
- Test: `Modules/Core/tests/Integration/Search/EnsembleSearchServiceTest.php` (extend)

**Interfaces:**
- Behaviour: blend `score = fused * (1 - blend) + rerank * max * blend`, where `blend` defaults to
  `0.60`, reproducing today's hardcoded `0.4 / 0.6` exactly.

- [x] **Step 1: Failing test.** Default blend reproduces the current scores (assert against the
      existing expected values); an explicit `ranking.rerank_blend = 0.0` returns the fused order.
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement**, and delete `search.features.ensemble` together with every mention of
      `SEARCH_ENSEMBLE_ENABLED` (config, README, `SEARCH_MATCHING_DEVELOPER.md`,
      `SEARCH_RETRIEVAL_PIPELINE.md`). Diverged: the default is the seeded runtime setting
      `search.reranker.weight` (Float 0.6, read as `config('core.search.reranker.weight')`), not an
      env var; `features.ensemble` / `reranker.weight` had already left `config/search.php` and the
      README in Core c3bfeec4, so only the stack explainer mention remained (fixed in Task 9).
- [x] **Step 4: Run, confirm pass**; re-run both baseline gates, byte-identical.
- [x] **Step 5: Pint + commit** in `Modules/Core`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 7: `test:ai:tune-retrieval`

**Files:**
- Create: `Modules/AI/app/Console/TuneRetrievalCommand.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/RetrievalTuningService.php`
- Test: `Modules/AI/tests/Feature/TuneRetrievalCommandTest.php`
- Test: `Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/RetrievalTuningServiceTest.php`

**Interfaces:**
- Consumes: `PerStrategyEngineRetrieverInterface` (existing seam), the curated datasets,
  `RankFusion` from Task 5, `IrMetrics` from R3 Phase 2.
- Produces: a JSON report `{version, source, dataset, metric, candidates: [{params, metrics,
  per_class_metrics, delta_vs_committed}], winner}` plus a printed PHP profile block.

- [x] **Step 1: Failing unit test** for `RetrievalTuningService`: given canned per-strategy
      orderings for three cases and a two-point grid, assert the candidate ranking by `ndcg_at_5`
      and that each case was retrieved **once** (the grid re-fuses, it does not re-query).
- [x] **Step 2: Run, confirm fail.**
- [x] **Step 3: Implement the service.** Per case: retrieve once (reranker off) to get
      `meta['per_strategy']`; for every candidate parameter set call `RankFusion::fuse()`, then
      `IrMetrics::atK()` against `expected_hit_ids`; aggregate overall and per `QueryClass`.
      Reranking is scored separately with one reranker-on pass, since its blend cannot be
      re-computed offline from fused scores alone. Candidates are partial fusion parameter sets
      (only `ensemble.*`; ranking keys are rejected as not replayable) merged over the L0
      planner values per case; the committed baseline is the same merge with the committed
      profile. Query class comes from `TextMatchOptionsResolver` analysis, as at runtime.
- [x] **Step 4: Failing feature test** for the command: invalid `--source` fails; a run writes the
      report and prints a profile block; assert no other file was touched.
- [x] **Step 5: Implement the command** — `test:ai:tune-retrieval {--source=} {--dataset=} {--grid=}
      {--metric=ndcg_at_5} {--output=} {--force}`, mirroring `EvaluateApplicationContentCommand`
      (strict dataset load, atomic write, `JSON_PRESERVE_ZERO_FRACTION`, no overwrite without
      `--force`).
- [x] **Step 6: Run both tests, confirm pass.**
- [x] **Step 7: Pint + commit** in `Modules/AI`. (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 8: Profile regression gate

**Files:**
- Modify: `Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php`
- Modify: `Modules/SAO/tests/Feature/ApplicationContent/SaoApplicationContentEvaluationBaselineTest.php`
  (the gate was first planned as a new AI feature test; see the delivery status)
- Test: reuses `Modules/CMS/tests/Fixtures/application-content/cms-contents.json` and the SAO fixture

**Interfaces:**
- Asserts: with the setting **on** and the committed profile applied, the deterministic fixture
  evaluation is **not worse** than the committed baseline on `ndcg_at_5` and `recall_at_5`, per
  source, within a stated tolerance; and with the setting **off**, the baseline artifacts stay
  byte-identical.

- [x] **Step 1: Write the gate** driving the same seam the baseline tests use. Diverged: written
      as a second case inside each baseline test (`CmsApplicationContentEvaluationBaselineTest`,
      `SaoApplicationContentEvaluationBaselineTest`), sharing their corpus seeding, instead of an
      AI test depending on CMS and SAO test helpers. Tolerance: one rounding unit (0.0001).
      Honest limit: the suite runs Scout on `collection`, so both providers answer through their
      lexical fallback and no profile can move these numbers in CI; the gate guards the wiring,
      `test:ai:tune-retrieval` on a real engine is the measurement.
- [x] **Step 2: Run it** against the shipped L0-equivalent profile: it must pass as a no-op.
- [x] **Step 3: Document the regeneration procedure** in the test docblock, mirroring
      `APP_CONTENT_BASELINE_REGEN`.
- [x] **Step 4: Pint + commit** in `Modules/CMS` and `Modules/SAO` (where the gate lives). (Pint run on the explicit file list; the commit is made by the session that owns git.)

---

### Task 9: Documentation (RAG docs are part of the feature)

**Files:**
- Modify: `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` (tuning step, `meta['tuning']`,
  updated configuration reality-check table)
- Modify: `Modules/Core/docs/rag/SEARCH_MATCHING_USER.md` (one line: tuning changes fusion, not the
  `matching` preference)
- Modify: `Modules/Core/README.md` (the new setting and the `search` settings group)
- Modify: `Modules/AI/docs/rag/MODULE.md` (`test:ai:tune-retrieval` next to the evaluate commands)
- Modify: `docs/ricerca-spiegazione-semplice.md` (L0/L1 section: what the switch now does)

- [x] **Step 1: Update each file**, keeping the existing voice (English in the module docs, Italian
      in the stack explainer). Also `SEARCH_MATCHING_DEVELOPER.md` (the hardcoded-blend sentence) and
      the explainer's "dead knobs" table (both rows removed: one key is gone, the other is live).
- [-] **Step 2: Re-run** `php artisan ai:index-rag-docs` so the assistant corpus picks up the
      changes. Needs live Elasticsearch and embeddings: run manually after deploy.
- [x] **Step 3: Commit** in each owning submodule, then update this plan's front-matter to
      `status: completed`. (Front matter updated; the commits are made by the session that owns git.)

---

## Post-implementation: the actual tuning run (manual, not a code task)

The tasks above ship the mechanism with an L0-equivalent profile. Producing the real numbers is a
separate, deliberate act:

1. Seed and index the dev corpus, with Elasticsearch and embeddings available.
2. `php artisan test:ai:tune-retrieval --source=cms.contents --dataset=... --output=...`, same for
   `sao.tickets`.
3. Read the per-class table. Accept a candidate only if it wins **both** overall and on the class it
   targets; a candidate that trades one class against the total is overfitting.
4. Paste the winning profile into `config/search_tuning.php`, bump `version`, cite the report path.
5. Re-run Task 8's gate, then flip `search_adaptive_tuning` on in an environment and watch
   `meta['tuning']` on real responses before enabling it more widely.

## Self-Review notes

- The switch is reversible without a deploy, which is the point of using Settings.
- The anti-drift assertion in Task 4 is the load-bearing test of the whole plan: it is what lets
  this ship without risking the current behaviour.
- Task 5 is the only refactor of code that is currently working and measured; it is behaviour
  preserving by construction and guarded by two baseline gates.
- Nothing here reads user behaviour. L2 stays blocked on telemetry that does not exist yet, and the
  spec says so explicitly so the next reader does not mistake L1 for learning.

## Delivery status (2026-10-01): shipped, profile not yet measured

**Documented in:** `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md` (tuning step, query classes, profile rules, `meta['tuning']`, rerank blend, configuration table), `Modules/Core/docs/rag/SEARCH_MATCHING_USER.md` and `Modules/Core/docs/rag/SEARCH_MATCHING_DEVELOPER.md` (blend setting, tuning vs `matching`), `Modules/Core/README.md` (the `search` settings group and the retrieval tuning profile), `Modules/AI/docs/rag/MODULE.md` (`test:ai:tune-retrieval`), and the stack explainer `docs/ricerca-spiegazione-semplice.md`.

The mechanism ships with an L0-equivalent profile; the measured tuning run is manual (see "Post-implementation" above) and needs Elasticsearch plus embeddings. Divergences from the steps, all deliberate:

- **Settings, not env vars or `PerModelSettingResolver`.** Since Core c3bfeec4 switches and tuning values are seeded runtime settings read through the config overlay. The switch is `search.adaptive_tuning` (Boolean, off, group `search`, read as `config('core.search.adaptive_tuning')`), not `search_adaptive_tuning`; the rerank blend default is the new setting `search.reranker.weight` (Float `0.6`, read as `config('core.search.reranker.weight')`), not `config('search.reranker.weight')` / `SEARCH_RERANKER_WEIGHT`. `features.ensemble` and `reranker.weight` had already left `config/search.php` and the README, so Task 6 only had the stack explainer mention left to remove.
- **Profile semantics: partial sets over the planner.** A parameter the profile omits keeps the planner value. The spec's `default` block restating 0.35/0.35/0.30 would not be a no-op (L0 uses 0.30/0.40/0.30 for short queries, and settings own `rerank_top_k` and the blend), so the shipped profile carries only the constant L0 values (`rrf_k` 60, `rrf_weight` 0.25, `agreement_boost` 0.15) and empty class sets: switched on, it changes only `meta['tuning']`. Validation also rejects unknown parameters and unknown classes.
- **Query classes.** `identifier` means a significant numeric, UUID, email or structured-identifier token; short words and acronyms (protected from fuzziness by text matching) do not count, or `ad` in a sentence would make it an identifier. Stopwords are derived as tokens minus significant tokens. With at most two significant tokens a query is `short_keyword` whatever its stopwords (table precedence).
- **One analysis.** `AdvancedSearchService` resolves the text match before `resolveVector()` and classifies from `ResolvedTextMatch->analysis`; `RetrievalTuningProfile::apply()` takes the `QueryClass`, as the plan's interface says, rather than the analysis the spec sketched.
- **Task 5 proof.** `RankFusion` has static methods (pure math, like `IrMetrics`) plus `fuseExecuted()` for recorded rankings. Byte-identity is proven by characterization tests written before the refactor over a three-strategy fixture (`FusionFixtureSearchModel`), because the CMS/SAO baseline gates run Scout on `collection` and so never reach fusion.
- **Tuner.** Candidates are fusion-only parameter sets merged over each case's L0 planner values; ranking keys are rejected because the blend cannot be replayed offline, and the reranked ordering is reported from one reranker-on pass. The report adds `class_winners`, which the printed block uses for per-class entries.
- **Task 8 location.** The gate is a second case in each existing baseline test (CMS and SAO), not `Modules/AI/tests/Feature/RetrievalTuningProfileGateTest.php`, to reuse their corpus seeding without an AI test depending on CMS/SAO helpers. In CI it guards the wiring only: the lexical fallback cannot be moved by a profile.
- **Overfitting safeguards, added after review (2026-10-01).** The tuner scored and chose on the same cases, and the gate described in the spec cannot reject a one-class win (it only checks wiring on the `collection` driver). `test:ai:tune-retrieval` now takes `--holdout` (0.3), `--min-class-cases` (8) and `--class-margin` (0.01), through `RetrievalTuningSafeguards`; the report carries `validation`, `train_case_count`, `holdout_case_count`, `class_winners_skipped` and `safeguards`. The service keeps the old behaviour with its own defaults, so the first run of the tuner needs no new arguments in tests.
- **Noise margin and cited report, added 2026-10-01.** A tuned profile can still be a coin flip: with a few dozen cases, one flipped case moves the metric by more than most grid differences. `test:ai:tune-retrieval` takes `--noise-margin` (`auto` is `1 / selection cases` with a floor of 0.01; `0` turns it off), through `RetrievalTuningSafeguards::tolerance()`: the winner must beat the committed profile on the selection cases by more than the margin, or `winner` is `null` (`noise.status` `within_noise`) and a class override must clear the same margin sized on its own cases (`class_winners_skipped` reason `within_noise`). The report carries a `noise` block. The committed profile gets a `report` key: a profile with measured values must cite a report under `Modules/Core/docs/evaluations/retrieval-tuning/` that passed the validation and the noise check and holds its values, enforced by `Modules/Core/tests/Unit/Search/CommittedRetrievalTuningProfileTest.php` (the L0 profile is exempt, the runtime ignores the key). The check lives in `Modules/Core/tests/Support/RetrievalTuningProfileAudit.php`, not in `app/`, because nothing at runtime needs it.
- **Private datasets, evaluation locale and report context, added 2026-10-02.** The loader accepted only `synthetic` datasets, so a measure on the real corpus had nowhere to live: `data_classification: private` is now accepted and `fromFile()` refuses such a file inside the project directory. A pilot of 63 private queries then showed that the evaluation ran every case under the application locale: English queries scored 0 on every strategy because the model's `LocaleScope` hid the English-only rows, so `PerStrategyEngineRetriever` now sets the case's locale around the search (the interface gains an optional `$locale`). The report records the context it was measured in: the active embedding profile (`embedding`), the size of the indexed corpus (`corpus.size`) and the fingerprint of the dataset (`dataset.sha256`).
- **Language restriction inside the engine request, added 2026-10-02.** The pilot also showed that the language the results are requested in was applied after retrieval, by the model's `LocaleScope` when the hits were loaded, with `k` equal to the limit: in 20 of 63 cases fewer than five of the five nearest documents existed in that language, so a request for five results returned one to four. `EnsembleSearchService::applyRequestedLocale()` now adds a `locales` restriction (from the locale context) for engines implementing `ILocaleFilterableEngine` whose index has the field, and `ElasticsearchEngine` applies it to the nearest-vector search, the keyword search and the text half of a hybrid search, matching the text on the requested language's fields only (`ElasticsearchLocaleTextFields`). Every pilot list is now full (61 of 61). The ranking metrics barely move (fused `ndcg@5` 0.939 to 0.930): the expected ids were in the requested language already, so a fuller list cannot score better, and the one regression is a document that used to match through its English title. The same change gives the text half of a hybrid search the filters the vectors already had (the caller's, not only the language): it carried none, so a document that matched the text but not the filters could come back through the hybrid strategy. Because the generic CRUD search reloaded the hits without the user's ACL row filters (only `Media` re-authorized), that gap reached the user for every other model, so `CrudService` now reloads the hits of both search paths under the ACL filters too (`searchHitsQuery()`), as a second barrier.
- **The reranker is reported, not assumed, added 2026-10-02.** The two evaluation commands ask for the reranked ordering but read only `ids()`, and a reranker that is down leaves the fused order with `meta['reranked'] = false`: the pilot's `reranked` figures were the `fused` ones, and `CrossEncoderService` turned a malformed answer into zeros reported as a successful rerank. Both reports now carry `reranker: {requested, ran, status}` (`RerankerRun`), both commands warn when it is not `ran`, and `CrossEncoderService` throws on an error status or an answer that is not one numeric score per pair, so the failure takes the path `EnsembleSearchService` already handles. `metrics.reranked` stays in the report for compatibility; it is only meaningful when the status is `ran`. Whether a down service should also cost the 10 s timeout on every search is not decided here.
- **The reranker read no text, found 2026-10-05.** With the cross-encoder service up and `reranker.status` at `ran` for 61 of 61 cases, `reranked` was still identical to `fused` on every metric. The probe showed why: every pair reached the reranker with an empty text. A hit carries the model's own columns (`getAttributes()`), and the text of a `Content` lives in its translations; `buildRerankerText()` also read only scalar `title`/`content` fields, which the per-language objects are not. For CMS contents every rerank, cross-encoder or heuristic, had been a no-op, hidden by `meta['reranked'] = true`. Fixed with `IProvidesRerankerText` (Core), implemented by `Content` (CMS), plus a failure when no pair has any text. A test of `EnsembleSearchService` that asserted `reranked === true` on hits with no text had been passing vacuously; its hits now have a title. First real measure on the 61-case private pilot with `cross-encoder/mmarco-mMiniLMv2-L12-H384-v1`: nDCG@5 0.9296 fused against 0.9288 reranked, nDCG@3 0.9202 against 0.9256, hit@5 and precision@1 unchanged, about 5 s more per search on the CPU server: no gain at this scale.
- **First tuning run, 2026-10-05: no winner.** `test:ai:tune-retrieval` on the 61-case private pilot (39 selection cases, 22 held out; 3 identifier, 23 short keyword, 22 multi term, 13 natural language) over the 108-candidate default grid. The best candidate scored nDCG@5 0.9023 against 0.8827 for the committed profile on the selection cases, a gain of 0.0196 against a noise margin of 0.0256, and the held-out validation passed with no difference: within the noise, so the committed profile stays and `config/search_tuning.php` is unchanged. At this size and with `fused` already at 0.93 nDCG@5 and 0.918 precision@1, the grid has no headroom to find; a larger or harder dataset is the way to a measured profile.
- **`reranker.model`, 2026-10-05.** The service answers with the model that scored the pairs; `IRerankerWithModel` (Core) lets a reranker hand it back with the scores, `EnsembleSearchService` puts it in `meta['reranker_model']`, and both reports carry it as `reranker.model`, so a report says which model it measured.
- **Not done:** `ai:index-rag-docs` (needs live Elasticsearch, run manually); a measured `config/search_tuning.php` (the first run found no winner above the noise margin).
- This plan and its spec already live in `laraplate/docs/superpowers/`, so the move announced at the top was not needed.

