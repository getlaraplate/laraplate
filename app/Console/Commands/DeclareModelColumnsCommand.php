<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Override;
use ReflectionClass;

/**
 * Writes, into each model's own class docblock, an `@property` tag for every column of its table, typed as ide-helper
 * infers it from the schema and the casts. Only columns: relations, relation counts and accessors are left to
 * Larastan, which reads them from the methods. Every other line of the docblock stays as it is.
 *
 * The tags are written once and then maintained by hand with the migrations; the app test
 * ModelsDeclareTheirColumnsTest fails when they drift. See the plan 2026-11-24-phpstan-typed-model-columns, D1.
 *
 * ide-helper is a dev dependency registered only when APP_ENV is local, and Laravel loads this class to discover
 * commands, so nothing here may reference it: its `ide-helper:models` command is called by name, or its output is
 * read from --from.
 */
final class DeclareModelColumnsCommand extends Command
{
    /**
     * @var string
     */
    #[Override]
    protected $signature = 'models:declare-columns
        {--module=* : The modules whose models get their columns declared; the app models when none is given}
        {--from= : An ide-helper models file to read instead of running ide-helper:models}
        {--dry-run : Print the column tags instead of writing them}';

    /**
     * @var string
     */
    #[Override]
    protected $description = 'Declare every model column as an @property tag in the model docblock';

    public function handle(): int
    {
        $helper_file = $this->helperFile();

        if ($helper_file === null) {
            return self::FAILURE;
        }

        $tags_by_target = [];

        foreach ($this->propertyLinesByClass((string) file_get_contents($helper_file)) as $class => $lines) {
            if (! $this->isRequestedModel($class)) {
                continue;
            }

            $target = $this->tableOwner($class);

            // A table owned by a model of another module is declared by that module's run, in that repository.
            if (! $this->isInScope($target)) {
                continue;
            }

            $tags_by_target[$target] ??= $this->columnTags($class, $lines);
        }

        foreach ($tags_by_target as $target => $tags) {
            $this->declare($target, $tags);
        }

        $this->info(sprintf('%d models declare their columns.', count($tags_by_target)));

        return self::SUCCESS;
    }

    /**
     * $code with $tags as the column block of the class docblock of $short: the docblock is created when missing,
     * placed above the class attributes, and every line of it is kept, column tags included: a tag written by hand
     * stays as written and only the missing columns are added, so running twice changes nothing.
     *
     * @param  list<string>  $tags
     */
    private static function withColumnTags(string $code, string $short, array $tags): string
    {
        // One match, rebuilt from its groups: the docblock right above the class (if any), the attribute lines between
        // them, and the class line. No byte offsets, which a multibyte character in the docblock would shift.
        $pattern = '/^(?<doc>\/\*\*(?:(?!\*\/).)*\*\/\n)?(?<attributes>(?:#\[[^\n]*\]\n)*)(?<class>(?:(?:final|abstract|readonly)\s+)*class\s+'
            . preg_quote($short, '/') . '\b)/ms';

        return preg_replace_callback($pattern, static function (array $match) use ($tags): string {
            $body = [];
            $declared = [];

            if ($match['doc'] !== '') {
                foreach (explode("\n", mb_trim(mb_substr($match['doc'], 3, -3))) as $line) {
                    $text = preg_replace('/^\s*\*\s?/', '', mb_rtrim($line)) ?? '';
                    $body[] = $text;

                    if (preg_match('/^@property(?:-read|-write)?\s+\S+\s+\$(\w+)/', $text, $name) === 1) {
                        $declared[] = $name[1];
                    }
                }
            }

            // A column tag already in the docblock was written by hand, or by an earlier run: it stays as it is.
            $missing = array_values(array_filter(
                $tags,
                static fn (string $tag): bool => preg_match('/\$(\w+)/', $tag, $name) === 1 && ! in_array($name[1], $declared, true),
            ));

            if ($missing === []) {
                return $match[0];
            }

            while ($body !== [] && mb_trim((string) end($body)) === '') {
                array_pop($body);
            }

            if ($body !== [] && preg_match('/^@property/', (string) end($body)) !== 1) {
                $body[] = '';
            }

            $lines = array_map(static fn (string $text): string => $text === '' ? ' *' : ' * ' . $text, [...$body, ...$missing]);

            return "/**\n" . implode("\n", $lines) . "\n */\n" . $match['attributes'] . $match['class'];
        }, $code, 1) ?? $code;
    }

    private function helperFile(): ?string
    {
        $from = $this->option('from');

        if (is_string($from) && $from !== '') {
            if (! is_file($from)) {
                $this->error("The ide-helper models file {$from} does not exist.");

                return null;
            }

            return $from;
        }

        if (! array_key_exists('ide-helper:models', Artisan::all())) {
            $this->error('ide-helper:models is registered only when APP_ENV is local: run this command there, or pass --from=<_ide_helper_models.php>.');

            return null;
        }

        $this->call('ide-helper:models', ['--nowrite' => true]);

        return base_path('_ide_helper_models.php');
    }

    /**
     * The `@property`, `@property-read` and `@property-write` lines ide-helper wrote for each class, by class name.
     *
     * @return array<class-string, list<string>>
     */
    private function propertyLinesByClass(string $helper): array
    {
        $classes = [];

        preg_match_all('/namespace ([\w\\\\]+)\s*\{\s*\/\*\*(.*?)\*\/\s*(?:#\[[^\n]*\]\s*)*(?:(?:final|abstract|readonly)\s+)*class (\w+)/s', $helper, $blocks, PREG_SET_ORDER);

        foreach ($blocks as $block) {
            preg_match_all('/^\s*\*\s*(@property(?:-read|-write)?\s+\S+\s+\$\w+.*)$/m', $block[2], $lines);

            /** @var class-string $class */
            $class = $block[1] . '\\' . $block[3];
            $classes[$class] = array_map(static fn (string $line): string => mb_rtrim($line), $lines[1]);
        }

        return $classes;
    }

    /**
     * @phpstan-assert-if-true class-string<Model> $class
     */
    private function isRequestedModel(string $class): bool
    {
        return $this->isInScope($class) && class_exists($class) && is_subclass_of($class, Model::class) && ! new ReflectionClass($class)->isAbstract();
    }

    /**
     * Whether $class belongs to the requested modules, or to the app when no module is requested.
     */
    private function isInScope(string $class): bool
    {
        $modules = array_filter((array) $this->option('module'), static fn (mixed $module): bool => is_string($module) && $module !== '');
        $prefixes = $modules === []
            ? ['App\\']
            : array_map(static fn (string $module): string => 'Modules\\' . $module . '\\', $modules);

        return array_any($prefixes, static fn (string $prefix): bool => str_starts_with($class, $prefix));
    }

    /**
     * The topmost model of the application or a module that shares the table of $class: a model that declares no
     * `$table` and no `getTable()` of its own uses its parent's, so the columns are declared once, on the parent.
     *
     * @param  class-string<Model>  $class
     * @return class-string<Model>
     */
    private function tableOwner(string $class): string
    {
        $owner = $class;

        while (! $this->declaresItsTable($owner)) {
            $parent = get_parent_class($owner);

            if ($parent === false || ! is_subclass_of($parent, Model::class) || ! $this->isProjectModel($parent)) {
                break;
            }

            $owner = $parent;
        }

        return $owner;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function declaresItsTable(string $class): bool
    {
        $reflection = new ReflectionClass($class);

        return ($reflection->hasProperty('table') && $class === $reflection->getProperty('table')->getDeclaringClass()->getName())
            || $class === $reflection->getMethod('getTable')->getDeclaringClass()->getName();
    }

    private function isProjectModel(string $class): bool
    {
        return (str_starts_with($class, 'Modules\\') || str_starts_with($class, 'App\\'))
            && ! str_contains($class, '\\Overrides\\');
    }

    /**
     * The lines that describe a column of the model's table, in the table's column order.
     *
     * @param  class-string<Model>  $class
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function columnTags(string $class, array $lines): array
    {
        $model = new ReflectionClass($class)->newInstance();
        $columns = Schema::connection($model->getConnectionName())->getColumnListing($model->getTable());
        $by_name = [];

        foreach ($lines as $line) {
            if (preg_match('/\$(\w+)/', $line, $name) === 1) {
                $by_name[$name[1]] = $line;
            }
        }

        $tags = [];
        $casts = $model->getCasts();

        foreach ($columns as $column) {
            if (! isset($by_name[$column])) {
                continue;
            }

            // ide-helper types the decimal cast as numeric, but Laravel's cast always returns a string.
            $cast = $casts[$column] ?? null;
            $tags[] = is_string($cast) && str_starts_with($cast, 'decimal')
                ? (preg_replace('/^(@property(?:-read|-write)?\s+)numeric\b/', '$1numeric-string', $by_name[$column]) ?? $by_name[$column])
                : $by_name[$column];
        }

        return $tags;
    }

    /**
     * @param  class-string<Model>  $class
     * @param  list<string>  $tags
     */
    private function declare(string $class, array $tags): void
    {
        $file = (string) new ReflectionClass($class)->getFileName();
        $short = new ReflectionClass($class)->getShortName();

        if ($this->option('dry-run') === true) {
            $this->line($class);

            foreach ($tags as $tag) {
                $this->line(' * ' . $tag);
            }

            return;
        }

        $code = (string) file_get_contents($file);
        $updated = self::withColumnTags($code, $short, $tags);

        if ($updated !== $code) {
            file_put_contents($file, $updated);
        }
    }
}
