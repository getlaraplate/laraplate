---
status: completed
created_on: 2026-10-06
---
# AI Module: Neuron Usage and Duplication Review — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Status:** Shipped 2026-10-07. All tasks are delivered; Task 11 was carried out in `2026-10-07-ai-module-review-decisions.md`.

**Goal:** `Modules/AI` uses the Neuron framework for what Neuron provides, repeats no code that can live once, and carries no code that nothing reaches. Behaviour changes only where a finding is a bug.

**Source:** three read-only reviews of the module, 2026-10-06: the use of Neuron in the calls to the model (findings A1-A9), retrieval, embeddings and evaluation (B1-B15), duplication and dead code (D1-D8, R1-R11). Finding ids in the steps point back to them. The reviews are not committed; every claim below was checked against the code when it was written, and a step that finds the claim false says so in its line and is not forced.

## Global Constraints

- **Neuron v3.17.0 is installed and is the source of truth.** The online documentation describes a newer retrieval API (`DocumentSchema`, `Filter`, `SearchRequest`, `store->delete(FilterGroup)`) that does not exist in `vendor/neuron-core/neuron-ai/src`. Do not plan against it. Read the vendor source and the docs through the Neuron MCP (`searchDocumentation`, `getPage`), and Laravel Boost `search-docs` for Laravel APIs, before writing code that depends on either. Do not write again what the framework offers: structured output, tools, middleware, chat history, testing fakes.
- No new dependencies and no new base folders. Neuron's own testing classes (`FakeAIProvider`, `FakeVectorStore`, `FakeEmbeddingsProvider`, `FakeMiddleware`) are already installed.
- Settings, not env: a feature switch or a tuning value is a seeded setting (`AIDatabaseSeeder`), read as `config('ai....')`. A task that finds an env variable that should be a setting records it; Task 11 decides.
- Never declare a class, trait, interface or enum inside a test file: stubs go in `Modules/AI/tests/Stubs/` (PSR-4 `Modules\AI\Tests\Stubs\`).
- Every PHP file `declare(strict_types=1);`, `#[Override]` where overriding, `final`/`readonly` where the siblings use them.
- Run tests from the `laraplate` root with `php artisan test --compact <path>`, never with a cached config. Format from the root, on an explicit file list: `vendor/bin/pint --format agent <files>`.
- Tick each step's checkbox when the step is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.
- A deleted file, route, setting or env variable is searched across `Modules/*`, `config`, `routes`, `tests`, `docs` and `resources` first; the command and its result go in the commit message.
- Closing the plan needs a `**Documented in:**` line naming the module docs that describe the behaviour, and the docs of every removed or changed behaviour updated in the task that changes it.

---

### Task 1: Small fixes with no behaviour risk

Findings B4, B5, B14, B13(b-c), D7, R7 (path half), and the keys the code reads and nothing declares.

**Files:**
- Modify: `Modules/AI/app/Ai/Embeddings/EmbeddingsProviderFactory.php`
- Modify: `Modules/AI/app/Ai/Agents/DocumentationAgent.php`
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php`
- Modify: `Modules/AI/app/Services/DocumentationService.php`
- Modify: `Modules/AI/config/config.php`
- Modify: `Modules/AI/README.md`
- Delete: `Modules/AI/app/Ai/MediaAnalysis/Transcription/NullMediaTranscriber.php`
- Delete: `Modules/AI/tests/Stubs/TranslatableTestModelA.php`, `Modules/AI/tests/Stubs/TranslatableTestModelB.php`, `Modules/AI/tests/Stubs/TranslatableMissingTestModelA.php`, `Modules/AI/tests/Stubs/TranslatableMissingTestModelB.php`
- Test: `Modules/AI/tests/Integration/EmbeddingsProviderFactoryTest.php`, `Modules/AI/tests/Integration/DocumentationAgentTest.php`

- [x] **Step 1: Dimensions of OpenAI and Voyage embeddings (B4).** Neuron's `OpenAIEmbeddingsProvider` defaults to 1024 dimensions and sends them on every call, so a profile of 1536 gets vectors of 1024; `VoyageEmbeddingsProvider` takes them as its third argument. Pass the profile's `dimensions` when the provider is built for a profile. Test, with the reflection style the file already uses, that the dimensions reach both providers.
  Done for OpenAI, and the provider that is not the profile's. The API takes `dimensions` on the `text-embedding-3` models only (`withoutDimensions()` exists in Neuron for the others), so the profile's dimensions are sent for those and not for `text-embedding-ada-002`, and a provider that is not the profile's keeps the model's length instead of the 1024 of Neuron. Voyage is not changed: `output_dimension` is accepted by some of its models only, which cannot be checked without the service, and `EmbeddingDimensionProbe` already refuses a vector of the wrong length.

- [x] **Step 2: One path for the filesystem vector store (B5, R7).** `DocumentationAgent` builds a `FileVectorStore` that appends `.store`; `DocumentationService` checks, sizes and unlinks the configured path as it is, and the suffix of the profile is derived twice (`DocumentationAgent::getStorePath`/`profiledStorePath`, `DocumentationService::getFilesystemVectorStoreFilePath`). Put the derivation in one class both use, pass the extension to `FileVectorStore`, and make `isAvailable()` and the `--full` unlink act on the file that the store writes. Test a filesystem case: after indexing, `isAvailable()` is true and `--full` removes that file. Reconcile the default of `ai.features.faq.vector_store` (the code says `filesystem`, `config.php` says `elasticsearch`) and the comment of the path in `config.php`. Done: the strategy service kept its empty-records guard in `EvaluationStatistics::latency()`.
  Done in `FaqVectorStoreConfig` (the driver, with the default of `config.php`, and the file of a profile) and `FaqVectorStoreFile` (the directory, name and extension that `FileVectorStore` takes), used by the agent, the service, `IndexDocumentationCommand` and `RagIndexRebuilder`. A path with another extension than `.store` was written by the store as `.store` and checked by the service as configured; the config comment promised `.json` while the code default is `.store`.

- [x] **Step 3: Remove the static constructors that the framework already gives (B14).** `ChatAgent::make()` and `DocumentationAgent::make()` copy `NeuronAI\StaticConstructor`, which the Neuron workflow uses. Delete them; keep `ChatAgent::forFeature()`. Run `ChatAgentTest` and `DocumentationAgentTest`. Done: `EvaluationCaseRules` holds the shared case checks.
  Done: Neuron's `StaticConstructor` is the same method.

- [x] **Step 4: Keys read and never declared.** `ai.features.application_content.timeout_seconds` (`ApplicationContentToolProvider`), `ai.features.embeddings.retry_until_minutes` (`GenerateEmbeddingsJob`, documented in `SEARCH_AND_TRANSLATION.md`), `ai.features.chat.summary_threshold` (`MemoryService`) and `ai.vendor` (`rag_paths()`). Declare each in `config.php` with its present default, or inline the default and drop the read of the key. Fix the comment of `GenerateEmbeddingsJob` that says 180 seconds while the value is 300. Done: `Console/Concerns/WritesJsonReport` (also `outputPathIssue()`) and `ResolvesDocumentationProfiles`.
  Done: `application_content.timeout_seconds`, `embeddings.retry_until_minutes` and `chat.summary_threshold` are declared in `config.php` with their present values, as fixed values and not env (two of them are set by tests). `ai.vendor` is removed from `rag_paths()`: nothing declares or documents it and the module's own `composer.json` gives the vendor.

- [x] **Step 5: Dead provider, keys and stubs (D7).** Delete `NullMediaTranscriber` (the provider binds `WhisperTranscriber`, which is already a no-op without its URL), `features.faq.elasticsearch.index` with `AI_FAQ_ES_INDEX` (`.env.example`, README), `providers.openai.api_url` with `OPENAI_API_URL`, `providers.deepl.api_key` (the DeepL service reads `core.deepl_api_key`) and the four unused stubs. For each, run the repo-wide search first; a key that something reads stays. Done: 189 tests pass across the evaluation, tuning and index commands; no file under `docs/rag/evaluations` changed.
  Done, with two corrections to the review: `AI_FAQ_ES_INDEX` is still read, as the fallback of `AI_FAQ_DEVELOPER_ES_INDEX`, so only the config key `elasticsearch.index`, which nothing reads, is removed; and `Modules/Core/README.md` still lists `OPENAI_API_URL` (deprecated), which is a Core line and is left to Core.
  Also a correction, found by the suite after the first commit: only four of the five stubs were unused. `TranslatableMissingTestModelTranslation` is the translation class that `HasTranslations` derives from the name of `TranslatableMissingTestModel` (`{Model}Translation`), so no search for its name finds the use; it was restored. A search for the name of a class is not enough to prove a class unused where a trait builds class names by convention.

- [x] **Step 6: Format, run the AI embeddings, documentation and agent tests, commit.** `fix(ai): ...`.

---

### Task 2: One service graph for the assistant tests

Finding R6.

**Files:**
- Modify: `Modules/AI/tests/Stubs/Assistance/ScriptedAssistantFixtures.php`
- Modify: `Modules/AI/tests/Unit/Services/ApplicationContent/ApplicationContentToolProviderTest.php`
- Modify: `Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php`
- Create: `Modules/AI/tests/Stubs/Assistance/StaticToolsProvider.php`
- Modify: `Modules/AI/tests/Feature/InAppAssistanceSecurityTest.php`
- Modify: `Modules/AI/tests/Feature/Assistance/ConversationTitleTest.php`
- Modify: `Modules/AI/tests/Feature/Assistance/AssistantScopeRespondTest.php`
- Modify: `Modules/AI/tests/Feature/Assistance/UiProposalsTest.php`
- Modify: `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`

- [x] **Step 1: The helper.** Add `ScriptedAssistantFixtures::inAppService(Request, ?Closure $retrieve, Closure $complete, ?AssistantPolicyCompiler $compiler = null, ?ChatService $chat = null)` building the `InAppAssistanceService` with the real collaborators, and `NoToolsProvider`, a `ContextualToolProviderInterface` that offers no tool. It replaces the six pasted constructions and the `Mockery::mock(ContextualToolProviderInterface::class)` with `shouldReceive('tools')` repeated six times; the anonymous classes of `ApplicationContentToolProviderTest` and `InAppApplicationContentAssistanceTest` become `NoToolsProvider` too when they fit.
  Done, with one correction: those anonymous classes are not empty, each offers a tool (`graph_search`, `application_content_search`), so the stub is `StaticToolsProvider`, which offers the definitions it is given and none by default. `ScriptedAssistantFixtures::inAppService(Request, ?Closure $complete, ?Closure $retrieve, ?AssistantPolicyCompiler, ?ChatService, ?ContextualToolProviderInterface)` is the helper; no `Mockery::mock(ContextualToolProviderInterface::class)` is left in the module's tests.

- [x] **Step 2: Move the five test files to it.** Each file keeps a one-line wrapper only where its name is part of the test's vocabulary.
  Done: the five wrappers (`protectedAssistanceService`, `scopedAssistanceService`, `titleAssistance`, `proposalAssistance`, and the container binding of `AgentEndpointTest`) are one line each. Each file passes alone and all of them together (230 tests). Run each file alone, then together, after every file: a helper defined twice in one Pest process is a fatal error.

- [x] **Step 3: Format, run the assistant suites, commit.** `test(ai): one construction of the in-app service for the assistant tests`.

---

### Task 3: Shared code of the evaluation harnesses

Findings R1, R2, R3, B6, B7, B12. Neuron's Evaluation module is not a replacement: it is pass or fail per item and has no aggregate metric, slice, percentile or committed baseline (B8). The IR harness stays ours.

**Files:**
- Create: `Modules/AI/app/Services/Evaluation/EvaluationStatistics.php`
- Create: `Modules/AI/app/Services/Evaluation/EvaluationDatasetReader.php`
- Create: `Modules/AI/app/Console/Concerns/WritesJsonReport.php`
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/IrMetrics.php`
- Modify: `Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationService.php`
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php`
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentRetrievalStrategyEvaluationService.php`
- Modify: `Modules/AI/app/Services/Assistance/Evaluation/AssistantEvaluationService.php`
- Modify: `Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationDataset.php`
- Modify: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationDataset.php`
- Modify: `Modules/AI/app/Services/Assistance/Evaluation/AssistantEvaluationDataset.php`
- Modify: `Modules/AI/app/Console/EvaluateDocumentationCommand.php`
- Modify: `Modules/AI/app/Console/EvaluateApplicationContentCommand.php`
- Modify: `Modules/AI/app/Console/EvaluateApplicationContentRetrievalStrategiesCommand.php`
- Modify: `Modules/AI/app/Console/TuneRetrievalCommand.php`
- Test: the `*EvaluationServiceTest`, `*EvaluationDatasetTest`, `*EvaluationCaseTest`, `IrMetricsTest` files, the three baseline gates and the four command tests

- [x] **Step 1: Characterize before moving.** Run the evaluation tests and the three baseline gates (`DocumentationBaselineGateTest`, `DeveloperDocumentationBaselineGateTest`, the CMS and SAO application-content baselines) and keep their output: the reports pin rounded values, and no committed report under `docs/rag/evaluations` may change.

- [x] **Step 2: `EvaluationStatistics` (R2, B6).** `ratio`, `rounded`, `percentile`, `latency` and `groupBySlices(records, metricsFn)`, lifted verbatim from the four services (their copies are identical; the strategy service adds an empty-records guard, which the class keeps). Add `IrMetrics::firstRank()` and `reciprocalRank()` for the loops that compute the first relevant rank and the reciprocal rank in four places (`DocumentationEvaluationService`, `ApplicationContentEvaluationService`, the strategy service, `RetrievalTuningService`). The services keep their own domain metrics. `RetrievalTuningService::ratio()` goes too.

- [x] **Step 3: `EvaluationDatasetReader` (R1, B7).** The reading of a dataset file (2,000,000 bytes, depth 32, at most 1000 cases, a list of cases), `assertExactKeys`, `string`, `integer`, `boolean`, `stringList` and `isValidRevision`, parameterised by the label that the exception messages carry so that they stay the same. The assistant dataset keeps its own optional-key helpers. The application-content dataset keeps `assertOutsideProject`. Neuron's `JsonDataset` only decodes, with no schema, size cap or location check, so it cannot replace this. The repeated checks of the three case classes (id and locale patterns, the 2000 character query, `validStringList`, `validSlugList`) become one small shared class.

- [x] **Step 4: `WritesJsonReport` (R3, B12).** `optionString()`, and `writeReport(...)` doing the checks and the write the four commands repeat: the output exists and `--force` is absent, the directory is not writable, one `json_encode` with the same flags, a temporary file moved into place and removed in a `finally`. The profile parsing of `IndexDocumentationCommand` and `CreateRagElasticsearchIndexCommand` becomes one concern.

- [x] **Step 5: The same output.** Rerun Step 1 and compare: every report and every message is identical. Format, commit. `refactor(ai): one copy of the evaluation statistics, the dataset reader and the report writer`.

---

### Task 4: Real citations and one retrieval for the documentation agent

Findings B1, B3, B10, B11. All three are bugs, not duplication.

**Files:**
- Create: `Modules/AI/app/Ai/Rag/RecordingPostProcessor.php`
- Modify: `Modules/AI/app/Ai/Agents/DocumentationAgent.php`
- Modify: `Modules/AI/app/Services/DocumentationService.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DeveloperDocumentationRetrieval.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php`
- Modify: `Modules/AI/app/Console/LaraplateHelpCommand.php`
- Test: `Modules/AI/tests/Integration/DocumentationServiceTest.php`, `Modules/AI/tests/Unit/Ai/Rag/Retrieval/`, `Modules/AI/tests/Feature/DeveloperDocumentationBaselineGateTest.php`

- [x] **Step 1: Prove the bug with a real agent (B1, B10).** `DocumentationServiceTest` mocks the concrete `DocumentationAgent`, a `Mockery` handler and a `Message` that is given `getCitations()`; in Neuron 3.17 neither `Message` nor `AssistantMessage` has that method, and the RAG flow never sets citations, so `buildCitations()` is dead and `ai:help` never prints sources. Write the failing test with a real `DocumentationAgent` on `FakeVectorStore`, `FakeEmbeddingsProvider` and `FakeAIProvider` (`setVectorStore`, `setEmbeddingsProvider`, `setAiProvider`): asking a question must give the retrieved documents as sources. Keep the existing assertion that a source is never split across two batches, from the order that `FakeVectorStore` records. Done: four tests in `DocumentationServiceTest` over a real `DocumentationAgent` with `FakeAIProvider`, `FakeVectorStore` and `FakeEmbeddingsProvider` (they failed before: citations were always empty). The two tests that mocked `getCitations()` are replaced.

- [x] **Step 2: Citations from the documents.** `RecordingPostProcessor` is a Neuron `PostProcessorInterface` (`vendor/neuron-core/neuron-ai/src/RAG/PostProcessor/PostProcessorInterface.php`) that keeps the documents that reach the model; `DocumentationAgent::postProcessors()` returns it, and `DocumentationService` builds the citations from those documents (source name, excerpt, score). Delete the `method_exists($message, 'getCitations')` branch. Done: `RecordingPostProcessor`, `DocumentationAgent::retrievedDocuments()`; `buildCitations()` reads the documents. `LaraplateHelpCommand` needed no change: it already prints the citations it receives.

- [x] **Step 3: One retrieval for evaluation and production (B3, B11).** `test:ai:evaluate-documentation --index=developer` measures `DeveloperDocumentationRetrieval` (query embedding, prefix, `min_similarity`, fail-fast); `ai:help` runs the default `SimilarityRetrieval` with no threshold. Give `DocumentationAgent::postProcessors()` Neuron's `FixedThresholdPostProcessor` on the `min_similarity` setting, so the agent path applies the same filter, and reduce the two retrieval classes to the same embedding and query handling plus that processor. The duplicated `aboveMinimumSimilarity` goes, and so does the extra Elasticsearch count that `hasDocuments()` makes on every query where the result is already known. Keep the fail-fast behaviour and the generic exception message (no leak). Done with one narrowing: the agent keeps its vector store and `SimilarityRetrieval`, with `MinimumSimilarity` (Neuron's `FixedThresholdPostProcessor` on `min_similarity`) in its post-processors; the two retrievals share `MinimumSimilarity::filter()` and `EmbedsDocumentationQuery`. The agent still embeds the question without the query prefix, which Task 5 fixes with the embeddings decorator. `hasDocuments()` now runs only when a search comes back empty (fail-fast kept, generic message kept).

- [x] **Step 4: Run the baseline gates.** The retrieval of the in-app and developer indexes must not change its ranking; the answers of `ai:help` become stricter by the threshold, and the plan records it. Format, commit. `fix(ai): documentation answers cite the documents they used and apply the retrieval that is evaluated`. Done: 223 documentation, retrieval and baseline tests pass; no report changed. `ai:help` answers are stricter by the `min_similarity` threshold when one is set.

---

### Task 5: Passage prefix on the documentation index

Finding B2. Behaviour changes: relevance shifts, and the documentation indexes must be rebuilt.

**Files:**
- Create: `Modules/AI/app/Ai/Embeddings/PrefixingEmbeddingsProvider.php`
- Modify: `Modules/AI/app/Ai/Embeddings/EmbeddingsProviderFactory.php`
- Modify: `Modules/AI/app/Services/EmbeddingService.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/InAppDocumentationRetrieval.php`
- Modify: `Modules/AI/app/Ai/Rag/Retrieval/DeveloperDocumentationRetrieval.php`
- Modify: `Modules/AI/app/Services/SearchEmbedder.php`
- Test: `Modules/AI/tests/Unit/Embeddings/EmbeddingPrefixTest.php`, `Modules/AI/tests/Unit/Ai/Rag/Retrieval/`

- [x] **Step 1: The failing test.** With the first profile (`intfloat/multilingual-e5-small`), the documentation chunks reach the provider without `passage: ` while the in-app and developer queries are embedded as `query: ...`. Assert with `FakeEmbeddingsProvider::assertEmbeddedText('passage: ...')` that indexing a documentation chunk embeds the prefixed text, and that the stored `Document` content is not changed (it is shown to the model). Done: `PrefixingEmbeddingsProviderTest` (decorator on a recording provider, content untouched, order kept, factory and `DocumentationAgent::embeddings()` return it for the E5 profile). Instead of `FakeEmbeddingsProvider::assertEmbeddedText` the tests use `RecordingEmbeddingsProvider`, which records what reaches the provider boundary.

- [x] **Step 2: The decorator.** `PrefixingEmbeddingsProvider` wraps the provider that `EmbeddingsProviderFactory::make()` returns: `embedText()` prepends the query prefix, `embedDocument()` and `embedDocuments()` embed a prefixed copy and copy the vector back, without touching `Document::content`. Neuron's `EmbeddingsProviderInterface` has no query and passage split, so a decorator is the extension point. Then delete the manual prefixing in `SearchEmbedder`, the two retrievals and `EmbeddingService`, which mutates `$chunk->content` today. A site missed means a double prefix: search the repo for `query: ` and `passage: ` and account for each hit. Done: `PrefixingEmbeddingsProvider::forProfile()` applied by the factory only to the provider of the active profile (a provider asked for explicitly gets no prefix, its model is not the profile's). The manual prefixing is gone from `EmbeddingService` (it mutated `$chunk->content`), `SearchEmbedder` and `EmbedsDocumentationQuery`; no `query: ` or `passage: ` literal is left in `app/`. `formattedContent` is not a property of `Document` in 3.17, so the decorator ignores it. Side effect, intended: the documentation agent now also embeds the question as a query.

- [x] **Step 3: Rebuild and measure.** Rebuild the documentation indexes (`ai:index-rag-docs --full`, or a model switch) and run the two documentation baselines. The baseline stub strips prefixes and hashes, so it cannot see this change; say so in the delivery status, and that the relevance gain is measured by `test:ai:evaluate-documentation` against a live Elasticsearch and embeddings service, which is manual. Not measurable here: the baselines pass (546 AI tests) but their stub strips prefixes and hashes, so they cannot see this change; the relevance gain is measured by `test:ai:evaluate-documentation` against a live Elasticsearch and embeddings service, which is manual, and so is the rebuild of the indexes.

- [x] **Step 4: Document the rollout.** `Modules/AI/docs/rag/MODULE.md` says that the documentation indexes must be rebuilt after this change. Format, commit. `fix(ai): documentation chunks are embedded as passages, through one embeddings decorator`. Done: `Modules/AI/docs/rag/MODULE.md` (profile keys and the indexing section: rebuild with `ai:index-rag-docs --full` per profile) and `SENTENCE_TRANSFORMERS_INSTALLATION.md`.

---

### Task 6: Neuron structured output where the code parses model JSON by hand

Findings A1, B9, D3 (the helper half).

**Files:**
- Create: `Modules/AI/app/Data/ModerationVerdictData.php`, `ExtractedFacts.php`, `ImageAnalysisData.php`, `SearchPlanData.php`, `InjectionVerdict.php`
- Modify: `Modules/AI/app/Services/ModerationService.php`
- Modify: `Modules/AI/app/Services/LlmSearchService.php`
- Modify: `Modules/AI/app/Services/SearchOrchestratorAgent.php`
- Modify: `Modules/AI/app/Ai/MediaAnalysis/Vision/NeuronVisionAnalyzer.php`
- Modify: `Modules/AI/app/Services/MemoryService.php`
- Modify: `Modules/AI/app/Services/GuardrailsService.php`
- Modify: `Modules/AI/config/config.php`
- Test: `Modules/AI/tests/Integration/Services/ModerationServiceTest.php`, `Modules/AI/tests/Integration/LlmSearchServiceTest.php`, `Modules/AI/tests/Integration/MemoryServiceFullTest.php`, `Modules/AI/tests/Integration/GuardrailsServiceFullTest.php`

The model to copy is `ConversationTitleService` with `GeneratedConversationTitle`, and its test `ConversationTitleServiceTest`, which asserts the class and the schema sent with `FakeAIProvider`.

- [x] **Step 1: Moderation.** `ModerationVerdictData` (a backed enum for the verdict, `confidence` from 0 to 1, categories, reason, the auto-approve flag) with `#[SchemaProperty]` and Neuron's validation rules; `ModerationService::analyze()` calls `structured(..., maxRetries)`. Delete the markdown fence regex, `json_decode`, `stringValue`, `floatValue`, `boolValue`, `stringListValue`, `mapResponse`, the hand-written retry that appends "Respond with valid JSON only", and `GuardrailsService::validateJsonOutput`. The existing `catch (Throwable)` that answers `Uncertain` stays, so a verdict that never validates ends as before, at one retry. The prompt is owned by CMS and already carries the JSON shape; `structured()` only adds the output constraint, so `CommentModerationPrompt` is not touched. Rework the two tests that call `mapResponse` directly onto `FakeAIProvider`. Done: `ModerationVerdictData` (the property names are the keys of the CMS prompt, and `OutOfRange` keeps the confidence between 0 and 1: a value outside it is now asked again instead of clamped). `ModerationService` lost its `GuardrailsService` dependency, which only served `validateJsonOutput`; the method and its four tests are deleted. `ModerationServiceTest` now runs on `FakeAIProvider`: verdicts, fenced answers, four kinds of answer that make Neuron ask again, two that never fit, and the provider down.

- [x] **Step 2: Search plan.** `SearchPlanData` for the plan that `LlmSearchService` asks for; delete `parseJsonResponse` and the coercion helpers (`stringListValue`, `arrayValue`, `expandedQueryValue`). `SearchOrchestratorAgent::sanitizePlan()` keeps its clamping, which is domain defence, and drops only the type coercion. The plan is cached for 600 seconds: keep the key stable. Give `LlmSearchService` the agent seam that `ConversationTitleService` has, and write the first tests that drive it with `FakeAIProvider` (today only its failure path is tested). Done with two additions: the intent extraction of `LlmSearchService` also parsed JSON by hand, so `SearchIntentData` exists beside `SearchPlanData`, and the plan sections are small classes under `Data/Search/` (the schema of a nested object needs a class). `LlmSearchService` has the `chatAgentFactory` seam and neither call retries (`MAX_RETRIES = 0`: a search waits for them). `generateSearchPlan()` returns `?SearchPlanData`, and a missing plan now ends in `fallbackPlan()` (source `fallback_rules`): before, an empty array went through `sanitizePlan()` and came out labelled `llm+guardrails` with defaults. The cache key is unchanged. First tests that drive both calls with `FakeAIProvider`, plus clamping, vector-disabled and caching tests on the orchestrator.

- [x] **Step 3: Vision and memory.** `ImageAnalysisData` for `NeuronVisionAnalyzer` (delete the "STRICT JSON" prompt wording, `withoutCodeFence` and `json_decode`); a failure still throws, so `AnalyzeMediaJob` retries as before. `ExtractedFacts` for `MemoryService::extractFacts()`, which swallows any parse failure and returns `[]` with no retry. `MemoryService` is dormant (Task 11), and the plan of persistent user memory reuses it, so this makes that plan start from a structured call. Done: `ImageAnalysisData` and `ExtractedFacts` (the facts are under `facts` because a structured output is an object). `NeuronVisionAnalyzer` is now testable (`chatAgentFactory`, `@codeCoverageIgnore` gone) and `NeuronVisionAnalyzerParseTest` runs it on `FakeAIProvider` with a real PNG (Neuron counts image tokens). A bare list instead of the `facts` object deserializes to no facts and is not asked again; that is the only shape mismatch Neuron cannot see.

- [x] **Step 4: The injection verdict.** `InjectionVerdict` (an enum: safe or unsafe) replaces the `str_contains` and `preg_replace` parsing of the LLM fallback in `GuardrailsService`. Whether that path is wired into the assistant or removed is Task 11; this step only stops it parsing by hand. Done: `InjectionCheck` with the `InjectionVerdict` enum; the fail-closed behaviour and both refusal messages are unchanged (an invalid verdict, after one retry, is the "no verdict" refusal; a provider that fails is the "unavailable" one). The tests moved to `FakeAIProvider`.

- [x] **Step 5: Config.** Remove `ai.features.guardrails.retry_on_failure` (`AI_GUARDRAILS_RETRY`) and the hand-written retry it controlled: `maxRetries` of `structured()` is the knob now. Record it in the module docs. Done: the key and its env var are gone from `config.php`; the module docs record the retries as constants in the new "Structured output of the model" section of `docs/rag/MODULE.md`.

- [x] **Step 6: Format, run the four test files and the moderation listeners tests, commit.** `refactor(ai): the model's JSON goes through Neuron's structured output`. Done: 724 AI tests across the touched services pass, and the CMS moderation tests pass.

---

### Task 7: Tool failures that do not refuse the whole turn

Finding A3. The guardrail on tool results (A2) is part of Task 11, because it needs the decision on the safety classifier.

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php`
- Modify: `Modules/AI/app/Services/Tools/ToolDefinition.php`
- Modify: `Modules/AI/app/Services/Tools/ToolRegistry.php`
- Modify: `Modules/AI/app/Services/Tools/GraphToolProvider.php`
- Modify: `Modules/AI/app/Services/Tools/CrudToolProvider.php`
- Test: `Modules/AI/tests/Integration/ToolRegistryTest.php`, `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`

- [x] **Step 1: The failing test.** A model that omits a required argument makes `Tool::execute()` throw `MissingCallbackParameter`; `ToolNode` rethrows it without a handler, and `respond()` turns the whole turn into the canned refusal. Drive it with `ToolCallingFakeProvider` (a call with a missing argument, then a normal answer): the turn must complete. Done: `AgentEndpointTest` drives a call to `propose_preference_change` with no arguments through `ToolCallingFakeProvider`; it failed (the second model call never happened) and now the turn completes and the tool result the model sees is the fixed message.

- [x] **Step 2: `toolErrorHandler`.** `ChatAgent` gives Neuron's `toolErrorHandler()` a handler that answers the model with a fixed message that never echoes the exception text, so the model can try again. Done in `ChatAgent::resolveToolErrorHandler()` (the override point of Neuron's `HandleTools`, so `toolErrorHandler()` stays free for a caller). Two distinct fixed messages (a failure, a tool called too often), the tool name and exception class logged, and `AssistancePolicyViolationException` is rethrown: it is a decision to refuse, not a tool failure.

- [x] **Step 3: `toolMaxRuns`.** `ToolDefinition` gains an optional `maxRuns`, which `ToolRegistry::buildNeuronToolStructure()` sets with `Tool::setMaxRuns()`; the expensive tools (`graph_*`, `crud_*`) declare a small limit. `ToolRunsExceededException` after the default 10 runs also ends in the canned refusal today. Done: `ToolDefinition::$maxRuns`, `ToolRegistry::register(..., maxRuns:)` and `buildNeuronToolStructure()`; `GraphToolProvider::MAX_RUNS` and `CrudToolProvider::MAX_RUNS` are 3. The default 10 (`ToolRunsExceededException`) now also ends in the fixed message instead of the refusal.

- [x] **Step 4: Format, run the tool and assistant suites, commit.** `fix(ai): a tool that fails or runs too often does not refuse the whole turn`. Done: 400 tool, assistant and agent tests pass; documented in `docs/rag/MODULE.md` ("Tool failures").

---

### Task 8: One way to build an agent, and the tests on Neuron's fakes

Findings A4, A5, B10, R9. After Task 6, the list of services that build an agent is shorter.

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php`
- Modify: `Modules/AI/app/Services/MemoryService.php`
- Modify: `Modules/AI/app/Services/ModerationService.php`
- Modify: `Modules/AI/app/Services/GuardrailsService.php`
- Modify: `Modules/AI/app/Services/ContextualSuggestionService.php`
- Modify: `Modules/AI/app/Services/Translation/AiTranslationService.php`
- Modify: `Modules/AI/app/Listeners/HandleAiTextGenerationListener.php`
- Modify: `Modules/AI/app/Services/Assistance/ConversationTitleService.php`
- Test: the seven test files that mock `ChatAgent`

- [x] **Step 1: Decide the seam by what is left.** Each service carries a nullable `Closure $chatAgentFactory` and a private `makeChatAgent()` with an inline `@codeCoverageIgnore`, and the same `chat(new UserMessage(x))->getMessage()->getContent() ?? ''` plus `mb_trim`. Add `ChatAgent::ask(string $prompt): string` for the plain text one-shots. For the seam, prefer Neuron's own: a test builds the agent with `setAiProvider(new FakeAIProvider(...))`, so one container-resolvable factory per `AiModelFeature` that tests swap replaces the closure argument. If that proves heavier than the closures it replaces for a service, the step says so for that service and leaves it. Decision: the seam stays the closure that each service already has (after Task 6 every one of them takes the system prompt or the feature it needs, and a test builds `ChatAgent::make(...)->setAiProvider(new FakeAIProvider(...))`); a container-resolvable factory per `AiModelFeature` would add seven bindings and an interface to do what the closures do. `ChatAgent::ask()` is added and used by the four plain-text one-shots (`MemoryService` summary, `ContextualSuggestionService`, `AiTranslationService`, `HandleAiTextGenerationListener`); the `@codeCoverageIgnore` on the default branches is gone, since `AiModelFeatureWiringTest` covers how the default agents are built.

- [x] **Step 2: Move the services,** one commit each, running that service's tests: the constructor signatures change, all the call sites are in the module and resolved by the container. Done in one pass rather than one commit per service: each change is a few lines (the call goes through `ask()`) and the constructor signatures did not change, so there are no call sites to move.

- [x] **Step 3: The tests.** Replace the `Mockery::mock(ChatAgent) -> chat() -> Mockery::mock(AgentHandler) -> getMessage()` chain (about 58 times in `GuardrailsServiceFullTest`, `MemoryServiceFullTest`, `HandleAiTextGenerationListenerTest`, `AiTranslationServiceTest`, `ContextualSuggestionServiceTest`, `DocumentationServiceTest`, `ModerationServiceTest`) with `new FakeAIProvider(new AssistantMessage(...))`, which also lets each test assert the system prompt and the messages the model was given. `FakeEmbeddingsProvider` is limited to 32 components, so the tests that need vectors of 384 or 1024 keep a stub from `tests/Stubs/Embeddings`; do not force the fake there. `AgentRoundTripTest` uses `FakeVectorStore`. Done: no `Mockery::mock(ChatAgent)` or `AgentHandler` mock is left in `tests/`; the four files that had them (`HandleAiTextGenerationListenerTest`, `AiTranslationServiceTest`, `ContextualSuggestionServiceTest`, `MemoryServiceFullTest`) run on `FakeAIProvider` and now also assert the prompt the model was given. Tasks 6 and 7 had already moved the others (`GuardrailsServiceFullTest`, `ModerationServiceTest`). `DocumentationServiceTest` mocks the agent only to count indexing calls, which is not a model call. `AgentRoundTripTest` uses `FakeVectorStore`; it keeps `RecordingEmbeddingsProvider`, as the plan says.

- [x] **Step 4: Overlapping test files.** `GuardrailsServiceTest`/`GuardrailsServiceFullTest`, `MemoryServiceTest`/`MemoryServiceFullTest`, `ConversationModelTest`/`ConversationModelExtendedTest`, `EmbeddingServiceTest`/`EmbeddingServiceFullTest` test the same classes twice; merge the repeated cases. Move the anonymous `new class` declarations that the `AGENTS.md` rule forbids in test files to `tests/Stubs`. Done: the four pairs are merged (`GuardrailsServiceTest`, `MemoryServiceTest`, `ConversationModelTest` and `EmbeddingServiceTest` are gone, their unique cases moved into the `Full`/`Extended` file; their other cases were repeated there). The twelve anonymous classes in test files are now stubs under `tests/Stubs` (users, translatable and searchable models, command resolvers, splitters, a request, a document); the one in `EmbeddingSwitchHarness` is in a stub file already.

- [x] **Step 5: Format, run the AI suite, commit.** `refactor(ai): agents are built in one place and the tests use Neuron's fakes`. Done: AI commit `d40ef8a` (see the delivery status for what else it holds); the whole AI suite ran green in Task 12 Step 1.

---

### Task 9: One gate for embeddings and for translation

Findings R4, R5.

**Files:**
- Create: `Modules/AI/app/Services/EmbeddingsGate.php`, `Modules/AI/app/Services/TranslationGate.php`
- Modify: `Modules/AI/app/Listeners/HandleModelIndexingListener.php`, `Modules/AI/app/Listeners/HandleBulkModelIndexingListener.php`, `Modules/AI/app/Listeners/HandleTranslationReembeddingListener.php`, `Modules/AI/app/Listeners/HandleModelTranslationListener.php`, `Modules/AI/app/Listeners/HandleModificationApprovedTranslationListener.php`
- Modify: `Modules/AI/app/Services/EmbeddableModels.php`
- Modify: `Modules/AI/app/Console/RepairMissingEmbeddingsCommand.php`
- Test: the `Handle*ListenerTest` files, `RepairMissingEmbeddingsCommandTest`, `FeatureModuleGateTest`

- [x] **Step 1: The gap first.** `HandleModificationApprovedTranslationListener` checks the flag and the settings and skips `FeatureModuleGate`, so an approved modification may translate in a module that the allowlist excludes; `isTranslatable` is implemented twice with `class_uses_trait` and `class_uses_recursive`; the indexing listeners read `config('ai.features.embeddings.enabled', true)` while the seeded default is `false`. Write a failing test for each, and check against the code that each is real before changing it. Done, and all real: five tests failed before the change (an approved modification translated in a module that the allowlist excludes; the three embeddings listeners and the translation ones ran while the setting was absent, because the code default was `true` and the seeded one is `false`). The duplicated `isTranslatable` was in the two translation listeners, both with `class_uses_recursive`, and the embeddings check was copied five times, with `class_uses_trait` in four and `class_uses_recursive` in the repair command.

- [x] **Step 2: The gates.** `EmbeddingsGate::allows(Model, bool $requireEmbeddable = true)` (the flag, `FeatureModuleGate`, the `Searchable` trait, `isEmbeddable`) and `TranslationGate::allows(Model)`, after the pattern of `MediaAnalysisGate`. The five embeddings copies and the two translation copies use them. The behaviour of the listeners that a module excludes is the same; the one that skipped the gate now applies it, which the delivery status records. Done: `EmbeddingsGate` (`enabled()`, `allows(Model, requireEmbeddable)`, and `handles(Model)` for what a repair or a model switch covers whatever the switch says, which is why `EmbeddableModels` uses `handles()` and not `allows()`) and `TranslationGate` (the switch, the allowlist, `HasTranslations`, the model's own setting). Both read the switch with `ai_config_bool(..., false)`, the seeded default. The five embeddings sites, `EmbeddableModels`, `RepairMissingEmbeddingsCommand` and the two translation listeners use them; `HandleModificationApprovedTranslationListener` now applies the module allowlist, and the listeners are `final readonly` with the gate injected (the container builds them; tests resolve them with `app()`). The repair tests set the switch explicitly: they used to depend on the old default.

- [x] **Step 3: The cache event key (R5).** The key `model_indexing:{table}:{key}` with a ten minute `Cache::put` is written in three AI listeners and read in Core (`FinalizeModelIndexingListener`, `Searchable`); the moderation flavour is in two more. Put `cacheKey()` and a TTL constant on the Core events and call them from both modules. This touches `Modules/Core`: it is a separate commit in that submodule, and the key string stays byte for byte the same. Done, in `Modules/Core` as its own commit: `ModelRequiresIndexing::cacheKey()` and `ModificationRequiresModeration::cacheKey()` with `CACHE_TTL_MINUTES = 10` on the events; `FinalizeModelIndexingListener`, `FinalizeModificationModerationListener`, `Searchable` and the four AI listeners call them. A Core test pins the two key strings byte for byte.

- [x] **Step 4: Format, run the listener, repair-command and Core indexing tests, commit.** Done: the listener, repair and gate tests, the Core search, indexing and event tests, and the CMS, SAO and ERP embeddings, indexing, translation and moderation tests all pass. Delivery status for the plan: the switches of embeddings and translation now default to off when the setting is absent (as seeded), which only differs from before in an environment where the settings were never seeded.

---

### Task 10: Dead code whose removal needs no decision

Findings D2, D5, D6, D8 (the half that does not depend on Task 11).

**Files:**
- Modify: `Modules/AI/app/Http/Controllers/ChatController.php`
- Modify: `Modules/AI/routes/web.php`
- Modify: `Modules/AI/resources/swagger/AI-swagger.json`
- Modify: `Modules/AI/app/Models/Message.php`, `Modules/AI/app/Models/Conversation.php`
- Modify: `Modules/AI/app/Services/Assistance/AssistantAccessContextFactory.php`
- Delete: `Modules/AI/app/Actions/IntelligentSearchAction.php`, `Modules/AI/tests/Integration/IntelligentSearchActionTest.php`
- Modify: `Modules/AI/docs/GLOSSARY.md`, `Modules/AI/docs/rag/GLOSSARY.md`, `Modules/AI/docs/rag/ASSISTANT_SCOPE.md`

- [x] **Step 1: The duplicated message route (D2).** `ChatController::sendMessageWithTools` is `insertMessage` wrapped with `action_requests: []`, always empty, and its route `ai.crud.messages.with-tools` is live while the glossaries call the method removed. Delete the method, the route, the swagger entry and its two tests (`ChatControllerTest`), and remove `messages-with-tools` from `ResolveAssistantApplicationContextTest` and from `ASSISTANT_SCOPE.md`. Search `laraplate-ui` and `laraplate-importers` for a client first. Done: the method, its route, the swagger path, its two tests and the `.with-tools` entry of `ResolveAssistantApplicationContextTest` and `ASSISTANT_SCOPE.md` are gone. No client in `laraplate-ui` or `laraplate-importers` calls it. Its body had already lost `action_requests` when the `ActionRequest` path was removed by the governed-writes plan, which left it a copy of `insertMessage` under another key. `TOOLS_USAGE_EXAMPLE.md` and the route table of `ARCHITECTURE.md` still describe it: Task 11 decides what stays of them.

- [x] **Step 2: Methods only tests call (D5).** `Message::byRole`, `Conversation::getMessagesForNeuron` (a hand-written message mapper; Neuron's `ChatHistory` would be the way if history is ever replayed) and `AssistantAccessContextFactory::forDeveloperHelp` (`LaraplateHelpCommand` never calls it). For each, search first and delete it with its tests, or keep it and say in the step why. Done, all three deleted with their tests, after a search of `Modules`, `app`, `config`, `routes`, `docs` and `resources` found no other caller: `Message::byRole`, `Conversation::getMessagesForNeuron` and `AssistantAccessContextFactory::forDeveloperHelp`. The last was the only code that checked "console only" for the developer help context; nothing used it, and `answerDeveloperQuestion()` checks the profile alone, so no behaviour changes, but if developer help ever needs a context object that check has to be written again.

- [x] **Step 3: `IntelligentSearchAction` (D6).** It calls `EnsembleSearchService::search()` with a signature that no longer exists and nothing calls it. Its removal is Task 4 Step 5 of `2026-09-16-search-modes-and-strategy-resolution.md`, which first salvages `evaluateResults` and `shouldRetry` into Core's `SearchQualityEvaluator`: do it only when that plan reaches the step, or do the salvage here and tick it there. Then delete the file, its test, its entries in `phpstan-baseline.neon` and the empty `app/Actions` folder. Done here, with the salvage first as the step allows: `SearchQualityEvaluator` in Core (`evaluate()` and `shouldRetry()`, the same thresholds, typed, with `Modules/Core/tests/Unit/Search/SearchRetryTest.php`), then `IntelligentSearchAction`, its test, its eight entries in `phpstan-baseline.neon` and the empty `app/Actions` folder. Ticked as Steps 1 and 3 of Task 4 in `2026-09-16-search-modes-and-strategy-resolution.md`; its Step 2 (retries driven by the strategy) is still open there.

- [x] **Step 4: Docs that describe code that is gone (D8).** `docs/GLOSSARY.md` and `docs/rag/GLOSSARY.md` say `ChatService` orchestrates streaming and tool calls and that `streamMessage` is the primary SSE path (it answers 422, and `ChatService` has two methods); the README says in-app chat is `ChatService` with question detection and lists "Streaming responses (SSE)". Correct them. The long superseded sections of `ARCHITECTURE.md` and `DESIGN_DECISIONS.md` wait for Task 11, which decides what stays. Done: both glossaries and the README describe `ChatService` as conversation lifecycle plus the protected agent, `streamMessage` as the 422 it answers, and `POST /app/ai/agent` as the event stream; `ChatAgent` is described as the one door to the model (`forFeature`, `ask`, `structured`). The `ActionRequest` section of the glossaries is left to the governed-writes plan and Task 11.

- [x] **Step 5: Format, run the chat, assistant and search tests, commit.** Done: 403 chat, assistant, message and conversation tests and the Core search retry tests pass; the route list no longer has `with-tools`.

---

### Task 11: Decisions of the owner

None of these has an obvious answer from the code. Each step asks for one decision, with what the review found; the work that follows goes in a plan of its own or in an amended step here.

- [x] **Step 1: The `ActionRequest` approval flow (D1, A6).** `ActionRequestService`, `RiskClassifier`, `ActionRequestController` and its five routes, `ApproveActionRequest`/`RejectActionRequest`, `ExecuteActionRequestJob`, the model and table `ai_action_requests`, the exceptions, and the global registry of `ToolRegistry` (`register`, `getTool`, `getAllTools`, `hasTools`, `getAllNeuronTools`, `getAllNeuronToolsWithApproval`) are reached by nothing but their own tests: about 690 production lines and about 1,000 test lines. Retire it (delete it all, with a drop-table migration, and keep `ToolRegistry`'s mapping from definition to tool), or reconnect it on Neuron's `ToolApproval` middleware (`src/Agent/Middleware/ToolApproval.php`, with interrupt and resume) using `RiskClassifier` as the condition. Spec `2026-09-16-in-app-assistant-tools-design.md` and plan `in-app-assistant-tools` depend on the answer.
  **Decided 2026-10-07 by the owner: retire it.** Core approvals enforce approval at the model and cover every surface, so a second tool-level mechanism is a duplicate. The removal is Task 1 of `2026-10-07-assistant-governed-writes.md`, which also opens assistant writes through Core approvals; a drop-table migration is not written because the project has no other installation and the create migration is the only description of the schema. Delivered 2026-10-07. Delivered 2026-10-07: that plan's Task 1 removed the path, and its pieces went into commits `d40ef8a` (swept in with the Task 8 commit of this plan, which staged a concurrent session's removal; recorded there too) and `6d18b46`.

- [x] **Step 2: Guardrails (D3, A2).** `GuardrailsService::checkPromptInjection` and its Lakera and LLM fallback are called by nobody, so `ai.features.guardrails.prompt_injection_detection` and `LAKERA_API_KEY` do nothing for the assistant, which uses the deterministic regex classifier; the config keys `guardrails.enabled` and `guardrails.json_validation` and `tools.enabled` have no reader. And what a tool returns to the model during the loop is not re-validated: `ApplicationContentToolProvider` calls the guardrail, `CrudToolProvider` and `GraphToolProvider` do not, so rows written by users reach the model as they are. Decide: implement `AssistanceSafetyClassifierInterface` with a small structured `InjectionVerdict` agent behind a setting, and add a Neuron `WorkflowMiddleware` on `ToolNode` that checks every tool result (rollout behind a flag, evaluated with `InAppAssistanceSecurityTest` data, since the regexes may flag legitimate data); or delete the dead path with its keys, env variables and tests. The CMS and SAO specs say "reuse `GuardrailsService`", so check them first.
  **Decided 2026-10-07 by the owner: delete the dead path (option B).** Checked before deciding: the CMS comments-moderation spec asks to reuse `GuardrailsService` only for the JSON validation and retry, which `ModerationService` no longer takes from it (Neuron's structured-output retry replaced it); the SAO spec (phase 8) names it only as general AI infrastructure, together with `ActionRequest`, which is already retired, so that sentence is corrected with the removal; the model-selection spec lists it among the consumers of `AiModelChoice`, so that line goes too. The second half of the finding is already closed: `ToolResultGuard` (commit `695db7f`, 2026-10-07) withholds any entity read-tool result whose text reads like an instruction, through `AssistanceGuardrailPipeline` and the deterministic classifier, and `InAppAssistanceService` wraps every definition with it, `CrudToolProvider` and `GraphToolProvider` included. The removal (`GuardrailsService`, `InjectionCheck`, `InjectionVerdict`, `AiModelFeature::Guardrails`, the `guardrails.*` keys, `LAKERA_API_KEY`, their tests and the doc lines) is part of Step 5.

- [x] **Step 3: `MemoryService` and the summaries (D4, A7).** Dormant, wired nowhere; the plan `persistent-user-memory` reuses its `extractFacts`, which Task 6 turns into a structured call. If that plan is dropped, `MemoryService`, `ConversationSummary`, `features.chat.summary.enabled` and their tests go; if it stays, Neuron's `Summarization` middleware and a chat history over `Message` are the framework way, and the assistant stays stateless until a security review accepts replaying history.
  **Decided 2026-10-07 by the owner: keep it.** `persistent-user-memory` is still valid and goes ahead at the priority that suits; `MemoryService`, `ConversationSummary` and `features.chat.summary.enabled` stay, with their tests, and the plan's structured `extractFacts` call (Task 6) is what it reuses. Neuron's `Summarization` middleware and the history over `Message` are the framework way when that plan is built; the assistant stays stateless until a security review accepts replaying history.

- [x] **Step 4: Env variables for switches and tuning (R11).** `AI_TEXT_GENERATION_*`, `AI_SEARCH_ORCHESTRATION_ENABLED`, `AI_FAQ_VECTOR_STORE`, `AI_FAQ_POLICY_CLASSIFICATION_VERSION`, `AI_MODERATION_QUEUE` are read from env through `config.php`, against the rule that switches and tuning values are seeded settings. The embeddings chunk and lock values already say they are deliberately not settings. Move them to seeded settings, or record the exception.
  **Decided 2026-10-07 by the owner: move them to seeded settings.** All five groups (`AI_TEXT_GENERATION_*`, `AI_SEARCH_ORCHESTRATION_ENABLED`, `AI_FAQ_VECTOR_STORE`, `AI_FAQ_POLICY_CLASSIFICATION_VERSION`, `AI_MODERATION_QUEUE`) become settings in `AIDatabaseSeeder`, read as `config('ai....')`, with the env variable removed from `config.php`, `.env.example` and the docs. The embeddings chunk and lock values keep their stated exception.

- [x] **Step 5: Do the work that the answers allow,** in a plan of its own. This includes the removal of the superseded chat sections of `ARCHITECTURE.md` and `DESIGN_DECISIONS.md`, `TOOLS_USAGE_EXAMPLE.md` and the env variables that the docs name and nothing reads (`AI_EMBEDDINGS_ENABLED`, `AI_CHAT_ENABLE_SUMMARY`, `AI_MODERATION_*`, `AI_COMMENT_*`, `AI_TOOLS_ENABLED`, `AI_GUARDRAILS_ENABLED`), rewritten as "setting X in Filament".
  Done in `2026-10-07-ai-module-review-decisions.md` (AI commits `56ac9e5`, `21abf3f`, `eea67eb`; Core `7f9b5ded`): the guardrails path is deleted, the text generation values, the vector store, the policy classification version and the moderation queue are seeded settings, and the docs are rewritten. Two claims of this step were false and were not forced: `TOOLS_USAGE_EXAMPLE.md` is current (the governed-writes plan rewrote it) and is kept with its route corrected, and `AI_MODERATION_QUEUE` with `AI_COMMENT_MOD_QUEUE` were read (now settings). `AI_SEARCH_ORCHESTRATION_ENABLED` is read at boot to choose container bindings, which a setting applied by the HTTP middleware cannot reach: **decided 2026-10-07 by the owner to stay an env variable** (the documented exception), see that plan's Task 2 Step 3.

---

### Task 12: Close

- [x] **Step 1: Run the whole AI suite and the baseline gates,** and the CMS and SAO application-content baselines, from the `laraplate` root. Done 2026-10-07: 1493 passed, 7 skipped, 1 failed: `RouteOverrideTest`, which passed when run alone straight after; it checks the `assistant-writes` routes that the governed-writes plan was adding to `routes/web.php` while the suite ran. The two documentation baseline gates and the CMS and SAO application-content baselines pass (33 tests), and no committed report under `docs/rag/evaluations` changed.

- [x] **Step 2: Delivery status and documentation.** Add the `## Delivery status (date): ...` section with the divergences and what Task 11 left open, and the `**Documented in:**` line. The module docs named in it describe each changed behaviour: `Modules/AI/docs/rag/MODULE.md` (citations, the retrieval and the prefix, the rebuild of the indexes), `DOCUMENTATION_EVALUATION_DEVELOPER.md` (the shared evaluation code), `ASSISTANT_EVALUATION.md`, `Modules/AI/README.md` (removed env variables). Done 2026-10-07: the delivery status below covers Tasks 1 to 12 and the docs are updated; `ARCHITECTURE.md` and `DESIGN_DECISIONS.md` lost their superseded chat sections and `TOOLS_USAGE_EXAMPLE.md` was kept (see the delivery status).

- [x] **Step 3: Record what is not measured.** The effect of Task 4 and Task 5 on relevance needs a live Elasticsearch and embeddings service and is measured by hand with `test:ai:evaluate-documentation`; the plan says so rather than claiming a gain. Done in the delivery status below.

## Delivery status (2026-10-07): shipped

**Documented in:** `Modules/AI/docs/rag/MODULE.md`, `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md`, `Modules/AI/docs/rag/ASSISTANT_EVALUATION.md`, `Modules/AI/README.md`, `Modules/AI/docs/ARCHITECTURE.md`, `Modules/AI/docs/DESIGN_DECISIONS.md`, `Modules/AI/docs/TOOLS_USAGE_EXAMPLE.md`.

Divergences from the plan, one line each (the detail is in the step):

- Task 1: Voyage embeddings are left as they were (its dimension parameter cannot be checked without the service); `AI_FAQ_ES_INDEX` is still read as the fallback of the developer index variable; the anonymous test providers did offer tools, so the stub is `StaticToolsProvider`, not `NoToolsProvider`; one of the "unused" stubs was found by `HasTranslations` through its name and was restored.
- Task 4: the agent keeps its own vector store and retrieval, with the same similarity threshold as the evaluated retrievals as a Neuron post-processor; the query prefix came with Task 5.
- Task 6: `SearchIntentData` and the `Data/Search` section classes were added (the intent extraction parsed JSON by hand too, and a nested schema needs classes); a missing search plan now ends in the rule-based plan instead of default values labelled as the model's.
- Task 7: the handler lives in `ChatAgent::resolveToolErrorHandler()`; a policy violation still ends the turn.
- Task 8: the seam stays the closure each service already has (a container factory per feature would add bindings for nothing); the work was one pass, not one commit per service.
- Task 9: the switches of embeddings and translation default to off when the setting is absent, as seeded; `EmbeddingsGate::handles()` exists beside `allows()` because a repair and a model switch must not depend on the switch.
- Task 10: the salvage into Core's `SearchQualityEvaluator` was done here, and the matching steps are ticked in the search-modes plan; `forDeveloperHelp` went with its tests, so the "console only" check for a developer help context has to be written again if one is ever needed.
- The Task 8 commit of the AI module (`d40ef8a`) also holds, by an error of mine (`git add -A` on directories while a concurrent session had staged and edited files), that session's removal of the `ActionRequest` path. The content is theirs and was verified by the AI suite run at the time; the commit message does not say it.

Task 11 (2026-10-07): the owner decided all four questions (retire `ActionRequest`, delete the dead guardrails path, keep `MemoryService`, move the env switches to settings), and the work is done in `2026-10-07-ai-module-review-decisions.md`. One exception, decided by the owner: `AI_SEARCH_ORCHESTRATION_ENABLED` is read at boot to choose container bindings and stays an env variable.

Not measured: the effect of Task 4 (the threshold and citations of the documentation answers) and of Task 5 (the passage prefix) on relevance needs a live Elasticsearch and embeddings service, and is measured by hand with `test:ai:evaluate-documentation` after the documentation indexes are rebuilt (`ai:index-rag-docs --full` per profile). The baseline gates use a stub that strips prefixes and hashes, so they pass whatever the effect is; the plan claims no gain.
