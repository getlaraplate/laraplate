<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationDataset;
use Modules\AI\Services\ApplicationContent\Evaluation\ApplicationContentEvaluationService;
use Modules\CMS\Tests\Stubs\ApplicationContent\EvaluationContentCorpus;
use Modules\Core\ApplicationContent\Contracts\ApplicationContentRetrievalProviderRegistryInterface;
use Modules\Core\Services\DynamicContentsService;
use Nwidart\Modules\Facades\Module;

/**
 * AI's retrieval evaluation run over CMS's `cms.contents` provider: an application-level
 * check, because it needs two modules that do not depend on each other. Each module tests
 * only its own side (AI the scoring, CMS the provider over its dataset); this compares the
 * pair against CMS's committed baseline report. Skipped when either module is not
 * installed and enabled, since modules are optional.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    if (! class_exists(ApplicationContentEvaluationService::class)
        || ! class_exists(EvaluationContentCorpus::class)
        || ! Module::isEnabled('AI')
        || ! Module::isEnabled('CMS')) {
        $this->markTestSkipped('Needs the AI and CMS modules installed and enabled.');
    }

    DynamicContentsService::reset();
});

/**
 * Seeds CMS's evaluation corpus and evaluates the committed fixture dataset through the
 * registered `cms.contents` provider, with a deterministic clock.
 *
 * @return array<string, mixed>
 */
function cmsBaselineEvaluationReport(): array
{
    EvaluationContentCorpus::create();

    $dataset = ApplicationContentEvaluationDataset::fromFile(module_path('CMS', EvaluationContentCorpus::DATASET));
    $provider = app(ApplicationContentRetrievalProviderRegistryInterface::class)->providerFor('cms.contents');
    expect($provider)->not->toBeNull();

    $tick = 0.0;
    $evaluation = new ApplicationContentEvaluationService(
        clock: static function () use (&$tick): float {
            $current = $tick;
            $tick += 0.01;

            return $current;
        },
    );

    return $evaluation->evaluate(
        $dataset,
        'cms.contents',
        'database-generated-fixture',
        static fn ($query, $authorization) => $provider->retrieve($query, $authorization),
    );
}

it('reproduces the committed record-level baseline from generated CMS records', function (): void {
    $report = cmsBaselineEvaluationReport();
    $artifact_path = module_path('CMS', 'docs/evaluations/application-content/2026-07-record-baseline.json');

    if (getenv('APP_CONTENT_BASELINE_REGEN') === '1') {
        file_put_contents($artifact_path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
    }

    $artifact = json_decode((string) file_get_contents($artifact_path), true, flags: JSON_THROW_ON_ERROR);

    expect($report)->toBe($artifact)
        ->and($report['metrics']['unavailable_rate'])->toBe(0.0)
        ->and($report['slices']['category']['passage_candidate']['supported_answer_rate'])->toBe(0.0);
});

/**
 * Retrieval tuning profile regression gate (measured retrieval tuning L1).
 *
 * With `core.search.adaptive_tuning` on, the committed `Modules/Core/config/search_tuning.php`
 * profile must not score below the committed baseline on `ndcg_at_5` or `recall_at_5`, within
 * one rounding unit of the 4-decimal report. With the switch off the test above already asserts
 * the baseline artifact byte for byte.
 *
 * The test suite runs Scout on the `collection` driver, so the provider answers through its
 * lexical fallback and a profile change cannot move these numbers here: the gate guards the
 * wiring, and the measurement that justifies a profile is `php artisan test:ai:tune-retrieval` against
 * a real engine. When a new profile legitimately changes this fixture's ordering, regenerate the
 * baseline as for the test above (`APP_CONTENT_BASELINE_REGEN=1`), review the diff, and commit it
 * together with the profile.
 */
it('does not regress the committed baseline with the retrieval tuning profile applied', function (): void {
    $tolerance = 0.0001;
    config()->set('core.search.adaptive_tuning', true);

    $report = cmsBaselineEvaluationReport();
    $artifact = json_decode((string) file_get_contents(
        module_path('CMS', 'docs/evaluations/application-content/2026-07-record-baseline.json'),
    ), true, flags: JSON_THROW_ON_ERROR);

    foreach (['ndcg_at_5', 'recall_at_5'] as $metric) {
        expect($report['metrics'][$metric])->toBeGreaterThanOrEqual($artifact['metrics'][$metric] - $tolerance);
    }
});
