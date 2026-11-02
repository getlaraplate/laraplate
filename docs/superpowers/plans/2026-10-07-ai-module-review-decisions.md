---
status: completed
created_on: 2026-10-07
---
# AI Module Review: the Owner's Decisions — Implementation Plan

> **Superseded in part (2026-11-02):** The `core.search.vector.enabled` setting it names no longer exists; `features.embeddings.enabled` is the single switch. Superseded by `../specs/2026-10-30-vector-search-owned-by-ai-design.md`: the AI module owns the vector profile and Core keeps no `search.vector.*` setting. This plan stays true about its moment.

> **For agentic workers:** steps use checkbox (`- [ ]`) syntax. Tick each step when it is done, in the same work block. A step dropped on purpose is `- [-]` with its reason on the same line.

**Status:** Open. Tasks 1 and 3 are delivered, and Task 2 except its Step 3: `AI_SEARCH_ORCHESTRATION_ENABLED` needs a decision of the owner (see that step). The plan closes after it.

**Goal:** carry out the decisions of the owner recorded on 2026-10-07 in Task 11 of `2026-10-06-ai-module-neuron-review.md`: delete the dead guardrails path, move the env variables of switches and tuning to seeded settings, and remove the documentation that describes code that is gone. `MemoryService`, `ConversationSummary` and `features.chat.summary.enabled` are kept (Task 11 Step 3) and nothing here touches them.

**Source:** `2026-10-06-ai-module-neuron-review.md`, Task 11 Steps 2, 3 and 4 (the decisions) and Step 5 (the work they allow).

## Global Constraints

- Neuron v3.17.0 is installed and is the source of truth.
- A deleted file, setting, config key or env variable is searched across `Modules/*`, `config`, `routes`, `tests`, `docs` and `resources` first; the command and its result go in the commit message.
- `ToolResultGuard`, `AssistanceGuardrailPipeline` and the deterministic classifier are not touched, nor the config keys they read (`ai.features.guardrails.in_app_*`).
- A switch or a tuning value is a seeded setting (`AIDatabaseSeeder::runtimeSettingDefinitions()`), read as `config('ai....')` through the settings overlay; the config default stays as the fallback and has no env variable. The embeddings chunk and lock values keep their stated exception.
- Specs and plans are dated decisions: a sentence that a removal makes false gets a short dated note after it, it is not rewritten.
- The project has no other installation: seeding defines the settings, no data migration.

---

### Task 1: Delete the dead guardrails path

Decision of Task 11 Step 2 (option B).

- [x] **Step 1: Search first.** `rg -l "GuardrailsService|InjectionCheck|InjectionVerdict|AiModelFeature::Guardrails|LAKERA|lakera|Guardrails"` over `Modules config routes tests docs resources app database .env.example`, and the readers of each `ai.features.guardrails.*` key and of `ai.features.tools.enabled`.
  Done: 23 files, all in `Modules/AI` and `docs/superpowers`, none in `laraplate-ui`, `laraplate-importers` or the stack `docs`. `GuardrailsService` was the only reader of `prompt_injection_detection`, `lakera_api_key` and `lakera_endpoint`; `guardrails.enabled`, `json_validation` and `tools.enabled` had no reader at all. `in_app_policy_version`, `in_app_max_input_length` and `in_app_max_output_length` are read by `AssistantPolicyCatalog` and `AssistanceGuardrailPipeline` and stay. `.env.example` named none of them.
- [x] **Step 2: Delete the code and its tests.** `GuardrailsService`, `Data/InjectionCheck`, `Enums/InjectionVerdict`, `AiModelFeature::Guardrails` (every `match` over the enum), the config keys `guardrails.enabled`, `prompt_injection_detection`, `lakera_api_key`, `lakera_endpoint`, `json_validation` and `tools.enabled` when nothing reads them; `GuardrailsServiceFullTest`, the guardrail row of `AiModelFeatureWiringTest`, the guardrail default of `AiModelChoiceTest`.
  Done, plus `Exceptions/GuardrailViolationException`, which only `GuardrailsService` threw, and its line in `ExceptionHierarchyTest`. The two `match` over the enum with a guardrail arm (`settingName()`, `settingDescription()`) lost it; the others end in `default`. No Filament page lists the features by hand: the model settings come from `AiModelFeature::cases()`, so the seeder stops defining `features.guardrails.model`. A row already seeded in a database stays until it is deleted by hand; the project has no other installation.
- [x] **Step 3: Docs.** Every module doc line naming them; a dated note after the sentences of the CMS comments-moderation spec and plan ("reuse `GuardrailsService`"), of the SAO spec phase 8 (`ActionRequest`, `GuardrailsService` and `ModerationService`) and of the consumer list of the model-selection spec.
  Done: `README.md` (features, env block, the guardrails section rewritten as the in-app guardrails, the roadmap), `docs/rag/MODULE.md`, `docs/rag/AI_MODEL_SELECTION_DEVELOPER.md` and `_USER.md`, `docs/MODERATION.md`. Dated notes in the SAO spec (also for `ActionRequest`), the CMS comments-moderation spec and plan, and the model-selection spec (the consumer list and the default table). The model-selection plan's code blocks and the in-app security plan's commands are dated records and are left as they are.
- [x] **Step 4: Run the touched tests, format, commit.** 82 tests pass (model choice, feature wiring, exception hierarchy, moderation service, guardrail pipeline, in-app security). AI `56ac9e5`.

### Task 2: Env variables of switches and tuning become seeded settings

Decision of Task 11 Step 4: `AI_TEXT_GENERATION_*`, `AI_SEARCH_ORCHESTRATION_ENABLED`, `AI_FAQ_VECTOR_STORE`, `AI_FAQ_POLICY_CLASSIFICATION_VERSION`, `AI_MODERATION_QUEUE` (with its `AI_COMMENT_MOD_QUEUE` fallback).

- [x] **Step 1: The failing tests.** `AiRuntimeSettingDefinitionsTest` asserts each new setting with today's default, type and choices; `ModificationModerationListenerTest` asserts that the moderation job goes on the queue of the setting as it is when the listener runs.
  Done: eight definition cases (each also checks that the config default equals the seeded value) and an `AIDatabaseSeederTest` case that seeds, changes two rows and applies `DatabaseConfigOverlay`, then reads them from config; nine failed before Step 2. The queue test passed from the start: `HandleModificationModerationListener` already read the queue at dispatch, so it pins that behaviour rather than changing it.
- [x] **Step 2: The settings.** `AIDatabaseSeeder::runtimeSettingDefinitions()` defines them beside the other switches (same group, type and description style); `config.php` keeps the defaults without `env()`.
  Done: `features.text_generation.{enabled,max_output_chars,cache_ttl_seconds}`, `features.text_generation.rate_limit.{max,per_seconds}`, `features.faq.vector_store` (choices `elasticsearch` and `filesystem`, default `FaqVectorStoreConfig::DEFAULT_DRIVER`; `memory` stays for tests, set in config), `features.faq.policy_classification_version` and `features.moderation.queue`, all in group `ai`, same defaults as before. The readers were already `ai_config_*` calls at the time of use, so no reader changed. `.env.example` named none of these variables.
- [x] **Step 3: `AI_SEARCH_ORCHESTRATION_ENABLED`.** **Decided 2026-10-07 by the owner: (b), the exception.** It stays an env variable, like the embeddings chunk and lock values; `config.php` and `Modules/AI/README.md` ("Search orchestration bindings") now say why. It was blocked on this decision: The switch is read once, in `AIServiceProvider::boot()` (`registerSearchBindings()`), to choose the classes the container binds for Core's four search contracts. An HTTP request gets the settings overlay from the `ApplyDatabaseSettingsOverlay` middleware, after the providers have booted, and a queue worker boots once, so a setting read there would be honoured by console commands only. The env variable stays until the owner chooses: (a) bind the three contracts that have a Core fallback (`IReranker`, `ISearchPlanner`, `IQueryIntentParser`) through a resolver that reads the setting when the contract is resolved (not as singletons, or a worker keeps its first answer), and keep `ITextEmbedder` bound whatever the switch says; that changes behaviour, since with the switch off a rule-based plan that asks for vectors would now get them (`core.search.vector.enabled` and the embeddings settings still decide), and keeping today's "no embedder" with the switch off needs a Core change, because `AdvancedSearchService` asks `bound(ITextEmbedder::class)`; or (b) record the exception: a wiring switch read at boot, like the embeddings chunk and lock values, which stays env.
- [x] **Step 4: Docs.** `Modules/AI/README.md`, `docs/rag/DEPLOYMENT.md`, `docs/MODERATION.md`, `docs/rag/MODERATION_AND_SEARCH.md`, and the Core orchestration docs that name `AI_MODERATION_*`: "setting X in Filament".
  Done, plus the policy version lines of `DOCUMENTATION_EVALUATION_DEVELOPER.md`, `DOCUMENTATION_EVALUATION_USER.md` and `ASSISTANT_EVALUATION.md`. `DEPLOYMENT.md` and the README called `filesystem` the default store; the default is `elasticsearch` (config and code), corrected. `DEPLOYMENT.md` keeps the sentence that names the former `AI_FAQ_VECTOR_STORE`, which also keeps the committed live dataset case `kw-faq-vector-store` (`2026-09-18-developer-retrieval.json`) answerable. `Modules/Core/docs/EVENT_ORCHESTRATION.md` and `docs/rag/EVENT_ORCHESTRATION.md` are a separate commit in Core.
- [x] **Step 5: Run the touched tests and `RuntimeSettingNameLengthTest`, format, commit.** 88 tests pass (settings, seeder, moderation listener, setting name length, text generation listener, FAQ vector store file, documentation service, RAG index rebuilder, service provider). AI `21abf3f`, Core `7f9b5ded`.

### Task 3: Documentation of code that is gone

Task 11 Step 5 of the review. Each claim of "superseded" or "nothing reads it" is checked against the code first.

- [x] **Step 1: `ARCHITECTURE.md`.** The chat sections that describe the removed `ChatService` message path.
  Done: the status note, the streaming and non-streaming flows of `ChatService`, its RAG shortcut, the answering half of the RAG flow (LLPhant `QuestionAnswering`), the summary trigger "after sendMessage" and the `messages-with-tools` route are gone; the overview names Neuron instead of LLPhant, the message path points at `InAppAssistanceService`, the routes carry their real `/app/crud/.../ai/...` paths, and `MemoryService` is described as kept for the persistent user memory plan and not called today. The tool, embedding, translation and suggestion sections stay.
- [x] **Step 2: `DESIGN_DECISIONS.md`.** The same for its questions on streaming, tools and the memory configuration.
  Done: "both `streamMessage` and `insertMessage`" became why the answer is sent whole; "do streaming messages support tool calling" (LLPhant) is gone; the memory answer names the setting and says the assistant does not call it; the internal-use example uses `ChatAgent::forFeature()->ask()` instead of `ChatService::sendMessage()`; the feature table and the principles (no risk levels, settings not env) are corrected.
- [x] **Step 3: `TOOLS_USAGE_EXAMPLE.md`.** The review's claim is false now: the governed-writes plan rewrote it on 2026-10-07 as the live propose-then-confirm flow, and that closed plan names it in its `**Documented in:**`. It is kept; only the route it named, `messages-with-tools` (removed by Task 10 of the review), became `.../messages`, and the response body now has the shape `insertMessage` returns (`data` holds the message).
- [x] **Step 4: Env variables the docs name and nothing reads.** `AI_EMBEDDINGS_ENABLED`, `AI_CHAT_ENABLE_SUMMARY`, `AI_MODERATION_*`, `AI_COMMENT_*`, `AI_TOOLS_ENABLED`, `AI_GUARDRAILS_ENABLED`, rewritten as "setting X in Filament" where a setting exists.
  Done, each checked against `config.php` and `app/` first: `AI_EMBEDDINGS_ENABLED` (`SENTENCE_TRANSFORMERS_INSTALLATION.md`, `SEARCH_AND_TRANSLATION.md`) is the setting `features.embeddings.enabled`; `AI_CHAT_ENABLE_SUMMARY` (`DESIGN_DECISIONS.md`, `ARCHITECTURE.md`) is `features.chat.summary.enabled`; `AI_MODERATION_ENABLED`, `_APPROVAL_MODE`, `_AI_VOTES`, `_APPROVE_THRESHOLD`, `_REJECT_THRESHOLD` and `AI_COMMENT_*` (`MODERATION.md`, `MODERATION_AND_SEARCH.md`, Core's two orchestration docs) are the `features.moderation.*` settings. The claim was false for two of them until today: `AI_MODERATION_QUEUE` and `AI_COMMENT_MOD_QUEUE` were read (Task 2 moved them). `AI_TOOLS_ENABLED` and `AI_GUARDRAILS_ENABLED` went with Task 1. Also corrected on the way: the glossaries called LLPhant the underlying library (it is Neuron).
- [x] **Step 5: `docs/rag` and the RAG indexes** checked for the removed names. The root `docs/rag` names none of them; the module RAG docs are covered by the steps above. `Modules/AI/CHANGELOG.md` keeps its history. The committed evaluation datasets are not changed (see Task 2 Step 4).
- [x] **Step 6: Commit.** AI `eea67eb` (with the env lines of Step 4 that belong to Task 2 in `21abf3f`); Core `7f9b5ded`.

### Task 4: Close

- [x] **Step 1: Run the whole AI suite, `RuntimeSettingNameLengthTest`, the two documentation baseline gates and the CMS and SAO application-content baselines; static analysis of the changed files against `HEAD`.** Done 2026-10-07: the AI suite 1606 passed, 7 skipped, none failed; `RuntimeSettingNameLengthTest` and `ApplicationSettingDefinitionsTest` pass; the two documentation baseline gates with `Modules/CMS/tests/Feature/ApplicationContent` and `Modules/SAO/tests/Feature/ApplicationContent` pass (22 tests), and no file under `docs/rag/evaluations` changed. PHPStan on the changed PHP files reports the same two errors of `AIDatabaseSeeder` as before the change (the return shape of `runtimeSettingDefinitions()` and the `where('name')` column type, both pre-existing), nothing new.
- [x] **Step 2: Delivery status, `**Documented in:**`, the index, and the review plan closed.** Done 2026-10-07 once the owner decided Task 2 Step 3.

## Delivery status (2026-10-07): shipped

**Documented in:** `Modules/AI/README.md`, `Modules/AI/docs/ARCHITECTURE.md`, `Modules/AI/docs/DESIGN_DECISIONS.md`, `Modules/AI/docs/TOOLS_USAGE_EXAMPLE.md`, `Modules/AI/docs/MODERATION.md`, `Modules/AI/docs/rag/DEPLOYMENT.md`, `Modules/AI/docs/rag/MODULE.md`.

Divergences from the plan:
- `TOOLS_USAGE_EXAMPLE.md` was kept, not removed: the governed-writes plan had rewritten it as the live propose-then-confirm flow; only its route and response shape were corrected.
- `AI_MODERATION_QUEUE` and `AI_COMMENT_MOD_QUEUE` were read by the code, not unused; they became the setting `features.moderation.queue`.
- `GuardrailViolationException` was removed too, because only `GuardrailsService` threw it.
- `AI_SEARCH_ORCHESTRATION_ENABLED` stays an env variable by decision of the owner (Task 2 Step 3); it is the only one of the five groups that did not become a setting.
- No data migration: a `features.guardrails.model` row already seeded in a database stays until someone deletes it by hand.
