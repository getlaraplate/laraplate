<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ModelClasses;
use Tests\Support\ModelColumnTags;

/**
 * Decision D1 of the plan 2026-11-24-phpstan-typed-model-columns: every model declares the columns of its table as
 * `@property` tags, maintained by hand with the migrations (`php artisan models:declare-columns` writes the missing
 * ones). A column without a tag is read as mixed by PHPStan; a tag that names nothing is stale.
 */
uses(RefreshDatabase::class);

/**
 * Writes and loads a throw-away model whose docblock is $doc, in a namespace no other test uses.
 *
 * @return class-string<Illuminate\Database\Eloquent\Model>
 */
function columnTagsProbeModel(string $directory, string $namespace, string $class, string $doc, string $header = '', string $extends = '\\Illuminate\\Database\\Eloquent\\Model', string $body = ''): string
{
    $file = $directory . '/' . $class . '.php';
    file_put_contents($file, "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$namespace};\n\n{$doc}{$header}class {$class} extends {$extends}\n{\n{$body}}\n");

    require $file;

    return $namespace . '\\' . $class;
}

beforeEach(function (): void {
    $this->namespace = 'ColumnTagsProbe' . bin2hex(random_bytes(4));
    $this->directory = sys_get_temp_dir() . '/' . $this->namespace;
    mkdir($this->directory);
    $this->table = mb_strtolower($this->namespace);
    Schema::create($this->table, static function (Blueprint $table): void {
        $table->id();
        $table->string('title');
    });
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory . '/*') ?: []);
    rmdir($this->directory);
});

it('declares every column of every model', function (): void {
    $missing = [];

    foreach (ModelClasses::all() as $class) {
        if (new ReflectionClass($class)->isAbstract()) {
            continue;
        }

        foreach (ModelColumnTags::missing($class) as $column) {
            $missing[] = "{$class}: {$column}";
        }
    }

    expect($missing)->toBe([], 'these columns have no @property tag; run php artisan models:declare-columns --module=<Name> or add them by hand');
});

it('names the model and the column that has no tag', function (): void {
    $class = columnTagsProbeModel($this->directory, $this->namespace, 'Article', "/**\n * @property int \$id\n */\n", body: "    protected \$table = '{$this->table}';\n");

    expect(ModelColumnTags::missing($class))->toBe([$this->table . '.title']);
});

it('accepts columns declared on a parent model of the same table', function (): void {
    columnTagsProbeModel($this->directory, $this->namespace, 'Node', "/**\n * @property int \$id\n * @property string \$title\n */\n", 'abstract ', body: "    protected \$table = '{$this->table}';\n");
    $leaf = columnTagsProbeModel($this->directory, $this->namespace, 'Leaf', '', 'final ', '\\' . $this->namespace . '\\Node');

    expect(ModelColumnTags::missing($leaf))->toBe([]);
});

it('refuses a tag that is neither a column, a relation nor an accessor', function (): void {
    $class = columnTagsProbeModel(
        $this->directory,
        $this->namespace,
        'Article',
        "/**\n * @property int \$id\n * @property string \$title\n * @property string \$headline\n * @property-read int \$title_length\n * @property-read string \$gone\n */\n",
        body: "    protected \$table = '{$this->table}';\n\n    public function headline(): \\Illuminate\\Database\\Eloquent\\Relations\\HasOne\n    {\n        return \$this->hasOne(self::class);\n    }\n\n    public function getTitleLengthAttribute(): int\n    {\n        return 0;\n    }\n",
    );

    expect(ModelColumnTags::unknown($class))->toBe(['gone']);
});

it('declares no tag that names nothing', function (): void {
    $unknown = [];

    foreach (ModelClasses::all() as $class) {
        if (new ReflectionClass($class)->isAbstract()) {
            continue;
        }

        foreach (ModelColumnTags::unknown($class) as $name) {
            $unknown[] = "{$class}: \${$name}";
        }
    }

    expect($unknown)->toBe([], 'these @property tags name no column, relation or accessor');
});

it('accepts the tags of attributes a model reads from another model', function (): void {
    // ERP's ProxiesCounterparty reads these from the counterparty; translated fields are covered the same way.
    $class = columnTagsProbeModel(
        $this->directory,
        $this->namespace,
        'Article',
        "/**\n * @property int \$id\n * @property string \$title\n * @property string|null \$vat_number\n */\n",
        body: "    protected \$table = '{$this->table}';\n\n    /**\n     * @return list<string>\n     */\n    public static function counterpartyAttributes(): array\n    {\n        return ['vat_number'];\n    }\n",
    );

    expect(ModelColumnTags::unknown($class))->toBe([]);
});
