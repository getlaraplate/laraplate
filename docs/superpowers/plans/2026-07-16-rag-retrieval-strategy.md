---
status: completed
verified_on: 2026-10-08
verified_by: repo audit, reconciled with the code on 2026-10-08
note: Closed 2026-10-08. Tasks 1 and 2 (measurement) shipped under different names (DocumentationEvaluationCase/Dataset/Service, test:ai:evaluate-documentation). Task 3 shipped its chunk metadata steps (developer corpus defaults, stop on an unknown audience); its retrieval factory (Steps 3 to 6) was cancelled. Hybrid (Task 4), reranking (Task 5) and the graph checkpoint (Task 6, except its documentation steps) are deferred with their reopening conditions in the delivery status.
---
# RAG Retrieval Strategy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Improve documentation RAG through measured metadata scoping, hybrid retrieval, and reranking while keeping vector retrieval as the default and allowing graph retrieval only through a later evidence-gated spike.

**Architecture:** Keep `DocumentationService`, `DocumentationAgent`, NeuronAI `RetrievalInterface`, and the current answer/citation contracts. Add a retrieval evaluation harness first, then introduce replaceable retrieval implementations behind a factory. Elasticsearch may add lexical candidates and deterministic fusion; reranking wraps a bounded candidate set. Graph retrieval is not implemented by this plan: the final task evaluates the adoption gate and, only if it passes, creates a separate graph spike spec and plan.

**Tech Stack:** Laravel 12, PHP 8.4, NeuronAI v3 `RetrievalInterface`, Elasticsearch PHP client v8, Core `IReranker`, Pest 4, JSON evaluation fixtures.

**Spec:** `docs/superpowers/specs/2026-07-16-rag-retrieval-strategy-design.md`

**Security prerequisite:** `docs/superpowers/plans/2026-07-16-in-app-ai-assistance-security.md` owns the separate user corpus, permissions/ACL enforcement, mandatory guardrails, and read-only Core Graph tools. Complete that plan before exposing RAG through the in-app assistant.

**Application data extension:** `docs/superpowers/plans/2026-07-17-application-content-retrieval.md` owns the general Core provider contract, authenticated module evidence tool, CMS reference provider, and separately gated public phase. It does not change the documentation retrieval experiments in this plan.

## Delivery status (2026-10-08): shipped with Tasks 3 (factory), 4, 5 and 6 deferred

**Documented in:** `Modules/AI/docs/rag/MODULE.md` (sections "Chunk metadata, the two corpora and a wrong audience" and "Retrieval strategy decision"), `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md` (the evaluation harness).

What shipped:

- **Tasks 1 and 2, under other names.** The plan's `RagEvaluationCase`, `RagEvaluationDataset`, `RagEvaluationService`, `RagEvaluationResult` and `ai:evaluate-rag` were built as `DocumentationEvaluationCase`, `DocumentationEvaluationDataset`, `DocumentationEvaluationService` (hit@k and MRR through the shared `IrMetrics`) and `EvaluateDocumentationCommand` (`test:ai:evaluate-documentation --index=user|developer`), in `Modules/AI/app/Services/Documentation/Evaluation/`. The baselines are the developer datasets and reports under `Modules/AI/docs/rag/evaluations/` (2026-09-17 and 2026-09-18), not `2026-07-vector-baseline.json`.
- **Task 3, Steps 1, 2 and 7 (2026-10-08).** `DocumentationMetadata` (`Modules/AI/app/Services/Documentation/`) gives every developer corpus document the metadata it does not declare (audience `shared`, module `app`, locale `und`, its source name as canonical source, an empty breadcrumb, source type `file`), applied by `DocumentationService` after the audience policy and for the developer profile only, so the user corpus stays deny-by-default. `FileDocumentReader` throws `UnknownDocumentAudienceException` (`Modules/AI/app/Exceptions/`) on an audience outside `user`, `developer`, `shared`, naming the file and the value. The reader was chosen over the service because it holds the physical path and every caller reads all sources before writing: `ai:index-rag-docs` stops with `Indexing failed: ...` and a non-zero exit before a `--full` reset, and `RagIndexRebuilder::prepare()` calls the new `DocumentationService::validateSources()` before recreating the indexes of a model switch. The Elasticsearch mapping types `metadata.source_type` as a keyword. No real document was affected: the corpus declares only `user` (11) and `developer` (1).

Divergences and deferrals:

- **Retrieval factory cancelled (Task 3 Steps 3 to 6).** With one strategy there is nothing to choose: `InAppDocumentationRetrieval` and `DeveloperDocumentationRetrieval` stay wired directly, and there is no `AI_FAQ_RETRIEVAL`. Reopen with the first second strategy.
- **Hybrid deferred (Task 4).** On the production model (`multilingual-e5-small`, symmetric prefixes) vector-only gives hit@5 1.00 and MRR 0.80. Reopen if a larger corpus shows real misses on the configured model, against the promotion gate of Task 4 Step 6.
- **Reranking deferred (Task 5).** It depends on Task 4 and was never measured on documentation. Reopen with Task 4.
- **Graph not authorized (Task 6 Steps 1 to 3).** The gate needs at least 10 unsolved multi-hop cases; the largest developer dataset has 20 cases at hit@5 0.90. The failure analysis was never written, so its planned path below is spelled without the module prefix: it names a file that does not exist. Reopen when a dataset with enough residual failures exists.
- **Not re-measured.** Task 3 Step 6 (re-run the evaluation) was cancelled with the factory; the metadata defaults change no stored text or embedding, and no Elasticsearch index was rebuilt. The live developer index gains the defaults on its next `ai:index-rag-docs`.

---

**Workspace rule:** Run Artisan and tests from the Laraplate application root. `Modules/AI` is a nested Git repository, so commit AI module files with `rtk git -C Modules/AI ...`; commit application-level specs and plans with `rtk git ...` from the Laraplate root.

## Scope and sequencing

Tasks 1–3 establish measurement and a replaceable vector baseline. Tasks 4 and 5 are independently feature-flagged experiments. Task 6 is a decision checkpoint, not permission to implement GraphRAG.

Do not start Task 4 until Tasks 1–3 have produced a committed baseline report. Do not start Task 5 until hybrid results are recorded. Do not authorize a graph implementation from this plan.

This plan evaluates documentation retrieval quality. It does not authorize sharing a corpus between assistant profiles or exposing Core Graph data. Evaluation reports must slice results by server-owned profile, and user-profile cases must use only the physically separate user index defined by the security prerequisite.

Application-module data must not be added to this evaluation corpus. Its search indexes, provider results, citations, and metrics belong to the application content retrieval plan.

**Relationship to `2026-09-16-search-modes-and-strategy-resolution`.** That plan reworks the *application-content* search path (`AdvancedSearchService` / `EnsembleSearchService`, Core `ISearchPlanner`/`IReranker`), where hybrid retrieval and cross-encoder reranking already run. This plan is the *documentation* corpus (NeuronAI `RetrievalInterface`, served today by `InAppDocumentationRetrieval`, vector-only). They are two different retrievers over two different indexes and must not be conflated: shipping hybrid on the application-content path did not give the documentation corpus hybrid retrieval. Tasks 4 and 5 remain the only route to it. Task 5 already reuses Core `IReranker`; a documentation `SearchStrategy` analogue is out of scope unless the Task 1-2 measurement justifies it.

> **State (2026-09-17).** The documentation corpus is now indexable and indexed (developer profile, 483 chunks in `laraplate_rag_docs`). The blocker was not the corpus but the embeddings client: a hardcoded 10s timeout and 128 batch aborted indexing against a CPU-bound Sentence Transformers service; both are now configurable (`SENTENCE_TRANSFORMERS_TIMEOUT` / `SENTENCE_TRANSFORMERS_BATCH_SIZE`). What is still missing before Task 4 (hybrid) can be judged is a **developer-profile evaluation dataset** with exact-term / keyword cases — the weakness hybrid would address. The only datasets on disk are user-profile and tiny: `Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json` (5 synthetic cases) and `Modules/CMS/docs/rag/evaluations/assistant-cms.json` (4). Decision taken: pursue the serious path — measure the developer corpus, vector-only, and let the failure mix decide hybrid.
>
> **Update (2026-09-17).** The shipped `test:ai:evaluate-documentation` only measured the **user** index (empty here). It now takes `--index=user|developer` (default user) via a separate `DeveloperDocumentationRetrieval` — plain vector search on the developer index, no ACL/audience gate; the security-gated in-app user path is untouched. Verified end-to-end on the live corpus (a 4-case smoke run scored MRR 1.0 on easy topic queries). Remaining before Task 4 can be judged: a representative developer dataset with exact-term / keyword cases (config keys, command names, acronyms, error strings) run on vector-only, so the failure mix — not intuition — decides hybrid.
>
> **Finding (2026-09-17) — Task 4 is justified.** Measured. A committed Core developer baseline (`Modules/AI/docs/rag/evaluations/2026-09-17-developer-core-baseline.json`, 13 cases; real-ES report `…-developer-core-vector-baseline.json`), run vector-only on the live corpus (embedding service: all-MiniLM-L6-v2), scores semantic **MRR 1.00** but **near-dup MRR 0.80** and **keyword hit@5 0.67 / MRR 0.67**: `IReranker` misses the top 5 entirely (pulls unrelated SAO/ERP docs) and paraphrased developer queries land on the USER twin at rank 1. The same queries carrying an exact token (`CannotUnlockException`, `DatabaseTextMatchCompiler`, `perf:crud`) hit rank 1 — so a lexical signal disambiguates exactly the cases vector blurs. Conclusion: **Task 4 (hybrid + RRF on the developer retriever) is justified** for identifier and near-duplicate retrieval; the semantic path already works and must not regress. Separate issue surfaced: vector kNN never abstains on out-of-corpus queries (a min-similarity/threshold concern, not hybrid). A deterministic plumbing gate (`DeveloperDocumentationBaselineGateTest`) locks the harness. Caveat: the baseline reflects the embedding service actually running (all-MiniLM-L6-v2), which differs from the configured active model (`multilingual-e5-small`) — worth reconciling before treating the absolute numbers as production-representative.
>
> **Reconciliation (2026-09-18) — Task 4 is NOT justified on the production model; this supersedes the 2026-09-17 finding.** That finding ran against the embedding service as it happened to be loaded — `all-MiniLM-L6-v2` — not the configured active model `multilingual-e5-small`, exactly the caveat it flagged. Re-running the **same** 13-case baseline on `multilingual-e5-small` (service restarted to the configured model) isolates that one variable: keyword `hit@5 0.67 → 1.00` (MRR `0.67 → 0.78`), near-dup `hit@5 1.00`, semantic `hit@5 1.00`, overall `hit@5 1.00 / MRR 0.80`. The IReranker-misses-top-5 and paraphrase-lands-on-the-USER-twin symptoms do not reproduce on e5. A second, independent 20-case developer dataset (`Modules/AI/docs/rag/evaluations/2026-09-18-developer-retrieval.json`, report alongside) agrees: overall `hit@5 0.90`, and keyword (`0.92`) beats semantic (`0.88`). So the exact-term/identifier weakness that motivated Task 4 was substantially an artifact of the wrong (mono-lingual, weaker) embedding model, not an inherent vector limitation. On the production model vector-only clears the corpus, so **Task 4 (hybrid + RRF) is not justified** and stays deferred unless a genuine keyword regression appears on the configured model. Two real issues remain, neither fixed by hybrid: (1) the vector kNN never abstains on out-of-corpus queries (the `unsupported` slice scores 0.0 — a min-similarity / threshold concern); (2) the embedding service ran a model different from config, so the active model must be pinned and monitored (`ai:embeddings:repair` `/health` cross-check). Next levers, in order: pin/verify the embedding model, then add an abstention floor — both cheaper and higher-impact than hybrid.
>
> **Update (2026-09-17, e5 cutover) — Task 4 now inconclusive.** The model caveat above is resolved: the embedding service now serves the configured `intfloat/multilingual-e5-small` (made multi-model and request-driven — `EmbeddingsProviderFactory` sends the active profile's `service_model` per request), and the corpus was re-embedded. New baseline (`2026-09-17-developer-core-vector-baseline.json`): hit@5 **1.00** on every slice — `IReranker` no longer misses — MRR **0.78** (keyword 0.75, near-dup 0.77, semantic 0.83). Recall is solved. But the MRR is confounded by a pipeline bug: the RAG retrieval embeds the query via `embedText()` **without** e5's `query:` prefix, while documents are indexed with `passage:` (`EmbeddingService`), so semantic MRR fell from 1.00 (MiniLM, prefix-agnostic) to 0.83. **The hybrid decision (Task 4) is therefore inconclusive until the query-prefix asymmetry is fixed and re-measured** — that fix comes first, ahead of any hybrid work. The no-abstention behaviour on out-of-corpus queries is unchanged.
>
> **Resolved (2026-09-17) — query prefix fixed.** The RAG retrieval (`InAppDocumentationRetrieval` and `DeveloperDocumentationRetrieval`) now applies the active profile's `query:` prefix before embedding, mirroring `SearchEmbedder`, so queries and passages are symmetric. Re-measured (no re-index needed): hit@5 **1.00**, MRR **0.80** (keyword 0.78, near-dup 0.80, semantic 0.83). The prefix helped ranking modestly (keyword 0.75→0.78, near-dup 0.77→0.80); the residual gap is one near-duplicate twin and one semantic case ranking #2, not a recall failure. **Conclusion: with the correct model (e5, multilingual) and symmetric prefixes, vector-only already puts every expected source in the top 5 and ranks most at #1. Hybrid (Task 4) is a modest ranking optimisation, not urgent — deferred; revisit if a larger corpus surfaces real misses.** The no-abstention behaviour (separate min-similarity/threshold concern) still stands as the more valuable follow-up than hybrid.
>
> **Abstention added (2026-09-17).** The retriever now supports a `min_similarity` floor (`AI_FAQ_MIN_SIMILARITY`, default 0 = off) in both `InAppDocumentationRetrieval` and `DeveloperDocumentationRetrieval`: hits scoring below it are dropped, so out-of-corpus queries return empty instead of noise. Demonstrated on the live corpus — at **0.93** the two unsupported smoke cases abstain (0 docs) while supported queries keep hit@5 1.00. Caveat (in config + README): e5-small compresses scores (supported ~0.94, unrelated ~0.92), so the useful threshold sits in a ~0.01 band — too fragile for a global default, hence off by default and tuned per corpus/model. A sharper model or a cross-encoder rerank would widen that band.

### Task 1: Versioned RAG evaluation dataset and loader

> **Built under different names (reconciled 2026-10-08).** Task 1 shipped as `DocumentationEvaluationCase` and `DocumentationEvaluationDataset` (`Modules/AI/app/Services/Documentation/Evaluation/`, with `DocumentationEvaluationCaseTest` and `DocumentationEvaluationDatasetTest`); the fixtures are `Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json` and `Modules/AI/docs/rag/evaluations/2026-09-17-developer-core-baseline.json` and `2026-09-18-developer-retrieval.json`. Task 2 shipped as `DocumentationEvaluationService` (hit@k and MRR through the shared `IrMetrics`), `EvaluateDocumentationCommand` (`test:ai:evaluate-documentation`, `--index=user|developer`) and `DocumentationEvaluationServiceTest`; the baseline reports are `2026-09-17-developer-core-vector-baseline.json` and `2026-09-18-developer-retrieval-report.json`. The `Rag*` names and file paths in the steps below are the plan's; the ticks map to what the code does.

**Files:**

- Create: `Modules/AI/tests/Fixtures/rag/evaluation.json`
- Create: `Modules/AI/app/Services/Documentation/Evaluation/RagEvaluationCase.php`
- Create: `Modules/AI/app/Services/Documentation/Evaluation/RagEvaluationDataset.php`
- Create: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/RagEvaluationDatasetTest.php`

- [x] **Step 1: Write the failing dataset loader test**

Cover valid loading, duplicate IDs, missing expected sources, invalid audience values, and invalid hop classes. The initial fixture must contain at least one `user`, one `developer`, one Italian, one English, one unsupported, and one multi-hop case.

```php
it('loads typed evaluation cases from the versioned fixture', function (): void {
    $dataset = RagEvaluationDataset::fromJson(
        base_path('Modules/AI/tests/Fixtures/rag/evaluation.json'),
    );

    expect($dataset->cases())->not->toBeEmpty()
        ->and($dataset->cases()[0])->toBeInstanceOf(RagEvaluationCase::class);
});
```

- [x] **Step 2: Run the test and verify failure**

Run:

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/RagEvaluationDatasetTest.php
```

Expected: FAIL because the evaluation DTO and loader do not exist.

- [x] **Step 3: Implement the DTO and strict loader**

Use this public shape:

```php
final readonly class RagEvaluationCase
{
    /**
     * @param list<string> $expectedSources
     * @param list<string> $requiredFacts
     */
    public function __construct(
        public string $id,
        public string $question,
        public string $audience,
        public string $locale,
        public string $hopClass,
        public array $expectedSources,
        public array $requiredFacts,
        public bool $answerable,
    ) {}
}
```

`RagEvaluationDataset::fromJson()` must reject malformed JSON, duplicate IDs, audiences outside `user|developer|shared`, hop classes outside `single|multi`, and answerable cases without expected sources.

- [x] **Step 4: Populate the initial fixture from canonical RAG documents**

Use stable questions whose expected source paths exist in `docs/rag/` or `Modules/*/docs/rag/`. Do not invent expected facts not stated by those documents. Start with 30 cases: 20 single-hop, 5 multi-hop, and 5 unsupported; include both supported audiences and locales represented in the corpus.

- [x] **Step 5: Run the targeted test**

Expected: PASS.

- [x] **Step 6: Commit**

```bash
rtk git -C Modules/AI add app/Services/Documentation/Evaluation tests/Fixtures/rag/evaluation.json tests/Unit/Services/Documentation/Evaluation
rtk git -C Modules/AI commit -m "test(ai): add versioned RAG evaluation dataset"
```

### Task 2: Retrieval evaluation runner and baseline report

**Files:**

- Create: `Modules/AI/app/Services/Documentation/Evaluation/RagEvaluationResult.php`
- Create: `Modules/AI/app/Services/Documentation/Evaluation/RagEvaluationService.php`
- Create: `Modules/AI/app/Console/EvaluateRagCommand.php`
- Create: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/RagEvaluationServiceTest.php`
- Create: `Modules/AI/tests/Feature/EvaluateRagCommandTest.php`
- Create after running baseline: `Modules/AI/docs/rag/evaluations/2026-07-vector-baseline.json`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php`

- [x] **Step 1: Write failing metric tests**

Use an injected `RetrievalInterface` fake returning known source orders. Assert hit@K, reciprocal rank, unsupported empty-result accuracy, per-audience slices, and per-hop slices.

```php
expect($result->hitRateAt(5))->toBe(0.5)
    ->and($result->meanReciprocalRank())->toBe(0.5)
    ->and($result->slice('hop_class', 'multi')->caseCount)->toBe(1);
```

- [x] **Step 2: Run the metric tests and verify failure**

Expected: FAIL because the evaluator does not exist.

- [x] **Step 3: Implement deterministic retrieval metrics**

`RagEvaluationService` accepts a `RetrievalInterface` and evaluates retrieval without calling the chat provider. Normalize citation/source names before matching, preserve raw ranks for diagnostics, and emit a serializable result containing:

```php
[
    'strategy' => 'vector',
    'case_count' => 30,
    'hit_rate_at_5' => 0.0,
    'mrr' => 0.0,
    'unsupported_empty_rate' => 0.0,
    'latency_ms' => ['median' => 0.0, 'p95' => 0.0],
    'slices' => [],
    'cases' => [],
]
```

- [x] **Step 4: Add the command**

Register `ai:evaluate-rag` with `--dataset=`, `--output=`, and `--strategy=vector`. Refuse to overwrite an existing report unless `--force` is passed. The command must never run as part of the default CI suite because it may require configured embeddings and Elasticsearch.

- [x] **Step 5: Test the command with a fake evaluator**

Assert successful JSON output, refusal to overwrite, non-zero exit for missing datasets, and no chat-provider call in retrieval-only mode.

- [x] **Step 6: Run the vector baseline against the configured test corpus**

Run:

```bash
rtk php artisan ai:evaluate-rag --strategy=vector --dataset=Modules/AI/tests/Fixtures/rag/evaluation.json --output=Modules/AI/docs/rag/evaluations/2026-07-vector-baseline.json
```

Expected: a committed JSON report with aggregate and sliced metrics. Record environment, embeddings model, dimensions, index name, and corpus revision in the report metadata.

- [x] **Step 7: Run targeted tests and commit**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/RagEvaluationServiceTest.php Modules/AI/tests/Feature/EvaluateRagCommandTest.php
rtk git -C Modules/AI add app/Services/Documentation/Evaluation app/Console/EvaluateRagCommand.php app/Providers/AIServiceProvider.php tests/Unit/Services/Documentation/Evaluation tests/Feature/EvaluateRagCommandTest.php docs/rag/evaluations/2026-07-vector-baseline.json
rtk git -C Modules/AI commit -m "feat(ai): measure documentation RAG retrieval quality"
```

### Task 3: Stable chunk metadata and explicit vector retrieval factory

> **Done (2026-10-08).** Steps 1, 2 and 7 shipped; Steps 3 to 6 are cancelled. See the delivery status at the top.
>
> **State (2026-10-08, before the work).** Steps 1 and 2 are only partly built, by the security plan rather than this one: `FileDocumentReader` parses the front matter, `MarkdownAwareSplitter` carries the metadata to every chunk with a `heading_breadcrumb`, and `DocumentAudiencePolicy` admits to the user corpus only documents with `audience` `user` or `shared` and with `module`, `locale`, `canonical_source`, `safe_source_label` and `version` (tests in `InAppDocumentationRetrievalTest`, `ElasticsearchRagVectorStoreTest`). Not verified: the developer-corpus defaults (`shared`, `app`, `und`, empty breadcrumb, `file`) and the rejection of an unknown audience value at indexing, so those steps stay open. Steps 3 and 4 are **not built**: there is no `DocumentationRetrievalFactory`, `VectorDocumentationRetrieval` or `AI_FAQ_RETRIEVAL`; `InAppDocumentationRetrieval` and `DeveloperDocumentationRetrieval` are wired directly. With hybrid and reranking deferred (below), a factory would have one strategy to choose from; whether to build it, or to cancel Steps 3 to 6 with `- [-]`, is a decision of the owner.

**Files:**

- Create: `Modules/AI/app/Ai/Rag/Retrieval/DocumentationRetrievalFactory.php`
- Create: `Modules/AI/app/Ai/Rag/Retrieval/VectorDocumentationRetrieval.php`
- Create: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/DocumentationRetrievalFactoryTest.php`
- Modify: `Modules/AI/app/Ai/Agents/DocumentationAgent.php`
- Modify: `Modules/AI/app/Services/Documentation/FileDocumentReader.php`
- Modify: `Modules/AI/app/Services/Documentation/Chunking/MarkdownAwareSplitter.php`
- Modify: `Modules/AI/config/config.php`
- Modify: `Modules/AI/tests/Integration/FileDocumentReaderTest.php`
- Modify: `Modules/AI/tests/Integration/MarkdownAwareSplitterTest.php`

- [x] **Step 1: Write failing metadata tests** Done 2026-10-08: `FileDocumentReaderTest`, `MarkdownAwareSplitterTest`, `DocumentationMetadataTest`, `DocumentAudiencePolicyTest` (developer defaults never pass the user policy), `DocumentationServiceTest` (developer chunks carry the six keys, user chunks no defaults, an unknown audience stops before the store is touched), `IndexDocumentationCommandTest`, `RagIndexRebuilderTest`, `ElasticsearchRagVectorStoreTest`; they failed before the code.

Assert that every chunk has `audience`, `module`, `locale`, `canonical_source`, `heading_breadcrumb`, and `source_type`. Legacy documents without front matter may receive `shared`, `app`, `und`, the normalized source path, an empty breadcrumb, and `file` only in the developer corpus. They remain ineligible for the user corpus until explicitly classified `user` or `shared` and approved by its policy.

- [x] **Step 2: Implement metadata normalization** Done 2026-10-08: `DocumentationMetadata::applyDeveloperDefaults()` in `DocumentationService::profileDocuments()` (developer profile only, after `DocumentAudiencePolicy`), `UnknownDocumentAudienceException` thrown by `FileDocumentReader`, `DocumentationService::validateSources()` called by `RagIndexRebuilder::prepare()`, `source_type` keyword in the Elasticsearch mapping.

Read optional front matter keys without changing document content semantics. Propagate normalized metadata from the source document to every split chunk. Reject unknown audience values during indexing with a source-specific exception. Never let developer-compatible defaults weaken the user-index deny-by-default rule.

- [-] **Step 3: Write the failing retrieval factory test** Cancelled by the owner on 2026-10-08: the retrieval factory has one strategy to choose from (vector); `InAppDocumentationRetrieval` and `DeveloperDocumentationRetrieval` stay wired directly, and a factory is written when a second strategy is built.

Assert that missing configuration and `strategy=vector` both resolve to `VectorDocumentationRetrieval`, and unknown strategies fail during agent construction rather than silently switching behavior.

- [-] **Step 4: Implement the explicit vector wrapper and factory** Cancelled with Step 3.

`VectorDocumentationRetrieval` implements NeuronAI `RetrievalInterface` and delegates to `SimilarityRetrieval`. `DocumentationRetrievalFactory::make()` receives the vector store and embeddings provider explicitly. Override `DocumentationAgent::retrieval()` to call the factory.

The initial config is:

```php
'retrieval' => [
    'strategy' => env('AI_FAQ_RETRIEVAL', 'vector'),
],
```

Do not add `graph` as an accepted value.

- [-] **Step 5: Run regression tests** Cancelled with Step 3.

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Ai/Rag/Retrieval/DocumentationRetrievalFactoryTest.php Modules/AI/tests/Integration/DocumentationAgentTest.php Modules/AI/tests/Integration/FileDocumentReaderTest.php Modules/AI/tests/Integration/MarkdownAwareSplitterTest.php Modules/AI/tests/Integration/DocumentationServiceTest.php
```

Expected: PASS and the default strategy remains vector.

- [-] **Step 6: Re-run the vector evaluation and compare** Cancelled with Step 3.

Expected: no retrieval regression caused by the wrapper or metadata defaults. If hit@5 or MRR changes, explain the exact corpus/indexing cause in the new report before continuing.

- [x] **Step 7: Commit** Done 2026-10-08 in `Modules/AI` as `feat(ai): normalize documentation chunk metadata and stop on an unknown audience` (`bf054a4`), with the message the work called for rather than the factory's.

```bash
rtk git -C Modules/AI add app/Ai/Rag/Retrieval app/Ai/Agents/DocumentationAgent.php app/Services/Documentation config/config.php tests
rtk git -C Modules/AI commit -m "refactor(ai): make documentation retrieval strategy explicit"
```

### Task 4: Feature-flagged Elasticsearch hybrid retrieval

> **State (2026-10-08): deferred, nothing built.** The measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric `query:`/`passage:` prefixes) found hit@5 1.00 and MRR 0.80, so hybrid is not justified; revisit only if a larger corpus shows real misses. The classes below do not exist.

> **Scope:** the documentation retriever only (NeuronAI `RetrievalInterface`, `InAppDocumentationRetrieval`). This is a different path from the application-content ensemble hybrid reworked in `2026-09-16-search-modes-and-strategy-resolution`; that work does not deliver hybrid retrieval to this corpus. See *Relationship to search-modes* above. As of 2026-09-16 none of the classes below exist on master.

**Files:**

- Create: `Modules/AI/app/Ai/Rag/Retrieval/HybridDocumentationRetrieval.php`
- Create: `Modules/AI/app/Ai/Rag/Retrieval/ReciprocalRankFusion.php`
- Create: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/HybridDocumentationRetrievalTest.php`
- Create: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/ReciprocalRankFusionTest.php`
- Modify: `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DocumentationRetrievalFactory.php`
- Modify: `Modules/AI/config/config.php`
- Modify: `Modules/AI/tests/Unit/Ai/Rag/ElasticsearchRagVectorStoreTest.php`

- [-] **Step 1: Write failing reciprocal-rank-fusion tests** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Cover documents present in both lists, documents present in only one list, stable tie-breaking, duplicate IDs, and configurable vector/lexical weights. Fusion must use ranks, not raw Elasticsearch scores.

- [-] **Step 2: Implement deterministic fusion** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Use `score += weight / (60 + rank)` with one-based ranks. Break equal fused scores by best individual rank, then stable document ID.

- [-] **Step 3: Write failing lexical retrieval tests** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Assert `multi_match` or `simple_query_string` over `content` and heading metadata, exact filters for audience/module/locale, bounded candidate size, and mapping back to Neuron `Document` objects with canonical provenance.

- [-] **Step 4: Add lexical search to the Elasticsearch store** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Add a documentation-specific method without changing Neuron's `VectorStoreInterface`:

```php
/** @return list<Document> */
public function lexicalSearch(string $query, int $limit, array $filters = []): array;
```

Keep user input in query values, never interpolate it into field names or raw query JSON.

- [-] **Step 5: Implement hybrid retrieval and safe fallback** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Run vector and lexical retrieval, fuse candidates, and return the configured limit. If the lexical branch fails, log structured diagnostics and return the vector order. A vector failure remains fail-fast.

Enable only through:

```php
'strategy' => env('AI_FAQ_RETRIEVAL', 'vector'), // vector|hybrid
```

For memory/filesystem stores, requesting `hybrid` must fall back to vector with a configuration warning; do not emulate lexical search in PHP.

- [-] **Step 6: Run tests and evaluation** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

Run the targeted retrieval/store tests, then create `Modules/AI/docs/rag/evaluations/2026-07-hybrid-candidate.json` with the same corpus revision and embeddings model as the baseline.

Promotion gate: hybrid may become the recommended Elasticsearch strategy only if hit@5 improves by at least 5 percentage points on the full dataset, no required slice regresses by more than 2 percentage points, and p95 retrieval latency increases by no more than 25%. Otherwise keep `vector` as the documented default.

- [-] **Step 7: Commit** Deferred by the owner on 2026-10-08: the measurement of 2026-09-18 on the production model (`multilingual-e5-small`, symmetric prefixes) gives hit@5 1.00 and MRR 0.80, so hybrid retrieval has no problem to solve. Reopen if a larger corpus shows real misses on the configured model.

```bash
rtk git -C Modules/AI add app/Ai/Rag config/config.php tests/Unit/Ai/Rag docs/rag/evaluations/2026-07-hybrid-candidate.json
rtk git -C Modules/AI commit -m "feat(ai): add measured hybrid documentation retrieval"
```

### Task 5: Optional bounded reranking

> **State (2026-10-08): deferred, nothing built.** No documentation reranking was measured; the cross-encoder was evaluated on the application-content path only (reranker seeded off after a run with no gain, see `reranker.model` in the AI docs). The classes below do not exist.

**Files:**

- Create: `Modules/AI/app/Ai/Rag/Retrieval/RerankedDocumentationRetrieval.php`
- Create: `Modules/AI/tests/Unit/Ai/Rag/Retrieval/RerankedDocumentationRetrievalTest.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DocumentationRetrievalFactory.php`
- Modify: `Modules/AI/config/config.php`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php`

- [-] **Step 1: Write failing reranker wrapper tests** Deferred by the owner on 2026-10-08: it depends on Task 4, and no documentation reranking was measured (the cross-encoder was evaluated on the application-content path only, and is seeded off). Reopen with Task 4.

Use a fake Core `IReranker`. Assert bounded candidate count, query/document pair construction, stable score ordering, preservation of document provenance, fallback on exceptions, and fallback when score count is invalid.

- [-] **Step 2: Implement the wrapper** Deferred by the owner on 2026-10-08: it depends on Task 4, and no documentation reranking was measured (the cross-encoder was evaluated on the application-content path only, and is seeded off). Reopen with Task 4.

`RerankedDocumentationRetrieval` decorates another Neuron `RetrievalInterface`. It sends at most `candidate_limit` pairs to `IReranker`, sorts by reranker score with original rank as tie-breaker, and returns `result_limit` documents. It catches reranker failures, logs `rag_retrieval_reranker_fallback`, and returns the decorated retriever order.

- [-] **Step 3: Add configuration and factory wiring** Deferred by the owner on 2026-10-08: it depends on Task 4, and no documentation reranking was measured (the cross-encoder was evaluated on the application-content path only, and is seeded off). Reopen with Task 4.

```php
'reranker' => [
    'enabled' => env('AI_FAQ_RERANKER_ENABLED', false),
    'candidate_limit' => (int) env('AI_FAQ_RERANKER_CANDIDATES', 20),
    'result_limit' => (int) env('AI_FAQ_MAX_DOCS', 5),
],
```

Reuse the existing Core `IReranker` binding; do not duplicate the cross-encoder client.

- [-] **Step 4: Run tests and evaluation** Deferred by the owner on 2026-10-08: it depends on Task 4, and no documentation reranking was measured (the cross-encoder was evaluated on the application-content path only, and is seeded off). Reopen with Task 4.

Create a candidate report using the same dataset and environment metadata. Promotion gate: MRR must improve by at least 5% relative to the selected non-reranked strategy, no required slice may regress by more than 2 percentage points, and p95 must remain within the documented interactive latency budget. If no latency budget has been approved, record results but keep reranking disabled by default.

- [-] **Step 5: Commit** Deferred by the owner on 2026-10-08: it depends on Task 4, and no documentation reranking was measured (the cross-encoder was evaluated on the application-content path only, and is seeded off). Reopen with Task 4.

```bash
rtk git -C Modules/AI add app/Ai/Rag/Retrieval config/config.php app/Providers/AIServiceProvider.php tests/Unit/Ai/Rag/Retrieval docs/rag/evaluations
rtk git -C Modules/AI commit -m "feat(ai): add optional documentation reranking"
```

### Task 6: Graph retrieval decision checkpoint

> **State (2026-10-08): not started.** The failure analysis (`docs/rag/evaluations/2026-07-retrieval-failure-analysis.md` of the AI module) does not exist and no graph decision is recorded. With vector-only at hit@5 1.00 on the 13- and 20-case developer datasets, the gate in Step 2 (at least 10 unsolved multi-hop cases) is very unlikely to pass, but the failure classification has not been written.

**Files:**

- Create (not created, Task 6 deferred): `docs/rag/evaluations/2026-07-retrieval-failure-analysis.md` in `Modules/AI`
- Modify: `Modules/AI/docs/rag/MODULE.md`
- Modify: `Modules/AI/README.md`
- Create only if the gate passes: `docs/superpowers/specs/2026-07-16-rag-graph-retrieval-spike-design.md`
- Create only after that new spec is approved: `docs/superpowers/plans/2026-07-16-rag-graph-retrieval-spike.md`

- [-] **Step 1: Classify residual evaluation failures** Deferred by the owner on 2026-10-08: no graph spike is authorized. The gate needs at least 10 unsolved multi-hop cases, and the largest developer dataset has 20 cases with hit@5 0.90 (at most 2 misses), so it cannot be reached on today's evidence. Reopen when a corpus or dataset with enough residual failures exists.

For every failed case in the selected vector/hybrid/reranked report, assign exactly one primary category: missing corpus content, bad chunking, metadata/filter error, lexical miss, semantic miss, ranking error, multi-hop relationship loss, unsupported-answer behavior, or evaluation-fixture defect.

- [-] **Step 2: Apply the graph authorization gate** Deferred by the owner on 2026-10-08: no graph spike is authorized. The gate needs at least 10 unsolved multi-hop cases, and the largest developer dataset has 20 cases with hit@5 0.90 (at most 2 misses), so it cannot be reached on today's evidence. Reopen when a corpus or dataset with enough residual failures exists.

Authorize a graph spike only if multi-hop relationship loss accounts for at least 10% of valid residual failures and at least 10 representative multi-hop cases remain unsolved by the best non-graph strategy. Otherwise document “graph spike not authorized” and stop.

- [-] **Step 3: If authorized, write a separate spike spec** Deferred by the owner on 2026-10-08: no graph spike is authorized. The gate needs at least 10 unsolved multi-hop cases, and the largest developer dataset has 20 cases with hit@5 0.90 (at most 2 misses), so it cannot be reached on today's evidence. Reopen when a corpus or dataset with enough residual failures exists.

The spike spec must compare at least one no-graph baseline with the candidate graph implementation and define entity extraction, edge provenance, incremental refresh, deletion, tenant isolation, permissions, fallback, citations, latency, indexing cost, storage cost, and teardown. Graphify may be one candidate but must not appear in public application contracts.

- [x] **Step 4: Update module documentation** Done 2026-10-08 in `Modules/AI/docs/rag/MODULE.md` ("Retrieval strategy decision"): vector-only is the strategy, hybrid and reranking are deferred, no graph spike is authorized; there is no `AI_FAQ_RETRIEVAL` setting, the factory having been cancelled.

Record the selected default strategy, evaluation report links, and graph decision. Keep `AI_FAQ_RETRIEVAL=vector` as the default unless the relevant promotion gate was passed and explicitly approved.

- [x] **Step 5: Run documentation tests and commit** Done 2026-10-08: `AiRagModuleDocumentationTest` passes; committed in `Modules/AI` and here.

```bash
rtk php artisan test --compact Modules/AI/tests/Integration/AiRagModuleDocumentationTest.php
rtk git -C Modules/AI add docs/rag README.md
rtk git -C Modules/AI commit -m "docs(ai): record measured RAG retrieval decision"
rtk git add docs/superpowers/specs docs/superpowers/plans
rtk git commit -m "docs(ai): record graph retrieval gate"
```

---

## Plan self-review

| Spec requirement | Plan coverage |
|---|---|
| Vector RAG remains baseline | Tasks 2–3 |
| Measurement precedes complexity | Tasks 1–2 and promotion gates |
| Metadata and audience scoping | Task 3 |
| Hybrid before graph | Task 4 |
| Reranking before graph | Task 5 |
| Graph is optional and disabled | Task 6 |
| Graph Explorer remains separate | Spec and Task 6 documentation |
| Canonical citations survive retrieval changes | Tasks 3–5 tests |
| Safe fallback behavior | Tasks 4–5 |
| Tenant/permission graph requirements | Task 6 spike-spec gate |
| In-app corpus isolation, ACL, guardrails, and Core Graph tools | Separate mandatory security prerequisite plan |
| Module-owned searchable evidence | Separate application content retrieval plan |

**Placeholder scan:** No placeholders remain. The concrete graph-spike filenames are reserved but must not be created unless Task 6 passes and a separate brainstorming cycle approves the spike. No graph implementation task is authorized here.

**Type consistency:** All retrieval implementations use NeuronAI `RetrievalInterface`; reranking reuses Core `IReranker`; retrieved values remain NeuronAI `Document` objects consumed by the existing agent and citation flow.
