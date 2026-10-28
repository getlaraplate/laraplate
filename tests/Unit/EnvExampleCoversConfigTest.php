<?php

declare(strict_types=1);

use Tests\Support\ConfigEnvNames;

/**
 * A new empty directory, removed after the test whatever its expectations said.
 */
function env_coverage_root(): string
{
    $root = sys_get_temp_dir() . '/env-coverage-' . bin2hex(random_bytes(6));
    mkdir($root, 0o777, true);
    $GLOBALS['env_coverage_roots'][] = $root;

    return $root;
}

afterEach(function (): void {
    foreach ($GLOBALS['env_coverage_roots'] ?? [] as $root) {
        exec('rm -rf ' . escapeshellarg($root));
    }

    $GLOBALS['env_coverage_roots'] = [];
});

/**
 * Writes a file under `$root`, creating its folders.
 */
function env_coverage_write(string $root, string $relative, string $content): void
{
    $path = $root . '/' . $relative;

    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0o777, true);
    }

    file_put_contents($path, $content);
}

it('covers every Laraplate variable the module configs read', function (): void {
    $missing = ConfigEnvNames::uncovered(base_path(), base_path('.env.example'));

    if ($missing !== []) {
        $this->fail("`.env.example` does not list these variables read by a module config; add each as `# NAME=default`:\n" . implode("\n", $missing));
    }

    expect($missing)->toBe([]);
});

it('reports a name a module config reads and the example lacks, and one commented or active in the example is covered', function (): void {
    $root = env_coverage_root();
    env_coverage_write($root, 'Modules/X/config/config.php', "<?php\nreturn ['a' => env('NEW_VAR'), 'b' => env( 'COMMENTED_VAR', 5), 'c' => env('ACTIVE_VAR')];\n");
    env_coverage_write($root, '.env.example', "ACTIVE_VAR=1\n# COMMENTED_VAR=5\n");

    expect(ConfigEnvNames::uncovered($root, $root . '/.env.example'))->toBe(['NEW_VAR'])
        ->and(ConfigEnvNames::inScope($root))->toBe(['ACTIVE_VAR', 'COMMENTED_VAR', 'NEW_VAR']);
});

it('skips the configs of third-party packages and the framework root config', function (): void {
    $root = env_coverage_root();
    env_coverage_write($root, 'Modules/X/config/config.php', "<?php\nreturn ['a' => env('OWN_VAR')];\n");
    env_coverage_write($root, 'Modules/X/config/scout.php', "<?php\nreturn ['a' => env('SCOUT_X')];\n");
    env_coverage_write($root, 'Modules/X/config/elastic.client.php', "<?php\nreturn ['a' => env('ELASTIC_X')];\n");
    env_coverage_write($root, 'Modules/X/config/fortify.php', "<?php\nreturn ['a' => env('FORTIFY_X')];\n");
    env_coverage_write($root, 'config/app.php', "<?php\nreturn ['a' => env('LARAVEL_X')];\n");
    env_coverage_write($root, '.env.example', "\n");

    expect(ConfigEnvNames::uncovered($root, $root . '/.env.example'))->toBe(['OWN_VAR']);
});

it('ignores a variable that only appears in a comment of a config file', function (): void {
    $root = env_coverage_root();
    env_coverage_write($root, 'Modules/X/config/config.php', "<?php\nreturn [\n    'a' => env('READ_VAR'),\n    // 'b' => env('LINE_COMMENT_VAR'),\n    /* 'c' => env('BLOCK_COMMENT_VAR') */\n    /** env('DOC_COMMENT_VAR') */\n];\n");
    env_coverage_write($root, '.env.example', "\n");

    expect(ConfigEnvNames::inScope($root))->toBe(['READ_VAR']);
});

it('reads env() with either quote and does not take getenv() or a method named env for it', function (): void {
    $root = env_coverage_root();
    env_coverage_write($root, 'Modules/X/config/config.php', "<?php\nreturn [\n    'a' => env(\"DOUBLE_QUOTED\"),\n    'b' => env('SINGLE_QUOTED'),\n    'c' => getenv('FROM_GETENV'),\n    'd' => \$this->env('FROM_METHOD'),\n    'e' => Env::get('FROM_STATIC'),\n];\n");
    env_coverage_write($root, '.env.example', "\n");

    expect(ConfigEnvNames::inScope($root))->toBe(['DOUBLE_QUOTED', 'SINGLE_QUOTED']);
});
