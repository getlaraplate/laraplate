# Search and retrieval architecture

A map of how Laraplate finds things: which piece does what, how each one is measured, and what is
deliberately not built. The precise contracts live in the module documents linked below; this page
does not repeat them, it says where to look and which words to use.

## Three retrieval surfaces

Laraplate has three separate retrieval surfaces. They share infrastructure (embeddings, the search
engine) but they answer different questions and are governed by different rules.

| Surface | Owner | What it retrieves | Reference |
|---------|-------|-------------------|-----------|
| Application search | Core (with AI as an optional overlay) | Records of the application, through the CRUD search | [Search retrieval pipeline](../Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md) |
| Documentation RAG | AI | Passages of the user and developer documentation | [AI module, RAG](../Modules/AI/docs/rag/MODULE.md) |
| Application content and Core Graph | AI (assistant tools), Core, modules | Module-owned textual evidence, and known authorized relations of a record | [Graph system](../Modules/Core/docs/GRAPH_SYSTEM.md) |

The in-app assistant composes the second and third surfaces. Application search is a separate
endpoint used by lists, the command palette and clients.

## Application search

`CrudService::search()` takes the orchestrated path when the engine supports it, and plain Scout
otherwise. A request chooses how much it spends with the `cost` parameter:

- `fast` (default): rule-based planner, heuristic reranker, rule-based intent parser, keyword
  retrieval only. Milliseconds, no external call.
- `balanced`: adds the query embedding, so lexical and vector results are fused.
- `deep`: adds LLM intent parsing and planning, cross-encoder reranking when the plan asks for it,
  and up to two retries when the result is poor. Limited per person (`search.deep_per_minute`).

Core binds a resolver that always serves `fast`. A module that can do more rebinds the resolver; the
AI module does. Lexical, vector and hybrid results are combined by a weighted fusion that also uses
reciprocal rank fusion and an agreement bonus.

Properties worth knowing:

- **Degradation is part of the contract.** A search never fails because a model is unavailable. It
  returns the cheaper result and says why in `meta.search.degraded_reason`.
- **Adaptive tuning is measured, not learned.** An optional profile of fusion weights per query
  class is off by default, and its values come from `test:ai:tune-retrieval`, which validates on
  held-out cases and declines to name a winner inside the noise margin. Nothing reads user behaviour.
- **Dates in the query** ("March 2025", "last year", "recent") narrow the search for models that
  declare a date column. Rules only, English and Italian.
- **Query embeddings are cached** for 300 seconds, per signed-in user.

## Documentation RAG

As recorded on 2026-10-08, documentation retrieval is **vector only**: the question is embedded with
the active profile, searched with Elasticsearch kNN, and hits under an optional similarity floor are
dropped. Hybrid lexical retrieval and documentation reranking are deferred because the measurement
does not justify them yet; the developer datasets give hit@5 of 1.00 and 0.90 and an MRR of 0.80
(`Modules/AI/docs/rag/evaluations/`). They reopen if a larger corpus shows real misses.

- **Access first.** Retrieval is filtered by audience, locale, classification, permissions, tenant
  and module scope, and passages go through a safe projection before they reach a prompt.
- **Module scope is server-owned.** The assistant narrows retrieval to the module the user works in.
  With no recognizable module it falls back to the whole corpus, and scope never changes what a
  person is authorized to see ([Assistant scope](../Modules/AI/docs/rag/ASSISTANT_SCOPE.md)).
- **Passages know their document.** Each chunk carries a document key and a section number, so a
  client can read the section a search found together with its neighbours.
- **An optional relevance judge** can refine the candidates over several rounds. It is off by
  default, and its documentation says to compare the evaluation with it on and off before enabling.
- **Embedding operations are first-class:** a model switch that re-embeds, rebuilds and verifies
  before activating, a drift check that catches the same model name producing different vectors,
  and a keyword-only fallback when a document cannot be embedded.

## Assistant composition

`InAppAssistanceService::respond()` composes documentation retrieval, application content retrieval
and the Core Graph tools. Each surface authorizes on its own and neither bypasses the other. The
answer carries citations, and the assistant can ask for clarification or abstain when no evidence
supports an answer.

Application content retrieval is provider based: a module registers a provider that finds bounded
textual evidence it owns (CMS contents, SAO tickets). The AI module discovers providers through a
registry and needs no change when a module adds one.

## Graph: what it is and what it is not

Core Graph is an **authorized relation API**: expand one record, search seeds, and compute stats over
an expansion, for any CRUD-resolvable entity, honouring the same permissions as CRUD. The assistant
receives it as three read-only tools (`graph_search`, `graph_expand`, `graph_stats`) under the
in-app profile.

It is **not** a knowledge graph and **not** GraphRAG. Laraplate depends on no graph database or
GraphRAG library, and there is no graph in the retrieval path. This is a decision, not a gap:

- Traversal runs at request time. A materialized edge store was evaluated on 2026-09-30 and not
  built: an expand costs 16 to 32 queries, tracks query count rather than graph size, and batching
  those queries gets the same saving without a table, invalidation hooks and staleness checks.
- A graph retriever for documentation would stay optional, off by default, keep canonical
  citations and fall back to the non-graph path. It is authorized only after measurement shows at
  least 10 unsolved multi-hop cases; the largest dataset has 20 cases and no spike is authorized.

## Measuring

Retrieval changes enter only through measured stages. Evaluation is offline: nothing in the request
path reads an evaluation artifact.

- **Documentation:** `test:ai:evaluate-documentation --index=user|developer`, with hit@k, MRR,
  citation precision, supported-answer rate, refusal accuracy and latency.
- **Application content:** `test:ai:evaluate-application-content`, with precision, recall and
  nDCG at k, committed baselines for CMS and SAO, and regression gates in CI.
- **Assistant, level 1:** a deterministic gate that drives the real `respond()` with a scripted
  router. It measures composition plumbing (scope, citations, clarification, abstention, output
  validity), not the model's routing. A live level 2 that measures routing is not built.

## Wording

Say what is true of each surface.

- "Hybrid lexical and vector search with reranking and adaptive planning" describes **application
  search** in `balanced` and `deep`, not documentation RAG.
- "Documentation RAG" is access-aware vector retrieval with citations, measured by dataset.
- Core Graph is "authorized relation traversal". Do not call it a knowledge graph or GraphRAG.

## Where to read next

- [Search retrieval pipeline](../Modules/Core/docs/rag/SEARCH_RETRIEVAL_PIPELINE.md) and
  [Search matching](../Modules/Core/docs/rag/SEARCH_MATCHING_DEVELOPER.md)
- [AI module, RAG](../Modules/AI/docs/rag/MODULE.md) and
  [Search indexing, embeddings and translations](../Modules/AI/docs/SEARCH_AND_TRANSLATION.md)
- [Assistant evaluation](../Modules/AI/docs/rag/ASSISTANT_EVALUATION.md) and
  [Documentation evaluation](../Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md)
- [Graph system](../Modules/Core/docs/GRAPH_SYSTEM.md)
- [Come funziona oggi la ricerca di Laraplate](ricerca-spiegazione-semplice.md) (Italian, plain language)
