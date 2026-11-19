<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentGuard;

require dirname(__DIR__) . '/vendor/autoload.php';

TestEnvironmentGuard::assertSafe(
    dirname(__DIR__),
    TestEnvironmentGuard::effectiveEnvironment(dirname(__DIR__), array_merge($_ENV, getenv())),
);
