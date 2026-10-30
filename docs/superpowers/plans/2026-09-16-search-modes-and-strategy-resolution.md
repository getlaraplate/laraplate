---
status: completed
---
# Search Modes and Strategy Resolution Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development (or executing-plans). Steps use checkbox (`- [ ]`) tracking.

**Goal:** Make the cost of a search a per-request decision by its caller instead of a global boolean decided at boot. `fast` is the default and executes Core's own cheap path; `balanced` adds the query embedding and nothing else; `deep` buys LLM planning, embedding, reranking and optional retries. Anything that prevents the requested mode degrades to the next cheaper one that works, with a stated reason, rather than failing.

**Architecture:** Core gains `ISearchStrategyResolver` and a `SearchStrategy` value object, plus a resolver that ignores the mode and always returns its cheap implementations. AI replaces that one resolver and stops overriding `ISearchPlanner`, `IReranker`, `IQueryIntentParser` and `ITextEmbedder` globally. `AdvancedSearchService` takes the resolver rather than the components and asks per call.

**Tech Stack:** PHP 8.5, Laravel 12, Pest. No new dependencies.

**Spec:** `docs/superpowers/specs/2026-09-16-search-modes-and-strategy-resolution-design.md`

## Global Constraints

- Every PHP file `declare(strict_types=1);`; braces everywhere; explicit parameter and return types; `final` and `readonly` where the surrounding code uses them; constructor property promotion; `#[Override]` when overriding.
- **Core must never name an AI class.** The whole design fails the moment it does. Core knows one contract and its own implementations; who else implements it is the container's business.
- **A search never errors because a model was unavailable.** Every failure of the expensive path produces cheap results plus a `degraded_reason`. A test that asserts an exception reaching the caller is asserting a bug.
- `config()` outside config files, never `env()`. Caps that an operator should change at runtime go in Settings, not config.
- Tests: Pest, in the module that owns the code. Run `vendor/bin/pint --dirty --format agent` before finalising. Run the narrow set with `php artisan test --compact <path>`.
- Commits go inside the submodule that owns the change (`Modules/Core`, `Modules/AI`), never mixed.

**Verified touch points (read before coding):**
- `Modules/Core/app/Search/Services/AdvancedSearchService.php:40` — `search()`, whose first four statements are the four costly stages.
- `Modules/Core/app/Providers/SearchServiceProvider.php:51-54` — the `singletonIf` defaults (`HeuristicReranker`, `FallbackSearchPlanner`, `SimpleQueryIntentParser`). No Core default exists for `ITextEmbedder`.
- `Modules/AI/app/Providers/AIServiceProvider.php:110-118` — `registerSearchBindings()`, the four global overrides this plan removes.
- `Modules/Core/app/Services/Crud/CrudService.php:111` — `AdvancedSearchService` is an optional constructor dependency.
- `Modules/AI/app/Actions/IntelligentSearchAction.php` — `evaluateResults()` and `shouldRetry()` are what gets salvaged; the rest is retired.

---

### Task 0: measure, before writing any of the rest

**This is a gate, not a formality.** The design stands either way, but the number of modes depends on the numbers.

- [x] **Step 1:** Instrument the four stages of `AdvancedSearchService::search()` with timings, emitted into the existing search meta behind a debug flag: intent parse, plan, vector, ensemble plus rerank.
  - **Built (2026-10-08):** runtime setting `search.debug_timings` (config `core.search.debug_timings`, seeded off, group `search`, in `CoreDatabaseSeeder::runtimeSettingDefinitions()`). When on, `AdvancedSearchService::search()` adds `meta['timings'] = {intent_ms, plan_ms, vector_ms, ensemble_ms, total_ms}` measured with `hrtime()` by `Modules/Core/app/Search/Support/SearchStageTimings.php`; off, the meta is unchanged and no timings object is built. `plan_ms` is `safePlan()` alone; `vector_ms` is `null` when no embedding ran; `ensemble_ms` includes reranking (read `meta['reranked']`); a stage that throws is rethrown unchanged and the partial timings go to an `info` log line. Tests: `Modules/Core/tests/Integration/Search/AdvancedSearchTimingsTest.php`. How to measure: `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, *Measuring the stages* (a `tinker --execute` loop, cold run plus warm median, with `AI_SEARCH_ORCHESTRATION_ENABLED` true then false).
  - **For Step 2:** `perf:crud` does not exercise search at all (it benchmarks `/api/v1/select/...` without `qs`), and no perf command prints the response body; the HTTP response does not serialize the search meta either (`CrudController::buildResponse()` ignores `CrudMeta->search`). The per-stage numbers therefore come from the tinker loop; `test:perf:bench` or `perf:profile` on `GET:/api/v1/search/{module}/{entity}?qs=...&mode=orchestrated` give end-to-end latency only. Table template for the spec's *Measurements* section:

    | Date | Machine (CPU, RAM, engine and version) | Entity | Query | AI overlay | Run | intent_ms | plan_ms | vector_ms | ensemble_ms (reranked?) | total_ms |
    |------|----------------------------------------|--------|-------|------------|-----|-----------|---------|-----------|-------------------------|----------|
    |      |                                        |        |       | on         | cold |          |         |           |                         |          |
    |      |                                        |        |       | on         | warm median of 10 | |   |           |                         |          |
    |      |                                        |        |       | off        | cold |          |         | null      |                         |          |
    |      |                                        |        |       | off        | warm median of 10 | |   | null      |                         |          |
- [x] **Step 2:** Run `php artisan perf:crud` for a representative entity with the AI overlay on and off, and `php artisan perf:profile` on a search endpoint. Record the four numbers and the totals in the spec, under a new *Measurements* section with the date and the machine.
  - **Done 2026-10-09:** measured stage by stage and recorded in the spec, *Measurements*. `perf:crud` was not run: it does not exercise search. The first attempt below ran without an LLM and was discarded.
  - **First attempt, discarded:** the tinker loop ran, but `OLLAMA_API_URL` is empty here, so planner and intent parser fell back to rules (intent about 6 ms, plan about 7 to 13 ms) and no embedder was bound (`vector_ms` null). Overlay on vs off differed only by about 10 ms warm, which says nothing about the LLM cost. Redo with a reachable Ollama.
- [x] **Step 3:** Decide from the numbers and write the decision in the spec:
  - if the cross-encoder dominates, evaluate a score cache first; it may recover most of the latency with none of this restructuring, in which case the rest of this plan is still worth doing but stops being urgent;
  - **Outcome (2026-10-09):** the LLM calls dominate (23 to 60 s each, timeouts at 60 s); the embedding is about 80 ms warm. Continue as planned, and a `balanced` mode is justified. Building it now or later is for the user to decide.
  - if the two LLM calls dominate, continue as planned, and record whether a third `balanced` mode (lexical plus vector, no LLM) should be built now rather than later, since that is what keeps semantic matching on the default path.

---

### Task 1: the contract and Core's resolver

**Files:**
- Create: `Modules/Core/app/Search/Contracts/ISearchStrategyResolver.php`
- Create: `Modules/Core/app/Search/DTOs/SearchStrategy.php`
- Create: `Modules/Core/app/Search/Services/CoreSearchStrategyResolver.php`
- Create: `Modules/Core/app/Search/Enums/SearchMode.php`
- Modify: `Modules/Core/app/Providers/SearchServiceProvider.php`
- Test: `Modules/Core/tests/Unit/Search/CoreSearchStrategyResolverTest.php`

- [x] **Step 1: `SearchMode`** — a backed string enum, `Fast = 'fast'`, `Balanced = 'balanced'` and `Deep = 'deep'`, with `tryFrom()` used at the HTTP boundary so an unknown value degrades to `Fast` rather than throwing. An enum rather than a bare string so a further mode is added in one place. `Balanced` was added on 2026-10-09 after the Task 0 numbers.

- [x] **Step 2: `SearchStrategy`** — `final readonly`, holding `applied_mode`, `planner`, `reranker`, `intent_parser`, nullable `embedder`, `max_retries`, nullable `degraded_reason`. One object, so an incoherent mixture cannot be constructed by accident, and so the caller has everything it needs to fill the response meta.

- [x] **Step 3: `ISearchStrategyResolver`** — one method, `resolve(SearchMode $mode): SearchStrategy`.

- [x] **Step 4: `CoreSearchStrategyResolver`** — takes Core's three implementations, returns them whatever it is asked for, `embedder: null`, `max_retries: 0`, and `degraded_reason: null` for `Fast` or `'mode_unavailable'` for anything else. Add a comment saying the mode is ignored deliberately: the next reader will otherwise assume it is a stub.

- [x] **Step 5: Register** with `singletonIf` alongside the existing three, so a module may substitute it.

- [x] **Step 6: Test** — asked for `Deep` with no AI installed, it returns Core's implementations, `applied_mode` `fast`, and `mode_unavailable`; asked for `Fast`, the same components and no reason.

- [x] **Step 7:** run the test (PASS), pint, commit in `Modules/Core`: `feat(search): strategy resolver contract with a cheap default`.

---

### Task 2: `AdvancedSearchService` asks per call

**Files:**
- Modify: `Modules/Core/app/Search/Services/AdvancedSearchService.php`
- Modify: `Modules/Core/app/Search/Services/EnsembleSearchService.php` (`search()` takes the reranker per call; the constructor one stays as default)
- Modify: `Modules/Core/app/Search/DTOs/AdvancedSearchResult.php` (meta)
- Test: `Modules/Core/tests/Unit/Search/AdvancedSearchServiceModeTest.php`

**This is the substantive refactor.** The parameter is the easy half: what matters is that the service stops receiving `ISearchPlanner`, `IReranker`, `IQueryIntentParser` and `ITextEmbedder` in its constructor. While it does, the expensive implementations are wired in before any request parameter can be read, and no per-request decision is possible.

- [x] **Step 1: Test first** — with a resolver double returning a cheap strategy for `Fast` and an expensive one for `Deep`, assert that `search(..., mode: Fast)` never touches the expensive doubles, and that the result meta carries `mode_requested`, `mode_applied`, `degraded_reason` and `retries_used`. The first assertion is the whole point of the task: write it and watch it fail.

- [x] **Step 2: Implement.** (Done 2026-10-09; `$retries` is deferred to Task 4, where it first has an effect. `meta['search']` is set by `AdvancedSearchService`, `AdvancedSearchResult` is unchanged.) Constructor takes `ISearchStrategyResolver`. `search()` gains `SearchMode $mode = SearchMode::Fast` and `?int $retries = null`, resolves the strategy first, and uses `$strategy->planner`, `$strategy->intent_parser`, `$strategy->reranker` and `$strategy->embedder` from there. When `$strategy->embedder` is null, skip the vector stage entirely rather than passing a null vector down.

- [x] **Step 3: Meta.** `AdvancedSearchResult` carries a `search` meta block with the four keys from the spec. It is populated on every path, including the unsupported-driver early return, so a client never has to guess.

- [x] **Step 4:** run the tests (PASS), pint, commit in `Modules/Core`: `feat(search): resolve the search strategy per request`.

---

### Task 3: AI decorates, and stops overriding

**Files:**
- Create: `Modules/AI/app/Search/AiSearchStrategyResolver.php`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php`
- Modify: `Modules/AI/app/Services/CrossEncoderService.php`, `Modules/AI/app/Services/SearchEmbedder.php`
- Test: `Modules/AI/tests/Integration/AiSearchStrategyResolverTest.php`

- [x] **Step 1: The decorator.** `AiSearchStrategyResolver` takes `CoreSearchStrategyResolver` as its fallback and delegates to it for anything that is not `Deep`, and for `Deep` when `ai_config_bool('ai.features.search_orchestration.enabled', true)` is false, with `degraded_reason: 'search_orchestration_disabled'`. `Balanced` takes Core's planner, intent parser and reranker plus the bound query embedder, `applied_mode: balanced`; if the embedder cannot be built it degrades to `Fast` with `embedder_unavailable`. Only for an enabled `Deep` does it build the expensive set.

- [x] **Step 2: Remove the four overrides** from `registerSearchBindings()` and register the resolver instead: `singleton(ISearchStrategyResolver::class, AiSearchStrategyResolver::class)`. **This is the line that stops every CRUD search paying for AI.** The expensive classes stay bound by their own names so the resolver can build them; what goes away is their substitution for the Core contracts.

- [x] **Step 3: Runtime degradation.** (Done 2026-10-09. The reranker already degraded: `EnsembleSearchService` catches a failing reranker and reports `reranked=false`. The embedder is now caught in `AdvancedSearchService::resolveVector()`, with a log line and no vector, since an `ITextEmbedder` cannot return null. The LLM calls get a timeout through `ChatAgent::withTimeout()`, `ai.features.search_orchestration.timeout`, env `AI_SEARCH_LLM_TIMEOUT`, default 10 s. The gap that a timed-out LLM call left `degraded_reason` null was closed on 2026-10-14 with `SearchDegradations`.) `ISearchPlanner::safePlan()` is already named for not exploding. Give the reranker and the embedder the same property: on transport failure, timeout or a non-2xx from the cross-encoder microservice, fall back to the cheap behaviour (identity ordering for the reranker, no vector for the embedder) and surface a reason rather than throwing. `AdvancedSearchService` must not learn that language models exist in order to defend itself against them.

- [x] **Step 4: Test** — `Balanced` embeds and never calls an LLM; `Balanced` with an embedder that throws degrades to `Fast`; `Fast` with AI installed returns Core's components, and asserts that the AI doubles were never constructed; `Deep` with the switch off degrades with the right reason; `Deep` with a cross-encoder that throws still returns results, with `degraded_reason` set.

- [x] **Step 5:** run the tests (PASS), pint, commit in `Modules/AI`: `feat(ai): overlay search through one strategy resolver`.

---

### Task 4: retries, and retiring `IntelligentSearchAction`

**Found on 2026-09-17, while triaging PHPStan: the class is already broken, and has
been for months.** `runSearchPipeline()` calls `EnsembleSearchService::search()`
against a signature that no longer exists:

```php
// IntelligentSearchAction.php:93
return $this->ensemble->search($intent, $vector, $final_query, $plan, $index);

// EnsembleSearchService::search() as it is today
search(Model $model, string $query, array $plan, ?array $vector,
       int $page, int $perPage, …): AdvancedSearchResult
```

All five arguments are of the wrong type, two required parameters are missing, and
the return value is an object the caller indexes as `array{results, meta}`. With
`declare(strict_types=1)` that is a TypeError on the first line executed. It never
fires because nothing calls it: the class is referenced only by its own two tests,
and both assert `toBeInstanceOf` and nothing else.

This does not change the plan, it confirms it. Do not repair the call as part of
Task 4 — salvage `evaluateResults()` and `shouldRetry()` as Step 1 says, then delete
the rest. It does mean the class cannot be trusted as a reference for how search is
meant to work: read `AdvancedSearchService`, not this.

**Files:**
- Modify: `Modules/Core/app/Search/Services/AdvancedSearchService.php`
- Create: `Modules/Core/app/Search/Services/SearchQualityEvaluator.php`
- Delete: `Modules/AI/app/Actions/IntelligentSearchAction.php` and `Modules/AI/tests/Integration/IntelligentSearchActionTest.php`
- Delete: `Modules/Core/app/Search/Ai/SentenceTransformersEmbeddingGenerator.php` and `Modules/Core/app/Search/Ai/SentenceTransformersConfig.php`
- Modify: `phpstan.neon` (drop the exclusion those two files needed)
- Test: `Modules/Core/tests/Unit/Search/SearchRetryTest.php`

- [x] **Step 1: Salvage before deleting.** Port `evaluateResults()` and `shouldRetry()` out of `IntelligentSearchAction` into `SearchQualityEvaluator` in Core, keeping the behaviour and adding types. Read what the methods do; do not reimplement them from their names. This is the only part of that class worth keeping, and it is the part that makes a retry mean something instead of being a repeated identical query.
  Done 2026-10-07 by the AI module review (Task 10, Step 3): `SearchQualityEvaluator` with `evaluate()` and `shouldRetry()`, behaviour unchanged, tested in `SearchRetryTest`. `refinePlan()` was not salvaged; Step 2 decides what a re-plan is.

- [x] **Step 2: Drive retries from the strategy.** (Done 2026-10-09. `AdvancedSearchService::search()` takes `?int $retries`, capped by `SearchStrategy::max_retries`: 0 for `fast` and `balanced`, 2 for `deep`. A retry is `relaxedPlan()`: the candidate window doubles up to 200, the user's own words replace the intent expansion, and vector retrieval is switched on when the strategy has an embedder; the retry result replaces the first only if it has at least as many hits. Task 5 adds the Settings cap and the HTTP parameter.) When `$strategy->max_retries > 0` and the evaluator judges the results poor, re-plan and search again, up to the cap, recording `retries_used` in the meta. `Fast` has `max_retries: 0`, so the loop is not reachable there.

- [x] **Step 3: Delete the Action and its test.** Deleting a test file needs approval under AGENTS; it is granted here by the spec, which retires the class. Record in the commit message that `evaluateResults`/`shouldRetry` moved to `SearchQualityEvaluator` rather than being lost, and say why the rest went: pagination is the CRUD layer's, and its cache was keyed on query plus index with no authorization context, which would let two users with different ACL trade results.
  Done 2026-10-07: the class, its test, its entries in `phpstan-baseline.neon` and the empty `app/Actions` folder are gone; `evaluateResults`/`shouldRetry` live in `SearchQualityEvaluator`. Pagination is the CRUD layer's, and the cache of the old action was keyed on query plus index with no authorization context.

- [x] **Step 4:** run the tests (PASS), pint, commit in both submodules separately: `feat(search): quality-driven retries` in Core, `refactor(ai): retire IntelligentSearchAction` in AI.

- [x] **Step 5: delete the orphaned SentenceTransformers pair in Core.** Same area, same
  kind of leftover, found on 2026-09-17.

  `Modules/Core/app/Search/Ai/SentenceTransformersEmbeddingGenerator.php` implements
  `LLPhant\Embeddings\EmbeddingGenerator\EmbeddingGeneratorInterface`. **LLPhant is not a
  dependency of this application** — there is no llphant entry in `composer.json` and the
  interface is not autoloadable — so the class cannot even be loaded. Nothing references it.
  `SentenceTransformersConfig.php`, in the same directory, is used by that class and by
  nothing else, so it goes with it.

  This is not the provider the application uses: embeddings run through
  `Modules\AI\Ai\Embeddings\SentenceTransformersEmbeddingsProvider`, built by
  `EmbeddingsProviderFactory` from `ai.features.embeddings.default_provider`. The Core pair
  is a leftover from a dependency that went away, and keeping it invites someone to wire
  the wrong one.

  When they are deleted, remove the matching exclusion from `phpstan.neon` (it is commented
  there with this reason): `interface.notFound` is not ignorable, so the file had to leave
  the analysis until it leaves the repository.

---

### Task 5: the HTTP surface, with its cost controls

**Files:**
- Modify: the search Form Request and the CRUD search controller path
- Modify: `Modules/Core/app/Services/Crud/CrudService.php`
- Create: `Modules/Core/app/Search/Services/SearchCostGuard.php` (retry cap and the per-person deep rate limit)
- Test: `Modules/Core/tests/Feature/Search/SearchModeRequestTest.php`

- [x] **Step 1: Parameters.** **`cost`, not `mode` or `depth`** (decided 2026-10-12): `mode` already exists on `SearchRequest` and picks the path (`auto|basic|orchestrated`), and `depth` is the traversal depth of the graph search, so the new parameter is `cost=fast|balanced|deep`, read through `SearchMode::tryFrom()` and defaulting to `Fast` when absent or unknown. `retry` an integer, clamped to the maximum held in Settings, `0` when absent. Both travel to `AdvancedSearchService` as **explicit named parameters** (`mode:`, `retries:`), not inside an options bag: a value that changes latency and cost by an order of magnitude should be visible in every signature it passes through. `meta.search` is now also serialized in the HTTP response (`ResponseBuilder::setSearchMeta()`), the only part of the engine meta that is.

- [x] **Step 2: The cap in Settings.** Setting `search.max_retries` (config `core.search.max_retries`, default 2, seeded in `CoreDatabaseSeeder`), read at request time through `SearchCostGuard::clampRetries()`.

- [-] **Step 3: Permission.** Dropped on 2026-10-12 by the user: no permission for `deep`, only the rate limiter of Step 4. The `not_authorized` reason is not built. Revisit if `deep` is ever offered to anonymous callers or priced per role.

- [x] **Step 4: Rate limiter.** `SearchCostGuard::allowsDeep()` counts deep searches per person (`user:{id}`, else `ip:{address}`) per minute, limit `search.deep_per_minute` (default 6, a Setting). Beyond it the request is served as `fast` with `degraded_reason: rate_limited`, through `AdvancedSearchService::search(withheld_reason: ...)`. It uses `RateLimiter::attempt()` rather than a named limiter on a route, because the limit applies to one value of a parameter, not to a route.

- [x] **Step 5: Test** — absent parameter yields `fast`; an unknown cost yields `fast` without an error; `retry` above the Settings cap is clamped, not rejected; the limiter degrades rather than refusing (`SearchModeRequestTest`, and an end-to-end case in `CrudSearchRehydrationAclTest`).

- [x] **Step 6:** run the tests (PASS), pint, commit in `Modules/Core`: `feat(search): mode and retry parameters with their cost controls`.

---

### Task 6: documentation

**Files:**
- Modify: `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`
- Modify: `Modules/AI/docs/rag/MODULE.md` (the *Perimeters* section describes the overlay this plan changes)
- Modify: `Modules/Core/README.md` and `Modules/AI/README.md` for any new env var
- Modify: `docs/superpowers/specs/2026-09-12-mcp-server-design.md` (the `search` tool passes `mode=deep`)

- [x] **Step 1:** Document the modes as a contract for callers. Done 2026-10-09 and 2026-10-12 in `SEARCH_RETRIEVAL_PIPELINE.md`, *Search modes*: what `fast`, `balanced` and `deep` buy, that `fast` is the default, that degradation is normal and reported in `meta.search`, the `cost` and `retry` parameters, the rate limit, and that a client reads `mode_applied`.
- [x] **Step 2:** Update *Perimeters* in the AI module docs: search is still Core's, but AI now overlays exactly one contract instead of four, for `balanced` and `deep`.
- [x] **Step 3:** Add the `**Documented in:**` line to this plan naming those documents, per the AGENTS closed-plan rule, and a `## Delivery status (date)` section recording anything deliberately not built, in particular that `balanced` was added on 2026-10-09 and why.
- [x] **Step 4:** pint, commit in each submodule that changed.

---

## Final verification
- [x] `php artisan test --compact Modules/Core/tests/Unit/Search Modules/Core/tests/Feature/Search Modules/AI/tests/Integration/AiSearchStrategyResolverTest.php`
- [-] `php artisan perf:crud` compared against the Task 0 baseline: not run, `perf:crud` benchmarks the list endpoint and does not exercise search (see Task 0). The comparison was made on the search path itself: `fast` runs in about 30 to 35 ms warm with no model call, where the old default with the overlay on paid an intent call, a plan call and an embedding (23 to 60 s per LLM call on the test server).
- [x] `vendor/bin/pint` clean on the files changed in Core, AI, CMS and SAO.

## Out of scope (per spec)
A cache for deep results; ranking parameter tuning, which belongs to the L1 design; the UI affordance, which is `laraplate-ui`; moving the planner off the synchronous path.

## Notes for the executor
- The single most valuable line in this plan is removing the four overrides in `registerSearchBindings()`. Everything else exists to make that removal safe rather than a feature regression.
- `fast` loses the vector stage, because `ITextEmbedder` has no Core default. That is a relevance regression on ordinary search, not only a latency win, and it is the reason Task 0 exists, the reason `mode` is a string rather than a boolean, and the reason `balanced` exists.
- If you find yourself writing `if ($module_ai_installed)` anywhere in Core, stop: the container already answers that question, and answering it again in Core is the design error this plan removes.

## Delivery status (2026-10-12): shipped

**Documented in:** `Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md`, `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/README.md`.

Read from the code, not from the task list. Where the plan and the shipped design differ:

- **Three modes, not two.** Task 0 showed the embedding costs about 80 ms warm while each LLM call costs seconds, so `balanced` (lexical plus vector, no LLM) was built in this plan. Core cannot serve it (no embedder) and answers `mode_unavailable`; the AI resolver serves it.
- **The HTTP parameter is `cost`, not `mode`.** `mode` already picks the search path (`auto|basic|orchestrated`) and `depth` is the graph search's relation depth.
- **No permission for `deep`.** Dropped by the user on 2026-10-12; Task 5 Step 3 is `[-]`. Only the per-person rate limit remains (`search.deep_per_minute`, over it `rate_limited`), plus the retry cap (`search.max_retries`). There is no `not_authorized` reason.
- **The rate limit is `RateLimiter::attempt()` in `SearchCostGuard`, not a named limiter on a route**, because it applies to one value of a parameter.
- **`meta.search` is serialized in the HTTP response**, the only part of the engine meta that is, so a client can read `mode_applied`.
- **A failing embedder no longer fails the search** (`AdvancedSearchService::resolveVector()` logs and goes on without the vector). The reranker already degraded.
- **LLM calls of a search have a timeout**, `AI_SEARCH_LLM_TIMEOUT` (10 s, was Neuron's 60). `deep` is allowed two retries; a retry widens the plan rather than repeating the query.
- **Known gap, closed on 2026-10-14.** An LLM planner or intent parser that timed out used to fall back to rules without saying so. The fallbacks now note `llm_unavailable`, `embedder_failed` or `reranker_failed` in a scoped `SearchDegradations`, and the first note is `degraded_reason`.
- **Not built, still open:** the MCP `search` tool (a separate plan) must read `meta.search.mode_applied`. The latency of `deep` has to be measured again on the production Ollama host: the 2026-10-09 figures come from a 1 vCPU test server.
- **Failures outside this plan, seen on the full Core run:** `DatabaseConnectionAffinityTest` (`WriteProposalService`, `MachineConfigurationObserver`) and `ModelSoftDeletesRemoveCommandTest` (`core_settings` without `managed`) fail without these changes too. A single `php artisan test Modules/Core` run also exhausts 2 GB of memory; it passes when split by folder.
