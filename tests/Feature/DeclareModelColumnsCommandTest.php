<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * models:declare-columns writes a model's column tags into the model's own docblock, from the @property lines ide-helper
 * generated for it. Each test builds throw-away models in a temporary directory, under a namespace no other test uses,
 * and hands the command an ide-helper file written for them with --from.
 */
beforeEach(function (): void {
    $this->probe = 'Probe' . bin2hex(random_bytes(4));
    $this->directory = sys_get_temp_dir() . '/declare-columns-' . $this->probe;
    mkdir($this->directory);
    $this->tableFor = fn (string $class): string => mb_strtolower($this->probe . '_' . $class);
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . '/*') ?: []);
    rmdir($this->directory);
});

/**
 * Writes and loads a model class in the probe namespace, and returns its file.
 */
function declareColumnsProbeModel(object $test, string $class, string $header, string $body = '', string $extends = '\\Illuminate\\Database\\Eloquent\\Model'): string
{
    $file = $test->directory . '/' . $class . '.php';
    $table = ($test->tableFor)($class);
    $code = "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$test->probe}\\Models;\n\n{$header}class {$class} extends {$extends}\n{\n    protected \$table = '{$table}';\n{$body}}\n";
    file_put_contents($file, $code);

    require $file;

    return $file;
}

/**
 * Writes an ide-helper models file listing the given @property lines per class, and returns its path.
 *
 * @param  array<string, list<string>>  $properties
 */
function declareColumnsHelperFile(object $test, array $properties): string
{
    $file = $test->directory . '/_ide_helper_models.php';
    $blocks = '';

    foreach ($properties as $class => $lines) {
        $tags = implode("\n", array_map(static fn (string $line): string => ' * ' . $line, $lines));
        $blocks .= "namespace Modules\\{$test->probe}\\Models{\n/**\n{$tags}\n * @method static \\Illuminate\\Database\\Eloquent\\Builder<static>|{$class} query()\n * @mixin \\Eloquent\n */\n\tclass {$class} extends \\Eloquent {}\n}\n\n";
    }

    file_put_contents($file, "<?php\n\n// @formatter:off\n\n{$blocks}");

    return $file;
}

function declareColumnsArticleTable(object $test, string $class = 'Article'): void
{
    Schema::create(($test->tableFor)($class), static function (Blueprint $table): void {
        $table->id();
        $table->string('title');
        $table->text('body')->nullable();
    });
}

/**
 * @return list<string>
 */
function declareColumnsArticleTags(): array
{
    return [
        '@property int $id',
        '@property string $title',
        '@property string|null $body',
        '@property-read \Illuminate\Database\Eloquent\Collection<int, \Modules\Core\Models\User> $authors',
        '@property-read int|null $authors_count',
    ];
}

it('writes a property tag for each column and none for relations', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', '');
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    $code = (string) file_get_contents($model);

    expect($code)->toContain("/**\n * @property int \$id\n * @property string \$title\n * @property string|null \$body\n */\nclass Article")
        ->and($code)->not->toContain('$authors')
        ->and($code)->not->toContain('@method')
        ->and($code)->not->toContain('@mixin');
});

it('keeps the existing docblock lines', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', "/**\n * An article of the probe.\n *\n * @phpstan-use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory<\\Illuminate\\Database\\Eloquent\\Factories\\Factory<static>>\n */\n");
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($model))->toContain(
        "/**\n * An article of the probe.\n *\n * @phpstan-use \\Illuminate\\Database\\Eloquent\\Factories\\HasFactory<\\Illuminate\\Database\\Eloquent\\Factories\\Factory<static>>\n *\n * @property int \$id\n * @property string \$title\n * @property string|null \$body\n */\nclass Article",
    );
});

it('writes the docblock above the class attributes', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', "#[\\Illuminate\\Database\\Eloquent\\Attributes\\UseFactory(\\Illuminate\\Database\\Eloquent\\Factories\\Factory::class)]\n");
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($model))->toContain(" * @property string|null \$body\n */\n#[\\Illuminate\\Database\\Eloquent\\Attributes\\UseFactory(");
});

it('replaces the column tags it wrote before and nothing else when run twice', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', "/**\n * An article of the probe.\n */\n");
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();
    $first = (string) file_get_contents($model);
    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($model))->toBe($first);
});

it('writes the columns on the parent model that owns the table', function (): void {
    Schema::create(($this->tableFor)('Node'), static function (Blueprint $table): void {
        $table->id();
        $table->string('kind');
    });
    $parent = declareColumnsProbeModel($this, 'Node', 'abstract ');
    $child_file = $this->directory . '/Leaf.php';
    file_put_contents($child_file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$this->probe}\\Models;\n\nfinal class Leaf extends Node\n{\n}\n");

    require $child_file;
    $helper = declareColumnsHelperFile($this, ['Leaf' => ['@property int $id', '@property string $kind']]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($parent))->toContain(" * @property int \$id\n * @property string \$kind\n */\nabstract class Node")
        ->and((string) file_get_contents($child_file))->not->toContain('@property');
});

it('writes nothing with --dry-run', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', '');
    $before = (string) file_get_contents($model);
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper, '--dry-run' => true])
        ->expectsOutputToContain('@property string $title')
        ->assertSuccessful();

    expect((string) file_get_contents($model))->toBe($before);
});

it('asks for --from where ide-helper is not registered', function (): void {
    $this->artisan('models:declare-columns', ['--module' => [$this->probe]])
        ->expectsOutputToContain('--from')
        ->assertFailed();
});

it('leaves a parent model of another module to that module run', function (): void {
    Schema::create(($this->tableFor)('Node'), static function (Blueprint $table): void {
        $table->id();
        $table->string('kind');
    });
    $parent = declareColumnsProbeModel($this, 'Node', 'abstract ');
    $other = $this->probe . 'Other';
    $child_file = $this->directory . '/Leaf.php';
    file_put_contents($child_file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace Modules\\{$other}\\Models;\n\nfinal class Leaf extends \\Modules\\{$this->probe}\\Models\\Node\n{\n}\n");

    require $child_file;
    $parent_before = (string) file_get_contents($parent);
    $helper = $this->directory . '/_ide_helper_models.php';
    file_put_contents($helper, "<?php\n\nnamespace Modules\\{$other}\\Models{\n/**\n * @property int \$id\n * @property string \$kind\n */\n\tclass Leaf extends \\Eloquent {}\n}\n");

    $this->artisan('models:declare-columns', ['--module' => [$other], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($parent))->toBe($parent_before)
        ->and((string) file_get_contents($child_file))->not->toContain('@property');
});

it('keeps the text around the class when the docblock holds multibyte characters', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', "/**\n * Shareable — across types, città and 日本.\n */\n");
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($model))->toContain("/**\n * Shareable — across types, città and 日本.\n *\n * @property int \$id\n * @property string \$title\n * @property string|null \$body\n */\nclass Article extends");
});

it('keeps a column tag written by hand and adds only the missing ones', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', "/**\n * @property non-empty-string \$title\n */\n");
    $helper = declareColumnsHelperFile($this, ['Article' => declareColumnsArticleTags()]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    $code = (string) file_get_contents($model);

    expect($code)->toContain('@property non-empty-string $title')
        ->and($code)->not->toContain('@property string $title')
        ->and($code)->toContain('@property int $id')
        ->and($code)->toContain('@property string|null $body');
});

it('declares a column cast to decimal as a numeric string', function (): void {
    Schema::create(($this->tableFor)('Line'), static function (Blueprint $table): void {
        $table->id();
        $table->decimal('amount', 12, 2);
        $table->decimal('raw', 12, 2)->nullable();
    });
    $model = declareColumnsProbeModel($this, 'Line', '', "\n    protected \$casts = ['amount' => 'decimal:2'];\n");
    $helper = declareColumnsHelperFile($this, ['Line' => ['@property int $id', '@property numeric $amount', '@property numeric|null $raw']]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    // Laravel's decimal cast returns a string; a decimal column without a cast is a string on MySQL and a float on
    // SQLite, so it keeps ide-helper's numeric.
    expect((string) file_get_contents($model))->toContain(" * @property numeric-string \$amount\n * @property numeric|null \$raw\n");
});

it('leaves a model untouched when none of its lines is a column', function (): void {
    declareColumnsArticleTable($this);
    $model = declareColumnsProbeModel($this, 'Article', '');
    $before = (string) file_get_contents($model);
    $helper = declareColumnsHelperFile($this, ['Article' => ['@property-read int|null $authors_count']]);

    $this->artisan('models:declare-columns', ['--module' => [$this->probe], '--from' => $helper])->assertSuccessful();

    expect((string) file_get_contents($model))->toBe($before);
});
