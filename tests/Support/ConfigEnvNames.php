<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The environment variables the Laraplate module configs read, and which of them `.env.example` does not mention.
 *
 * In scope: every module config file (`Modules/<Name>/config/<file>.php`), except the files that are the configuration of a third-party package
 * (the framework's own root `config/` is not looked at either). A variable of the scope is covered when the example
 * has a line `NAME=` or `# NAME=`.
 */
final readonly class ConfigEnvNames
{
    /**
     * Name prefixes of the module config files that belong to third-party packages, not to Laraplate.
     *
     * @var list<string>
     */
    public const array PACKAGE_CONFIGS = ['scout', 'elastic', 'media-library', 'permission', 'swagger-ui', 'laravel-swagger', 'fortify', 'laravel-impersonate'];

    /**
     * The names passed to `env('NAME'` in the in-scope config files under `$root`, sorted, each once.
     *
     * @return list<string>
     */
    public static function inScope(string $root): array
    {
        $names = [];

        foreach (glob($root . '/Modules/*/config/*.php') ?: [] as $file) {
            if (self::isPackageConfig(basename($file, '.php'))) {
                continue;
            }

            preg_match_all('/(?<![A-Za-z0-9_>:])env\\(\\s*[\'"]([A-Z][A-Z0-9_]*)[\'"]/', self::withoutComments((string) file_get_contents($file)), $matches);

            foreach ($matches[1] as $name) {
                $names[$name] = true;
            }
        }

        $names = array_keys($names);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * The in-scope names that the example file does not list, as `NAME=` or `# NAME=`.
     *
     * @return list<string>
     */
    public static function uncovered(string $root, string $examplePath): array
    {
        $example = (string) file_get_contents($examplePath);

        return array_values(array_filter(
            self::inScope($root),
            static fn (string $name): bool => preg_match('/^\s*#?\s*' . preg_quote($name, '/') . '=/m', $example) !== 1,
        ));
    }

    /**
     * The PHP source without its comments: a variable named only in commented-out code is not read.
     */
    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }

    private static function isPackageConfig(string $basename): bool
    {
        foreach (self::PACKAGE_CONFIGS as $prefix) {
            if (str_starts_with($basename, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
