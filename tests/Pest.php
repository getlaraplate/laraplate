<?php

declare(strict_types=1);

// Pest loads CallsTerminable only at Kernel::terminate(). Preload it before
// BypassFinals wraps the file stream so a corrupted wrapper cannot break shutdown.
class_exists(Pest\Plugins\Actions\CallsTerminable::class);

DG\BypassFinals::enable();
// BypassFinals is test-runtime only: it does not edit source. Our `final`
// keywords stay in the codebase. allowPaths limits which loaded files can be
// stripped so the full suite does not OOM on vendor/.
// Prefer interfaces for doubles; add a path here only when mocking a final
// class is unavoidable (app services today, Elastic Client as third-party).
DG\BypassFinals::allowPaths([
    '*/app/*',
    '*/Modules/*/app/*',
    '*/vendor/elasticsearch/elasticsearch/src/*',
]);
DG\BypassFinals::setCacheDirectory(dirname(__DIR__) . '/storage/framework/cache/bypass-finals');

/*
| BypassFinals rewrites a class only when its file is read, and a class stays declared for the
| whole process. Its wrapper, though, re-registers the file:// stream wrapper around every file
| operation, and PHP keeps each registration until the process ends: with the wrapper left on,
| every application boot (thousands of stat and read calls) retained about 0.8 MB, so a long
| suite exhausted memory_limit. Load every class it may rewrite now, then hand file:// back to
| PHP: the classes keep their final and readonly bypassed, and later file operations cost nothing.
*/
(static function (array $roots): void {
    foreach ($roots as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $name = $file->getBasename('.php');
            $code = file_get_contents($file->getPathname());

            if (
                $code === false
                || ! preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)
                || ! preg_match('/^\s*(?:(?:final|abstract|readonly)\s+)*(?:class|interface|trait|enum)\s+' . preg_quote($name, '/') . '\b/m', $code)
            ) {
                continue;
            }

            $class = $namespace[1] . '\\' . $name;

            try {
                class_exists($class) || interface_exists($class) || trait_exists($class) || enum_exists($class);
            } catch (Throwable) {
                // A class of an optional integration whose dependency is not installed: it is never loaded by the suite either.
            }
        }
    }

    stream_wrapper_restore('file');
})([
    ...glob(dirname(__DIR__) . '/app', GLOB_ONLYDIR) ?: [],
    ...glob(dirname(__DIR__) . '/Modules/*/app', GLOB_ONLYDIR) ?: [],
    ...glob(dirname(__DIR__) . '/vendor/elasticsearch/elasticsearch/src', GLOB_ONLYDIR) ?: [],
]);

/*
| Module test configuration lives in each module's tests/Pest.php. The application
| test bootstrap only loads tests/ by default; require installed module bootstrap
| files dynamically so optional modules do not become root-level dependencies.
*/
$module_pest_files = glob(__DIR__ . '/../Modules/*/tests/Pest.php') ?: [];
sort($module_pest_files);

foreach ($module_pest_files as $module_pest_file) {
    require_once $module_pest_file;
}

/*
|--------------------------------------------------------------------------
| Test Case (application shell under tests/)
|--------------------------------------------------------------------------
*/

pest()->extend(Tests\TestCase::class)
    ->in(__DIR__ . '/Feature', __DIR__ . '/Unit', __DIR__ . '/Integration');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeOne', fn () => $this->toBe(1));

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function something(): void
{
    // ..
}
