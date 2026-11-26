<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Modules\Core\Support\Db2Drivers;
use RuntimeException;

/**
 * Guards and clean-up for the application's Db2 tests (`tests/Feature/Db2`), which run under the bare test case.
 */
final class Db2TestDatabase
{
    /**
     * Refuse a run on a Db2 connection with no schema.
     *
     * On Db2 for i the schema is the library that holds the tables. Without one the statements land in whatever
     * library the job resolves, and the run fails much later and far from the cause. On any other connection this
     * does nothing.
     *
     * @throws RuntimeException
     */
    public static function assertSchemaConfigured(): void
    {
        $name = (string) config('database.default');
        $driver = (string) config("database.connections.{$name}.driver");

        if (! Db2Drivers::is($driver) || mb_trim((string) config("database.connections.{$name}.schema")) !== '') {
            return;
        }

        throw new RuntimeException(sprintf(
            'The Db2 test connection "%s" has no schema. Set DB_SCHEMA to the library that holds the tables; '
            . 'DB_DATABASE is the relational database (RDB) name, or empty.',
            $name,
        ));
    }

    /**
     * Forget that RefreshDatabase has migrated the database in this process.
     *
     * A test that rebuilds and seeds the schema itself leaves it seeded; the next RefreshDatabase test then
     * migrates from scratch instead of trusting the state of an earlier one.
     */
    public static function forgetRefreshedDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
        RefreshDatabaseState::$lazilyRefreshed = false;
        RefreshDatabaseState::$inMemoryConnections = [];
    }
}
