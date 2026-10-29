# AI module on standard servers

**Status:** Built 2026-10-23, plan `../plans/2026-10-22-ai-standard-servers.md` (closed). The hands-on verification of section 9 is done for Infinity (test A, see Verification); TEI and speaches are not yet run, and the guide says so.

**Date:** 2026-10-22

**Modules:** `Modules/AI` only. Core is not touched: `IReranker` and `IRerankerWithModel` already say "pairs in, scores in [0, 1] out" and nothing in Core knows a server.

**Supersedes:** the evolutions of `2026-10-20-self-hosted-ai-services-client-design.md`: E1 is delivered here against standard protocols, E2 (asynchronous transcription) and E6 (Filament status panel) are dropped because they depended on `whisper-api` and `sentence-transformers-api`. Its Phase 0 and Phase 1 stay delivered; section 6 says what of them survives.

**Research:** stack root, `docs/superpowers/specs/2026-10-22-self-hosted-ai-services-alternatives-design.md` (stack pull request 12). It holds the sourced comparison of servers and the recommendation this spec acts on. Facts below that were re-read for this spec are marked in section 12.

## 1. Problem and goal

`Modules/AI` talks to two Python services of the maintainer through contracts only they speak: `POST /embed` and `GET /health` (`api_version`) of `sentence-transformers-api`, `POST /score` of the same service as a cross-encoder, `POST /transcribe` of `whisper-api`. Both services were declared obsolete before they ran in production: maintained servers cover the same needs (research spec, section 1).

The public commitment of the AGPL edition is that it keeps a public, maintained way to run every AI feature. The two services may go away only once the AI module speaks standard, documented APIs. Goal: the module speaks OpenAI embeddings, two rerank dialects and OpenAI transcription; the two services and everything that mentions them are removed.

## 2. Decisions

| # | Decision | Taken |
|---|---|---|
| D1 | **Clean cut.** One release replaces the native clients with the standard ones and removes the native ones, their fixtures, tests and guides. No dual mode, no alias. There are no production users. | Owner, 2026-10-22 |
| D2 | **Servers are declared by whoever installs, in `config/ai.php` with URL and key from `.env`. Settings keep only dropdowns and toggles.** An administrator who can edit an address could make the backend call any host (SSRF), and keys do not belong in the database. | Owner, 2026-10-22 |
| D3 | **Optional server per embedding profile.** A profile may name a server; without one it uses the default `embeddings` server. One process per model (TEI) and one process for all models (Infinity, Ollama, LocalAI) are both expressed with it. | Owner, 2026-10-22 |
| D4 | **The provider of the profiles is renamed `openai_compatible`** (`openai_compatible:intfloat/multilingual-e5-small`). No alias for `sentence_transformers`. | Owner, 2026-10-22 |
| D5 | **The two repositories are archived first and deleted later**, only on a later explicit decision of the owner. | Owner, 2026-10-22 |
| D6 | The module speaks one embeddings dialect (OpenAI `/v1/embeddings`), two rerank dialects (`tei`, `cohere`) and one transcription dialect (OpenAI `/v1/audio/transcriptions`). | Proposed here |
| D7 | Query and passage prefixes stay on the client (`PrefixingEmbeddingsProvider` unchanged). | Proposed here, from the current code |
| D8 | The documented servers are TEI (embeddings, reranking) with Infinity v1 as the no-Docker alternative, and speaches (transcription). The code is the same for every server of a dialect, so this choice does not block the client work. | Research spec, confirmed by the hands-on check of section 9 |
| D9 | A new command `ai:servers:check` and the existing embedding commands carry the health checks; the Filament panel is dropped. | Proposed here |

## 3. What changes, at a glance

| Today | After |
|---|---|
| `Ai/Embeddings/SentenceTransformersEmbeddingsProvider` (`POST /embed`) | `Ai/Embeddings/OpenAiCompatibleEmbeddingsProvider`, a subclass of Neuron's `OpenAILikeEmbeddings` (`POST /v1/embeddings`) |
| `Ai/Embeddings/EmbeddingServiceIdentity` (`/health`, `api_version`, probe on `/embed`) | `Ai/Embeddings/EmbeddingServerIdentity`: probe on `/v1/embeddings`, `GET /info` where the server has it |
| `Services/CrossEncoderService` (`POST /score`) | same class, dialects `tei` and `cohere` |
| `Ai/MediaAnalysis/Transcription/WhisperTranscriber` (`POST /transcribe`) | same class, OpenAI `POST /v1/audio/transcriptions` |
| env `SENTENCE_TRANSFORMERS_*`, `CROSS_ENCODER_*`, `WHISPER_*` | env `AI_EMBEDDINGS_*`, `AI_RERANK_*`, `AI_TRANSCRIPTION_*` through `ai.servers` |
| profile provider `sentence_transformers` | `openai_compatible` |
| guides `SENTENCE_TRANSFORMERS_INSTALLATION.md`, `WHISPER_INSTALLATION.md` | one guide for the standard servers |

## 4. Servers and configuration (D2, D3)

`config/ai.php` gets a `servers` map. Each entry has `url`, `api_key`, `timeout` and what its capability needs.

```php
'servers' => [
    'embeddings' => ['url' => env('AI_EMBEDDINGS_URL'), 'api_key' => env('AI_EMBEDDINGS_API_KEY'), 'timeout' => (int) env('AI_EMBEDDINGS_TIMEOUT', 30), 'batch_size' => (int) env('AI_EMBEDDINGS_BATCH_SIZE', 32)],
    'embeddings_minilm' => ['url' => env('AI_EMBEDDINGS_MINILM_URL'), 'api_key' => env('AI_EMBEDDINGS_MINILM_API_KEY')],
    'rerank' => ['url' => env('AI_RERANK_URL'), 'api_key' => env('AI_RERANK_API_KEY'), 'dialect' => env('AI_RERANK_DIALECT', 'tei'), 'model' => env('AI_RERANK_MODEL')],
    'transcription' => ['url' => env('AI_TRANSCRIPTION_URL'), 'api_key' => env('AI_TRANSCRIPTION_API_KEY'), 'timeout' => (int) env('AI_TRANSCRIPTION_TIMEOUT', 120)],
],
```

- A profile entry of `ai.features.embeddings.models` may carry `'server' => '<name>'`; without it the default is `embeddings`. A server without a URL falls back to the default `embeddings` server for URL, key, timeout and batch size, so every shipped profile has its own optional slot and `.env.example` can list them all.
- A deployment with TEI runs one process per model: the second model gets its own port, `AI_EMBEDDINGS_MINILM_URL` points to it and the profile names `embeddings_minilm`. A deployment with Infinity, Ollama or LocalAI sets only `AI_EMBEDDINGS_URL`.
- The reranker has no fallback to the embeddings server: TEI serves a reranker model in its own process. No URL means no reranking, as today.
- `ProviderConfiguration::isConfigured('openai_compatible')` is true when the resolved server of the profile has a URL; `whisper` is replaced by the same test on `transcription`.
- Settings keep what they hold today: the embedding profile (`features.embeddings.model`), the transcription choice (`features.media_analysis.transcription.model`) and the reranker toggle. A new `ai.features.media_analysis.transcription.models` config list names the models the dropdown offers (default `Systran/faster-whisper-base`).
- New and removed variables go into `.env.example` in the same work block. The Core command that proposes the variables missing from a production `.env` has its own spec and plan; this spec only needs the variables to be in `.env.example`.

## 5. The three clients

### 5.1 Embeddings: OpenAI `POST /v1/embeddings`

`OpenAiCompatibleEmbeddingsProvider extends OpenAILikeEmbeddings` (Neuron 4.1.12: `baseUri`, `key`, `model`, `dimensions`, `httpClient`). It reuses Neuron's request body, its `index`-based answer handling and its credential-free error messages. It adds three things Neuron does not do:

- **Batching.** Neuron splits documents in fixed chunks of 100 (`OpenAIEmbeddingsProvider::embedDocuments`). TEI refuses a client batch over `--max-client-batch-size` (default 32; the OpenAPI document lists `413`). The subclass hands the documents to Core's `AdaptiveBatchController` (start at the server's `batch_size`, halve on failure or slowness), exactly as the current provider does.
- **Request id.** One `X-Request-Id` per call, through the protected `httpHeaders` of `HasHttpClient`, logged on failure through `ServiceRequestId`.
- **Failures.** A `NeuronAI\Exceptions\HttpException` is converted to the existing `SelfHostedServiceException` with the id and the status, so the commands keep printing it.

The body sends `model` (the service model of the profile; TEI ignores it, multi-model servers use it) and `dimensions` only for models that take it, as `EmbeddingsProviderFactory::createOpenAI` already decides. An answer naming a different model than the profile is refused, as today (`EmbeddingModelProfile::sameServiceModel`).

`max_length`, `truncation` and `normalize_embeddings` of the native body have no standard counterpart. The server owns truncation (TEI `--auto-truncate`, documented) and normalisation; the identity check of section 7 verifies the vector norm.

### 5.2 Reranking: dialects `tei` and `cohere`

`CrossEncoderService` keeps its contract (`score()`, `scoreWithModel()`, at most 64 pairs, scores clamped to [0, 1], failure throws and `EnsembleSearchService` keeps the fused order). The server entry names the dialect.

| | `tei` (TEI) | `cohere` (Infinity, vLLM) |
|---|---|---|
| Request | `POST {url}/rerank` `{query, texts}` | `POST {url}/rerank` `{model, query, documents}` (`model` from `servers.rerank.model`, required for this dialect) |
| Answer | `[{index, score}]` | `{results: [{index, relevance_score}], model}` |
| Order | order of `texts` is not promised: scores are placed by `index` | sorted by score: scores are placed by `index` |
| Range | sigmoid unless `raw_scores`: [0, 1], which the blend in `EnsembleSearchService` needs; `raw_scores` is never sent | same default |
| Model name | `GET /info` `model_id`, cached ten minutes | `model` of the answer |

- One query and many texts per call, as today; `EnsembleSearchService` builds every pair from the same query. The client sends `query` once and the texts of the pairs.
- A missing `index`, a repeated one, one out of range or a count different from the pairs is a malformed answer and throws, as a wrong count does today. Zeros made up for an unusable answer would pass for a real rerank.
- TEI answers `424` when the loaded model is not a reranker: the failure message says so.
- The TEI client batch limit applies: the guide tells the installer to start the reranker process with `--max-client-batch-size 64`, the most pairs the client sends. `ai:servers:check` compares it with `/info` and says so when it is lower.

### 5.3 Transcription: OpenAI `POST /v1/audio/transcriptions`

`WhisperTranscriber` posts multipart `file` and `model` (the service model of the transcription choice) and `response_format=json`; it reads `text` only, as today. Its contract does not change: no URL, a missing file, any failure or an empty text returns `null` and the media indexes without a transcript; a failed call logs one warning with the request id and nothing of the media. The class is renamed `OpenAiTranscriber`.

The upload and duration limits of `whisper-api` have no equivalent in speaches (research spec section 5, not found in `config.py`): the guide tells the installer to put a body-size limit on the reverse proxy and states that the duration is not limited. The existing `timeout` stays.

## 6. What survives of Phase 1 (delivered 2026-10-21)

| Delivered | After this spec |
|---|---|
| `Ai/SelfHosted/ServiceRequestId`, `SelfHostedServiceException`, request id on every call | **Kept.** Used by the three clients; the `service` label becomes `embeddings`, `rerank`, `transcription` |
| `api_version` check and its warning in `EmbeddingServiceIdentity` | **Dropped** with the `/health` convention |
| Fixtures `tests/Fixtures/SelfHostedServices/*.openapi.json` and `*.version` | **Dropped.** Replaced by the fixtures of section 8 |
| `tests/Stubs/SelfHostedServiceContract` (schema checker) and `SelfHostedServicesContractTest` | **Helper kept**, the test rewritten against the new fixtures |
| Guides' readiness status lines | **Dropped** with the guides |

## 7. Identity, health and errors

The custom `/health` plus `api_version` convention goes away. What the module needs to know to warn about a misconfigured server:

- **Embeddings, per profile.** `EmbeddingServerIdentity::probe($profile)` sends the probe text through the profile's server and checks: the answer carries a vector of the profile's dimensions (`EmbeddingDimensionProbe`, unchanged); the answered `model` is the profile's; when the profile declares `normalize`, the vector norm is within 1e-3 of 1 (a server started without normalisation fails here instead of poisoning the index). Where the server offers `GET /info` (TEI) the `model_id` is also read as a second witness. `ai:embeddings:switch` runs it against the **target profile's server** in its preflight, and refuses to start when that server does not answer or does not run the model; `ai:embeddings:probe` and `ai:embeddings:repair` use the same class.
- **Rerank.** A probe scores two fixed pairs and checks two numbers in [0, 1] come back.
- **Transcription.** Not probed: a probe would need audio. A failed call is logged with its id.
- **`ai:servers:check`** (new, read-only): lists every configured server with its capability, URL (no credentials), the result of the probe above, the batch limit TEI reports in `/info` against the configured `batch_size`, and the exit code `1` when any configured server fails. It is the operator's answer to "is it misconfigured".
- Logs and exceptions keep the request id, the endpoint without credentials and the status (`ServiceRequestId`). Nothing of the text, the query or the file name.

## 8. Prefixes, vectors and the profile switch

- D7: nothing changes in how prefixes are applied. TEI must be started **without** `--default-prompt` (and the `/v1/embeddings` body carries no `prompt_name`), otherwise the prefix is applied twice. The guide says it; the probe cannot detect it.
- The same model through another server gives the same vectors up to numeric noise, so a deployment moving from `sentence-transformers-api` to TEI with the same model does not need re-embedding. The profile key changes (D4), which Core's setting `search.vector.model` (seeded in `CoreDatabaseSeeder`) and the embedding rows carry: the project is pre-stable, so a development database is rebuilt (`migrate:fresh`) or switched with `ai:embeddings:switch`; the seeders and `ai.features.embeddings.models` are renamed in the same release. No migration for existing keys.
- `EmbeddingSwitchPreview` and the switch latency estimate read `batch_size` and the latency of the profile's server instead of the fixed provider id.
- A profile whose server has no URL fails `isConfigured` and is not offered in the dropdown, as an unconfigured provider is today.

## 9. Verification before the guides and the archive

The research spec was desk research: nothing was run. Before the guides are written and before the repositories are archived, the maintainer runs, or explicitly allows, tests A, B and C of section 8 of the research spec on their own machine (Infinity in a throwaway venv, TEI built from source, speaches with `uv`), and the results are recorded in a **Verification** section appended to this spec: vector equality with the current service for the same prefixed text, rerank order and score range for 64 pairs, the 5 s per search figure, build time and size of TEI on the LXC, `/health` while the model loads, and the behaviour of speaches on an oversized file. Nothing is installed without that go-ahead. A failure there changes D8 (the documented server), not the client code.

## Verification

**Test A, Infinity, run on 2026-10-23** at the owner's request (Infinity 0.0.78, CPU, Python 3.12 from `uv` in a throwaway environment, a 14-core workstation, models from Hugging Face; raw results in the shared notes folder, `infinity-verification-2026-10-23.json`). The system Python was 3.14, which Infinity refuses (it needs below 3.14).

- Install: CPU torch first, then `infinity_emb[server,torch]==0.0.78` (1.2 GB in all); `[all]` was not tried. Start: `infinity_emb v2 --model-id intfloat/multilingual-e5-small --model-id cross-encoder/mmarco-mMiniLMv2-L12-H384-v1 --engine torch --device cpu --port 7997 --api-key ... --url-prefix /v1`.
- Embeddings: both models load; `POST /v1/embeddings` answers `data[].embedding` with `index`, `model` and `usage`; 384 dimensions, norm 1; vectors identical to `sentence-transformers` for the same prefixed text (cosine 0.99999999, largest difference 0.0). The `model` in the answer is the requested name.
- Rerank: `POST /v1/rerank` `{model, query, documents}` answers `results[{relevance_score, index, document}]` sorted by score; scores are the sigmoid of the `CrossEncoder` logits (difference 0.0, same order), range 0.00003 to 0.99; `raw_scores` gives the logits. 64 pairs took 0.7 s on this machine (the figure of 5 s per search was measured by the owner on the former service and their CPU; not comparable). A request without `model` is a `400`.
- Routes: with `--url-prefix /v1`, `/v1/embeddings`, `/v1/rerank`, `/v1/models` and `/v1/openapi.json` exist and `/health` stays at the root; the key protects the first three, `/health` and the OpenAPI document answer without it. There is no `/info` and no `X-Request-Id` echo.
- Errors and limits: an unknown model is a `400` that lists the loaded ones, an embedding model asked to rerank (or the reverse) a `400`, a wrong or missing key a `401`, an empty batch a `422`; empty texts, 12 000-character texts and batches of 200 are accepted; the `dimensions` field truncates the vector for any model (not sent by the module).
- The module against it: `ai:servers:check` passed for the e5 profile and the reranker (dialect `cohere`, `AI_RERANK_URL` with the `/v1` prefix) and failed, as designed, for the MiniLM profile that the process does not run; `ai:embeddings:probe` passed for the e5 profile; a wrong key made every row fail.

**Test B, TEI, run on 2026-10-29** at the owner's request, on their server `deneb` (x86_64, Ubuntu 22.04.5, glibc 2.35, 3912 MB of RAM shared with other services, 3925 MB of swap, 24 GB of free disk), TEI v1.9.4 with `intfloat/multilingual-e5-small`.

- **Build from source fails on this host.** `cargo install --path router -F mkl -j 1` with the pinned Rust 1.92.0, as an unprivileged user under a 2 GB memory cap, with swap off, at `nice 19`; peak memory about 1.55 GB, so memory was not the problem. Three attempts ended with exit 101 at the link step. Attempt 1 (19 min 25 s): `undefined symbol: hgemm_`, `sgemm_`, `dgemm_`, `vsAdd`; the build script of `intel-mkl-src` found no MKL and put no `mkl` library on the link line. Attempt 2 (4 min 41 s), after `apt install intel-mkl` (Ubuntu 22.04 ships 2020.4.304): the same error, because Cargo reused the cached build-script result. Attempt 3 (8 min 32 s), after `cargo clean -p intel-mkl-src -p intel-mkl-tool`: the build script now links `mkl_intel_lp64`, `mkl_intel_thread`, `mkl_core` and `iomp5`, and the link still fails with `undefined symbol: hgemm_` (the first error listed; the linker offers `cgemm_` from `libmkl_intel_lp64.so`). The half-precision matrix product that `candle-core` calls is not provided by that MKL 2020.4, so a source build needs a newer oneMKL than the distribution package; which minimum version was not measured. A build without `-F mkl` was not tried.
- **The Docker image works.** `ghcr.io/huggingface/text-embeddings-inference:cpu-1.9.4` exists (`docker manifest inspect`), started with `--memory 1500m --memory-swap 1500m`, `-p 8080:80`, a volume for the model cache, `--model-id intfloat/multilingual-e5-small --api-key ... --max-client-batch-size 32`, without `--default-prompt`. `POST /v1/embeddings` with `{"model", "input"}` and the bearer key answers `data[].embedding`; the container used 858 MiB after the first request (limit 1.465 GiB, 6 processes), and the first start downloaded the weights (about 510 MB on the network).
- **The module against it:** `ai:servers:check` passes for the e5 profile (model `intfloat/multilingual-e5-small`, 384 dimensions, norm 1.0000, so the server applies no prompt of its own and normalises by default), and fails for the MiniLM profile, as designed, because the process does not run that model; that profile was removed afterwards (laraai pull request 7).
- **Not measured:** the reranker on TEI, memory under a full batch of 32 documents, the time of a batch, `/health` while the model loads, and the build time of a successful source build.

**Test C (speaches) is not run.** The guide marks what is verified and what is written from documentation.

## 10. Removal and sequence

AI side (this spec, its plan):

1. Plan and spec merged.
2. Clients, configuration, renames and tests (TDD), with the new contract fixtures: `docs/openapi.json` of TEI, `/openapi.json` of Infinity and `docs/openapi.json` of speaches, each with the server version it comes from.
3. Hands-on verification (section 9).
4. Guides replaced by one, README, `docs/rag/MODULE.md`, `docs/rag/DEPLOYMENT.md`, `CHANGELOG.md`, `docs/GLOSSARY.md`, `docs/SEARCH_AND_TRANSLATION.md`, the config comments; `.env.example`; the old classes, fixtures and tests deleted (D1).

Stack root (a separate stack-root plan, after the AI release ships):

5. README banner on each repository, then archive (D5): `getlaraplate/sentence-transformers-api` and `getlaraplate/whisper-api`.
6. Remove the stack `.gitmodules` entries `laraplate-whisper` and `laraplate-sentencetransformer` and the two folders; the submodule pointers of `laraplate` are not bumped by the agent.
7. `docs/legal/org-profile-readme-draft.md`, `contributing-draft.md`, `cla-draft.md`, `docs/licensing.md`: remove the services, state that no Python service is required by the AGPL edition.
8. Close the production-readiness specs and plans of the two services as superseded, with the reason, following the plans rule (`- [-]` and a delivery status). Their Phase 1 plans are already closed.
9. Deletion of the repositories: only on a later explicit decision (D5).

## 11. Risks and open points

- **Neuron batches by 100.** The subclass must not call the parent `embedDocuments`: a test sends 33 documents to a fake server that refuses more than 32 and passes.
- **TEI refuses a batch over its limit** with `413`; `--max-client-batch-size` is documented as at least `batch_size` (32) and 64 for the reranker; the check of section 7 reports a lower value.
- **`--default-prompt` doubles the prefix** (section 8).
- **Infinity v1 is in maintenance and V3 has a restricted licence**; it needs its own venv (`numpy<2`, `sentence-transformers<4`, `transformers<=5.0`, Python below 3.14). It is the alternative, not the default.
- **Infinity publishes `/embeddings` and `/rerank` under its `--url-prefix`**, not under `/v1` (its OpenAPI document, read 2026-10-23). The client sends `{url}/v1/embeddings` and `{rerank url}/rerank`, so the guide starts Infinity with `--url-prefix /v1` and sets `AI_RERANK_URL` to `http://host:port/v1`; the flag spelling was confirmed by test A.
- **speaches** has no stable release after 0.8.3 (`v0.9.0-rc.3`) and no limits found; any OpenAI-style server works because the client is generic. Cloud OpenAI transcription is not in scope.
- **The `/v1/embeddings` normalisation default** of TEI is not stated in the OpenAPI document. Section 7 checks it with the norm and the hands-on test records it.
- **The dropdown of transcription models** needs a read of `AiModelChoice`, `AiModelFeature::supportedProviders()` and `ModelCatalog` to accept `openai_compatible:<model>`; the plan starts there.
- **Core has no `.env` tool yet.** `.env.example` lists 99 lines against about 375 `env()` names in the config files (measured 2026-10-22); the new variables are added by hand until the Core command and its guard test exist.

## 12. Facts read for this spec

- `Modules/AI` source on 2026-10-22: `SentenceTransformersEmbeddingsProvider`, `EmbeddingServiceIdentity`, `EmbeddingsProviderFactory`, `CrossEncoderService`, `WhisperTranscriber`, `ServiceRequestId`, `EmbeddingModelRegistry`, `EmbeddingSwitchPreview`, `EmbeddingsSwitchCommand`, `AIDatabaseSeeder`, `AiModelFeature`, `config/config.php`; Core's `EnsembleSearchService`, `IReranker`, `IRerankerWithModel`.
- `vendor/neuron-core/neuron-ai` 4.1.12: `OpenAIEmbeddingsProvider` (chunks of 100, `index` handling, `dimensions`), `OpenAILikeEmbeddings`, `HasHttpClient`, `HttpException`. Neuron has no reranking or transcription.
- TEI `docs/openapi.json` (read 2026-10-22): `/v1/embeddings` takes `input`, `model`, `dimensions`, `encoding_format` and answers `data[].embedding` with `index` and `model`; `413` for a batch over the limit; `/rerank` takes `query`, `texts`, `raw_scores` and answers `[{index, score}]`; `/info` carries `model_id`, `max_client_batch_size`, `version`; `/health` answers `200` or `503`.
- Everything else about TEI, Infinity, speaches, LocalAI and the others is from the research spec, section 10.
