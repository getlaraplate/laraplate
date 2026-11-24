<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;

/**
 * The Eloquent models of the application and of every installed module: the classes declared in `app/Models` and
 * `Modules/<Name>/app/Models`, abstract ones included. Test stubs live elsewhere and are not part of it.
 */
final class ModelClasses
{
    /**
     * @return list<class-string<Model>>
     */
    public static function all(): array
    {
        $roots = [base_path('app/Models'), ...(glob(base_path('Modules/*/app/Models'), GLOB_ONLYDIR) ?: [])];
        $classes = [];

        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = (string) file_get_contents($file->getPathname());

                if (! preg_match('/^namespace\s+([^;]+);/m', $code, $namespace)) {
                    continue;
                }

                $class = $namespace[1] . '\\' . $file->getBasename('.php');

                if (class_exists($class) && is_a($class, Model::class, true) && new ReflectionClass($class)->getFileName() === $file->getPathname()) {
                    $classes[] = $class;
                }
            }
        }

        sort($classes);

        return $classes;
    }
}
