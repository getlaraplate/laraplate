<?php

declare(strict_types=1);

use Modules\Core\Contracts\ISearchableModel;
use Modules\Core\Search\Traits\Searchable;
use Tests\Support\ModelClasses;

/**
 * Searchable declares `@phpstan-require-implements ISearchableModel`, and the search widgets, engines and the AI
 * embedding switch take a searchable model by the contract. A model that uses the trait without declaring the
 * contract would be skipped by them, so every one of them has to declare it.
 */
it('requires every searchable model to implement the search contract', function (): void {
    $missing = array_values(array_filter(
        ModelClasses::all(),
        static fn (string $class): bool => in_array(Searchable::class, class_uses_recursive($class), true)
            && ! is_a($class, ISearchableModel::class, true),
    ));

    expect($missing)->toBe([], 'these models use Searchable without implementing ISearchableModel');
});
