<?php

declare(strict_types=1);

/**
 * PHPUnit expands a glob in a `<testsuite>` `<directory>` but not in a `<source>` one.
 *
 * `phpunit.xml` used to include a `Modules` wildcard path in `<source>`, which looks like it covers every
 * module and resolves to nothing: `realpath()` returns false for it, so coverage and
 * pestphp/pest-plugin-type-coverage both measured only the three files under `app/` and reported
 * 100% of that. The module directories are therefore listed one by one, and this keeps the list
 * complete, because a module added without its line would silently leave its code unanalysed.
 */
it('lists every module app directory in the phpunit source include', function (): void {
    $xml = simplexml_load_file(base_path('phpunit.xml'));

    expect($xml)->not->toBeFalse();

    $included = array_map(
        static fn (SimpleXMLElement $directory): string => (string) $directory,
        iterator_to_array($xml->source->include->directory, false),
    );

    $onDisk = array_map(
        static fn (string $path): string => 'Modules/' . basename(dirname($path)) . '/app',
        glob(base_path('Modules/*/app')) ?: [],
    );

    expect($onDisk)->not->toBeEmpty();
    expect(array_values(array_diff($onDisk, $included)))->toBe(
        [],
        'these module app directories are not analysed by phpunit.xml <source>',
    );
});

it('keeps every included directory resolvable, so no entry is a glob', function (): void {
    $xml = simplexml_load_file(base_path('phpunit.xml'));

    $unresolved = [];

    foreach ($xml->source->include->directory as $directory) {
        $path = (string) $directory;

        if (realpath(base_path($path)) === false) {
            $unresolved[] = $path;
        }
    }

    expect($unresolved)->toBe([], implode(', ', $unresolved) . ' do not resolve to a directory');
});
