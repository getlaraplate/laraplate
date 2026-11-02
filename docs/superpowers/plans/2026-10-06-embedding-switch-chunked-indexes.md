---
status: completed
---
# Embedding Switch: Chunked Index Writes Implementation Plan

> **Superseded in part (2026-11-02):** `VectorModelContext` is now `VectorProfileContext` (Core). Superseded by `../specs/2026-10-30-vector-search-owned-by-ai-design.md`: the AI module owns the vector profile and Core keeps no `search.vector.*` setting. This plan stays true about its moment.

> **For agentic workers:** Steps use checkbox syntax for tracking. Tick each step when it finishes.

**Goal:** The `indexes` and `verify` phases of an embedding model switch stop rewriting every searchable document inside one run of `SwitchEmbeddingModelJob`. The documents are written by short, idempotent chunk jobs on a dedicated queue, so no job rewrites a whole corpus.

**Follows:** [`2026-10-05-embedding-model-switch.md`](2026-10-05-embedding-model-switch.md) (shipped). **Spec:** [`../specs/2026-10-05-embedding-model-switch-design.md`](../specs/2026-10-05-embedding-model-switch-design.md), sections 4.4, 10 and 11.

**Architecture:** The control job keeps the once-only work of the `indexes` phase (the pgvector profile index, each index recreated or emptied, the RAG rebuild) and stores a chunk plan in the switch state: one chunk per embeddable model and key range, `ai.features.embeddings.index_chunk_size` keys each (default 250). The next passes dispatch one `IndexDocumentsChunkJob` per pending chunk on the `embeddings-index` queue (Horizon `supervisor-embeddings-index`, 1 process), wait while that queue holds work, re-dispatch what is still pending up to 3 rounds and then fail naming the chunks. `verify` plans and runs its refresh the same way, then checks. Chunk completions remove the chunk from the state under a cache lock, so no update is lost between concurrent writers.

**Tech Stack:** PHP 8.5, Laravel 12, Horizon, Pest. Code in `Modules/AI` (`laraplate-ai`); Horizon config, spec and plans in `laraplate`.

---

### Task 1: State and store

- [x] **Step 1:** `EmbeddingSwitchState` gains `chunkPhase`, `chunksTotal`, `chunksDone`, `pendingChunks` (id => model, from, to), with defaults, so a stored JSON state without them loads unchanged; helpers to plan, complete a chunk and merge completions recorded since a snapshot.
- [x] **Step 2:** `EmbeddingSwitchStore::update()` re-reads and writes the state under a cache lock; the orchestrator and the chunk jobs write through it.
- [x] **Step 3:** Tests: backwards-compatible load, chunk completion is idempotent and guarded, completions are merged, not lost.

### Task 2: Chunk planning and the chunk job

- [x] **Step 1:** `EmbeddingSwitchCorpus` plans key ranges per model (open first and last range) and walks the searchable records of one range.
- [x] **Step 2:** `EmbeddingSwitchIndexes` splits into `prepare()` (profile index, recreate or empty each index, RAG rebuild), `plan()` and `writeChunk()`, each write inside `VectorModelContext::using(target)` with the target's dimensions and similarity.
- [x] **Step 3:** `IndexDocumentsChunkJob` on queue `embeddings-index`: skips a chunk the state no longer expects, writes it, records its completion.
- [x] **Step 4:** Config key `ai.features.embeddings.index_chunk_size` (env `AI_EMBEDDINGS_INDEX_CHUNK_SIZE`, default 250).
- [x] **Step 5:** Tests: planning covers every record, idempotent rewrite, stale chunk skipped.

### Task 3: Orchestrator, resume, status

- [x] **Step 1:** `indexes`: prepare and plan in one pass, dispatch in the next, wait while `embeddings-index` holds jobs, re-dispatch pending chunks up to 3 rounds, fail naming them.
- [x] **Step 2:** `verify`: plan the refresh, dispatch and wait the same way, then run the checks.
- [x] **Step 3:** `--resume`: a failed or interrupted `indexes` re-dispatches only its pending chunks; a failed refresh replans the refresh without rebuilding the indexes.
- [x] **Step 4:** `ai:embeddings:status`, the settings lock text and the command's refusal show chunk progress.
- [x] **Step 5:** `SwitchEmbeddingModelJob::TIMEOUT_SECONDS` and every sentence about it and about `retry_after` restated for what the control job still does.
- [x] **Step 6:** Tests: bounded rounds and failure message, resume re-dispatches only incomplete chunks, chunked verify refresh, the existing switch tests stay green.

### Task 4: Horizon and documentation

- [x] **Step 1:** `config/horizon.php`: `supervisor-embeddings-index` in defaults and every environment; the switch supervisor comment restated; test on the config.
- [x] **Step 2:** `Modules/AI/docs/rag/MODULE.md` (phases, operating notes), `Modules/AI/README.md` (queues, env var), spec section 4.4, 10 and 11.
- [x] **Step 3:** Whole `Modules/AI` suite once, Pint on the changed files.
- [x] **Step 4:** Close this plan (delivery status, documented in), index entries, `tests/Unit/ClosedPlansPointToDocumentationTest.php`, `bash plan-status`.
- [x] **Step 5:** Commits: `Modules/AI`; `laraplate` (Horizon, spec, plan, indexes); the submodule pointer in `laraplate`.

## Delivery status (2026-10-06): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md` (the switch procedure, failure, operating notes), `Modules/AI/README.md` (model switch line, `AI_EMBEDDINGS_INDEX_CHUNK_SIZE`), spec sections 4.4, 10 and 11.

Divergences and refinements, all recorded in the spec (section 11, chunked index writes):
- The wait reads `Queue::size` of the dedicated `embeddings-index` queue together with the pending count in the state: the state alone cannot tell a running chunk from a lost one. No other job uses that queue.
- The RAG rebuild stays one step of the switch job, so `SwitchEmbeddingModelJob::TIMEOUT_SECONDS` stays 900 and the connection keeps `retry_after` >= 1000 s; the requirement did not disappear, it is now justified by the RAG rebuild alone.
- `--resume` of a `verify` whose refresh ran out of rounds refreshes every document again, not only the pending chunks (ruling R18 of the earlier plan: records edited while the switch was failed must be rewritten); it no longer rebuilds the indexes for that case. A failed or interrupted `indexes` writes only its pending chunks.
- Not verified: Elasticsearch, PostgreSQL, a real redis queue and cache lock (spec section 10).
