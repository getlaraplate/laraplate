<?php

declare(strict_types=1);

use Modules\Core\Contracts\ITranslatableModel;
use Modules\Core\Models\Concerns\HasTranslations;
use Tests\Support\ModelClasses;

/**
 * HasTranslations declares `@phpstan-require-implements ITranslatableModel`, and the AI module narrows models to the
 * contract with `@phpstan-assert`. A model that uses the trait without declaring the contract is analysed as if it did
 * not translate, so every one of them has to declare it.
 */
it('requires every model with translations to implement the translation contract', function (): void {
    $missing = array_values(array_filter(
        ModelClasses::all(),
        static fn (string $class): bool => in_array(HasTranslations::class, class_uses_recursive($class), true)
            && ! is_a($class, ITranslatableModel::class, true),
    ));

    expect($missing)->toBe([], 'these models use HasTranslations without implementing ITranslatableModel');
});
