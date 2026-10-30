# Documentation RAG evaluation baseline (R0)

**Status:** Implemented 2026-09-15; see the delivery status of `docs/superpowers/plans/2026-08-04-documentation-rag-evaluation-baseline.md`. Was: Approved for planning.

**Date:** 2026-08-04

**Program:** RAG assistant — goal **R0** (evaluation baseline). Prerequisite for R1 (grounded module assistant) and for any later change to documentation retrieval quality.

## Decision summary

Laraplate gains a **per-module documentation RAG evaluation baseline**: a versioned dataset of graded questions plus a deterministic, retrieval-only scoring harness and a report artifact. It answers one question for every future change: *did documentation retrieval get better or worse?*

The application content retrieval surface already has a mature, retrieval-only evaluation harness (`ApplicationContentEvaluation*` + `test:ai:evaluate-application-content`). Documentation RAG has **no equivalent baseline**. This goal fills that gap by mirroring the existing pattern, adapted to the documentation retrieval contract, and starting with the **Core** module, **user** audience index.

The harness is owned by the AI module. Each module owns its own dataset file. Datasets never mix modules: one agent lives in one module's app context, so one module has its own isolated "report card". This matches the module-isolation principle and the contextual/generic routing boundary already in the retrieval architecture.

## Terminology

- **Pagella / report card** — an evaluation dataset for one `(module × audience index)` plus the metrics report produced by running it.
- **Case** — one graded question with its expected correct source document(s) and expected behavior (answerable vs must-refuse).
- **Slice** — a tag inside a dataset (topic, hop class, locale) used to break metrics into sub-scores. A slice is *not* a separate pagella.
- **Level 1 (retrieval)** — deterministic scoring of the *search step* only; no chat model; runs in CI on every change; free.
- **Level 2 (generation)** — opt-in scoring of the *written answer*; uses a live LLM provider; non-deterministic; never a CI gate.

## Scope

### In scope (R0)

- A documentation evaluation dataset schema and immutable value objects (`DocumentationEvaluationCase`, `DocumentationEvaluationDataset`), validated on load, mirroring the application-content equivalents.
- A retrieval-only scoring service (`DocumentationEvaluationService`) computing Level-1 metrics and slices, deterministic and provider-free.
- A console command `test:ai:evaluate-documentation` that runs a dataset against the documentation retrieval seam and writes a JSON report.
- A deterministic in-memory corpus + fixed-embedding fake `search` closure used to drive `InAppDocumentationRetrieval` without Elasticsearch.
- The **Core / user** dataset as the first report card, stored under `Modules/Core/docs/rag/evaluations/`.
- A committed baseline report and a regression gate (a Pest test asserting Level-1 metrics stay at or above committed thresholds).
- A **forward-looking contract** (design only, no code) for the future assistant-level end-to-end evaluation (R1), so R1 is born measurable.

### Out of scope (R0)

- Level-2 live-generation scoring implementation (only its contract is defined; opt-in execution is deferred).
- Datasets for modules other than Core, and the developer-audience index (both follow the same stamp later).
- Any change to production retrieval behavior, indexing, chunking, or the safe projection.
- Refactoring the application-content harness into a shared framework (explicitly the "small" option; unification is deferred).
- Hybrid retrieval, reranking, or graph retrieval (those are R3 and gated by *this* baseline).

## Why retrieval-only and deterministic

The application-content harness is deliberately retrieval-only: it calls the provider, not the chat model, so it is deterministic and runs in CI without external services. The documentation baseline preserves that property.

`InAppDocumentationRetrieval` already exposes the seam:

```php
public function __construct(
    private IEmbeddingService $embedding_service,
    private ?Closure $search = null, // (list<float> $embedding, DocumentationRetrievalContext): array<Document>
) {}
```

The evaluation injects a deterministic `$search` closure backed by a small in-memory corpus with fixed vectors and a stub embedding service. No Elasticsearch, no live provider, no network. The same retrieval code path, safe projection, and audience/permission/tenant filtering run; only the vector store is replaced by a controlled fixture.

Level 2 (generation quality) requires the live answer path (`DocumentationService::answerQuestion()`) and is therefore non-deterministic. R0 defines its metrics and leaves execution opt-in and outside the CI gate.

## Dataset contract

A dataset file is JSON, validated on load with exact-key assertions and bounded sizes, exactly like `ApplicationContentEvaluationDataset`.

Dataset-level fields:

- `version` — dataset revision (stable slug).
- `corpus_revision` — revision of the documentation corpus the expectations were authored against.
- `module` — owning module key (e.g. `core`).
- `index_profile` — `user` or `developer` (R0 authors `user`).
- `data_classification` — `synthetic` for R0 (hand-authored fixtures, no tenant data).
- `cases` — non-empty, bounded list of unique cases.

Case-level fields:

- `id` — stable slug.
- `query` — the user question.
- `locale` — BCP-ish locale matching the corpus.
- `top_k` — requested retrieval depth (1–10, matching `DocumentationRetrievalContext`).
- `expected_source_ids` — canonical document identifiers that count as a correct hit (see *Document identity*). Empty for must-refuse cases.
- `expected_citation_references` — canonical references the answer may cite; a subset of the corpus. Empty for must-refuse cases.
- `expect_supported_answer` — the corpus contains a grounded answer.
- `expect_refusal` — the assistant must retrieve nothing usable (out-of-corpus / out-of-audience question).
- `authorization` — the request principal projection: `effective_permissions` (list), `tenant_scope` (`global` | `tenant`), optional `tenant_id`. This drives `DocumentationRetrievalContext` / `AssistantAccessContext` construction under `AssistantProfile::InAppAssistance`.
- `slices` — tags: topic (e.g. `permissions`, `grid`, `moderation`), hop class (`single_hop` | `multi_hop`), and any locale tag.

Validation invariants (mirroring the existing case object): must-refuse cases carry no expected sources or citations; a case cannot be both `expect_supported_answer` and `expect_refusal`; citation references must match the safe reference shape; slices match a strict slug pattern.

### Document identity

A documentation chunk is graded by a **stable canonical source identifier** carried in chunk metadata: the canonical source path plus heading breadcrumb (e.g. `core/grid-export#exporting-a-grid`). The evaluation reads this from retrieved documents at the harness layer. This identity is used only for grading; it is **not** added to the user-facing safe projection, which keeps exposing only `safe_source_label`, `heading_breadcrumb`, `module`, `locale`, `version`, `audience`.

## Metrics (Level 1)

Computed deterministically from retrieved documents, with the same ratio/percentile helpers as the application-content service:

- `source_hit_at_k` — a correct `expected_source_ids` entry appears in the top-K.
- `mean_reciprocal_rank` — rank of the first correct source.
- `citation_precision` — fraction of returned references present in `expected_citation_references`.
- `refusal_accuracy` — empty result exactly when `expect_refusal` is true.
- `permission_excluded_accuracy` — documents the principal must not see are never returned (authored via `authorization`).
- `tenant_excluded_accuracy` — cross-tenant documents are never returned.
- `supported_answer_rate` — non-empty retrieval when `expect_supported_answer` is true.
- `latency_ms` — average/p50/p95/max. In deterministic fake mode this measures harness overhead only; it is meaningful primarily in the opt-in live variant.

Slices report each metric split by `locale`, `topic`, and `hop_class`, via the same `ksort`ed slice mechanism as the existing service.

A raw similarity score is never presented as confidence.

## Metrics (Level 2, contract only)

Defined now, executed opt-in later, never a CI gate:

- `grounded_answer_rate` — the written answer is supported by retrieved documents.
- `citation_faithfulness` — cited sources actually support the claims.
- `correct_refusal_rate` — the answer refuses when the corpus is insufficient.
- `provider_latency_ms` / `provider_cost` — where measurable.

Level 2 runs against `DocumentationService::answerQuestion()` with a fixed judge rubric or a required-facts match list per case. Its non-determinism keeps it out of the deterministic suite.

## Components and ownership

AI module owns the harness (mirroring `Modules/AI/app/Services/ApplicationContent/Evaluation/`):

- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationCase`
- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset`
- `Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService`
- `Modules\AI\Console\EvaluateDocumentationCommand` — signature `test:ai:evaluate-documentation --module= --index=user --dataset= --output= --force`.
- A deterministic corpus + fake `search` closure and stub embedding service under the AI module test-support namespace.

Each business module owns its dataset content:

- `Modules/Core/docs/rag/evaluations/<slug>.json` — the first report card (`module: core`, `index_profile: user`).

The command resolves the retrieval seam, iterates cases, records results, and writes the report atomically (temp file + move), exactly like the application-content command. It refuses to overwrite an existing report without `--force`.

## Report artifact and regression gate

1. **Baseline capture** — run the command once and commit the resulting report as the baseline snapshot of today's quality.
2. **Regression gate** — a Pest test runs the deterministic dataset through the harness and asserts each Level-1 metric is at or above committed thresholds. A change that degrades documentation retrieval fails the build.
3. **Threshold updates** — thresholds move only with an intentional, reviewed commit that also updates the baseline report. No new retrieval strategy becomes the default without a report comparison, per the retrieval-strategy decision.

The report schema carries `schema_version`, `module`, `index_profile`, `dataset_version`, `corpus_revision`, `data_classification`, `case_count`, `metrics`, `latency_ms`, and `slices`.

## Forward-looking: assistant-level evaluation contract (R1)

R0 defines, without implementing, the shape R1 will populate so the future grounded assistant is measurable from birth. It stays **per-module, never global**.

An assistant-level case adds, on top of a documentation case:

- `expected_surface` — which retrieval surface should answer: `documentation` | `application_content` | `graph` | `refuse`.
- `expected_citations_by_surface` — correct canonical references per surface.
- `expect_clarification` — the assistant should ask which module/source when routing is genuinely ambiguous (the "page without a recognizable module → general documentation only" rule lives here).

Assistant-level metrics: `surface_routing_accuracy`, `cross_surface_citation_precision`, `clarification_accuracy`, plus the reused refusal and grounded-answer metrics. Level-1 (routing + retrieval) stays deterministic; Level-2 (composed written answer) stays opt-in. This contract is a design placeholder; its implementation belongs to R1.

## Testing

- `DocumentationEvaluationCase` / `DocumentationEvaluationDataset` reject malformed input (unknown keys, wrong types, refusal cases carrying expected sources, contradictory flags), mirroring the application-content dataset tests.
- `DocumentationEvaluationService` computes each metric correctly on crafted records, including empty/refusal, permission-excluded, tenant-excluded, and multi-locale slices.
- The deterministic corpus + fake `search` closure drives `InAppDocumentationRetrieval` end to end with no Elasticsearch and no provider.
- The regression-gate test asserts committed thresholds on the Core/user dataset.
- No test requires external services; live-provider Level-2 runs are opt-in and excluded from the default suite.

## Success criteria

- Documentation RAG has a versioned, per-module dataset and a deterministic retrieval-only scoring harness, starting with Core/user.
- The harness reuses the documentation retrieval seam (`InAppDocumentationRetrieval` injected `search`) and its audience/permission/tenant filtering; only the vector store is faked.
- A baseline report is committed and a regression gate fails the build on documentation-retrieval quality drops.
- The application-content harness is untouched; documentation and content report cards stay separate and isolated per module.
- The assistant-level evaluation contract (R1) is specified but not implemented.
- Level-2 generation scoring is defined, opt-in, and never gates CI.

## Related documents

- `docs/superpowers/specs/2026-07-16-rag-retrieval-strategy-design.md` (Phase 0 baseline)
- `docs/superpowers/specs/2026-07-17-application-content-retrieval-design.md` (mirrored harness)
- `docs/superpowers/specs/2026-07-16-in-app-ai-assistance-security-design.md` (profile, audience indexes, tenant/permission boundary)
- `Modules/AI/app/Services/ApplicationContent/Evaluation/` (reference implementation)
- `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php` (deterministic seam)
