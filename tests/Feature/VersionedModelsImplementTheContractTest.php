<?php

declare(strict_types=1);

use Modules\Core\Contracts\IVersionedModel;
use Modules\Core\Models\Concerns\HasVersions;
use Tests\Support\ModelClasses;

/**
 * HasVersions declares `@phpstan-require-implements IVersionedModel`, and the history code asks an owner or a pivot
 * root for the contract before it reads its versioning switch. A model that uses the trait without declaring the
 * contract would have its parts and pivots recorded as if it kept no history, so every one of them has to declare it.
 */
it('requires every model with history to implement the versioning contract', function (): void {
    $missing = array_values(array_filter(
        ModelClasses::all(),
        static fn (string $class): bool => in_array(HasVersions::class, class_uses_recursive($class), true)
            && ! is_a($class, IVersionedModel::class, true),
    ));

    expect($missing)->toBe([], 'these models use HasVersions without implementing IVersionedModel');
});
