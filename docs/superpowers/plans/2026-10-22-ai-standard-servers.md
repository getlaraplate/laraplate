---
status: completed
created_on: 2026-10-22
---
# AI module on standard servers. Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax; tick each step when it is done, in the same work block (`.ai/rules/plans.md`). A step deliberately not done is `- [-]` with its reason.

**Goal:** `Modules/AI` speaks OpenAI embeddings, two rerank dialects and OpenAI transcription, and nothing in it mentions `sentence-transformers-api` or `whisper-api`.

**Architecture:** servers are declared in `config/ai.php` from `.env` (`AiServers` resolves them, a profile may name one); the three clients (`OpenAiCompatibleEmbeddingsProvider` extending Neuron's `OpenAILikeEmbeddings`, `CrossEncoderService` with a dialect, `OpenAiTranscriber`) replace the native ones in the same release; health is a probe per server, not a `/health` convention. Core changes by one rename.

**Tech Stack:** PHP 8.5, Laravel 12, Neuron AI 4.1.12 (`OpenAILikeEmbeddings`, `CurlHttpClient`), Pest, Core's `AdaptiveBatchController`. No new dependency.

**Spec:** `../specs/2026-10-22-ai-standard-servers-design.md` (decisions D1 to D9 and sections 4 to 11). Stack-root removal work: `2026-10-22-ai-standard-servers-stack-removal.md` in the stack `docs/superpowers/plans/`.

## Global Constraints

- Work on branches and pull requests, never on `master`, in worktrees from `origin/master`. Code lives in the `Modules/AI` repository (`getlaraplate/laraai`) and `Modules/Core` (`getlaraplate/laracore`); this plan, `.env.example` and the indexes live in `laraplate`. Commit with `git -c user.name=swolley -c user.email=myentity@inwind.it commit`, no `Co-Authored-By`. Never bump a submodule pointer.
- `declare(strict_types=1)`, `final`, explicit types, PHPDoc over inline comments, `#[Override]` where overriding. Pint from the laraplate root on explicit files: `vendor/bin/pint --format agent <files>` (`.ai/rules/modules.md`).
- Tests in the module `tests/`, Pest; no class declared in a test file (stubs in `tests/Stubs/`). Run `php artisan config:clear` before tests and never leave a cached config; `php artisan test --compact <path>`.
- D1: clean cut. A task that replaces a class deletes the old class, its tests and its fixtures in the same task.
- D2: URL, key, dialect and model of a server come from config/env only. No URL or key in a setting.
- No secret, text, query, transcript or file name in a log line or an exception message; URLs are logged without credentials (`ServiceRequestId::endpointOf`).
- New variables (spec section 4): `AI_EMBEDDINGS_URL`, `AI_EMBEDDINGS_API_KEY`, `AI_EMBEDDINGS_TIMEOUT` (30), `AI_EMBEDDINGS_BATCH_SIZE` (32), `AI_EMBEDDINGS_MINILM_URL`, `AI_EMBEDDINGS_MINILM_API_KEY`, `AI_RERANK_URL`, `AI_RERANK_API_KEY`, `AI_RERANK_DIALECT` (`tei`), `AI_RERANK_MODEL`, `AI_TRANSCRIPTION_URL`, `AI_TRANSCRIPTION_API_KEY`, `AI_TRANSCRIPTION_TIMEOUT` (120). Removed: every `SENTENCE_TRANSFORMERS_*`, `CROSS_ENCODER_*`, `WHISPER_*`.
- English in code and docs, no em-dashes.

## Review Focus

- A 33rd document sent to a server that refuses more than 32 per request still embeds (Task 2).
- A server returning vectors that are not normalised for a profile that declares `normalize` is refused before it poisons an index (Task 4).
- A rerank answer with a repeated, missing or out-of-range `index`, or an unsorted order, never yields scores in the wrong place (Task 5).
- A profile whose server has no URL falls back to the default server; with no default either it is not offered in the dropdown (Tasks 1 and 3).
- An oversized upload answered `413`, or a URL with `user:password@`, leaves no file name, no key and no password in any log or exception (Tasks 2 and 6).

---

### Task 1: Server configuration

**Files:** create `Modules/AI/app/Ai/SelfHosted/AiServer.php`, `Modules/AI/app/Ai/SelfHosted/AiServers.php`; modify `Modules/AI/config/config.php` (add `servers`, remove `providers.sentence_transformers`, `providers.whisper`, `providers.cross_encoder`), `laraplate/.env.example`; tests `Modules/AI/tests/Unit/Ai/SelfHosted/AiServersTest.php`, `Modules/AI/tests/Unit/EnvExampleListsAiServersTest.php`.

**Interfaces:**
- Produces: `AiServer(string $name, string $url, ?string $apiKey, int $timeout, int $batchSize, ?string $dialect, ?string $model)` with `isConfigured(): bool` (URL not empty); `AiServers::named(string $name): AiServer`, `forProfile(EmbeddingModelProfile $profile): AiServer`, `rerank(): AiServer`, `transcription(): AiServer`, `all(): array<string, AiServer>`.

- [x] **Step 1: Failing tests** in `AiServersTest`: `it('resolves the default embeddings server')`; `it('falls back to the embeddings server for url, key, timeout and batch size when a named embeddings server has no url')`; `it('uses the own url of a named server that has one and does not borrow the key of the default')`; `it('has no fallback for rerank and transcription')` (empty URL, `isConfigured()` false); `it('resolves the server a profile names and the default when it names none')` (needs `EmbeddingModelProfile::$server`, added in Task 3: build the profile through a test helper that passes `server`); `it('trims a trailing slash from the url')`. `EnvExampleListsAiServersTest`: every variable of the list in Global Constraints appears as `NAME=` in `base_path('.env.example')` and none of the removed prefixes does.
- [x] **Step 2: Run, expect FAIL** (`php artisan config:clear && php artisan test --compact Modules/AI/tests/Unit/Ai/SelfHosted/AiServersTest.php Modules/AI/tests/Unit/EnvExampleListsAiServersTest.php`).
- [x] **Step 3: Implement** the two classes and the `servers` config of spec section 4 (entries `embeddings`, `embeddings_minilm`, `rerank`, `transcription`); `named()` reads `ai.servers.<name>` through `ai_config_*` helpers and applies the fallback to `embeddings` for any name other than `rerank` and `transcription`. Add the variables to `.env.example`, remove none that does not exist there.
- [x] **Step 4: Run, expect PASS**; pint on the changed PHP files.
- [x] **Step 5: Commit** in `Modules/AI` `feat: declare the AI servers in configuration`; commit `.env.example` in `laraplate` `chore: list the AI server variables`.

### Task 2: OpenAI-compatible embeddings provider

**Files:** create `Modules/AI/app/Ai/Embeddings/OpenAiCompatibleEmbeddingsProvider.php`, `Modules/AI/tests/Stubs/ScriptedHttpClient.php`; test `Modules/AI/tests/Integration/OpenAiCompatibleEmbeddingsProviderTest.php`; delete `SentenceTransformersEmbeddingsProvider.php` and `tests/Integration/SentenceTransformersEmbeddingsProviderTest.php` at the end of Task 3 (the factory still builds it until then).

**Interfaces:**
- Consumes: `AiServer`, `ServiceRequestId`, `SelfHostedServiceException`, Core `AdaptiveBatchController`.
- Produces: `OpenAiCompatibleEmbeddingsProvider::__construct(AiServer $server, string $model, ?int $dimensions = null, ?HttpClientInterface $httpClient = null)`, extending `NeuronAI\RAG\Embeddings\OpenAILikeEmbeddings`, `embedText(string): list<float>`, `embedDocuments(array): list<Document>`. `ScriptedHttpClient(Closure $respond)` implements `HttpClientInterface`, records every `HttpRequest` in `public array $requests`, answers what the closure returns (`HttpResponse` or throws `HttpException`).

- [x] **Step 1: Failing tests:** `it('posts to /v1/embeddings of the server root with model, input and bearer key')`; `it('puts every answer vector on the document of its index, whatever the answer order')`; `it('sends dimensions only when given')`; `it('splits 33 documents into requests of at most the server batch size')` (server batch 32, the fake answers 413 above 32: expect two requests, all documents embedded); `it('halves the batch and retries when a request fails')`; `it('sends one X-Request-Id per request, 32 hex characters, and logs the id on failure')`; `it('throws SelfHostedServiceException with the id and the status when the server answers an error')`; `it('refuses an answer that names another model than the profile')` (`sameServiceModel`); `it('never puts a url password, a key or a text in the exception or the log')` (`Log::spy()`, URL `http://user:secret@host`).
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** the provider: `baseUri = rtrim($server->url, '/') . '/v1'`, `CurlHttpClient(timeout: $server->timeout)` unless a client is given, key from the server (`''` when none). Override `embedDocuments` to run `AdaptiveBatchController(minBatch: 1, maxBatch: $server->batchSize, targetLatencySeconds: max(1.0, $server->timeout / 2), startBatch: $server->batchSize)` over a per-batch method that posts one request with its own `ServiceRequestId('embeddings', $baseUri . '/embeddings')` in `httpHeaders`; place vectors by the answer's `index`; override `embedText` the same way for one input. Convert `NeuronAI\Exceptions\HttpException` through `$request_id->exception()` (its status comes from `$exception->response?->statusCode`: extend `ServiceRequestId::statusOf` to read it). Validate the answered `model` like the current provider does. Reuse Neuron's body (`encoding_format`, `dimensions`); do not call `parent::embedDocuments`.
- [x] **Step 4: Run, expect PASS**; pint.
- [x] **Step 5: Commit** in `Modules/AI` `feat: add the OpenAI-compatible embeddings provider`.

### Task 3: Profiles, factory and the `openai_compatible` rename

**Files:** modify `Modules/AI/app/Ai/Embeddings/EmbeddingModelProfile.php` (`?string $server = null`, last argument), `EmbeddingModelRegistry.php` (read `server`; `configuredKeys()` keeps a key whose resolved server `isConfigured()`), `EmbeddingsProviderFactory.php` (`'openai_compatible'` replaces both sentence-transformers cases; builds the provider of Task 2 from `AiServers::forProfile`), `app/Ai/Providers/Models/ProviderConfiguration.php` (`openai_compatible` is configured when the embeddings or the transcription server is), `config/config.php` (profile keys `openai_compatible:intfloat/multilingual-e5-small` and `openai_compatible:all-MiniLM-L6-v2`, the latter with `'server' => 'embeddings_minilm'`), `app/Console/EmbeddingsPruneCommand.php`, `EmbeddingsSwitchCommand.php`, `EmbeddingsProbeCommand.php` (help texts and the provider test), `app/Ai/Embeddings/Switching/EmbeddingSwitchPreview.php` (provider id, batch size and latency from the profile's server); in `Modules/Core`: `database/seeders/CoreDatabaseSeeder.php:118`, `tests/Feature/Search/VectorSearchConfigTest.php`, `tests/Feature/Seeding/VectorSettingsSeedTest.php`, `tests/Unit/Search/PgvectorProfileIndexTest.php`, `docs/rag/SEARCH_MATCHING_DEVELOPER.md`, `docs/rag/SETTING_ACTIONS_DEVELOPER.md`; AI tests that name the old provider (`grep -rln sentence_transformers Modules/AI/tests`: update each). Delete `SentenceTransformersEmbeddingsProvider.php` and its old test.

**Interfaces:**
- Consumes: Task 1 and Task 2.
- Produces: `EmbeddingModelProfile::$server: ?string`; the provider id `openai_compatible` everywhere.

- [x] **Step 1: Failing tests:** update `EmbeddingsProviderFactoryTest` (`it('builds the OpenAI-compatible provider of the profile server with the profile model')`, `it('uses the server a profile names')`), `EmbeddingModelRegistryTest` (`it('reads the server of a profile')`, `it('does not offer a profile whose server has no url and no default')`, `it('offers a profile whose named server falls back to the default')`), `EmbeddingSwitchPreviewTest` (batch size and latency come from the profile's server), and rename the provider id in the remaining listed tests.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** the changes above. The factory still answers `openai`, `ollama`, `mistral`, `voyageai` as before; an unknown provider, including `sentence_transformers`, throws `InvalidArgumentException` (no alias, D4).
- [x] **Step 4: Run** `php artisan test --compact Modules/AI/tests/Integration/EmbeddingsProviderFactoryTest.php Modules/AI/tests/Unit/Embeddings Modules/AI/tests/Feature/EmbeddingSettingsSeedTest.php` and the three Core tests; expect PASS; pint.
- [x] **Step 5: Commit** in `Modules/AI` `refactor: rename the self-hosted embeddings provider to openai_compatible`; in `Modules/Core` `refactor: seed the vector model under the openai_compatible provider`.

### Task 4: Server identity, probes and the embedding commands

**Files:** create `Modules/AI/app/Ai/Embeddings/EmbeddingServerIdentity.php`, `Modules/AI/app/Ai/Embeddings/EmbeddingInspection.php`; modify `app/Console/EmbeddingsSwitchCommand.php` (`serviceRunsModel`, `returnRefusal`, `preflight`), `app/Console/RepairMissingEmbeddingsCommand.php`, `app/Console/EmbeddingsProbeCommand.php`, `app/Ai/Embeddings/Switching/EmbeddingSwitchPreview.php`; tests `Modules/AI/tests/Unit/Ai/Embeddings/EmbeddingServerIdentityTest.php` plus the existing command tests (`EmbeddingSwitchStartTest`, `EmbeddingSwitchResumeTest`, `RepairMissingEmbeddingsCommandTest`, `EmbeddingsProbeCommandTest`, `EmbeddingModelSettingConfirmationTest`, `tests/Stubs/EmbeddingSwitchHarness.php`); delete `EmbeddingServiceIdentity.php` and `tests/Unit/Ai/Embeddings/EmbeddingServiceIdentityTest.php`.

**Interfaces:**
- Consumes: Tasks 1 to 3.
- Produces: `EmbeddingServerIdentity::inspect(EmbeddingModelProfile $profile, ?int $timeout = null): EmbeddingInspection` (throws `SelfHostedServiceException`, `EmbeddingDimensionMismatch`); `EmbeddingServerIdentity::refusal(EmbeddingModelProfile $profile): ?string` (null when the profile's server runs its model with the right dimensions and norm). `EmbeddingInspection(?string $probeModel, ?string $infoModel, float $norm, ?int $maxClientBatchSize)` with `reportedModel(): ?array{model: string, source: string}` (probe first, `/info` second).

- [x] **Step 1: Failing tests:** `it('probes /v1/embeddings of the profile server and reports the answered model')`; `it('reads /info model_id and max_client_batch_size when the server answers it and ignores a 404')`; `it('refuses a vector of the wrong dimensions')` (message from `EmbeddingDimensionMismatch`); `it('refuses an unnormalised vector when the profile declares normalize')` (norm 3.0 against 1e-3 tolerance) and `it('does not check the norm when the profile does not normalise')`; `it('refuses a server whose model is not the profile model')` (probe and `/info` each); `it('refuses a server that names no model')` for the switch, while `inspect()` returns null model for the repair; `it('sends a request id on every call and attaches it to the failure')`. Update the command tests to fake `POST {server}/v1/embeddings` and `GET {server}/info` instead of `/embed` and `/health`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** with the `Http` facade like the class it replaces (keep `send()`'s request id and failure handling, drop `checkApiVersion` and `API_VERSION`), using the probe text and dimension check of `EmbeddingDimensionProbe` unchanged. The probe body is the OpenAI one (`model`, `input`); `/info` is read with a 5 s timeout and any non-2xx or connection error there is not a failure (`infoModel` stays null). The switch preflight and the preview call the profile's server, so `ai:embeddings:switch` to a TEI profile on another port checks that port.
- [x] **Step 4: Run** the listed tests; expect PASS; pint.
- [x] **Step 5: Commit** in `Modules/AI` `feat: identify the embedding server without a health convention`.

### Task 5: Reranking dialects

**Files:** create `Modules/AI/app/Ai/SelfHosted/RerankDialect.php` (enum `Tei = 'tei'`, `Cohere = 'cohere'`); modify `Modules/AI/app/Services/CrossEncoderService.php`; tests `Modules/AI/tests/Integration/CrossEncoderServiceTest.php` (rewrite), `Modules/AI/tests/Unit/Ai/SelfHosted/RerankDialectTest.php`.

**Interfaces:**
- Consumes: `AiServers::rerank()`.
- Produces: `CrossEncoderService::__construct(private ?AiServer $server = null)` (the server of `AiServers` when null); `score(array $pairs): array`, `scoreWithModel(array $pairs): RerankResult` unchanged.

- [x] **Step 1: Failing tests** (`Http::fake()`): `it('posts query and texts once to /rerank for the tei dialect and places each score by its index')`; `it('posts model, query and documents for the cohere dialect and places each score by its index')`; `it('clamps scores to [0, 1]')`; `it('throws on a repeated index, a missing index, an index out of range and a count different from the pairs')` (dataset of four answers, both dialects); `it('names the model: /info model_id cached for the tei dialect, the answered model for cohere')`; `it('refuses the cohere dialect without a configured model')`; `it('says the model is not a reranker on a 424 answer')`; `it('reuses the request id across retries')` (kept from Phase 1); `it('sends at most 64 pairs')`; `it('throws when no rerank server is configured')`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** the dialects: one query, texts taken from the pairs (all pairs share the query: assert it and throw otherwise), the answer parsed into a list indexed by pair position; keep the connect timeout 2 s, timeout 10 s, `retry(2, 200)` and `ServiceRequestId('rerank', ...)`. `/info` for `tei` is read once per ten minutes through the cache and never fails the rerank. Remove the `CROSS_ENCODER_URL` message.
- [x] **Step 4: Run, expect PASS**; also `php artisan test --compact Modules/Core/tests --filter=Ensemble`; pint.
- [x] **Step 5: Commit** in `Modules/AI` `feat: rerank through the tei and cohere dialects`.

### Task 6: OpenAI transcription

**Files:** create `Modules/AI/app/Ai/Providers/Models/ConfiguredModelLister.php` (lists the strings of `ai.features.media_analysis.transcription.models`); rename `Ai/MediaAnalysis/Transcription/WhisperTranscriber.php` to `OpenAiTranscriber.php`; modify `app/Providers/AIServiceProvider.php:107`, `app/Enums/AiModelFeature.php` (`Transcription`: supported providers `['openai_compatible']`, default choice `openai_compatible:Systran/faster-whisper-base`), `app/Ai/Providers/Models/ProviderConfiguration.php` (`lister('openai_compatible')`), `config/config.php` (`features.media_analysis.transcription.models` default `['Systran/faster-whisper-base']`), `app/Ai/Providers/AiModelChoice.php` docblock; tests `Modules/AI/tests/Feature/MediaAnalysis/OpenAiTranscriberTest.php` (rename of `WhisperTranscriberTest.php`), `Unit/Ai/Providers/AiModelChoiceTest.php`, `Unit/Ai/MediaAnalysis/MediaAnalysisModelRegistryTest.php`, `Unit/Ai/Providers/Models/ModelCatalogTest.php`, `Feature/Console/RefreshAiModelsCommandTest.php`, `Unit/Ai/Providers/Models/ModelListersTest.php`.

**Interfaces:**
- Consumes: `AiServers::transcription()`, `MediaAnalysisModelProfile::$serviceModel`.
- Produces: `OpenAiTranscriber::transcribe(string $path, string $mimeType, MediaAnalysisModelProfile $profile): ?string` (contract of `MediaTranscriber` unchanged).

- [x] **Step 1: Failing tests:** `it('posts multipart file and model with response_format json to /v1/audio/transcriptions and returns the trimmed text')` (asserts the `model` field is the profile's service model and the file part is `file`); `it('returns null without a url, a missing file or an empty text')`; `it('returns null and logs one warning with the request id on a 413, a 503 and a connection failure, without the file name')`; `it('returns null when the log cannot be written')` (kept from Phase 1); `it('offers the configured transcription models as openai_compatible choices')` (catalog) and `it('offers none when no server is configured')`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** the client on the server of `AiServers::transcription()` with `ServiceRequestId('transcription', ...)`; keep the failure handling of the class it renames. The dropdown imprecision (spec section 11): `isConfigured('openai_compatible')` is true when any of the two servers is configured; the transcriber itself returns null without its URL.
- [x] **Step 4: Run** the listed tests; expect PASS; pint.
- [x] **Step 5: Commit** in `Modules/AI` `feat: transcribe through the OpenAI audio API`.

### Task 7: `ai:servers:check`

**Files:** create `Modules/AI/app/Console/ServersCheckCommand.php`, `Modules/AI/app/Ai/SelfHosted/ServerCheck.php`; register the command the way `EmbeddingsProbeCommand` is registered; tests `Modules/AI/tests/Feature/ServersCheckCommandTest.php`, `Modules/AI/tests/Unit/Ai/SelfHosted/ServerCheckTest.php`.

**Interfaces:**
- Consumes: `AiServers::all()`, `EmbeddingServerIdentity::inspect()`, `CrossEncoderService`.
- Produces: `ServerCheck::run(): list<array{server: string, capability: string, url: string, ok: bool, detail: string}>`; the command prints them as a table, exit code 1 when any configured server fails, 0 otherwise, and reports a configured server of no capability as skipped.

- [x] **Step 1: Failing tests:** `it('lists every configured server with capability, url without credentials and result')`; `it('exits 1 when an embeddings server answers the wrong model, dimensions or norm')`; `it('probes the reranker with two fixed pairs and expects two numbers in [0, 1]')`; `it('does not probe transcription')` (listed as not probed); `it('says when the tei max_client_batch_size is lower than the configured batch size or than 64 for the reranker')`; `it('skips servers without a url and exits 0 when none is configured')`.
- [x] **Step 2: Run, expect FAIL.**
- [x] **Step 3: Implement** both classes; the embeddings probe goes through each profile of `configuredKeys()` once per distinct server.
- [x] **Step 4: Run, expect PASS**; pint.
- [x] **Step 5: Commit** in `Modules/AI` `feat: add ai:servers:check`.

### Task 8: Contract fixtures and tests for the standard servers

**Files:** create `Modules/AI/tests/Fixtures/StandardServers/{tei,infinity,speaches}.openapi.json` with `.version` files; modify `Modules/AI/tests/Stubs/SelfHostedServiceContract.php` (`load(string $fixture)` over the new directory; add the JSON Schema keywords the new documents use, `oneOf`, `allOf`, `nullable`, `minimum`, one at a time under test); rewrite `Modules/AI/tests/Integration/SelfHostedServicesContractTest.php` as `StandardServersContractTest.php`; delete `tests/Fixtures/SelfHostedServices/` and the old test.

- [x] **Step 1: Fetch the documents:** TEI `docs/openapi.json` at tag `v1.9.4` and speaches `docs/openapi.json` at tag `v0.9.0-rc.3` through `gh api repos/<owner>/<repo>/contents/<path>?ref=<tag> -H "Accept: application/vnd.github.raw"`; for Infinity use its committed OpenAPI document if the repository has one under `docs/`, otherwise generate it from the throwaway venv of Task 9 and say so in its `.version`. Write the tag or version in each `<name>.version`. Never edit a fixture by hand.
- [x] **Step 2: Failing tests** in `StandardServersContractTest`: the body `OpenAiCompatibleEmbeddingsProvider` sends is valid against TEI `OpenAICompatRequest` and Infinity's embeddings request (required fields present, none undeclared); the body `CrossEncoderService` sends is valid for TEI `RerankRequest` (`tei`) and Infinity's rerank request (`cohere`); the multipart fields of `OpenAiTranscriber` are declared by the speaches transcription form; each client parses the schema's own example answer, and an answer reduced to the required fields; an example lacking a field the client cannot do without (`data[].embedding`, `index`, `score` or `relevance_score`, `text`) fails the client.
- [x] **Step 3: Extend the helper** only as far as a failing test needs; refuse any keyword it still does not check, as today.
- [x] **Step 4: Run** `php artisan test --compact Modules/AI/tests/Integration/StandardServersContractTest.php` and `Modules/AI/tests/Unit/Ai/SelfHosted`; expect PASS; pint.
- [x] **Step 5: Commit** in `Modules/AI` `test: check the AI clients against the OpenAPI documents of TEI, Infinity and speaches`.

### Task 9: Hands-on verification (owner go-ahead required)

**Files:** modify `../specs/2026-10-22-ai-standard-servers-design.md` (append the `## Verification` section).

Nothing is installed or downloaded without the owner's explicit yes for each test (research spec section 8: A Infinity in a throwaway venv, B TEI built from source, C speaches with `uv`). The agent states the source, command and size before each.

- [x] **Step 1: Ask the owner** which of A, B, C to run, or whether they run them and report.
- [x] **Step 2: Run** the allowed tests with `ai:servers:check` and the real models; record vector equality with the previous service for the same prefixed text (cosine above 0.999 on 20 texts), rerank order and score range for 64 pairs and the time per search against the 5 s figure, TEI build time, binary size and glibc on the LXC, `/health` while the model loads, `/v1/embeddings` normalisation default, and speaches behaviour on an oversized file. Done for test A (Infinity, 2026-10-23) and test B (TEI, 2026-10-29: the source build failed on MKL, the Docker image works), at the owner's request: results in the spec's Verification section. C (speaches) is still open.
- [x] **Step 3: Append `## Verification`** to the spec with the date, versions, commands and results; if a result changes D8, say which server the guide documents instead. Recorded for test A.
- [x] **Step 4: Commit** the spec in `laraplate` `docs: record the verification of the standard servers`.

### Task 10: Documentation, evaluation dataset and closing

**Files:** create `Modules/AI/docs/STANDARD_SERVERS_INSTALLATION.md`; delete `docs/SENTENCE_TRANSFORMERS_INSTALLATION.md of the AI module (replaced on 2026-10-23 by STANDARD_SERVERS_INSTALLATION.md)` and `WHISPER_INSTALLATION.md`; modify `Modules/AI/README.md` (9 references), `docs/rag/MODULE.md` (19), `docs/rag/DEPLOYMENT.md` (the timeout and batch variables), `docs/rag/MEDIA_ANALYSIS_DEVELOPER.md`, `docs/rag/GLOSSARY.md`, `docs/GLOSSARY.md`, `docs/SEARCH_AND_TRANSLATION.md`, `docs/rag/AI_MODEL_SELECTION_DEVELOPER.md`, `docs/rag/AI_MODEL_SELECTION_USER.md`, `CHANGELOG.md`, `docs/rag/evaluations/2026-09-18-developer-retrieval.json` (queries `SENTENCE_TRANSFORMERS_TIMEOUT` and `SENTENCE_TRANSFORMERS_BATCH_SIZE` become `AI_EMBEDDINGS_TIMEOUT` and `AI_EMBEDDINGS_BATCH_SIZE`) and its committed report; `../specs/2026-10-20-self-hosted-ai-services-client-design.md`, `../specs/2026-10-22-ai-standard-servers-design.md`, both `INDEX.md` files, this plan.

- [x] **Step 1: Guide.** One guide `STANDARD_SERVERS_INSTALLATION.md` with: the server per capability (TEI for embeddings and reranking, one process per model, flags `--model-id`, `--api-key`, `--max-client-batch-size 64` for the reranker, never `--default-prompt`; Infinity v1 in its own venv as the alternative; speaches or any OpenAI-style server for transcription), the variables of Global Constraints with what each does, the reverse-proxy body-size limit for uploads, systemd units for the Debian LXC without Docker (as recorded by Task 9), `ai:servers:check`, tracing a failed call by its request id, and the model-switch procedure with one TEI process per model. State the verification date and versions it rests on.
- [x] **Step 2: Sweep.** `grep -rniE "sentence.?transformers|whisper-api|WHISPER_|SENTENCE_TRANSFORMERS|CROSS_ENCODER" Modules/AI Modules/Core --include=*.md --include=*.json --include=*.php -l` returns only `CHANGELOG.md` history lines and the Phase 0 and 1 records; fix every other hit (the Whisper model itself is not a service reference).
- [-] **Step 3: Evaluation.** Update the two dataset queries, then run `php artisan ai:evaluate-documentation` for that dataset (`ai:evaluate-documentation --help` for its options, with the indexed developer corpus) and commit the regenerated `2026-09-18-developer-retrieval-report.json` only if the recorded metrics still hold or the owner accepts the new ones. Not done: the report needs the indexed developer corpus and Elasticsearch; only the two dataset queries were renamed. Run `php artisan ai:evaluate-documentation` once the guide is indexed and commit the report if its figures hold.
- [x] **Step 4: Specs and indexes.** In the client spec, state that E1, E2 and E6 are superseded and what Phase 1 left (spec section 6); in this spec set the status to `Built` with the date; update both `INDEX.md` lines.
- [x] **Step 5: Close this plan:** tick every step, add `## Delivery status (date): shipped` with each divergence and its reason, `**Documented in:**` naming `Modules/AI/docs/STANDARD_SERVERS_INSTALLATION.md`, `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/docs/rag/DEPLOYMENT.md` and `Modules/AI/docs/rag/MEDIA_ANALYSIS_DEVELOPER.md` (enforced by `tests/Unit/ClosedPlansPointToDocumentationTest.php`), `status: closed` in the front matter; run `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php`.
- [x] **Step 6: Run** the whole AI module tests (`php artisan test --compact Modules/AI`) and ask the owner to run the complete suite; pint on every changed PHP file; commit docs in `Modules/AI` `docs: document the standard servers`, plan and specs in `laraplate` `docs: close the AI standard servers plan`. The stack-root work starts only after the AI release ships.

## Delivery status (2026-10-23): built, pending the owner's merge; Infinity and TEI verified, speaches not yet

Tasks 1 to 8 and 10 are built in `Modules/AI` (laraai pull request 2), Core (laracore pull request 6) and the application (laraplate pull requests 29 and 30); the whole AI suite passes (2028 tests). Task 9, the hands-on verification, was first skipped on the owner's instruction and then done for Infinity (test A) at the owner's request on 2026-10-23: vectors identical to `sentence-transformers`, rerank scores equal to the sigmoid of the `CrossEncoder` logits, the module's clients and `ai:servers:check` pass against it, `--url-prefix /v1` confirmed (spec, section Verification). Test B (TEI) was run on 2026-10-29 on the owner's server: the build from source failed at the link step (the distribution's MKL 2020.4 lacks `hgemm_`), the Docker image `cpu-1.9.4` works and `ai:servers:check` passes for the e5 profile. Test C (speaches) is still open; the guide says which parts are verified. Divergences from the plan, each with its reason:

- **Task 1.** `EmbeddingModelProfile::$server` was added here, not in Task 3 (`AiServers::forProfile` needs it). The old `providers.*` entries stayed in the config until the task that replaced their client removed them. The variables went into the existing "AI Module" area of `.env.example`, commented.
- **Task 2.** `ServiceRequestId::statusOf` reads Neuron's `HttpException`; the malformed-answer cases are tested through `embedText`, because the adaptive batcher heals a wrong answer by falling back to single requests.
- **Task 3.** The factory does not send the profile's dimensions (no server tells which models take them; the probe checks the length) and throws `ConfigurationException` for a server without a URL.
- **Task 4.** `/info` is a second witness that must agree with the probe answer; test vectors are unit vectors because a normalising profile now refuses any other norm; the `/health`-only command tests were replaced by `/info` ones.
- **Task 5.** `score()` never asks `/info` (only `scoreWithModel()` does, cached ten minutes); the `tei` request carries `truncate: true`.
- **Task 6.** The dropdown lists `ai.features.media_analysis.transcription.models` through `ConfiguredModelLister`. A stored setting `whisper` is not migrated (pre-stable).
- **Task 7.** One row per embedding profile; the command tests assert on `Artisan::output()`.
- **Task 8.** The old contract helper was replaced by a generic `OpenApiContract`; TEI types its examples loosely, so a sample uses an example only when it conforms. The documents were downloaded with the owner's yes. Finding: Infinity publishes `/embeddings` and `/rerank` under `--url-prefix`; the guide starts it with `/v1` and sets `AI_RERANK_URL` to the same prefix.
- **Task 10.** `CHANGELOG.md` is generated from commits and was not edited; the retrieval evaluation report was not regenerated (see its step). `tests/Unit/EnvExampleListsAiServersTest.php` was dropped once the guard test of the application existed.
- **Final review.** A fresh reviewer found 9 issues. Fixed with a failing test first: the keyed reranker's `/info` was called without key and request id; `isConfigured('openai_compatible')` was true for the wrong servers (a profile on its own server could not be switched to, a transcription model was offered without a server); the body of an error answer reached the logs through the chained exception. The minors were fixed afterwards on 2026-10-23, each with a failing test first: a 2xx page that is not JSON is an `EmbeddingsException` with the request id (its body is not repeated); a named server with its own URL inherits the default timeout and batch size; `ai:embeddings:probe` also checks the model and the norm; the repair skips the probe for a hosted active profile; a failed `/info` is remembered for a minute and a 424 is not retried; the guide's wording on the reranker URL; `AiServers::all()` was removed.

**Documented in:** `Modules/AI/docs/STANDARD_SERVERS_INSTALLATION.md`, `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/docs/rag/DEPLOYMENT.md`, `Modules/AI/docs/rag/MEDIA_ANALYSIS_DEVELOPER.md`.
