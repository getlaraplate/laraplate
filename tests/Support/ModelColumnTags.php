<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use ReflectionClass;

/**
 * Compares the `@property` tags a model declares, on itself and its parent models, with the columns of its table.
 * Decision D1 of the plan 2026-11-24-phpstan-typed-model-columns: every column has a tag, and a tag that names no
 * column is a relation or an accessor, or it is stale.
 */
final class ModelColumnTags
{
    /**
     * Columns that exist only on some database drivers, and so are absent from the SQLite schema of the tests while
     * their tags are true on MySQL: `is_live` is the stored column MigrateUtils adds on MySQL and MariaDB only, for
     * unique keys over live rows.
     *
     * @var list<string>
     */
    private const array DRIVER_SPECIFIC_COLUMNS = ['is_live'];

    /**
     * The columns of the model's table that no tag declares, as `table.column`.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    public static function missing(string $class): array
    {
        $model = new ReflectionClass($class)->newInstance();
        $declared = self::declared($class);

        return array_values(array_map(
            static fn (string $column): string => $model->getTable() . '.' . $column,
            array_filter(self::columns($model), static fn (string $column): bool => ! in_array($column, $declared, true)),
        ));
    }

    /**
     * The tags that name neither a column, a relation nor an accessor of the model.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    public static function unknown(string $class): array
    {
        $model = new ReflectionClass($class)->newInstance();
        $columns = self::columns($model);

        return array_values(array_filter(
            self::declared($class),
            static fn (string $name): bool => ! in_array($name, $columns, true)
                && ! in_array($name, self::DRIVER_SPECIFIC_COLUMNS, true)
                && ! method_exists($model, $name)
                && ! $model->hasGetMutator($name)
                && ! $model->hasAttributeGetMutator($name),
        ));
    }

    /**
     * @return list<string>
     */
    private static function columns(Model $model): array
    {
        return Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
    }

    /**
     * The names of the `@property`, `@property-read` and `@property-write` tags of the class and its parent models.
     *
     * @param  class-string<Model>  $class
     * @return list<string>
     */
    private static function declared(string $class): array
    {
        $names = [];

        for ($reflection = new ReflectionClass($class); $reflection !== false && $reflection->isSubclassOf(Model::class); $reflection = $reflection->getParentClass()) {
            preg_match_all('/@property(?:-read|-write)?\s+[^\n$]+?\s\$(\w+)/', (string) $reflection->getDocComment(), $matches);
            array_push($names, ...$matches[1]);
        }

        return array_values(array_unique($names));
    }
}
