---
status: completed
---
# Application Content Retrieval Providers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the authenticated in-app assistant retrieve bounded, cited evidence from module-owned searchable data through a general Core provider contract, with CMS as the first provider.

**Architecture:** Core owns neutral contracts, typed source descriptors, the explicit registry, and the authorization gateway so optional modules never depend on AI. Provider modules own search and safe projection; AI builds a request-local source allowlist and routes one contextual read-only tool to a single authorized provider. Verified page context supplies the default source; requests without context use generic single-source routing and ask for clarification when ambiguous. Phase 1 is authenticated and non-guest only and reuses existing Core search; session-based guest assistance, automatic cross-provider fan-out, and new passage indexes remain separately gated.

**Tech Stack:** Laravel 12, PHP 8.4, Core authorization/ACL and orchestrated search, Laravel Scout, Elasticsearch/Typesense/database search drivers, NeuronAI v3, Pest 4.

**Spec:** `docs/superpowers/specs/2026-07-17-application-content-retrieval-design.md`

---

**Prerequisite:** Complete Tasks 1–7 of `docs/superpowers/plans/2026-07-16-in-app-ai-assistance-security.md` before registering `application_content_search` in the in-app assistant. This plan does not authorize a public route or profile.

**Workspace rule:** Run Artisan and tests from the Laraplate application root. `Modules/Core`, `Modules/CMS`, and `Modules/AI` are nested Git repositories. Commit files in the repository that owns them and stage only the exact paths named by the current task.

## Scope and sequencing

Tasks 1–2 establish the Core extension boundary and authorization gateway. Task 3 implements the CMS record-level baseline. Tasks 4–5 expose it safely to the authenticated assistant. Task 6 adds evaluation. Task 7 synchronizes documentation and records the Phase 2 gate. Task 8 is the release gate.

Do not create a second CMS index in this plan. Do not add `/api/v1` or guest assistance. Do not add module-specific fields to Core DTOs. Do not pass raw search `_source`, Eloquent arrays, permission names, or ACL expressions to AI.

### Task 1: Core provider contracts, DTOs, and deterministic registry

**Files:**

- Create: `Modules/Core/app/ApplicationContent/Contracts/ApplicationContentRetrievalProviderInterface.php`
- Create: `Modules/Core/app/ApplicationContent/Contracts/ApplicationContentRetrievalProviderRegistryInterface.php`
- Create: `Modules/Core/app/ApplicationContent/Data/ApplicationContentQuery.php`
- Create: `Modules/Core/app/ApplicationContent/Data/ApplicationContentSourceDescriptor.php`
- Create: `Modules/Core/app/ApplicationContent/Data/ApplicationContentAuthorization.php`
- Create: `Modules/Core/app/ApplicationContent/Data/ApplicationContentHit.php`
- Create: `Modules/Core/app/ApplicationContent/Data/ApplicationContentResult.php`
- Create: `Modules/Core/app/ApplicationContent/ApplicationContentRetrievalProviderRegistry.php`
- Create: `Modules/Core/app/ApplicationContent/Exceptions/DuplicateApplicationContentSourceException.php`
- Create: `Modules/Core/config/application-content.php`
- Create: `Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalProviderRegistryTest.php`
- Modify: `Modules/Core/app/Providers/CoreServiceProvider.php`

- [x] **Step 1: Write the failing registry and DTO tests**

Cover normalized source-key lookup, deterministic source listing, unknown source, duplicate registration failure, immutable DTOs, descriptor validation, limit validation, and rejection of unsafe/oversized hit fields. Assert registration is explicit and no event, reflection, or container scan discovers providers. Define an inline fake implementing `ApplicationContentRetrievalProviderInterface` in the test file; it receives its typed descriptor through the constructor and returns an empty typed result.

```php
it('rejects duplicate source keys instead of silently replacing providers', function (): void {
    $registry = app(ApplicationContentRetrievalProviderRegistryInterface::class);
    $registry->register(new FakeApplicationContentProvider('cms.contents'));

    expect(fn () => $registry->register(new FakeApplicationContentProvider('CMS.CONTENTS')))
        ->toThrow(DuplicateApplicationContentSourceException::class);
});
```

- [x] **Step 2: Run the registry test and verify failure**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalProviderRegistryTest.php
```

Expected: FAIL because the contracts and registry do not exist.

- [x] **Step 3: Implement the typed contract**

Use this public provider shape:

```php
interface ApplicationContentRetrievalProviderInterface
{
    public function descriptor(): ApplicationContentSourceDescriptor;

    public function retrieve(
        ApplicationContentQuery $query,
        ApplicationContentAuthorization $authorization,
    ): ApplicationContentResult;
}
```

`ApplicationContentSourceDescriptor` contains exactly a normalized source key, module key, Core entity key, supported locales, retrieval capabilities, and bounded intent categories. Reject free-form prompt instructions, class/index/connection names, callbacks, authorization data, and user- or tenant-specific values.

`ApplicationContentQuery` contains exactly `source`, `query`, `locale`, and `limit`. Clamp `limit` to the Core configuration maximum and reject blank queries. `ApplicationContentAuthorization` contains only the resolved permission name and optional `FiltersGroup`; it is internal control-plane data and must not implement `JsonSerializable`.

`ApplicationContentHit` contains exactly `id`, `source`, `module`, `entity`, `recordKey`, `excerpt`, `label`, `canonicalReference`, `locale`, `strategy`, optional `score`, optional `revision`, and `truncated`. Enforce configured maximum lengths at construction.

Create `application-content.php` with conservative server-owned defaults: `max_results=8`, `max_query_chars=2000`, `max_excerpt_chars=2000`, `max_label_chars=200`, and `max_reference_chars=500`. Environment configuration may lower or raise deployment limits within hard code-level ceilings; request/tool arguments can only lower them.

- [x] **Step 4: Implement and bind the registry**

Use normalized lowercase source keys from the descriptor. `register()` throws on collision. `providerFor()` returns `null` for unknown keys; it never resolves a class name dynamically. `descriptors()` returns a source-key-sorted list. Bind the interface as a singleton in `CoreServiceProvider`, following `GraphProviderRegistryInterface`. Providers are registered explicitly by module service providers during boot; do not introduce discovery events. Events remain available only for post-registration indexing, invalidation, deletion, and freshness notifications.

- [x] **Step 5: Run tests and commit Core**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalProviderRegistryTest.php
rtk git -C Modules/Core add app/ApplicationContent app/Providers/CoreServiceProvider.php config/application-content.php tests/Feature/ApplicationContent/ApplicationContentRetrievalProviderRegistryTest.php
rtk git -C Modules/Core commit -m "feat(core): add application content provider registry"
```

### Task 2: Authenticated Core retrieval gateway

**Files:**

- Create: `Modules/Core/app/ApplicationContent/ApplicationContentRetrievalService.php`
- Create: `Modules/Core/app/ApplicationContent/Exceptions/ApplicationContentUnavailableException.php`
- Create: `Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php`

- [x] **Step 1: Write failing gateway authorization tests**

Use a capturing fake provider. Cover missing authentication, unknown source, disabled provider module, select permission denial, row ACL propagation, provider exception, invalid returned source/entity, oversized result, and successful bounded retrieval. Assert the provider is never called before authorization succeeds.

```php
it('passes server-resolved ACL filters to the provider', function (): void {
    $request = Request::create('/app/ai/messages', 'POST');
    $request->setUserResolver(fn () => $user);

    $result = app(ApplicationContentRetrievalService::class)->retrieve(
        request: $request,
        query: new ApplicationContentQuery('cms.contents', 'renewal policy', 'en', 5),
    );

    expect($provider->capturedAuthorization?->filters)->not->toBeNull()
        ->and($result->hits)->toHaveCount(1);
});
```

- [x] **Step 2: Run the gateway test and verify failure**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php
```

Expected: FAIL because the gateway does not exist.

- [x] **Step 3: Implement the single authorized gateway**

`retrieve(Request $request, ApplicationContentQuery $query)` must:

1. require an authenticated Core user from the request;
2. resolve only a registered source key;
3. confirm that the provider module is installed and enabled;
4. call `AuthorizationService::ensurePermission($request, $provider->entity(), 'select')`;
5. resolve ACL filters for that permission;
6. invoke the provider with `ApplicationContentAuthorization`;
7. validate source/module/entity invariants and global result limits;
8. map internal failures to `ApplicationContentUnavailableException` without sensitive payloads.

Keep request identity, role names, tenant internals, permission name, and ACL expressions out of `ApplicationContentResult`.

- [x] **Step 4: Test Auth guard consistency**

Add a regression case where the request resolver and global guard disagree. The service must fail closed until both authorization paths refer to the same authenticated user. Do not allow queued/background provider execution to inherit a missing request identity.

- [x] **Step 5: Run Core authorization regressions and commit**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent Modules/Core/tests/Integration/Services/Authorization/AuthorizationServiceTest.php
rtk git -C Modules/Core add app/ApplicationContent tests/Feature/ApplicationContent
rtk git -C Modules/Core commit -m "feat(core): authorize application content retrieval"
```

### Task 3: CMS record-level retrieval provider baseline

**Files:**

- Create: `Modules/CMS/app/ApplicationContent/CmsApplicationContentRetrievalProvider.php`
- Create: `Modules/CMS/app/ApplicationContent/CmsContentEvidenceProjector.php`
- Create: `Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentRetrievalProviderTest.php`
- Modify: `Modules/CMS/app/Providers/CMSServiceProvider.php`

- [x] **Step 1: Write failing provider tests**

Cover registration only when CMS is enabled, source key `cms.contents`, exact lexical search, vector/hybrid result adaptation, locale selection, translation fallback policy, validity, soft deletion, row ACL, cross-tenant exclusion, deterministic ordering, excerpt truncation, safe canonical references, and degraded lexical fallback.

Assert that search `_source`, unrestricted components, media payloads, hidden relations, permission names, ACL filters, model/table/class names, and internal storage paths never appear in a hit.

```php
it('rehydrates authorized records before creating safe evidence', function (): void {
    $result = $provider->retrieve($query, $authorization);

    expect($result->hits[0]->excerpt)->toContain('Visible text')
        ->and((array) $result->hits[0])->not->toHaveKeys(['_source', 'components', 'media']);
});
```

- [x] **Step 2: Run the provider test and verify failure**

```bash
rtk php artisan test --compact Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentRetrievalProviderTest.php
```

Expected: FAIL because the provider and projector do not exist.

- [x] **Step 3: Implement authorized search adaptation**

Inject `AdvancedSearchService` and `AuthorizationService`. Search `Content` with the natural query, bounded result count, locale, and the ACL `FiltersGroup` supplied by Core. Do not call `Model::search()` without the Core constraint path and do not return `AdvancedSearchResult::source` directly.

Collect ranked record IDs, then rehydrate them through an Eloquent query that reapplies `AuthorizationService::applyAclFiltersToQuery()` and CMS validity/deletion rules. Preserve the ranked ID order after rehydration. A stale search hit that no longer passes the database query disappears without confirming its existence.

- [x] **Step 4: Implement the safe evidence projector**

Build evidence from provider-approved title, textual fields, locale, canonical path, update/revision marker, and normalized rank information. Strip markup, normalize whitespace, and truncate by Unicode character count. Never serialize `Content::toArray()` or `toSearchableArray()` into a hit.

Register the provider in `CMSServiceProvider::boot()` through `ApplicationContentRetrievalProviderRegistryInterface`, following the existing Graph provider registration pattern.

- [x] **Step 5: Run CMS search/ACL regressions and commit**

```bash
rtk php artisan test --compact Modules/CMS/tests/Feature/ApplicationContent/CmsApplicationContentRetrievalProviderTest.php Modules/CMS/tests/Integration/Models/ContentTest.php Modules/CMS/tests/Feature/Graph
rtk git -C Modules/CMS add app/ApplicationContent app/Providers/CMSServiceProvider.php tests/Feature/ApplicationContent/CmsApplicationContentRetrievalProviderTest.php
rtk git -C Modules/CMS commit -m "feat(cms): provide authorized content evidence retrieval"
```

### Task 4: Context-bound AI application content tool

**Files:**

- Create: `Modules/AI/app/Services/ApplicationContent/ApplicationContentToolProvider.php`
- Create: `Modules/AI/app/Services/ApplicationContent/ApplicationContentSourceRouter.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Data/ApplicationContentRequestContext.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Data/ApplicationContentRoutingDecision.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Enums/ApplicationContentRoutingStatus.php`
- Create: `Modules/AI/app/Services/ApplicationContent/ApplicationContentPromptProjector.php`
- Create: `Modules/AI/tests/Unit/Services/ApplicationContent/ApplicationContentToolProviderTest.php`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php`

- [x] **Step 1: Write failing tool schema and context tests**

Assert one tool named `application_content_search` with only `source`, `query`, `locale`, and `limit`. Assert `source` is a runtime enum constrained to the request-local authorized allowlist, never a free-form string, and the schema contains no unavailable provider. Assert absence of user/tenant/role/permission/ACL/index/class/field/filter/system-prompt arguments. Cover developer profile exclusion, unauthenticated context, unapproved source, limit clamping, timeout, provider denial, and safe evidence projection.

Add router cases for: verified CMS context selects `cms.contents` for a compatible request; forged, stale, or unverified context is ignored; contextual mode does not broaden to another source without an explicit user request; absent context uses generic descriptor routing; a sole authorized candidate is selected deterministically; no suitable source returns no selection; ambiguous generic routing requests clarification; registry order never resolves ambiguity; permissions or disabled modules remove a source before routing.

```php
it('registers content retrieval only for approved authenticated sources', function (): void {
    $tools = $provider->tools($inAppContext);

    expect($tools)->toHaveCount(1)
        ->and($tools[0]->name)->toBe('application_content_search')
        ->and(array_keys($tools[0]->parameters))->toBe(['source', 'query', 'locale', 'limit']);
});
```

- [x] **Step 2: Run the tool test and verify failure**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/ApplicationContentToolProviderTest.php
```

Expected: FAIL because the contextual tool provider does not exist.

- [x] **Step 3: Implement contextual tool creation**

Build the tool per authenticated `AssistantAccessContext`. Construct the source allowlist as registered providers intersected with enabled modules, profile capabilities, tenant configuration, and effective authorization eligibility. Do not store request identity, tenant, permissions, ACL, or page context in the singleton registry or provider instances.

`ApplicationContentRequestContext` is created only from server-verified route/module/entity metadata and contains `module`, optional `entity`, and optional opaque `recordKey`. Absence of verified context is represented by `null`, not by accepting an unverified DTO.

`ApplicationContentSourceRouter::route()` receives the natural-language query, authorized descriptors, optional verified context, and optional explicit source intent. It returns an immutable `ApplicationContentRoutingDecision` with status `selected`, `no_match`, or `clarification_required` and an optional selected source. In contextual mode, it selects the matching authorized source as the default for compatible requests and permits another source only for an explicit user request. Without context, use descriptor intent categories to select exactly one authorized source. Do not use registry order as a fallback and do not fan out in Phase 1. Keep selection diagnostics payload-free and internal.

Build the tool schema per request. Contextual mode narrows the runtime `source` enum to the compatible default unless explicit source intent was verified from the user's request. Generic mode exposes the request allowlist as the enum and validates any model proposal through the router. The model never makes the authoritative routing decision.

Call `ApplicationContentRetrievalService` directly with the authenticated request; never issue an HTTP request to Laraplate.

Keep the tool outside `ActionRequestService` and mutation replay. Provider errors return a generic unavailable tool result and payload-free reason code.

- [x] **Step 4: Implement the prompt projector**

Convert hits to bounded instruction-neutral evidence blocks containing excerpt, safe label, canonical reference, locale, revision, and rank. Escape or delimit retrieved text as untrusted data. Exclude internal diagnostics, raw scores not comparable across engines, authorization context, and unknown fields.

- [x] **Step 5: Run AI tool regressions and commit**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/ApplicationContentToolProviderTest.php Modules/AI/tests/Integration/ToolRegistryTest.php
rtk git -C Modules/AI add app/Services/ApplicationContent app/Providers/AIServiceProvider.php tests/Unit/Services/ApplicationContent/ApplicationContentToolProviderTest.php
rtk git -C Modules/AI commit -m "feat(ai): add authenticated application content tool"
```

### Task 5: In-app orchestration, citations, and abstention

**Files:**

- Create: `Modules/AI/app/Services/ApplicationContent/ApplicationContentCitationMapper.php`
- Create: `Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php`
- Modify: `Modules/AI/app/Services/Assistance/InAppAssistanceService.php`
- Modify: `Modules/AI/app/Services/Assistance/Policies/AssistanceContextPolicy.php`
- Modify: `Modules/AI/app/Services/Assistance/Policies/AssistanceOutputPolicy.php`

- [x] **Step 1: Write end-to-end failing assistance tests**

Cover a successful contextual CMS question, a generic request without page context, ambiguous generic routing, explicit cross-module intent, combined content evidence plus Graph expansion, no-evidence abstention, unsupported source, permission denial, hidden-record equivalence, malicious instructions inside evidence, unsafe citation, provider timeout, and output-policy rejection.

Assert application content is never added to either documentation index and the developer CLI receives no application content tool.

- [x] **Step 2: Run the feature test and verify failure**

```bash
rtk php artisan test --compact Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php
```

Expected: FAIL because the in-app orchestrator does not register or map application content evidence.

- [x] **Step 3: Integrate evidence under the existing security pipeline**

Register the tool only after profile/access-context creation, input policy success, request-local source allowlist construction, and routing. Add its evidence to `AssistantPromptContext` under the existing total token/chunk/node budget. The orchestration order remains authentication → verified application context → provider capability eligibility → source allowlist → routing → record permission and ACL authorization → retrieval → prompt projection → complete generation → output validation → persistence.

- [x] **Step 4: Map citations and enforce abstention**

Map each used hit to a user-safe citation label and canonical application reference. Do not present raw similarity as confidence. When no sufficient evidence is returned, respond with the localized insufficient-evidence message and do not let the model substitute assumed application data.

- [x] **Step 5: Run security regressions and commit**

```bash
rtk php artisan test --compact Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php Modules/AI/tests/Feature/InAppAssistanceSecurityTest.php Modules/AI/tests/Feature/InAppAssistanceAdversarialTest.php
rtk git -C Modules/AI add app/Services/ApplicationContent app/Services/Assistance tests/Feature/InAppApplicationContentAssistanceTest.php
rtk git -C Modules/AI commit -m "feat(ai): ground in-app answers in module evidence"
```

### Task 6: Evaluation harness and CMS baseline report

**Files:**

- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationCase.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationDataset.php`
- Create: `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php`
- Create: `Modules/AI/app/Console/EvaluateApplicationContentCommand.php`
- Create: `Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php`
- Create: `Modules/CMS/tests/Fixtures/application-content/cms-contents.json`
- Create after running baseline: `Modules/CMS/docs/evaluations/application-content/2026-07-record-baseline.json`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php`

- [x] **Step 1: Write failing deterministic metric tests**

Use a fake Core retrieval service and assert hit@K, reciprocal rank, citation precision, authorized-empty accuracy, supported-answer rate, abstention accuracy, latency slices, and locale/source slices. Ensure raw engine scores are retained only as internal diagnostics.

- [x] **Step 2: Implement the evaluation service and command**

Add `test:ai:evaluate-application-content` with required `--dataset`, `--source`, and `--output` options plus `--force`. The command runs provider-level retrieval evaluation without calling the chat provider and refuses sources not registered by enabled modules. The dataset loader builds typed, evaluation-only `FiltersGroup` access cases; this path is unavailable to HTTP/tools and does not bypass the authenticated gateway in production.

- [x] **Step 3: Create the CMS fixture**

Create at least 30 versioned cases: exact lookup, paraphrase, locale/translation, validity, ACL exclusion, cross-tenant exclusion, unsupported questions, long records, and citation mapping. Use generated test records and no production/customer data.

- [x] **Step 4: Run tests and the opt-in baseline**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationServiceTest.php
rtk php artisan test:ai:evaluate-application-content --dataset=Modules/CMS/tests/Fixtures/application-content/cms-contents.json --source=cms.contents --output=Modules/CMS/docs/evaluations/application-content/2026-07-record-baseline.json
```

The report records driver, corpus revision, provider version, aggregate/sliced metrics, and latency. It contains no prompts, full content, user identifiers, ACL expressions, or secrets.

- [x] **Step 5: Apply the passage-index gate**

Keep record-level retrieval when hit@5, citation precision, and supported-answer metrics meet the approved baseline. If long-record cases remain a material failure class, write a separate passage-index spec with ingestion, update, deletion, tenant isolation, storage, and cost analysis; do not add that index from this plan.

- [x] **Step 6: Commit evaluation code and artifacts in their owners**

```bash
rtk git -C Modules/AI add app/Services/ApplicationContent/Evaluation app/Console/EvaluateApplicationContentCommand.php app/Providers/AIServiceProvider.php tests/Unit/Services/ApplicationContent/Evaluation
rtk git -C Modules/AI commit -m "feat(ai): evaluate application content retrieval"
rtk git -C Modules/CMS add tests/Fixtures/application-content/cms-contents.json docs/evaluations/application-content/2026-07-record-baseline.json
rtk git -C Modules/CMS commit -m "test(cms): add application content retrieval baseline"
```

### Task 7: Module documentation and Phase 2 decision gate

**Files:**

- Modify: `Modules/Core/docs/rag/MODULE.md`
- Modify: `Modules/AI/docs/rag/MODULE.md`
- Modify: `Modules/CMS/docs/rag/MODULE.md`
- Modify: `Modules/Core/docs/GRAPH_SYSTEM.md`
- Modify: `Modules/AI/README.md`

- [x] **Step 1: Document the three separate retrieval surfaces**

Describe documentation RAG, Core Graph, and application content retrieval as independent capabilities with separate authorization, stores, DTOs, citations, evaluation, and failure behavior.

- [x] **Step 2: Document provider implementation rules**

Record Core ownership, typed descriptors, explicit service-provider registration without discovery events, duplicate failure, request-local allowlists, contextual and generic single-source routing, ambiguity clarification, authenticated gateway use, pre-query ACL, rehydration, safe projection, evidence DTO shape, no raw `_source`, and no module dependency on AI.

- [x] **Step 3: Record Phase 2 without implementing it**

Add a clearly non-authorized future section requiring a dedicated guest profile, session-level conversation isolation, guest permissions and ACL, rate limits, privacy/retention, abuse controls, prompt-injection treatment, safe fields, citations, cost budgets, and separate approval. State that a guest attached to the guard never receives `InAppAssistance`.

- [x] **Step 4: Run module documentation tests and commit by owner**

```bash
rtk php artisan test --compact Modules/Core/tests/Integration/CoreRagModuleDocumentationTest.php Modules/AI/tests/Integration/AiRagModuleDocumentationTest.php Modules/CMS/tests/Unit/CmsRagModuleDocumentationTest.php
rtk git -C Modules/Core add docs/rag/MODULE.md docs/GRAPH_SYSTEM.md
rtk git -C Modules/Core commit -m "docs(core): document application content providers"
rtk git -C Modules/AI add docs/rag/MODULE.md README.md
rtk git -C Modules/AI commit -m "docs(ai): document module evidence retrieval"
rtk git -C Modules/CMS add docs/rag/MODULE.md
rtk git -C Modules/CMS commit -m "docs(cms): document content retrieval provider"
```

### Task 8: Full security and release gate

**Files:**

- Create: `Modules/AI/tests/Feature/ApplicationContentRetrievalAdversarialTest.php`
- Modify: `Modules/AI/docs/rag/evaluations/2026-07-in-app-security.json`

- [x] **Step 1: Add adversarial cross-module cases**

Cover forged source keys, forged module/entity/page context, stale context, identity/tenant/permission arguments, raw filter/query DSL, source collision, disabled module, registry-order manipulation, ambiguous routing, attempted source selection outside the request allowlist, stale index hit, cross-tenant record, hidden field, malicious retrieved instructions, oversized excerpt, provider timeout, partial diagnostics, citation path injection, missing identity, guest principal, and attempted `/api/v1` use.

- [x] **Step 2: Run the full deterministic release suite**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent Modules/CMS/tests/Feature/ApplicationContent Modules/AI/tests/Unit/Services/ApplicationContent Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php Modules/AI/tests/Feature/ApplicationContentRetrievalAdversarialTest.php Modules/AI/tests/Feature/InAppAssistanceSecurityTest.php Modules/Core/tests/Feature/Graph
```

Expected: PASS without a live LLM or external search cluster.

- [x] **Step 3: Verify public access is absent**

Assert no application content route is registered under `/api/v1`, missing identities and guest principals cannot invoke the gateway/tool, and no configuration flag converts non-guest retrieval into guest retrieval.

- [x] **Step 4: Update the security report and commit**

Record aggregate pass/fail counts, policy/provider versions, and timings only. Release requires zero unauthorized-context, hidden-field, identity-spoofing, source-collision, public-fallback, or unsafe-output successes.

```bash
rtk git -C Modules/AI add tests/Feature/ApplicationContentRetrievalAdversarialTest.php docs/rag/evaluations/2026-07-in-app-security.json
rtk git -C Modules/AI commit -m "test(ai): gate application content retrieval security"
```

### Task 9: Close the configured guest boundary

**Files:**

- Modify: `Modules/Core/app/Models/User.php`
- Modify: `Modules/Core/tests/Feature/Models/UserTest.php`
- Modify: `Modules/Core/app/ApplicationContent/ApplicationContentRetrievalService.php`
- Modify: `Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php`
- Modify: `Modules/Core/docs/rag/MODULE.md`
- Modify: `Modules/AI/app/Services/Assistance/AssistantAccessContextFactory.php`
- Modify: `Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php`
- Modify: `Modules/AI/docs/rag/MODULE.md`
- Modify: `Modules/AI/README.md`
- Modify: `Modules/AI/docs/rag/evaluations/2026-07-in-app-security.json`
- Modify: `Modules/CMS/docs/rag/MODULE.md`
- Modify: `docs/superpowers/specs/2026-07-21-application-content-passage-index-gate.md`

- [x] **Step 1: Write the failing Core guest-classification tests**

Keep the existing no-email compatibility case and add configured-name, configured-username, normal-user, and invalid-configuration cases to `UserTest.php`:

```php
it('recognizes the configured guest account even when it has an email', function (): void {
    config()->set('permission.users.guest', 'visitor');
    $user = User::factory()->create([
        'name' => 'visitor',
        'username' => 'visitor',
        'email' => 'visitor@example.test',
    ]);

    expect($user->isGuest())->toBeTrue();
});

it('recognizes the configured guest account by username', function (): void {
    config()->set('permission.users.guest', 'visitor');
    $user = User::factory()->create([
        'name' => 'Public visitor',
        'username' => 'visitor',
        'email' => 'visitor@example.test',
    ]);

    expect($user->isGuest())->toBeTrue();
});

it('does not classify a normal account as guest', function (): void {
    config()->set('permission.users.guest', 'visitor');
    $user = User::factory()->create([
        'name' => 'member',
        'username' => 'member',
        'email' => 'member@example.test',
    ]);

    expect($user->isGuest())->toBeFalse();
});

it('fails guest classification when the configured account is invalid', function (): void {
    config()->set('permission.users.guest', '');
    $user = User::factory()->create(['email' => 'member@example.test']);

    expect(fn (): bool => $user->isGuest())
        ->toThrow(UnexpectedValueException::class);
});
```

- [x] **Step 2: Run the focused model tests and verify RED**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/Models/UserTest.php --filter='recognizes the configured guest|does not classify a normal account|fails guest classification'
```

Expected: both configured guest cases fail because `isGuest()` currently checks only whether email is absent, and the invalid-configuration case fails because no classification error is raised.

- [x] **Step 3: Implement the minimal central classifier**

Replace `User::isGuest()` with:

```php
public function isGuest(): bool
{
    $guest = config('permission.users.guest');

    if ($this->getAttribute('email') === null) {
        return true;
    }

    if (! is_string($guest) || $guest === '') {
        throw new \UnexpectedValueException('Configured guest account is invalid.');
    }

    return in_array($guest, [
        $this->getAttribute('name'),
        $this->getAttribute('username'),
    ], true);
}
```

- [x] **Step 4: Run the complete focused model file and verify GREEN**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/Models/UserTest.php
```

Expected: PASS, including legacy no-email compatibility, both configured-account shapes, normal-account exclusion, and invalid-configuration failure.

- [x] **Step 5: Write the failing Core gateway test**

Add to `ApplicationContentRetrievalServiceTest.php`:

```php
it('rejects the configured guest before provider execution', function (): void {
    config()->set('permission.users.guest', 'visitor');
    $guest = User::factory()->create([
        'name' => 'visitor',
        'username' => 'visitor',
        'email' => 'visitor@example.test',
    ]);
    $guest->givePermissionTo($this->permission);
    Auth::login($guest);
    $this->request->setUserResolver(static fn (): User => $guest);

    expect(fn () => $this->service->retrieve(
        $this->request,
        new ApplicationContentQuery('core.users', 'visible record', 'en', 5),
    ))->toThrow(ApplicationContentUnavailableException::class)
        ->and($this->provider->calls)->toBe(0);
});

it('fails closed before provider execution when guest classification fails', function (): void {
    config()->set('permission.users.guest', '');

    expect(fn () => $this->service->retrieve(
        $this->request,
        new ApplicationContentQuery('core.users', 'visible record', 'en', 5),
    ))->toThrow(ApplicationContentUnavailableException::class)
        ->and($this->provider->calls)->toBe(0);
});
```

- [x] **Step 6: Run the gateway test and verify RED**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php --filter='rejects the configured guest'
```

Expected: FAIL because the permitted configured guest reaches the provider.

- [x] **Step 7: Add the guest check to the Core identity invariant**

Extend `ApplicationContentRetrievalService::assertConsistentIdentity()` so its rejection condition ends with:

```php
|| $request_user->getAuthIdentifier() === null
|| $request_user->isGuest()
```

The provider registry and permission resolver must not run for a guest.

- [x] **Step 8: Run the Core gateway file and verify GREEN**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php
```

Expected: PASS and the guest test observes zero provider calls.

- [x] **Step 9: Write the failing AI access-context test**

Add a `bool $guest = false` argument to `assistanceUserMock()`, make it return that value from `isGuest()`, and add:

```php
it('rejects the configured guest before resolving tenant or permissions', function (): void {
    $user = assistanceUserMock(guest: true);
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class);
});

it('fails closed when guest classification fails', function (): void {
    $user = Mockery::mock(User::class)->makePartial();
    $user->shouldReceive('getKey')->andReturn(7);
    $user->shouldReceive('isGuest')->once()
        ->andThrow(new UnexpectedValueException('invalid guest configuration'));
    $resolver = Mockery::mock(AssistantTenantResolverInterface::class);
    $resolver->shouldNotReceive('resolveFor');

    expect(fn () => (new AssistantAccessContextFactory($resolver))->forInApp(
        assistanceConversation(),
        $user,
    ))->toThrow(AuthorizationException::class, 'Assistant access context is unavailable.');
});
```

- [x] **Step 10: Run the AI context test and verify RED**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php --filter='rejects the configured guest'
```

Expected: FAIL because `forInApp()` currently creates an in-app context for a guest-shaped user.

- [x] **Step 11: Reject guests before AI context resolution**

After ownership identifiers have been validated, move guest classification inside the existing normalized `try` block and before tenant or permission resolution:

```php
try {
    if ($authenticated_user->isGuest()) {
        throw new AuthorizationException('Assistant access context is unavailable.');
    }

    $tenant = $this->tenant_resolver->resolveFor($authenticated_user);
    $permissions = $this->effectivePermissions($authenticated_user);
} catch (Throwable $exception) {
    throw new AuthorizationException(
        'Assistant access context is unavailable.',
        previous: $exception,
    );
}
```

- [x] **Step 12: Run the complete AI context file and verify GREEN**

```bash
rtk php artisan test --compact Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php
```

Expected: PASS; guest rejection occurs before tenant and permission resolution.

- [x] **Step 13: Synchronize module documentation**

Document these exact boundaries:

- Core: `User::isGuest()` recognizes the configured guest account and the legacy missing-email shape; `ApplicationContentRetrievalService` rejects it before provider lookup.
- AI: Phase 1 is non-guest only even when the guest is attached to the session guard; Phase 2 is a separate session-based `GuestAssistance` design with session-subject conversation isolation.
- CMS: `cms.contents` is available only to authenticated non-guest `InAppAssistance`.
- Passage gate: it neither introduces nor authorizes session-based guest assistance.

Replace the stale public/anonymous Phase 2 wording in the named files; do not rename public DTO/API terminology that describes PHP visibility or a separately approved headless API.

- [x] **Step 14: Run documentation and focused security tests**

```bash
rtk php artisan test --compact Modules/Core/tests/Integration/CoreRagModuleDocumentationTest.php Modules/AI/tests/Integration/AiRagModuleDocumentationTest.php Modules/CMS/tests/Unit/CmsRagModuleDocumentationTest.php Modules/Core/tests/Feature/Models/UserTest.php Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php Modules/AI/tests/Feature/ApplicationContentRetrievalAdversarialTest.php
```

Expected: PASS without a live LLM or external search cluster.

- [x] **Step 15: Run the deterministic release suite**

```bash
rtk php artisan test --compact Modules/Core/tests/Feature/ApplicationContent Modules/CMS/tests/Feature/ApplicationContent Modules/AI/tests/Unit/Services/ApplicationContent Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php Modules/AI/tests/Feature/InAppApplicationContentAssistanceTest.php Modules/AI/tests/Feature/ApplicationContentRetrievalAdversarialTest.php Modules/AI/tests/Feature/InAppAssistanceSecurityTest.php Modules/Core/tests/Feature/Graph
```

Expected: PASS with zero failures. Record the emitted test, assertion, and duration totals in `Modules/AI/docs/rag/evaluations/2026-07-in-app-security.json`, set `evaluated_at` to `2026-08-03`, and retain only aggregate counts and existing policy/provider version identifiers.

- [x] **Step 16: Format changed PHP and repeat affected tests**

```bash
rtk vendor/bin/pint Modules/Core/app/Models/User.php Modules/Core/app/ApplicationContent/ApplicationContentRetrievalService.php Modules/Core/tests/Feature/Models/UserTest.php Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php Modules/AI/app/Services/Assistance/AssistantAccessContextFactory.php Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php
rtk php artisan test --compact Modules/Core/tests/Feature/Models/UserTest.php Modules/Core/tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php Modules/AI/tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php
```

Expected: Pint exits zero and the affected tests pass.

- [x] **Step 17: Commit exact owner paths without staging unrelated work**

Because `Modules/Core/app/Models/User.php` already contains unrelated worktree changes, stage only the guest-classifier hunk for the Core commit. Then commit by owner:

```bash
rtk git -C Modules/Core add -p app/Models/User.php
rtk git -C Modules/Core add app/ApplicationContent/ApplicationContentRetrievalService.php tests/Feature/Models/UserTest.php tests/Feature/ApplicationContent/ApplicationContentRetrievalServiceTest.php docs/rag/MODULE.md
rtk git -C Modules/Core commit -m "fix(core): reject guest content retrieval"
rtk git -C Modules/AI add app/Services/Assistance/AssistantAccessContextFactory.php tests/Unit/Services/Assistance/AssistantAccessContextFactoryTest.php docs/rag/MODULE.md README.md docs/rag/evaluations/2026-07-in-app-security.json
rtk git -C Modules/AI commit -m "fix(ai): exclude guest from in-app assistance"
rtk git -C Modules/CMS add docs/rag/MODULE.md
rtk git -C Modules/CMS commit -m "docs(cms): clarify non-guest retrieval boundary"
rtk git add Modules/Core Modules/AI Modules/CMS docs/superpowers/plans/2026-07-17-application-content-retrieval.md docs/superpowers/specs/2026-07-21-application-content-passage-index-gate.md
rtk git commit -m "docs(rag): close phase one guest boundary"
```

For the interactive Core staging, accept only the `isGuest()` hunk and reject the unrelated connection-affinity hunk already present in `User.php`. Before each commit, inspect `git diff --cached --stat` and ensure it contains only the paths owned by that commit.

## Completion checklist

- [x] Core owns neutral contracts and no AI/Neuron dependency.
- [x] Optional modules register providers without depending on AI.
- [x] Providers are registered explicitly during module boot; events are reserved for lifecycle notifications, not discovery.
- [x] Duplicate source registration fails deterministically.
- [x] Every request receives an allowlist intersecting registry, enabled modules, profile, tenant configuration, and effective authorization.
- [x] Verified application context selects the default source; absent context uses generic single-source routing.
- [x] Ambiguous generic requests ask for clarification and Phase 1 never fans out automatically.
- [x] Phase 1 requires an authenticated application user.
- [x] Phase 1 explicitly rejects the configured guest even when it is attached to the session guard.
- [x] Permission, ACL, tenant, validity, and deletion constraints apply before evidence reaches AI.
- [x] Search hits are rehydrated and reauthorized before safe projection.
- [x] Tool arguments cannot supply identity, authorization, fields, indexes, or raw query DSL.
- [x] Evidence contains only bounded allowlisted fields and canonical citations.
- [x] Documentation RAG, Graph tools, and application content retrieval remain distinct.
- [x] Empty/insufficient evidence causes abstention.
- [x] Record-level CMS retrieval has a committed evaluation baseline.
- [x] No passage index, multimodal processing, entity linking, guest assistant profile, or public endpoint is added without a separate approved design.

## Delivery status (2026-08-03): delivered, reconciled against the code on 2026-10-23

All nine tasks are built; the release evaluation ran on 2026-08-03. Divergences:

- The fake providers live in `Modules/Core/tests/Stubs/ApplicationContent/` and `Modules/AI/tests/Stubs/ApplicationContent/`, not inline in the tests, as the project rule requires.
- Added beyond the plan: `ProvidesPermissionModel`, `ApplicationContentDeadlineExecutor` with its exception, and `CmsApplicationContentDatasetTest`.
- The scored CMS baseline gate is in the application: `tests/Integration/ApplicationContent/CmsApplicationContentEvaluationBaselineTest.php`.
- The passage index is not built: `docs/superpowers/specs/2026-07-21-application-content-passage-index-gate.md` keeps it a deferred experiment. Guest assistance is not built.
- No module document describes the AI router, the tool schema or the citation mapping; to write in the AI repository.

**Documented in:** `Modules/Core/docs/rag/MODULE.md` (*Application content retrieval contract*), `Modules/CMS/docs/rag/MODULE.md` (*CMS application content retrieval provider*), `Modules/AI/docs/rag/MODULE.md` (*Application content evaluation*), `Modules/Core/docs/GRAPH_SYSTEM.md` (*AI Tool Boundary*).
