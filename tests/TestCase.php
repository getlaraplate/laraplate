<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        // A test that pretends to run over HTTP (runningInConsole() false) would otherwise be
        // checked for a CSRF token, which no test request carries.
        //
        // The class name matters: withoutMiddleware() binds that exact abstract to a passthrough, and the
        // middleware groups resolve PreventRequestForgery. Naming the deprecated ValidateCsrfToken alias here,
        // as this did on Laravel 12 where the alias was what the web group listed, excluded nothing at all.
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        // SchemaInspector is a process-level singleton; wipe it before each app boot so
        // memoized tables from a previous :memory: SQLite do not leak across Pest tests.
        if (class_exists(\Modules\Core\Inspector\SchemaInspector::class)) {
            \Modules\Core\Inspector\SchemaInspector::reset();
        }

        $app = require __DIR__ . '/../bootstrap/app.php';

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
