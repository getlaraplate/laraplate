<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;

abstract class TestCase extends BaseTestCase
{
    /**
     * Every module ships config files and route files, and every test boots a fresh application:
     * without these the run re-read and re-merged all of them for each of thousands of tests. The
     * first test of each process captures the configuration and the compiled routes after a full
     * boot, and the later ones reuse them. A test changes either at runtime with Config::set or
     * Route::get as before; what it cannot do is expect a fresh boot to read a changed .env.
     */
    use WithCachedConfig;
    use WithCachedRoutes;

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

        // Likewise for the version strategy each model resolved: a strategy a previous test seeded must not
        // decide whether this test's records get an initial version.
        if (class_exists(\Modules\Core\Versioning\VersionStrategyCache::class)) {
            \Modules\Core\Versioning\VersionStrategyCache::flush();
        }

        $app = require __DIR__ . '/../bootstrap/app.php';

        // This override replaces the framework's createApplication(), so it repeats the step that
        // hands the cached configuration and routes to the new application before it boots.
        if (CachedState::$cachedConfig !== null && isset($this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (CachedState::$cachedRoutes !== null && isset($this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(function () use ($app): void {
                $this->markRoutesCached($app);
            });
        }

        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
