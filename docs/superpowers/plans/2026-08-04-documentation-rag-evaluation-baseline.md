---
status: completed
verified_on: 2026-09-15
verified_by: repo audit
note: DocumentationEvaluation{Case,Dataset,Service}, test:ai:evaluate-documentation and DocumentationBaselineGateTest are all present.
---
# Documentation RAG Evaluation Baseline (R0) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give documentation RAG a deterministic, per-module, retrieval-only evaluation baseline (dataset + scoring harness + `test:ai:evaluate-documentation` command + regression gate), starting with the Core/user index.

**Architecture:** Mirror the existing, mature application-content evaluation harness (`Modules/AI/app/Services/ApplicationContent/Evaluation/*` + `EvaluateApplicationContentCommand`), adapted to the documentation retrieval contract. Scoring routes through the real `InAppDocumentationRetrieval::retrieve()` using its injectable `$search` closure seam, so audience/permission/tenant/locale filtering and the safe projection run exactly as in production; only the Elasticsearch vector store is replaced by a deterministic in-memory fixture. Level-1 (retrieval) metrics are deterministic and gate CI; Level-2 (live generation) is defined in the spec but not implemented here.

**Tech Stack:** PHP 8.5, Laravel 12, Pest, `nwidart/laravel-modules`, NeuronAI `Document`.

**Spec:** `docs/superpowers/specs/2026-08-04-documentation-rag-evaluation-baseline-design.md`

## Global Constraints

- Every PHP file starts with `declare(strict_types=1);`.
- Always braces for control structures; explicit param + return types; `final`/`readonly` where the mirrored classes use them; `#[Override]` on overrides.
- No new dependencies, no new base folders.
- Never declare classes/traits/enums inside test files. Test support classes go under `Modules/AI/tests/Stubs/...` in namespace `Modules\AI\Tests\Stubs\` (PSR-4 already registered in `Modules/AI/composer.json` `autoload-dev`).
- Tests are Pest feature/unit tests under `Modules/AI/tests/{Unit,Feature}`. No external services (no live Elasticsearch, no live LLM) in any test in this plan.
- Docs content in English. Run `vendor/bin/pint --dirty` before each commit.
- Grading identity for a retrieved documentation chunk is its **safe source label** (`Document::$sourceName`, set by `InAppDocumentationRetrieval::safeDocuments()` from `metadata['safe_source_label']`). This is the only stable per-document identity that survives the safe projection.

**Mirror sources (read before starting — copy their structure/idioms):**
- `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationCase.php`
- `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationDataset.php`
- `Modules/AI/app/Services/ApplicationContent/Evaluation/ApplicationContentEvaluationService.php`
- `Modules/AI/app/Console/EvaluateApplicationContentCommand.php`
- `Modules/AI/tests/Feature/EvaluateApplicationContentCommandTest.php`

**Retrieval seam facts (verified in code):**
- `InAppDocumentationRetrieval::__construct(IEmbeddingService $embedding_service, ?Closure $search = null)`; `$search` signature is `fn(list<float> $embedding, DocumentationRetrievalContext $context): array<Document>`.
- `retrieve(string $question, AssistantAccessContext $access): list<Document>` embeds the question, calls `$search` (or real ES when null), then `safeDocuments()`. On any failure it throws `RuntimeException`. An empty match returns `[]` (no throw).
- `safeDocuments()` requires each document's metadata to pass `DocumentAudiencePolicy::allows(..., User)` **and** `permissions_metadata_validated === true` **and** `required_permissions_count === count(required_permissions)`; it sets `$doc->sourceName = metadata['safe_source_label']`, `$doc->sourceType = 'documentation'`, and keeps only metadata keys `audience, heading_breadcrumb, locale, module, safe_source_label, version`.
- `DocumentAudiencePolicy::allows(..., User)` requires: `audience ∈ {user, shared}`; non-empty strings for `module, locale, canonical_source, safe_source_label, version`; `policy_classification === 'user_safe'`; `policy_classification_version === <config ai.features.faq.policy_classification_version>` (default `in-app-docs-v1`); string-lists `required_permissions` and `heading_breadcrumb`; `tenant_scope === 'global'` (no `tenant_id`) or `'tenant'` (non-empty `tenant_id`).
- `DocumentationRetrievalContext::fromAccessContext()` requires `AssistantProfile::InAppAssistance` and a non-null `tenantScope`; `topK = min(max(config('ai.features.faq.max_documents',5),1),10)` — **the case's `top_k` is informational; actual K comes from config.** Tests set `ai.features.faq.max_documents` to the intended K.
- `AssistantAccessContext` (InApp profile) requires non-empty `userId` and `conversationId`, a non-null `tenantScope`, `tenantId` null for Global / non-empty for Tenant, and non-empty-string `effectivePermissions`.

---

### Task 1: `DocumentationEvaluationCase` value object

**Files:**
- Create: `Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationCase.php`
- Test: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationCaseTest.php`

**Interfaces:**
- Produces: `final readonly class DocumentationEvaluationCase` with public props `string $id, string $query, string $locale, int $topK, list<string> $expectedSourceLabels, list<string> $expectedCitationLabels, bool $expectAuthorizedEmpty, bool $expectSupportedAnswer, bool $expectRefusal, list<string> $slices, AssistantTenantScope $tenantScope, ?string $tenantId, list<string> $effectivePermissions`; plus `accessContext(): AssistantAccessContext`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationCase;

function makeDocCase(array $overrides = []): DocumentationEvaluationCase
{
    return new DocumentationEvaluationCase(
        id: $overrides['id'] ?? 'exact',
        query: $overrides['query'] ?? 'how do I export a grid?',
        locale: $overrides['locale'] ?? 'en',
        topK: $overrides['topK'] ?? 5,
        expectedSourceLabels: $overrides['expectedSourceLabels'] ?? ['Core · Grid export'],
        expectedCitationLabels: $overrides['expectedCitationLabels'] ?? ['Core · Grid export'],
        expectAuthorizedEmpty: $overrides['expectAuthorizedEmpty'] ?? false,
        expectSupportedAnswer: $overrides['expectSupportedAnswer'] ?? true,
        expectRefusal: $overrides['expectRefusal'] ?? false,
        slices: $overrides['slices'] ?? ['grid', 'single_hop'],
        tenantScope: $overrides['tenantScope'] ?? AssistantTenantScope::Global,
        tenantId: $overrides['tenantId'] ?? null,
        effectivePermissions: $overrides['effectivePermissions'] ?? [],
    );
}

it('builds a valid case and an in-app access context', function (): void {
    $case = makeDocCase();
    $access = $case->accessContext();

    expect($access->profile)->toBe(AssistantProfile::InAppAssistance)
        ->and($access->tenantScope)->toBe(AssistantTenantScope::Global)
        ->and($access->tenantId)->toBeNull()
        ->and($access->locale)->toBe('en');
});

it('rejects a refusal case that still expects sources', function (): void {
    expect(fn () => makeDocCase(['expectRefusal' => true, 'expectSupportedAnswer' => false]))
        ->toThrow(InvalidArgumentException::class);
})->with([[['expectedSourceLabels' => ['x'], 'expectedCitationLabels' => []]]]);

it('rejects an authorized-empty case that expects sources', function (): void {
    expect(fn () => makeDocCase([
        'expectAuthorizedEmpty' => true,
        'expectedSourceLabels' => ['x'],
        'expectedCitationLabels' => [],
        'expectSupportedAnswer' => false,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects contradictory supported + refusal flags', function (): void {
    expect(fn () => makeDocCase(['expectSupportedAnswer' => true, 'expectRefusal' => true]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a Tenant scope without a tenant id', function (): void {
    expect(fn () => makeDocCase(['tenantScope' => AssistantTenantScope::Tenant, 'tenantId' => null]))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a bad locale and a bad slice slug', function (): void {
    expect(fn () => makeDocCase(['locale' => 'english']))->toThrow(InvalidArgumentException::class);
    expect(fn () => makeDocCase(['slices' => ['Not A Slug']]))->toThrow(InvalidArgumentException::class);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationCaseTest.php`
Expected: FAIL (class `DocumentationEvaluationCase` not found).

- [x] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;

final readonly class DocumentationEvaluationCase
{
    /**
     * @param  list<string>  $expectedSourceLabels
     * @param  list<string>  $expectedCitationLabels
     * @param  list<string>  $slices
     * @param  list<string>  $effectivePermissions
     */
    public function __construct(
        public string $id,
        public string $query,
        public string $locale,
        public int $topK,
        public array $expectedSourceLabels,
        public array $expectedCitationLabels,
        public bool $expectAuthorizedEmpty,
        public bool $expectSupportedAnswer,
        public bool $expectRefusal,
        public array $slices,
        public AssistantTenantScope $tenantScope,
        public ?string $tenantId,
        public array $effectivePermissions,
    ) {
        $empty_expected = $this->expectedSourceLabels === [] && $this->expectedCitationLabels === [];

        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $this->id) !== 1
            || mb_trim($this->query) === ''
            || mb_strlen($this->query) > 2000
            || preg_match('/^[a-z]{2,3}(?:[-_][A-Z]{2})?$/', $this->locale) !== 1
            || $this->topK < 1
            || $this->topK > 10
            || ! $this->validStringList($this->expectedSourceLabels, 200)
            || ! $this->validStringList($this->expectedCitationLabels, 200)
            || ! $this->validSlugList($this->slices, 63)
            || ! $this->validStringList($this->effectivePermissions, 200)
            || ($this->tenantScope === AssistantTenantScope::Global && $this->tenantId !== null)
            || ($this->tenantScope === AssistantTenantScope::Tenant
                && ($this->tenantId === null || mb_trim($this->tenantId) === ''))
            || ($this->expectAuthorizedEmpty && ! $empty_expected)
            || ($this->expectRefusal && ! $empty_expected)
            || ($this->expectSupportedAnswer && $this->expectRefusal)) {
            throw new InvalidArgumentException('Documentation evaluation case is invalid.');
        }
    }

    public function accessContext(): AssistantAccessContext
    {
        return new AssistantAccessContext(
            profile: AssistantProfile::InAppAssistance,
            userId: 'evaluation-user',
            tenantScope: $this->tenantScope,
            tenantId: $this->tenantId,
            locale: $this->locale,
            effectivePermissions: $this->effectivePermissions,
            conversationId: 'evaluation-conversation',
        );
    }

    /**
     * @param  array<mixed>  $values
     */
    private function validStringList(array $values, int $maximumLength): bool
    {
        if (! array_is_list($values) || count(array_unique($values)) !== count($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value) || mb_trim($value) === '' || $maximumLength < mb_strlen($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $values
     */
    private function validSlugList(array $values, int $maximumLength): bool
    {
        if (! $this->validStringList($values, $maximumLength)) {
            return false;
        }

        foreach ($values as $value) {
            if (preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) !== 1) {
                return false;
            }
        }

        return true;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationCaseTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationCase.php Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationCaseTest.php
git commit -m "feat(ai): documentation evaluation case value object"
```

---

### Task 2: `DocumentationEvaluationDataset` value object

**Files:**
- Create: `Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationDataset.php`
- Test: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationDatasetTest.php`

**Interfaces:**
- Consumes: `DocumentationEvaluationCase` (Task 1).
- Produces: `final readonly class DocumentationEvaluationDataset` with public props `string $version, string $corpusRevision, string $module, string $indexProfile, string $dataClassification, list<DocumentationEvaluationCase> $cases`; static `fromFile(string $path): self` and `fromArray(array $data): self`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;

function docDatasetArray(array $overrides = []): array
{
    return array_replace([
        'version' => '1',
        'corpus_revision' => 'core-1',
        'module' => 'core',
        'index_profile' => 'user',
        'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'exact',
            'query' => 'how do I export a grid?',
            'locale' => 'en',
            'top_k' => 5,
            'expected_source_labels' => ['Core · Grid export'],
            'expected_citation_labels' => ['Core · Grid export'],
            'expect_authorized_empty' => false,
            'expect_supported_answer' => true,
            'expect_refusal' => false,
            'slices' => ['grid', 'single_hop'],
            'tenant_scope' => 'global',
            'tenant_id' => null,
            'effective_permissions' => [],
        ]],
    ], $overrides);
}

it('builds a dataset from a valid array', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray());

    expect($dataset->module)->toBe('core')
        ->and($dataset->indexProfile)->toBe('user')
        ->and($dataset->cases)->toHaveCount(1)
        ->and($dataset->cases[0]->id)->toBe('exact');
});

it('rejects an unknown top-level key', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['surprise' => 1])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an unknown index profile', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['index_profile' => 'admin'])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty case list', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['cases' => []])))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a non-synthetic classification', function (): void {
    expect(fn () => DocumentationEvaluationDataset::fromArray(docDatasetArray(['data_classification' => 'live'])))
        ->toThrow(InvalidArgumentException::class);
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationDatasetTest.php`
Expected: FAIL (class not found).

- [x] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use InvalidArgumentException;
use JsonException;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Enums\AssistantTenantScope;

final readonly class DocumentationEvaluationDataset
{
    /**
     * @param  list<DocumentationEvaluationCase>  $cases
     */
    public function __construct(
        public string $version,
        public string $corpusRevision,
        public string $module,
        public string $indexProfile,
        public array $cases,
        public string $dataClassification = 'synthetic',
    ) {
        $ids = array_map(static fn (DocumentationEvaluationCase $case): string => $case->id, $this->cases);

        if (! $this->validRevision($this->version)
            || ! $this->validRevision($this->corpusRevision)
            || preg_match('/^[a-z][a-z0-9_]*$/', $this->module) !== 1
            || DocumentationIndexProfile::tryFrom($this->indexProfile) === null
            || $this->cases === []
            || count($this->cases) > 1000
            || ! array_is_list($this->cases)
            || count(array_unique($ids)) !== count($ids)
            || $this->dataClassification !== 'synthetic') {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }
    }

    public static function fromFile(string $path): self
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is unavailable.');
        }

        $contents = file_get_contents($path);

        if (! is_string($contents) || mb_strlen($contents) > 2_000_000) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        try {
            $data = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        if (! is_array($data)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        return self::fromArray($data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        self::assertExactKeys($data, [
            'cases',
            'corpus_revision',
            'data_classification',
            'index_profile',
            'module',
            'version',
        ]);

        $raw_cases = $data['cases'] ?? null;

        if (! is_array($raw_cases) || ! array_is_list($raw_cases)) {
            throw new InvalidArgumentException('Documentation evaluation dataset is invalid.');
        }

        $cases = array_map(static function (mixed $case): DocumentationEvaluationCase {
            if (! is_array($case)) {
                throw new InvalidArgumentException('Documentation evaluation case is invalid.');
            }

            return self::caseFromArray($case);
        }, $raw_cases);

        return new self(
            version: self::string($data, 'version'),
            corpusRevision: self::string($data, 'corpus_revision'),
            module: self::string($data, 'module'),
            indexProfile: self::string($data, 'index_profile'),
            cases: $cases,
            dataClassification: self::string($data, 'data_classification'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function caseFromArray(array $data): DocumentationEvaluationCase
    {
        self::assertExactKeys($data, [
            'effective_permissions',
            'expect_authorized_empty',
            'expect_refusal',
            'expect_supported_answer',
            'expected_citation_labels',
            'expected_source_labels',
            'id',
            'locale',
            'query',
            'slices',
            'tenant_id',
            'tenant_scope',
            'top_k',
        ]);

        $scope = AssistantTenantScope::tryFrom(self::string($data, 'tenant_scope'));

        if ($scope === null) {
            throw new InvalidArgumentException('Documentation evaluation tenant scope is invalid.');
        }

        $tenant_id = $data['tenant_id'] ?? null;

        if ($tenant_id !== null && ! is_string($tenant_id)) {
            throw new InvalidArgumentException('Documentation evaluation tenant id is invalid.');
        }

        return new DocumentationEvaluationCase(
            id: self::string($data, 'id'),
            query: self::string($data, 'query'),
            locale: self::string($data, 'locale'),
            topK: self::integer($data, 'top_k'),
            expectedSourceLabels: self::stringList($data, 'expected_source_labels'),
            expectedCitationLabels: self::stringList($data, 'expected_citation_labels'),
            expectAuthorizedEmpty: self::boolean($data, 'expect_authorized_empty'),
            expectSupportedAnswer: self::boolean($data, 'expect_supported_answer'),
            expectRefusal: self::boolean($data, 'expect_refusal'),
            slices: self::stringList($data, 'slices'),
            tenantScope: $scope,
            tenantId: $tenant_id,
            effectivePermissions: self::stringList($data, 'effective_permissions'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function assertExactKeys(array $data, array $keys): void
    {
        $actual = array_keys($data);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        if ($actual !== $keys) {
            throw new InvalidArgumentException('Documentation evaluation schema is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function integer(array $data, string $key): int
    {
        $value = $data[$key] ?? null;

        if (! is_int($value)) {
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function boolean(array $data, string $key): bool
    {
        $value = $data[$key] ?? null;

        if (! is_bool($value)) {
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException('Documentation evaluation value is invalid.');
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new InvalidArgumentException('Documentation evaluation value is invalid.');
            }
        }

        return $value;
    }

    private function validRevision(string $value): bool
    {
        return mb_trim($value) !== ''
            && mb_strlen($value) <= 200
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $value) === 1;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationDatasetTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationDataset.php Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationDatasetTest.php
git commit -m "feat(ai): documentation evaluation dataset loader"
```

---

### Task 3: Deterministic corpus fixtures (stub embedder + fake search)

**Files:**
- Create: `Modules/AI/tests/Stubs/Documentation/StubDocumentationEmbeddingService.php`
- Create: `Modules/AI/tests/Stubs/Documentation/FakeDocumentationSearch.php`
- Test: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/FakeDocumentationSearchTest.php`

**Interfaces:**
- Consumes: `DocumentationRetrievalContext`, `IEmbeddingService`, NeuronAI `Document`.
- Produces:
  - `StubDocumentationEmbeddingService implements IEmbeddingService` — `embedText(string): list<float>` returns `[(float) crc32($text)]`.
  - `FakeDocumentationSearch` — invokable `__invoke(list<float> $embedding, DocumentationRetrievalContext $context): array<Document>`; constructor `__construct(array<string, list<Document>> $rankedByQuery)` keyed by raw query string. Static `document(string $label, string $locale, string $content, list<string> $breadcrumb, list<string> $requiredPermissions = [], string $tenantScope = 'global', ?string $tenantId = null, string $classificationVersion = 'in-app-docs-v1'): Document` builds a safe-projection-passing document whose `safe_source_label` is `$label`.
  - `FakeDocumentationSearch::forInAppRetrieval(array<string, list<Document>> $rankedByQuery): InAppDocumentationRetrieval` — convenience wiring a `StubDocumentationEmbeddingService` + this closure into a real `InAppDocumentationRetrieval`.

The stub embedder and the fake search agree on the key `(int) crc32($query)`, so ranking is authored per query while the real `InAppDocumentationRetrieval::retrieve()` (embedding + safe projection) still runs. The fake applies locale, tenant, and permission filtering equivalent to the production Elasticsearch filter, then truncates to `context->topK`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\AI\Enums\AssistantProfile;
use Modules\AI\Enums\AssistantTenantScope;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

function docAccess(array $permissions = [], AssistantTenantScope $scope = AssistantTenantScope::Global, ?string $tenantId = null): AssistantAccessContext
{
    return new AssistantAccessContext(
        profile: AssistantProfile::InAppAssistance,
        userId: 'u1',
        tenantScope: $scope,
        tenantId: $tenantId,
        locale: 'en',
        effectivePermissions: $permissions,
        conversationId: 'c1',
    );
}

it('returns ranked safe documents and strips unsafe metadata', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use the export action.', ['Core', 'Grid export']),
        ],
    ]);

    $docs = $retrieval->retrieve('how do I export a grid?', docAccess());

    expect($docs)->toHaveCount(1)
        ->and($docs[0]->sourceName)->toBe('Core · Grid export')
        ->and($docs[0]->sourceType)->toBe('documentation')
        ->and(array_key_exists('canonical_source', $docs[0]->metadata))->toBeFalse();
});

it('excludes a permission-gated document the principal cannot see', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'secret settings?' => [
            FakeDocumentationSearch::document('Core · Secret', 'en', 'Restricted.', ['Core', 'Secret'], ['core.secret.view']),
        ],
    ]);

    expect($retrieval->retrieve('secret settings?', docAccess()))->toBe([]);
    expect($retrieval->retrieve('secret settings?', docAccess(['core.secret.view'])))->toHaveCount(1);
});

it('excludes a document in another locale', function (): void {
    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'ciao?' => [FakeDocumentationSearch::document('Core · IT', 'it', 'Contenuto.', ['Core', 'IT'])],
    ]);

    expect($retrieval->retrieve('ciao?', docAccess()))->toBe([]); // access locale is en
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/FakeDocumentationSearchTest.php`
Expected: FAIL (stub classes not found).

- [x] **Step 3: Write minimal implementation**

`StubDocumentationEmbeddingService.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use BadMethodCallException;
use Modules\AI\Contracts\IEmbeddingService;
use NeuronAI\RAG\Embeddings\EmbeddingsProviderInterface;

final class StubDocumentationEmbeddingService implements IEmbeddingService
{
    /**
     * @return array<int, mixed>
     */
    public function embedDocument(string $data): array
    {
        return [];
    }

    /**
     * @return list<float>
     */
    public function embedText(string $text): array
    {
        return [(float) crc32($text)];
    }

    public function getEmbeddingsProvider(): EmbeddingsProviderInterface
    {
        throw new BadMethodCallException('Stub embedding service has no provider.');
    }
}
```

`FakeDocumentationSearch.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use Closure;
use Modules\AI\Ai\Rag\Retrieval\DocumentationRetrievalContext;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use NeuronAI\RAG\Document;

final class FakeDocumentationSearch
{
    /**
     * @param  array<int, list<Document>>  $rankedByHash
     */
    private array $rankedByHash;

    /**
     * @param  array<string, list<Document>>  $rankedByQuery
     */
    public function __construct(array $rankedByQuery)
    {
        $indexed = [];

        foreach ($rankedByQuery as $query => $documents) {
            $indexed[crc32((string) $query)] = $documents;
        }

        $this->rankedByHash = $indexed;
    }

    /**
     * @param  array<string, list<Document>>  $rankedByQuery
     */
    public static function forInAppRetrieval(array $rankedByQuery): InAppDocumentationRetrieval
    {
        $search = new self($rankedByQuery);

        return new InAppDocumentationRetrieval(
            new StubDocumentationEmbeddingService,
            Closure::fromCallable($search),
        );
    }

    /**
     * @param  list<float>  $embedding
     * @return list<Document>
     */
    public function __invoke(array $embedding, DocumentationRetrievalContext $context): array
    {
        $key = (int) ($embedding[0] ?? 0);
        $candidates = $this->rankedByHash[$key] ?? [];
        $allowed = [];

        foreach ($candidates as $document) {
            if ($this->isVisible($document, $context)) {
                $allowed[] = $document;
            }

            if (count($allowed) >= $context->topK) {
                break;
            }
        }

        return $allowed;
    }

    private function isVisible(Document $document, DocumentationRetrievalContext $context): bool
    {
        $metadata = $document->metadata;

        if (($metadata['locale'] ?? null) !== $context->locale) {
            return false;
        }

        $scope = $metadata['tenant_scope'] ?? null;

        if ($scope === 'tenant'
            && ($context->tenantScope->value !== 'tenant' || ($metadata['tenant_id'] ?? null) !== $context->tenantId)) {
            return false;
        }

        $required = $metadata['required_permissions'] ?? [];

        return array_values(array_diff($required, $context->effectivePermissions)) === [];
    }

    /**
     * @param  list<string>  $breadcrumb
     * @param  list<string>  $requiredPermissions
     */
    public static function document(
        string $label,
        string $locale,
        string $content,
        array $breadcrumb,
        array $requiredPermissions = [],
        string $tenantScope = 'global',
        ?string $tenantId = null,
        string $classificationVersion = 'in-app-docs-v1',
    ): Document {
        $document = new Document($content);
        $document->sourceType = 'documentation';
        $document->sourceName = $label;
        $document->metadata = [
            'audience' => 'user',
            'module' => 'core',
            'locale' => $locale,
            'canonical_source' => 'core/' . mb_strtolower(str_replace([' · ', ' '], ['/', '-'], $label)),
            'safe_source_label' => $label,
            'version' => '1',
            'policy_classification' => 'user_safe',
            'policy_classification_version' => $classificationVersion,
            'required_permissions' => $requiredPermissions,
            'required_permissions_count' => count($requiredPermissions),
            'permissions_metadata_validated' => true,
            'heading_breadcrumb' => $breadcrumb,
            'tenant_scope' => $tenantScope,
        ];

        if ($tenantId !== null) {
            $document->metadata['tenant_id'] = $tenantId;
        }

        $document->setScore(1.0);

        return $document;
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `composer dump-autoload -o && php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/FakeDocumentationSearchTest.php`
Expected: PASS. (Run `composer dump-autoload -o` once so the new Stubs namespace paths resolve.)

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/AI/tests/Stubs/Documentation Modules/AI/tests/Unit/Services/Documentation/Evaluation/FakeDocumentationSearchTest.php
git commit -m "test(ai): deterministic documentation retrieval fixtures"
```

---

### Task 4: `DocumentationEvaluationService`

**Files:**
- Create: `Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationService.php`
- Test: `Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationServiceTest.php`

**Interfaces:**
- Consumes: `DocumentationEvaluationDataset`, `DocumentationEvaluationCase` (Tasks 1–2); NeuronAI `Document`.
- Produces: `final readonly class DocumentationEvaluationService` with `evaluate(DocumentationEvaluationDataset $dataset, string $driver, callable $retrieval): array<string, mixed>` where `$retrieval` is `fn(string $question, AssistantAccessContext $access, DocumentationEvaluationCase $case): list<Document>`. Report keys: `schema_version, module, index_profile, driver, dataset_version, corpus_revision, data_classification, case_count, metrics, latency_ms, slices`. Metric keys: `source_hit_at_k, mean_reciprocal_rank, citation_precision, authorized_empty_accuracy, supported_answer_rate, refusal_accuracy, unavailable_rate`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('scores a perfect hit, a citation, and a correct refusal', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray([
        'cases' => [
            [
                'id' => 'hit', 'query' => 'how do I export a grid?', 'locale' => 'en', 'top_k' => 5,
                'expected_source_labels' => ['Core · Grid export'],
                'expected_citation_labels' => ['Core · Grid export'],
                'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
                'slices' => ['grid'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
            ],
            [
                'id' => 'refuse', 'query' => 'what is the weather?', 'locale' => 'en', 'top_k' => 5,
                'expected_source_labels' => [], 'expected_citation_labels' => [],
                'expect_authorized_empty' => false, 'expect_supported_answer' => false, 'expect_refusal' => true,
                'slices' => ['off_topic'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
            ],
        ],
    ]));

    $retrieval = FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use export.', ['Core', 'Grid export']),
        ],
        'what is the weather?' => [],
    ]);

    $service = new DocumentationEvaluationService;
    $report = $service->evaluate(
        $dataset,
        'fake',
        static fn (string $q, $access) => $retrieval->retrieve($q, $access),
    );

    expect($report['metrics']['source_hit_at_k'])->toBe(1.0)
        ->and($report['metrics']['mean_reciprocal_rank'])->toBe(1.0)
        ->and($report['metrics']['citation_precision'])->toBe(1.0)
        ->and($report['metrics']['refusal_accuracy'])->toBe(1.0)
        ->and($report['module'])->toBe('core')
        ->and($report['index_profile'])->toBe('user')
        ->and(json_encode($report))->not->toContain('what is the weather?');
});

it('counts a retrieval that throws as unavailable', function (): void {
    $dataset = DocumentationEvaluationDataset::fromArray(docDatasetArray());
    $service = new DocumentationEvaluationService;

    $report = $service->evaluate($dataset, 'fake', static function (): array {
        throw new RuntimeException('down');
    });

    expect($report['metrics']['unavailable_rate'])->toBe(1.0);
});
```

(`docDatasetArray()` is the helper defined in Task 2's test file; redefine it locally at the top of this test file to keep files independent.)

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationServiceTest.php`
Expected: FAIL (service not found).

- [x] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Services\Documentation\Evaluation;

use Closure;
use NeuronAI\RAG\Document;
use Throwable;

final readonly class DocumentationEvaluationService
{
    private Closure $clock;

    public function __construct(?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1_000_000_000;
    }

    /**
     * @param  callable(string, \Modules\AI\Services\Assistance\AssistantAccessContext, DocumentationEvaluationCase): list<Document>  $retrieval
     * @return array<string, mixed>
     */
    public function evaluate(
        DocumentationEvaluationDataset $dataset,
        string $driver,
        callable $retrieval,
    ): array {
        $records = [];

        foreach ($dataset->cases as $case) {
            $started_at = ($this->clock)();
            $labels = [];
            $unavailable = false;

            try {
                $documents = $retrieval($case->query, $case->accessContext(), $case);

                foreach ($documents as $document) {
                    if (! $document instanceof Document || ! is_string($document->sourceName)) {
                        throw new \RuntimeException('Invalid evaluation document.');
                    }

                    $labels[] = $document->sourceName;
                }
            } catch (Throwable) {
                $unavailable = true;
                $labels = [];
            }

            $elapsed = max(0.0, (($this->clock)() - $started_at) * 1000);
            $records[] = [
                'case' => $case,
                'labels' => $labels,
                'empty' => $labels === [],
                'unavailable' => $unavailable,
                'latency_ms' => $elapsed,
            ];
        }

        return [
            'schema_version' => '1',
            'module' => $dataset->module,
            'index_profile' => $dataset->indexProfile,
            'driver' => mb_trim($driver),
            'dataset_version' => $dataset->version,
            'corpus_revision' => $dataset->corpusRevision,
            'data_classification' => $dataset->dataClassification,
            'case_count' => count($records),
            'metrics' => $this->metrics($records),
            'latency_ms' => $this->latency($records),
            'slices' => $this->slices($records),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function metrics(array $records): array
    {
        $relevant = 0;
        $hits = 0;
        $reciprocal = 0.0;
        $returned = 0;
        $correct = 0;
        $authorized_empty = 0;
        $authorized_empty_ok = 0;
        $supported = 0;
        $supported_ok = 0;
        $refusal_ok = 0;
        $unavailable = 0;

        foreach ($records as $record) {
            /** @var DocumentationEvaluationCase $case */
            $case = $record['case'];
            $labels = $record['labels'];
            $empty = $record['empty'];

            if ($case->expectedSourceLabels !== []) {
                $relevant++;
                $first_rank = null;

                foreach ($labels as $index => $label) {
                    if (in_array($label, $case->expectedSourceLabels, true)) {
                        $first_rank ??= $index + 1;
                    }
                }

                if ($first_rank !== null) {
                    $hits++;
                    $reciprocal += 1 / $first_rank;
                }
            }

            foreach ($labels as $label) {
                $returned++;
                $correct += (int) in_array($label, $case->expectedCitationLabels, true);
            }

            if ($case->expectAuthorizedEmpty) {
                $authorized_empty++;
                $authorized_empty_ok += (int) $empty;
            }

            if ($case->expectSupportedAnswer) {
                $supported++;
                $supported_ok += (int) ! $empty;
            }

            $refusal_ok += (int) ($empty === $case->expectRefusal);
            $unavailable += (int) $record['unavailable'];
        }

        $count = count($records);

        return [
            'source_hit_at_k' => $this->ratio($hits, $relevant),
            'mean_reciprocal_rank' => $this->ratio($reciprocal, $relevant),
            'citation_precision' => $this->ratio($correct, $returned),
            'authorized_empty_accuracy' => $this->ratio($authorized_empty_ok, $authorized_empty),
            'supported_answer_rate' => $this->ratio($supported_ok, $supported),
            'refusal_accuracy' => $this->ratio($refusal_ok, $count),
            'unavailable_rate' => $this->ratio($unavailable, $count),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, float>
     */
    private function latency(array $records): array
    {
        $values = array_map(static fn (array $record): float => $record['latency_ms'], $records);
        sort($values, SORT_NUMERIC);

        return [
            'average' => $this->rounded(array_sum($values) / count($values)),
            'p50' => $this->rounded($this->percentile($values, 0.50)),
            'p95' => $this->rounded($this->percentile($values, 0.95)),
            'max' => $this->rounded(max($values)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, array<string, array<string, float>>>
     */
    private function slices(array $records): array
    {
        $locales = [];
        $categories = [];

        foreach ($records as $record) {
            /** @var DocumentationEvaluationCase $case */
            $case = $record['case'];
            $locales[$case->locale][] = $record;

            foreach ($case->slices as $slice) {
                $categories[$slice][] = $record;
            }
        }

        ksort($locales, SORT_STRING);
        ksort($categories, SORT_STRING);

        return [
            'locale' => array_map(fn (array $slice): array => $this->metrics($slice), $locales),
            'category' => array_map(fn (array $slice): array => $this->metrics($slice), $categories),
        ];
    }

    private function ratio(float|int $numerator, int $denominator): float
    {
        return $denominator === 0 ? 0.0 : $this->rounded($numerator / $denominator);
    }

    /**
     * @param  list<float>  $values
     */
    private function percentile(array $values, float $percentile): float
    {
        $index = max(0, (int) ceil($percentile * count($values)) - 1);

        return $values[$index];
    }

    private function rounded(float $value): float
    {
        return round($value, 4);
    }
}
```

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationServiceTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/AI/app/Services/Documentation/Evaluation/DocumentationEvaluationService.php Modules/AI/tests/Unit/Services/Documentation/Evaluation/DocumentationEvaluationServiceTest.php
git commit -m "feat(ai): documentation evaluation scoring service"
```

---

### Task 5: `test:ai:evaluate-documentation` command

**Files:**
- Create: `Modules/AI/app/Console/EvaluateDocumentationCommand.php`
- Modify: `Modules/AI/app/Providers/AIServiceProvider.php` (`register()`, next to `$this->app->singleton(ApplicationContentEvaluationService::class);`)
- Test: `Modules/AI/tests/Feature/EvaluateDocumentationCommandTest.php`

**Interfaces:**
- Consumes: `DocumentationEvaluationDataset`, `DocumentationEvaluationService` (Tasks 2, 4); `InAppDocumentationRetrieval` (resolved from container); fixtures (Task 3).
- Produces: artisan command `test:ai:evaluate-documentation --module= --index=user --dataset= --output= --force`, writing a JSON report atomically. Commands in `app/Console` are auto-registered by the module provider (same as `EvaluateApplicationContentCommand`, which has no explicit registration) — verify with `php artisan list | grep ai:evaluate`.

- [x] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Tests\Stubs\Documentation\FakeDocumentationSearch;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('writes a payload-free documentation evaluation report and refuses overwrite', function (): void {
    app()->instance(InAppDocumentationRetrieval::class, FakeDocumentationSearch::forInAppRetrieval([
        'how do I export a grid?' => [
            FakeDocumentationSearch::document('Core · Grid export', 'en', 'Use export.', ['Core', 'Grid export']),
        ],
    ]));

    $directory = sys_get_temp_dir() . '/laraplate-doc-eval-' . bin2hex(random_bytes(5));
    mkdir($directory, 0700, true);
    $dataset_path = $directory . '/dataset.json';
    $output_path = $directory . '/report.json';
    file_put_contents($dataset_path, json_encode([
        'version' => '1', 'corpus_revision' => 'core-1', 'module' => 'core',
        'index_profile' => 'user', 'data_classification' => 'synthetic',
        'cases' => [[
            'id' => 'hit', 'query' => 'how do I export a grid?', 'locale' => 'en', 'top_k' => 5,
            'expected_source_labels' => ['Core · Grid export'],
            'expected_citation_labels' => ['Core · Grid export'],
            'expect_authorized_empty' => false, 'expect_supported_answer' => true, 'expect_refusal' => false,
            'slices' => ['grid'], 'tenant_scope' => 'global', 'tenant_id' => null, 'effective_permissions' => [],
        ]],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'core', '--index' => 'user',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertSuccessful();

        $report = json_decode((string) file_get_contents($output_path), true, flags: JSON_THROW_ON_ERROR);

        expect($report['metrics']['source_hit_at_k'])->toBe(1.0)
            ->and($report['module'])->toBe('core')
            ->and(json_encode($report))->not->toContain('how do I export a grid?');

        $this->artisan('test:ai:evaluate-documentation', [
            '--module' => 'core', '--index' => 'user',
            '--dataset' => $dataset_path, '--output' => $output_path,
        ])->assertFailed();
    } finally {
        @unlink($output_path);
        @unlink($dataset_path);
        @rmdir($directory);
    }
});

it('fails when the dataset module does not match --module', function (): void {
    app()->instance(InAppDocumentationRetrieval::class, FakeDocumentationSearch::forInAppRetrieval([]));
    $this->artisan('test:ai:evaluate-documentation', [
        '--module' => 'erp', '--index' => 'user',
        '--dataset' => '/missing.json', '--output' => '/tmp/x.json',
    ])->assertFailed();
});
```

- [x] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact Modules/AI/tests/Feature/EvaluateDocumentationCommandTest.php`
Expected: FAIL (command `test:ai:evaluate-documentation` not found).

- [x] **Step 3: Write minimal implementation**

Command `EvaluateDocumentationCommand.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Modules\AI\Ai\Rag\DocumentationIndexProfile;
use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Services\Assistance\AssistantAccessContext;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Override;
use Throwable;

final class EvaluateDocumentationCommand extends Command
{
    #[Override]
    protected $signature = 'test:ai:evaluate-documentation
                            {--module= : Module that owns the dataset}
                            {--index=user : Documentation index profile (user|developer)}
                            {--dataset= : Path to an evaluation dataset}
                            {--output= : New JSON report path}
                            {--force : Replace an existing report}';

    #[Override]
    protected $description = 'Evaluate documentation RAG retrieval for a module without calling the chat model.';

    public function handle(
        DocumentationEvaluationService $evaluation,
        InAppDocumentationRetrieval $retrieval,
        Filesystem $files,
    ): int {
        $module = $this->optionString('module');
        $index = $this->optionString('index') ?? 'user';
        $dataset_path = $this->optionString('dataset');
        $output_path = $this->optionString('output');

        if ($module === null || $dataset_path === null || $output_path === null) {
            $this->error('The --module, --dataset, and --output options are required.');

            return self::FAILURE;
        }

        try {
            if ($files->exists($output_path) && ! (bool) $this->option('force')) {
                $this->error('The output report already exists. Use --force to replace it.');

                return self::FAILURE;
            }

            $output_directory = dirname($output_path);

            if (! $files->isDirectory($output_directory) || ! $files->isWritable($output_directory)) {
                $this->error('The output directory is unavailable.');

                return self::FAILURE;
            }

            $dataset = DocumentationEvaluationDataset::fromFile($dataset_path);

            if ($dataset->module !== $module || $dataset->indexProfile !== $index) {
                $this->error('The dataset module or index does not match the requested options.');

                return self::FAILURE;
            }

            if (DocumentationIndexProfile::tryFrom($index) !== DocumentationIndexProfile::User) {
                $this->error('Only the user documentation index is supported.');

                return self::FAILURE;
            }

            $report = $evaluation->evaluate(
                $dataset,
                'in-app-documentation',
                static fn (string $question, AssistantAccessContext $access): array => $retrieval->retrieve($question, $access),
            );

            $encoded = json_encode(
                $report,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
            $temporary_path = $output_path . '.tmp-' . bin2hex(random_bytes(6));

            try {
                $files->put($temporary_path, $encoded . PHP_EOL, true);
                $files->move($temporary_path, $output_path);
            } finally {
                if ($files->exists($temporary_path)) {
                    $files->delete($temporary_path);
                }
            }

            $this->info(sprintf('Evaluated %d documentation cases.', $report['case_count']));

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Documentation evaluation failed.');

            return self::FAILURE;
        }
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && mb_trim($value) !== '' ? $value : null;
    }
}
```

In `AIServiceProvider::register()`, add next to the existing application-content singleton:

```php
$this->app->singleton(DocumentationEvaluationService::class);
```

(Import `use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;` at the top with the other imports.)

- [x] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact Modules/AI/tests/Feature/EvaluateDocumentationCommandTest.php`
Expected: PASS. Also confirm registration: `php artisan list | grep test:ai:evaluate-documentation`.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/AI/app/Console/EvaluateDocumentationCommand.php Modules/AI/app/Providers/AIServiceProvider.php Modules/AI/tests/Feature/EvaluateDocumentationCommandTest.php
git commit -m "feat(ai): test:ai:evaluate-documentation command"
```

---

### Task 6: Core/user dataset + fixture corpus + regression gate

**Files:**
- Create: `Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json`
- Create: `Modules/AI/tests/Stubs/Documentation/CoreUserDocumentationCorpus.php`
- Test: `Modules/AI/tests/Feature/DocumentationBaselineGateTest.php`

**Interfaces:**
- Consumes: `DocumentationEvaluationDataset`, `DocumentationEvaluationService` (Tasks 2, 4); `FakeDocumentationSearch` (Task 3).
- Produces: `CoreUserDocumentationCorpus::retrieval(): InAppDocumentationRetrieval` — a fixture whose documents' `safe_source_label`s match `expected_source_labels` in the Core dataset. The gate test asserts committed Level-1 thresholds.

The dataset questions are authored from real Core user docs (`Modules/Core/docs/rag/SEARCH_MATCHING_USER.md`, `GLOSSARY.md`, `EVENT_ORCHESTRATION.md`, `MODULE.md`). In deterministic mode the fixture corpus stands in for the indexed Core docs; labels are shared by construction between dataset and fixture. A later opt-in live-Elasticsearch run reuses the same questions and may require reconciling labels with the real indexer output — that is out of scope for R0.

- [x] **Step 1: Write the Core dataset file**

```json
{
  "version": "1",
  "corpus_revision": "core-2026-08",
  "module": "core",
  "index_profile": "user",
  "data_classification": "synthetic",
  "cases": [
    {
      "id": "search-required-terms",
      "query": "how do I force a search term to be required?",
      "locale": "en", "top_k": 5,
      "expected_source_labels": ["Core · Adaptive search matching · Required terms and exact phrases"],
      "expected_citation_labels": ["Core · Adaptive search matching · Required terms and exact phrases"],
      "expect_authorized_empty": false, "expect_supported_answer": true, "expect_refusal": false,
      "slices": ["search", "single_hop"], "tenant_scope": "global", "tenant_id": null, "effective_permissions": []
    },
    {
      "id": "search-preferences",
      "query": "what matching preferences can I set for search?",
      "locale": "en", "top_k": 5,
      "expected_source_labels": ["Core · Adaptive search matching · Matching preferences"],
      "expected_citation_labels": ["Core · Adaptive search matching · Matching preferences"],
      "expect_authorized_empty": false, "expect_supported_answer": true, "expect_refusal": false,
      "slices": ["search", "single_hop"], "tenant_scope": "global", "tenant_id": null, "effective_permissions": []
    },
    {
      "id": "permission-vs-acl",
      "query": "what is the difference between a permission and an ACL?",
      "locale": "en", "top_k": 5,
      "expected_source_labels": ["Core · Glossary · Permission vs ACL"],
      "expected_citation_labels": ["Core · Glossary · Permission vs ACL"],
      "expect_authorized_empty": false, "expect_supported_answer": true, "expect_refusal": false,
      "slices": ["permissions", "single_hop"], "tenant_scope": "global", "tenant_id": null, "effective_permissions": []
    },
    {
      "id": "event-orchestration",
      "query": "how does the cross-module event bus order listeners?",
      "locale": "en", "top_k": 5,
      "expected_source_labels": ["Core · Event orchestration"],
      "expected_citation_labels": ["Core · Event orchestration"],
      "expect_authorized_empty": false, "expect_supported_answer": true, "expect_refusal": false,
      "slices": ["events", "single_hop"], "tenant_scope": "global", "tenant_id": null, "effective_permissions": []
    },
    {
      "id": "off-topic-refusal",
      "query": "what is the capital of France?",
      "locale": "en", "top_k": 5,
      "expected_source_labels": [], "expected_citation_labels": [],
      "expect_authorized_empty": false, "expect_supported_answer": false, "expect_refusal": true,
      "slices": ["off_topic"], "tenant_scope": "global", "tenant_id": null, "effective_permissions": []
    }
  ]
}
```

- [x] **Step 2: Write the fixture corpus + failing gate test**

`CoreUserDocumentationCorpus.php`:

```php
<?php

declare(strict_types=1);

namespace Modules\AI\Tests\Stubs\Documentation;

use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;

final class CoreUserDocumentationCorpus
{
    public static function retrieval(): InAppDocumentationRetrieval
    {
        $doc = static fn (string $label, array $breadcrumb): array => [
            FakeDocumentationSearch::document($label, 'en', 'Reference content for ' . $label . '.', $breadcrumb),
        ];

        return FakeDocumentationSearch::forInAppRetrieval([
            'how do I force a search term to be required?' => $doc(
                'Core · Adaptive search matching · Required terms and exact phrases',
                ['Core', 'Adaptive search matching', 'Required terms and exact phrases'],
            ),
            'what matching preferences can I set for search?' => $doc(
                'Core · Adaptive search matching · Matching preferences',
                ['Core', 'Adaptive search matching', 'Matching preferences'],
            ),
            'what is the difference between a permission and an ACL?' => $doc(
                'Core · Glossary · Permission vs ACL',
                ['Core', 'Glossary', 'Permission vs ACL'],
            ),
            'how does the cross-module event bus order listeners?' => $doc(
                'Core · Event orchestration',
                ['Core', 'Event orchestration'],
            ),
            'what is the capital of France?' => [],
        ]);
    }
}
```

`DocumentationBaselineGateTest.php`:

```php
<?php

declare(strict_types=1);

use Modules\AI\Ai\Rag\Retrieval\InAppDocumentationRetrieval;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationDataset;
use Modules\AI\Services\Documentation\Evaluation\DocumentationEvaluationService;
use Modules\AI\Tests\Stubs\Documentation\CoreUserDocumentationCorpus;

beforeEach(function (): void {
    config()->set('ai.features.faq.max_documents', 5);
    config()->set('ai.features.faq.policy_classification_version', 'in-app-docs-v1');
});

it('keeps the Core/user documentation baseline at or above committed thresholds', function (): void {
    $path = base_path('Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json');
    $dataset = DocumentationEvaluationDataset::fromFile($path);
    $retrieval = CoreUserDocumentationCorpus::retrieval();

    $report = (new DocumentationEvaluationService)->evaluate(
        $dataset,
        'fixture',
        static fn (string $q, $access): array => $retrieval->retrieve($q, $access),
    );

    // Committed baseline thresholds (2026-08). Raise only with an intentional, reviewed commit.
    expect($report['metrics']['source_hit_at_k'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['mean_reciprocal_rank'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['citation_precision'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['refusal_accuracy'])->toBeGreaterThanOrEqual(1.0)
        ->and($report['metrics']['supported_answer_rate'])->toBeGreaterThanOrEqual(1.0);
})->skip(fn (): bool => ! is_file(base_path('Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json')), 'Core dataset missing');
```

- [x] **Step 3: Run gate test to verify it fails, then passes**

Run: `composer dump-autoload -o && php artisan test --compact Modules/AI/tests/Feature/DocumentationBaselineGateTest.php`
Expected: FAILs first if the corpus stub or dataset is absent/misaligned; PASSes once both exist and labels match.

- [x] **Step 4: Generate and commit the human-readable baseline report**

Run:
```bash
php artisan test:ai:evaluate-documentation \
  --module=core --index=user \
  --dataset=Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json \
  --output=Modules/Core/docs/rag/evaluations/2026-08-documentation-user.baseline.json --force
```
Note: with no live Elasticsearch bound this command run uses the real `InAppDocumentationRetrieval` (empty index) and will report zeros; the deterministic **gate** (Step 2–3) is the enforcement of record. Commit the gate test and dataset regardless; commit the report artifact only if produced against a fixture-bound or live index. If the report is all-zeros because no index is available, skip committing the artifact and note it.

- [x] **Step 5: Commit**

```bash
vendor/bin/pint --dirty
git add Modules/Core/docs/rag/evaluations/2026-08-documentation-user.json Modules/AI/tests/Stubs/Documentation/CoreUserDocumentationCorpus.php Modules/AI/tests/Feature/DocumentationBaselineGateTest.php
git commit -m "feat(core): Core/user documentation evaluation baseline + regression gate"
```

---

### Task 7: RAG documentation note

**Files:**
- Modify: `laraplate/docs/rag/README.md` (add the documentation-evaluation command under the existing "Command" section)
- Modify: `Modules/AI/docs/rag/MODULE.md` (add a short "Documentation evaluation" subsection referencing `test:ai:evaluate-documentation` and the spec)

Per `AGENTS.md`, feature work updates affected RAG docs. This adds operator/developer pointers; no behavior change.

- [x] **Step 1: Add the note to `docs/rag/README.md`**

Under the `## Command` section, after the `ai:laraplate-help` block, add:

```markdown
### Evaluating documentation retrieval quality

Run a per-module documentation retrieval baseline (retrieval-only, no chat model):

    php artisan test:ai:evaluate-documentation --module=core --index=user \
      --dataset=Modules/Core/docs/rag/evaluations/<file>.json \
      --output=<report>.json

Datasets live under each module's `docs/rag/evaluations/`. See
`docs/superpowers/specs/2026-08-04-documentation-rag-evaluation-baseline-design.md`.
```

- [x] **Step 2: Add the subsection to `Modules/AI/docs/rag/MODULE.md`**

Add a `## Documentation evaluation` section:

```markdown
## Documentation evaluation

`test:ai:evaluate-documentation` scores documentation retrieval per module and index
profile (Level-1, deterministic, no chat model), mirroring
`test:ai:evaluate-application-content`. Datasets are owned by each module under
`docs/rag/evaluations/`. The deterministic regression gate lives in
`Modules/AI/tests/Feature/DocumentationBaselineGateTest.php`. Live-generation
(Level-2) scoring is specified but opt-in. Design:
`docs/superpowers/specs/2026-08-04-documentation-rag-evaluation-baseline-design.md`.
```

- [x] **Step 3: Commit**

```bash
git add laraplate/docs/rag/README.md Modules/AI/docs/rag/MODULE.md
git commit -m "docs(ai): document test:ai:evaluate-documentation baseline"
```

(Path note: run `git add` from the `laraplate/` repo root — the two files are `docs/rag/README.md` and `Modules/AI/docs/rag/MODULE.md` relative to it.)

---

## Final verification

- [x] Run the whole new suite:
```bash
php artisan test --compact Modules/AI/tests/Unit/Services/Documentation Modules/AI/tests/Feature/EvaluateDocumentationCommandTest.php Modules/AI/tests/Feature/DocumentationBaselineGateTest.php
```
- [x] `vendor/bin/pint --dirty` clean.
- [x] `php artisan list | grep test:ai:evaluate-documentation` shows the command.

## Self-review notes (author)

- **Spec coverage:** dataset schema + value objects (Tasks 1–2); deterministic retrieval-only harness via the `$search` seam (Tasks 3–4); `test:ai:evaluate-documentation` (Task 5); Core/user first dataset + regression gate (Task 6); RAG doc updates (Task 7). Level-2 and the assistant-level (R1) contract are spec-only and intentionally have no task.
- **Determinism honesty:** the CI gate measures ranking + harness + the emulated permission/tenant/locale filter in the fixture; real Elasticsearch filtering and Level-2 generation are opt-in and out of scope, as the spec states. This is called out in Task 6.
- **Type consistency:** the report metric keys (`source_hit_at_k`, `mean_reciprocal_rank`, `citation_precision`, `authorized_empty_accuracy`, `supported_answer_rate`, `refusal_accuracy`, `unavailable_rate`) and the case fields are used identically across Tasks 4–6. Grading identity is `Document::$sourceName` (= `safe_source_label`) everywhere.

## Delivery status (2026-09-15): shipped

**Documented in:** `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_USER.md` and `Modules/AI/docs/rag/DOCUMENTATION_EVALUATION_DEVELOPER.md`.

Verified against the code: `DocumentationEvaluation{Case,Dataset,Service}`, `test:ai:evaluate-documentation` and `DocumentationBaselineGateTest` exist and carry the behaviour the tasks specify. Level-2 live scoring stays opt-in, as the plan intended.
