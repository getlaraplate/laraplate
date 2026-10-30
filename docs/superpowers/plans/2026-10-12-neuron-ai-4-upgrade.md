---
status: completed
created_on: 2026-10-12
---
# Neuron AI 4 Upgrade Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `Modules/AI` runs on `neuron-core/neuron-ai` `^4.1` with the same behaviour it has on 3.17.2, proven against a baseline taken before the upgrade.

**Architecture:** The vendor's upgrade guides (`vendor/neuron-core/neuron-ai/upgrade/NN-*.md`, after Task 2) are applied one at a time in numeric order, only the fifteen that apply, each with its own tests and commit. Each task below names its guide: the guide carries the how (patterns, before/after, checklist), this plan carries what we decided where the guide leaves a choice.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, Neuron AI 4.1.x, Elasticsearch 8/9.

**Spec:** `docs/superpowers/specs/2026-10-12-neuron-ai-4-upgrade-design.md`

## Delivery status (2026-10-19): done, merged into `master` locally, not pushed

**Documented in:** `Modules/AI/docs/GLOSSARY.md`, `Modules/AI/docs/rag/GLOSSARY.md`, `Modules/AI/docs/rag/MODULE.md`.

`Modules/AI` runs on `neuron-core/neuron-ai` 4.1.12 (`^4.1`). The fifteen guides were applied one task each, in numeric order, with the tests red before the code; a whole-branch review (read-only, fresh context) found no Critical issue and one Important, fixed below. On the owner's go it was merged into `master` of `Modules/AI` (fast-forward) and of `laraplate` (merge commit, with the submodule pointer), and this plan and its spec were closed. Nothing was pushed: the push is the owner's. The main checkout's `vendor/` still holds Neuron 3.17.2 until `composer install` runs there.

**Against the baseline.** Module AI: 1674 passed, 7 skipped, 0 failed before; 1712 passed, 7 skipped, 0 failed now (38 new tests). PHPStan on `Modules/AI`: 182 errors before, 170 now, no identifier above its baseline count. Whole repository from the worktree: 9181 passed, 35 skipped, 1 risky, 2 failed at the first full run; both failures are explained under Task 13 Step 2. The evaluation gates passed and no report under any `docs/rag/evaluations` changed.

**Where the work differs from the plan** (each is a ruling, with its reason in the step it touched):

- *Task 3, timeout.* Neuron 4's default HTTP client waits 300 s where 3.x waited 60 s. `ProviderFactory` and `EmbeddingsProviderFactory` hand each provider a `CurlHttpClient` with `ProviderFactory::HTTP_TIMEOUT` (60 s), and `ChatAgent` passes its cap at construction. No guide names this; the guide 2 searches found it.
- *Tasks 4 and 5, load order.* `SchemaToolProperty` got `isNullable()` and an identity `cast()` in Task 4 (Task 6 made the cast lenient), and `ToolCallingFakeProvider::chat()` returns `ProviderResponse` from Task 5: the classes did not load against the 4.x interfaces otherwise.
- *Task 6 Steps 3 and 4 ran after Task 7*: the endpoint test cannot reach a tool call before the agents have a thread id. `cast()` delegates to Neuron's `ScalarCaster` and returns what it cannot convert unchanged (spec Decision 4).
- *Task 7 Step 5, structured output (in no guide).* The 4.x `Deserializer` refuses a property that cannot be null and has no default, where 3.x let it through, and the retry loop then asks the model again. The sections of `SearchPlanData` and `SearchIntentData` (filled by their constructors) and the five fields of `ImageAnalysisData` are `required: false`; the review graded the first choice for the vision analysis (stay strict, tests changed) as a behaviour change and it was reversed: a partial answer is stored, as on 3.x. This changes what the model sees: those fields are no longer listed as required in the JSON schema. `GeneratedConversationTitle` and `ModerationVerdictData` keep `required: true`.
- *Task 9.* The store reads and writes `Document` through the accessors in this task, since its tests need them; `delete()` accepts an equality, alone or all together, and refuses any other shape (spec Decision 5).
- *Task 10.* The store validates the documents it is given; the `.lock` file that the 4.x `FileVectorStore` leaves beside its store is accepted (`FaqVectorStoreFileTest` expects it), and a full documentation rebuild removes it unless another process holds it; `ProviderFactoryTest` reads `Ollama::$baseUri`.
- *Task 11.* The retrieved documents of `DocumentationAgent` now travel in the user's turn, not in the system prompt (guide 24, Case 12). That agent has no tools and is reached only by `ai:help`; the in-app assistant keeps its authorized context in the system prompt (`ChatService`), so no policy decision rests on retrieved text.
- *Composer.* The lock removed `inspector-apm/inspector-php`, and `-W` also moved four packages by a patch or minor: `aws/aws-sdk-php` 3.400.1 to 3.401.2, `google/auth` v1.55.1 to v1.55.2, `laravel/horizon` v5.50.0 to v5.50.1, `smalot/pdfparser` v2.12.5 to v2.13.1. The Task 2 note above says only the first.
- *Static analysis.* The `@method static static make(...)` docblocks of `ChatAgent` and `DocumentationAgent` (guide 13), null-safe reads of the answer message, and float and string casts remove the errors the upgrade introduced.

**Minor review findings, fixed before the merge** (each with a test that failed first): `CallbackTool` reflects on its handler, so an argument the model leaves out or sends as null reaches the handler's own default, as Neuron's tools do; `SchemaToolProperty::cast()` refuses a value outside the `enum` and tells the model which values are allowed (on 3.x it reached the handler; two properties of the application content tool carry an enum); `FileDocumentReader` names the file when its front matter uses a key Neuron reserves (`id`, `content`, `embedding`, `score`, `sourceType`, `sourceName`, `metadata`); a full rebuild removes `<store>.lock` unless a process holds it (`flock` without waiting); the docblock of `RecordingPostProcessor` says the documents travel with the question. Nothing is deferred.

**Neuron integration against the Laravel skill** (Task 13 Step 3: `neuron-laravel-integration`, foundations 1 to 8; recommendations only). The application keeps its own conversation and message models and uses Neuron one call at a time, so most foundations are not in place by design:

1. Install: no `spatie/fork`; nothing runs evaluations in parallel.
2. Layout: Neuron classes live in the module (`Modules/AI/app/Ai/*`, `Services/Tools`), not in `App\Neuron`.
3. Keys: read through the module's `ai.providers.*` settings (editable at run time), not `config/services.php`.
4. Tables: no `chat_messages` or `workflow_store`; each agent gets a throwaway workflow id (Decision 3).
5. Container wiring: no `MessageStoreInterface` or `PersistenceInterface` binding; agents are built with `make()` per call, never as singletons, as the skill asks.
6. `bootstrap/app.php` error mapping: none (Laravel 10 layout); the assistant endpoint turns failures into its own `RunError` codes.
7. Evaluation: the application has `test:ai:evaluate-documentation`, not `neuron:evaluate`.
8. Queue: turns do not run as Neuron workflows, so `retry_after` does not depend on them.

Recommendations: when assistant turns move to Neuron workflows (a later spec), adopt `->for($threadId)` with `EloquentMessageStore` and `DatabasePersistence` and the skill's error mapping; declare the filterable metadata fields (`audience`, `module`, `locale`, `tenant_scope`) on the store's `DocumentSchema` so a Neuron retrieval scope becomes possible; make the 60-second request limit a setting if a provider needs longer. The two first-party packages the guides name (`neuron-core/router`, `neuron-core/raptor-retrieval`) are not required. No upgrade guide describes the stricter structured output above: worth telling the Neuron maintainers.

## Global Constraints

- Work on a branch named `neuron-4` in `Modules/AI` and in `laraplate` (lock and submodule pointer). Merge both only in Task 13, when the suite matches the baseline.
- Apply the guides in numeric order, one per task; never two at once. A symbol another guide migrates is left alone until that guide's task (the guide lists them).
- Behaviour does not change: tool names and descriptions, stored ids and index mappings, prompts, citations and refusals stay as they are on 3.17.2.
- No new dependencies and no `composer.json` change outside `Modules/AI` (spec Decision 7); the root `composer.lock` follows. No new base folders: the skills go in the tracked `.agents/skills/`.
- Never declare a class inside a test file: stubs go in `Modules/AI/tests/Stubs/` (PSR-4 `Modules\AI\Tests\Stubs\`).
- Every PHP file `declare(strict_types=1);`, `#[Override]` where overriding, `final`/`readonly` where siblings use them.
- Tests from the `laraplate` root, never with a cached config: `php artisan test --compact <paths>`; the whole module with `vendor/bin/pest Modules/AI/tests --parallel --compact`. The single-process full suite runs out of memory: use `vendor/bin/pest --parallel --compact`.
- Pint from the root on an explicit file list: `vendor/bin/pint --format agent <files>`. PHPStan: `vendor/bin/phpstan analyse <files>`. Fix errors, never silence them.
- Between Task 2 and Task 13 the module suite is not green: guides not yet applied break other tests. Each task runs its own tests and the tests the guide's checklist names; they must pass.
- Tick each step when it is done, in the same work block.

## Review Focus

- A model sends an integer for a tool argument declared `string` (a CRUD `id`): the tool must still run (Task 6 test).
- The 3.x documentation index, written before the upgrade, is searched by the 4.x store: the hits must come back with their content, source and metadata (Task 9 test).
- Every place that builds an agent (`ChatService`, `LlmSearchService`, `ModerationService`, `NeuronVisionAnalyzer`, `AiTranslationService`, `ConversationTitleService`, `ContextualSuggestionService`, `HandleAiTextGenerationListener`, `MemoryService`, `DocumentationService`) runs a call without "This agent has no thread ID" (Task 7 Step 3: one test per service through its real agent, added where missing).
- A document reaches the Elasticsearch store without an embedding: it is refused, not indexed with an empty vector (Task 10 test).
- A retrieved document with no score: the citation carries `score: null` and nothing fails (Task 10 test).

---

### Task 0: Baseline on 3.17.2

**Files:**
- Modify: this plan (the *Baseline* section at the end)

- [x] **Step 1:** Record `composer show neuron-core/neuron-ai | grep versions` (expected `3.17.2`). Done: 3.17.2.
- [x] **Step 2:** Run `vendor/bin/pest --parallel --compact` and record the totals (passed, skipped, failed) and the name of any failing test, in *Baseline*. Done, see *Baseline*.
- [x] **Step 3:** Run `vendor/bin/phpstan analyse Modules/AI --no-progress --error-format=json` and record the error total in *Baseline*. Done, see *Baseline*.
- [x] **Step 4:** Run the evaluation gates and record their result: `php artisan test --compact Modules/AI/tests/Feature/DocumentationBaselineGateTest.php Modules/AI/tests/Feature/DeveloperDocumentationBaselineGateTest.php Modules/AI/tests/Feature/Assistance/AssistantBaselineGateTest.php tests/Integration/ApplicationContent`. The committed reports under `Modules/AI/docs/rag/evaluations` and `docs/rag/evaluations` are the reference: Task 13 checks none changed. Done: they ran inside the Step 2 suite and passed (no AI or root test failed).
- [x] **Step 5:** Commit the plan: `docs: neuron 4 upgrade baseline`.

### Task 1: Branch and agent skills (guide 0)

- [x] **Step 1:** Create the branch `neuron-4` in `Modules/AI` and in `laraplate` from their `master`. Done 2026-10-19, in a worktree so the shared checkout and the other sessions keep 3.x: `laraplate/.claude/worktrees/neuron-4` (root branch `worktree-neuron-4`, from `origin/master` `50c99c8`; `Modules/AI` branch `neuron-4` from the pinned `ecb07f0`), with its own `vendor/` and a copy of `.env`. The AI suite there on 3.17.2 before any change: 1674 passed, 7 skipped, as in *Baseline*.
- [x] **Step 2 (after Task 2's `composer update`, since the skills ship in the 4.x package):** run `npx skills add ./vendor/neuron-core/neuron-ai/skills -y` from the `laraplate` root; the skills land in `.agents/skills/`. Commit them on the branch: `chore: install the Neuron 4 agent skills`. Done: the 13 `neuron-*` skills in `.agents/skills/`, linked into `.claude/skills/`, and `skills-lock.json` (root `5787385`).
- [x] **Step 3:** Guide 0 Step 4: stop and tell the owner to continue in a new session, so the new skills load. Done: the owner continued the plan in a new session (2026-10-19).

### Task 2: Composer (guide 1)

**Files:**
- Modify: `Modules/AI/composer.json`, `composer.lock`

- [x] **Step 1:** In `Modules/AI/composer.json`: `"neuron-core/neuron-ai": "^4.1"`. No other `composer.json` changes (spec Decision 7). Done (`Modules/AI` `4adadb8`).
- [x] **Step 2:** From the root: `composer update neuron-core/neuron-ai -W`. Expected: `neuron-core/neuron-ai` 4.1.x installed, `inspector-apm/inspector-php` removed (nothing else requires it), `guzzlehttp/guzzle` still installed (required by `laravel/framework`), nothing else downgraded. Record the package diff of `composer.lock` in the commit message. Done: 3.17.2 => **4.1.12** (released after the triage on 4.1.9; between the two only guides 7 and 26 changed, and neither applies), `inspector-apm/inspector-php` removed, nothing else changed (root `b43823b`).
- [x] **Step 3:** Run Task 1 Step 2. Done.
- [x] **Step 4:** Commit in `Modules/AI` and the root lock: `chore(ai): require neuron-ai 4.1`. Done.

### Task 3: HTTP client layer (guide 2)

**Files:**
- Modify: `Modules/AI/tests/Stubs/RecordingHttpClient.php`, `Modules/AI/tests/Integration/AiTextGenerationModelBindingTest.php`

- [x] **Step 1:** Apply guide 2 Case 7 to `RecordingHttpClient`: drop `withBaseUri()`, `withHeaders()`, `withTimeout()`; a recorded response with status 400 or above throws `HttpException::statusError()`. Done.
- [x] **Step 2:** `AiTextGenerationModelBindingTest` asserts the absolute Ollama chat URL instead of `'chat'` (read it from the 4.x Ollama provider, do not guess). Done: `http://ollama.test/api/chat` (`rtrim($this->baseUri, '/') . '/chat'` in `Ollama/HandleChat.php`; the factory appends `/api`). The assertion is reached only after Task 12 fixes the `ProviderResponse` read on the line above; the URL was checked on a one-off call.
- [x] **Step 3:** Run `AiTextGenerationModelBindingTest.php`, `AgentRoundTripTest.php`, `AiModelFeatureWiringTest.php`; they pass as far as later guides allow. Commit: `test(ai): the recording HTTP client follows the Neuron 4 contract`. Done: the failures left are Task 7 (thread id, `AgentRoundTripTest`) and Task 12 (`ProviderResponse::getContent()`, `AiTextGenerationModelBindingTest`).
- [x] **Step 4 (found by guide 2's searches, not in the triage):** Neuron 4's default client has a 300-second timeout (3.x: 60) and `ChatAgent::provider()` called `withTimeout()` on the provider's client after construction (Case 2). `ProviderFactory` and `EmbeddingsProviderFactory` now hand each provider a `CurlHttpClient` with `ProviderFactory::HTTP_TIMEOUT` (60 s), and `ChatAgent` passes its cap to `ProviderFactory::make()`. Tests: `ChatAgentTest` "leaves the provider timeout alone" (red, now green) and a new embeddings factory case. Commit: `fix(ai): keep the 60-second request timeout on Neuron 4 providers`.

### Task 4: Tools are classes (guide 3)

**Files:**
- Create: `Modules/AI/app/Services/Tools/CallbackTool.php`
- Modify: `Modules/AI/app/Services/Tools/ToolRegistry.php` (`buildNeuronTool()`, `buildNeuronToolStructure()`)
- Test: `Modules/AI/tests/Integration/ToolRegistryTest.php`

**Interfaces:**
- Produces: `final class CallbackTool extends NeuronAI\Tools\Tool`, constructed with `(string $name, string $description, Closure $handler)`; `public function __invoke(mixed ...$arguments): mixed` calls the handler with the arguments by name. `ToolRegistry::buildNeuronTool(ToolDefinition): Tool` returns a `CallbackTool`, with the same `setMaxRuns()` and `addProperty()` calls as today.

- [x] **Step 1:** Add to `ToolRegistryTest`: `it('builds every tool as a callable class with the definition name, description and properties')` — for a `ToolDefinition` with two parameters, the built tool is a `CallbackTool`, `getName()`/`getDescription()` equal the definition's, its properties are the two, and executing it with both arguments returns the handler's result.
- [x] **Step 2:** Run it: fails (`CallbackTool` missing, or `Cannot instantiate abstract class`). Done: failed with `Cannot instantiate abstract class NeuronAI\Tools\Tool`.
- [x] **Step 3:** Implement `CallbackTool` and switch `ToolRegistry` to it (guide 3 Case 2). Leave `ChatAgentTest.php:86` to Task 5. Done. `buildNeuronToolStructure()` was folded into `buildNeuronTool()` (nothing else called it). `SchemaToolProperty` got `isNullable()` (false) and an identity `cast()` so it loads against the 4.x `ToolPropertyInterface`; Task 6 turns `cast()` into the lenient conversion.
- [x] **Step 4:** Run `ToolRegistryTest.php` and the guide's checklist; pass. Commit: `refactor(ai): tools are built as a CallbackTool class (Neuron 4)`. Done: 9 passed; the only `Tool::make` left is `ChatAgentTest.php:86` (Task 5).

### Task 5: The ToolCall value object (guide 4)

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php` (`resolveToolErrorHandler()`), `Modules/AI/tests/Stubs/Assistance/ToolCallingFakeProvider.php`, `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`, `Modules/AI/tests/Integration/ChatAgentTest.php`

- [x] **Step 1:** `ChatAgent::resolveToolErrorHandler()` returns `Closure(Throwable, ToolCall): string|ToolOutput|null`; it still rethrows `AssistancePolicyViolationException` and returns the same safe text for anything else. Drop the `ToolInterface` import.
- [x] **Step 2:** The fake provider builds its calls with `ToolCall::make(name:, callId:, inputs:)`; `AgentEndpointTest` reads `getToolCalls()[0]`; `ChatAgentTest` builds a `ToolCall` instead of `Tool::make('lookup', …)`. Done. `ToolCallingFakeProvider::chat()` also returns `ProviderResponse` (guide 41 for test doubles, pulled forward from Task 12 Step 1): the class does not load against the 4.x `FakeAIProvider` otherwise, and `AgentEndpointTest` cannot run.
- [x] **Step 3:** Run `ChatAgentTest.php` and `AgentEndpointTest.php` (the missing-argument test may still fail until Task 6). Commit: `refactor(ai): tool errors and fake tool calls use Neuron 4's ToolCall`. Done: the handler tests pass; the rest fail on Task 7 (no thread id: the run ends in the refusal text), Task 8 (`Workflow::$executor` reflection) and the missing-argument case of Task 6.

### Task 6: Tool properties validate and cast (guide 5)

**Files:**
- Modify: `Modules/AI/app/Services/Tools/SchemaToolProperty.php`
- Test: `Modules/AI/tests/Unit/Services/Tools/SchemaToolPropertyTest.php` (create if absent), `Modules/AI/tests/Feature/Assistance/AgentEndpointTest.php`

**Interfaces:**
- Produces: `SchemaToolProperty::isNullable(): bool` (false: no schema parameter is nullable today) and `SchemaToolProperty::cast(mixed $input): mixed`, lenient as spec Decision 4 states.

- [x] **Step 1:** Write the tests: `cast()` turns `'42'` into `42` for `integer`, `'1.5'` into `1.5` for `number`, `42` into `'42'` for `string`, `'true'`/`1` into `true` and `'false'`/`0` into `false` for `boolean`; it returns `'abc'` unchanged for `integer`; arrays and objects pass through. The Review Focus case: a tool whose `id` is declared `string` runs when the model sends `42`. Done: `SchemaToolPropertyTest` (12 cases); 8 failed first, the 4 that passed are `isNullable()`, the unchanged `'abc'` and the pass-through, which Task 4's identity `cast()` already gave.
- [x] **Step 2:** Run: fail. Implement `isNullable()` and `cast()`. Run: pass. Done: `cast()` uses Neuron's `ScalarCaster` and returns the input unchanged when it cannot convert it.
- [x] **Step 3:** `AgentEndpointTest` "tool without a required argument": the model now receives Neuron's error output for the missing parameter and the error handler is not called. Assert that the stored answer is the model's next reply and that no exception text reaches the user. Done after Task 7 (the endpoint test cannot reach a tool call before the agents have a thread id): red on `toBeString()` since the model now gets `ToolOutput::error('Parameter "namespace" is required.')`; now asserts that, that no `Assistant tool call failed` warning is logged and that nothing of it is in the streamed events.
- [x] **Step 4:** Run both files; pass. Commit: `feat(ai): schema tool properties cast leniently (Neuron 4)`. Done: `SchemaToolPropertyTest` 12/12; `AgentEndpointTest` passes but for "keeps what the page claims…", which reads the system prompt as a string (Task 11).

### Task 7: A thread id for every agent (guide 13)

**Files:**
- Modify: `Modules/AI/app/Ai/Agents/ChatAgent.php`, `Modules/AI/app/Ai/Agents/DocumentationAgent.php` (constructors)
- Test: `Modules/AI/tests/Integration/ChatAgentTest.php`, `Modules/AI/tests/Integration/DocumentationServiceTest.php`

- [x] **Step 1:** Write the test: two `ChatAgent` instances have distinct, non-empty `getWorkflowId()`; one runs `chat()` on `FakeAIProvider` without "This agent has no thread ID". The same for `DocumentationAgent`. Done: `ChatAgentTest` (distinct ids, a `chat()` on `FakeAIProvider`) and `DocumentationServiceTest` (distinct ids); the two `AgentRoundTripTest` cases are the end-to-end runs of both agents. All red on "no thread ID" / a null id first.
- [x] **Step 2:** Run: fail. Both constructors call `parent::__construct(workflowId: (string) Str::uuid7())`; replace the comment about the executor with one line on why the id is generated (the application keeps its own conversations). Done: both comments are replaced by one line.
- [x] **Step 3:** For every service in Review Focus line 3, find the test that runs one call through the service's real agent on `FakeAIProvider` (or `FakeVectorStore` for `DocumentationService`). Where none exists, add one in that service's test file: `it('runs a call through a Neuron 4 agent with a workflow id')`, asserting the call returns and the provider recorded one request. List in this step's line which services already had one and which got a new test. Done: all ten already had one, none added: ChatService (`AgentEndpointTest`, through `buildProtectedAgent()`), LlmSearchService (`LlmSearchServiceTest`), ModerationService (`ModerationServiceTest`), NeuronVisionAnalyzer (`NeuronVisionAnalyzerParseTest`), AiTranslationService (`AiTranslationServiceTest`), ConversationTitleService (`ConversationTitleServiceTest`), ContextualSuggestionService (`ContextualSuggestionServiceTest`), HandleAiTextGenerationListener (`HandleAiTextGenerationListenerTest`), MemoryService (`MemoryServiceFullTest`), DocumentationService (`documentationServiceOver()` in `DocumentationServiceTest`). Each builds the real `ChatAgent`/`DocumentationAgent` over a `FakeAIProvider`.
- [x] **Step 4:** Run those tests; they pass as far as later guides allow (Document and SystemMessage failures belong to Tasks 10 and 11). Commit: `fix(ai): every agent runs with its own workflow id (Neuron 4)`. Done: see the ledger for what still fails and why.
- [x] **Step 5 (found when the services' tests ran, in no guide):** the 4.x `Deserializer` refuses a property the answer leaves out when it has no default, where 3.x let it through, and the structured output retry loop asks the model again. The sections of `SearchPlanData` and `SearchIntentData`, which their constructors fill, are declared `required: false` (red: `LlmSearchServiceTest` "fills what a plan leaves out", `SearchOrchestratorAgentTest` "clamps the plan", and a new intent test). `ImageAnalysisData` declared `required: true` and was first left strict (a partial answer retried, the two vision tests answering in full); the final review graded that an Important behaviour change, and it is reversed: its five fields are `required: false` too and the vision tests have their 3.x inputs back (a partial answer is stored). `GeneratedConversationTitle` and `ModerationVerdictData` stay `required: true`. Commit: `fix(ai): structured outputs keep tolerating the sections a model leaves out (Neuron 4)`.

### Task 8: Branch runners (guide 17)

- [x] **Step 1:** `ChatAgentTest.php:9-17` reflects on `Workflow::$executor`, which 4.x removed. Replace it with the assertion it was protecting: a constructed agent runs a `chat()` (covered by Task 7), and delete the reflection test. Fix the stale constructor comment in `DocumentationAgent` if Task 7 did not. Done: the reflection test (red: `ReflectionException`) and its `Workflow` import are gone; both agents' constructor comments were replaced in Task 7, and the guide's other searches find nothing in `Modules/AI`.
- [x] **Step 2:** Run `ChatAgentTest.php`; pass. Commit: `test(ai): drop the reflection on the removed workflow executor`. Done.

### Task 9: Vector store contract (guide 19)

**Files:**
- Modify: `Modules/AI/app/Ai/Rag/ElasticsearchRagVectorStore.php`, `Modules/AI/app/Ai/Rag/Retrieval/DeveloperDocumentationRetrieval.php`
- Test: `Modules/AI/tests/Unit/Ai/Rag/ElasticsearchRagVectorStoreTest.php`

**Interfaces:**
- Produces: `getSchema(): DocumentSchema`; `delete(FilterExpression $filters): VectorStoreInterface` translating the source filters Neuron sends (`sourceType`, `sourceName`) into today's term query; `search(SearchRequest $request): iterable` running today's kNN query with the request's embedding and top K. The private `search(array, ?array)` is renamed `knnSearch()`. `similaritySearchForContext()` stays as it is (spec Decision 5).

- [x] **Step 1:** Rewrite the four store tests that call `similaritySearch()`/`deleteBy()` against `search(SearchRequest)`/`delete(FilterExpression)` with the same expected Elasticsearch requests; add the Review Focus test: a hit shaped as 3.x wrote it (the current `documentToSource()` fields) maps back to a `Document` with its content, source and metadata. Done: the four tests rewritten against `search(SearchRequest)` / `delete(FilterExpression)` with the same Elasticsearch requests, plus the Review Focus test (a hit shaped as 3.x wrote it), a result-limit test, a schema test and a delete-by-unknown-field test. The add-documents test uses the `Document` setters (it needs them to build the document).
- [x] **Step 2:** Run: fail. Implement per guide 19 Case 5; `DeveloperDocumentationRetrieval` calls `search()` with a `SearchRequest`. Done (red: the class fatals on load, its private `search()` against the interface). The store uses `HasDocumentSchema` with the default schema, `delete()` accepts an equality, or an AND of equalities, on `sourceType` and `sourceName` and throws `DocumentSchemaException` on any other field and `VectorStoreException` on any other shape, and `search()` also applies the request's filters to the kNN query in the same way. The hit mapping and `documentToSource()` read and write through the `Document` accessors in this task (the tests need them); the rest of guide 20 stays in Task 10.
- [x] **Step 3:** Answer guide 19's "Data stored by 3.x" check for the documentation indexes and write the answer in this step's line. Run the store, retrieval and RAG rebuild tests; pass. Commit: `refactor(ai): the Elasticsearch RAG store implements the Neuron 4 search contract`. Answer: nothing to do. The documentation indexes are written by this store with its own mapping (`indexMappings()`: `sourceType`, `sourceName` and `neuron_id` keywords, a `metadata` object, a `dense_vector`) and read back by it, not by Neuron's built-in Elasticsearch store, whose `keyword`/schema rules the guide checks; the store declares no filterable field, and the Review Focus test reads a hit in the 3.x shape. Run: store 12/12, `DocumentationServiceElasticsearchTest` 7/7; the retrieval, rebuild, evaluation and switch tests still fail only on `Cannot access protected property Document::$…` (Task 10).

### Task 10: Document accessors (guide 20, and guide 33's check)

**Files:**
- Modify: the files guide 20's searches list (the triage found about 13 in `Modules/AI/app` and 30 in `Modules/AI/tests`, led by `ElasticsearchRagVectorStore`, `PrefixingEmbeddingsProvider`, `SentenceTransformersEmbeddingsProvider`, `MarkdownAwareSplitter`, `FileDocumentReader`, `DocumentationService`)
- Delete: `Modules/AI/tests/Stubs/Embeddings/DocumentWithArrayFormattedContent.php` and the test case that uses it (`tests/Integration/SentenceTransformersEmbeddingsProviderTest.php`)

- [x] **Step 1:** Write the Review Focus tests: `ElasticsearchRagVectorStore::addDocument()` with a document whose embedding is null throws and sends nothing to Elasticsearch; a citation built from a document with no score has `score: null` (`DocumentationService` and `InAppAssistanceService` citations). Done: the store test was red (it called Elasticsearch); the two citation tests passed at once, since the citation code already reads `getScore()`, so they were checked by mutation (`?? 0.0` makes both fail). The in-app one asserts `toBeNull()`, as `toMatchArray` would accept `0.0`.
- [x] **Step 2:** Run: fail. Apply guide 20 to every file its searches list: property reads become getters, writes become setters or `addMetadata()`, `PrefixingEmbeddingsProvider` embeds a new `Document` with the prefixed text and copies the embedding back, embeddings read from Elasticsearch are cast to float, the store refuses a document without an embedding (spec Decision 6), `SentenceTransformersEmbeddingsProvider` reads `getContent()` only. Done: 13 application files (the store was Task 9) and 33 test and stub files; the store now validates the documents it is given (`validateDocuments()`: embedding, schema, metadata values), embeddings from Elasticsearch are cast to float by `setEmbedding()`, `SentenceTransformersEmbeddingsProvider` reads `getContent()` only, and `DocumentWithArrayFormattedContent` and its test case are deleted. Found by the module suite and fixed here: the `.lock` file that the 4.x `FileVectorStore` leaves beside the store file (`FaqVectorStoreFileTest` expects it) and `ProviderFactoryTest` reading `Ollama::$url`, now `$baseUri` (guides 2 and 43).
- [x] **Step 3:** Guide 33: confirm chunk ids are only stored as the `neuron_id` keyword and never parsed; write the result in this step's line. Result: confirmed. A chunk id is written as `neuron_id` (a keyword in `indexMappings()`) and read back with `setId()`, in `ElasticsearchRagVectorStore` only; nothing parses it, and `FileDocumentReader` sets a sha256 as the id as before.
- [x] **Step 4:** Run the module's RAG, embedding, documentation and assistant tests; pass. Commit: `refactor(ai): documents are read and written through Neuron 4 accessors`. Done: the whole module suite was run (`vendor/bin/pest Modules/AI/tests --parallel --compact`): 1686 passed, 7 skipped, 14 failed before the last fixes; the five still failing are `DocumentationServiceTest` (2) and `HandleAiTextGenerationListenerTest` and `AgentEndpointTest` "keeps what the page claims" (system prompt, Task 11) and `AiTextGenerationModelBindingTest` (Task 12).

### Task 11: System messages and configured setters (guides 24 and 25)

- [x] **Step 1:** Guide 24: tests that read `RequestRecord::$systemPrompt` as a string read `?->getContent()` (`HandleAiTextGenerationListenerTest.php:48`, `AgentEndpointTest.php:227,237`, `ConversationTitleTest.php:180`); `DocumentationServiceTest.php:445,457` read the RAG context from the first user message's content instead of the system prompt. Any agent `instructions()` returning a string returns a `SystemMessage` with the same text. Done: the tests read `?->getContent()` and the RAG context from the first user message (they were red on the string comparisons); `ChatAgent` and `DocumentationAgent` return a `SystemMessage` from `instructions()`, and `ChatAgentTest` and `DocumentationAgentTest` read its text. The guide leaves a string return valid: this follows the plan. The prompt wording is untouched: its "provided context documents" still fits, the documents now follow the question.
- [x] **Step 2:** Guide 25: `DocumentationAgent::postProcessors()` stops spreading `parent::postProcessors()`. Done. No `setPostProcessors()` is called on any instance.
- [x] **Step 3:** Run the four test files and `DocumentationServiceTest.php`; pass. Commit: `refactor(ai): instructions are system messages (Neuron 4)`. Done: all pass.

### Task 12: Provider responses and testing fakes (guides 41 and 56)

- [x] **Step 1:** Guide 41: `ToolCallingFakeProvider::chat()` returns `ProviderResponse` (done in Task 5); `AiTextGenerationModelBindingTest.php:24` reads `->message()->getContent()`. Done: the `ToolCallingFakeProvider` part was Task 5; `AiTextGenerationModelBindingTest` reads `->message()->getContent()` (red: `ProviderResponse::getContent()` undefined, which hid the absolute-URL assertion of Task 3; both hold now). No application code calls a provider directly.
- [x] **Step 2:** Guide 56: check that `DocumentationServiceTest`'s `FakeVectorStore` canned hits (around lines 410 and 463) still come back unchanged; adjust to the 4.x constructor if they do not. Done: they come back unchanged (the answer, citation and source-isolation tests pass with the canned hits and `setSearchResults()`); nothing to adjust.
- [x] **Step 3:** Run `AgentEndpointTest.php`, `AiTextGenerationModelBindingTest.php`, `DocumentationServiceTest.php`; pass. Commit: `test(ai): fakes and provider calls follow Neuron 4`. Done: 58 passed.

### Task 13: Verify against the baseline, document, merge

**Files:**
- Modify: `Modules/AI/docs/GLOSSARY.md`, `Modules/AI/docs/rag/GLOSSARY.md` (Neuron "v3" becomes "v4"), `Modules/AI/README.md` if it names the Neuron version, `docs/superpowers/plans/2026-09-16-laravel-13-upgrade.md` (its Task 2 Neuron constraint step marked `- [-]` with the reason), this plan

- [x] **Step 1:** Re-run every guide's search patterns for the fifteen guides: no 3.x usage left. Done: the patterns of guides 2, 3, 4, 5, 13, 17, 19, 24, 25 and 56 and the property search of guide 20 find no 3.x usage; what they still list is 4.x code (`CallbackTool extends Tool`, the agents extending `Agent`/`RAG`, `RecordingHttpClient` implementing the 4.x interface, `ChatAgent::withTimeout()` which is the application's own method, Laravel's `Http::withHeaders()`, and `MissingCallbackParameter` as a sample exception in `ChatAgentTest`).
- [x] **Step 2:** `vendor/bin/pest --parallel --compact`: totals equal the baseline (a failing test of the baseline may still fail; any new failure is fixed). `vendor/bin/phpstan analyse Modules/AI`: no error above the baseline count. The evaluation gates of Task 0 Step 4 pass and `git status` shows no change under any `docs/rag/evaluations`. Done. Whole repository, from this worktree (the baseline was taken in the main checkout, whose other modules held another session's uncommitted work, so the totals differ by design): 9181 passed, 35 skipped, 1 risky, 2 failed; one failure was `ChatServiceFullTest` (fixed and green), the other `PartsOfParentHydrationTest` of CMS (a 403, green when run alone: a parallel-run flake outside the upgrade). Module AI alone: baseline 1674 passed, 7 skipped, 0 failed; 1700 passed, 7 skipped, 0 failed at that run, 1712 after the review fixes (38 new tests). PHPStan on `Modules/AI`: baseline 182 errors, now 170, and no identifier above its baseline count. The evaluation gates ran inside the suite and passed; `git status` shows no change under any `docs/rag/evaluations`.
- [x] **Step 3:** Run guide `AGENTS.md`'s conclusion: activate the `neuron-laravel-integration` skill, walk its foundations checklist, and write what differs from the recommended setup in the delivery status (recommendations only; no change in this plan). Done: see the delivery status.
- [x] **Step 4:** Update the docs listed above. Done: both glossaries say v4; `Modules/AI/README.md` names no Neuron version; `MODULE.md` states the structured output rule; the Task 2 line of the Laravel 13 plan is `- [-]`.
- [x] **Step 5:** Delivery status, `**Documented in:**` line, `status: completed`, the plans and specs `INDEX.md` entries; `php artisan test --compact tests/Unit/ClosedPlansPointToDocumentationTest.php` passes. Done: the closed-plans test passes (3 passed); the plan is `status: completed` and both `INDEX.md` entries and the spec status say delivered.
- [x] **Step 6:** Merge `neuron-4` into `master` in `Modules/AI` and the other touched modules, then in `laraplate` with the submodule pointers; push after the owner's go. Done on 2026-10-19 with the owner's go: `Modules/AI` fast-forwarded (no other module was touched), `laraplate` merged with a merge commit; no push.

## Baseline

Taken on 2026-10-15 on `neuron-core/neuron-ai` 3.17.2. Repository state: root `51849f8`, `Modules/AI` `def9115` (clean). Other modules had another session's uncommitted work in their trees (Core `cabdd064` +9 files, CMS `a29a0f1` +17, SAO `93fc4ce` +5, ERP `782f3be` +5, MES `306a658` +5; Shop `0b2fd35` clean), so compare their tests by name, not by totals.

**Suite** (`vendor/bin/pest --parallel --compact`, 14 processes, 1101 s): 8953 passed, 35 skipped, 1 risky, 2 failed.

| Module | Passed | Skipped | Failed |
|---|---|---|---|
| AI | 1674 | 7 | 0 |
| Core | 4032 | 24 | 0 |
| CMS | 919 | 0 | 2 |
| ERP | 721 | 1 | 0 |
| MES | 799 | 2 | 0 |
| SAO | 690 | 1 | 0 |
| Shop | 66 | 0 | 0 |
| root `tests/` | 53 | 0 | 0 |

The two failures are both CMS and belong to the uncommitted work above, not to this plan: `tests/Feature/Filament/TranslatedRecordFormsTest.php` (an untracked file at the time) and `tests/Feature/Models/ContentTest.php` "it leaves out the…".

**PHPStan** (`vendor/bin/phpstan analyse Modules/AI --no-progress --error-format=json --memory-limit=4G`): 182 errors. By identifier: `argument.type` 72, `cast.string` 20, `cast.int` 12, `return.type` 10, `method.notFound` 9, `staticMethod.notFound` 6, `arrayValues.list` 5, `ignore.count` 5, `property.nonObject` 5, `argument.unknown` 5, `function.alreadyNarrowedType` 4, `offsetAccess.nonOffsetAccessible` 4, `encapsedStringPart.nonString` 3, `assign.overwritten` 3, `binaryOp.invalid` 2, `offsetAccess.invalidOffset` 2, `missingType.generics` 2, and 13 identifiers with one error each. Task 13 runs the same command: no total above 182, and no identifier above its count here.

**Evaluation gates:** passed inside the suite; the committed reports under `Modules/AI/docs/rag/evaluations` and `docs/rag/evaluations` are the reference (Task 13 checks with `git status` that none changed).
