<?php

declare(strict_types=1);

use Tests\Support\Db2TestDatabase;

it('stops a Db2 run with no schema before it touches the database', function (string $schema): void {
    config(['database.default' => 'db2', 'database.connections.db2.schema' => $schema]);

    Db2TestDatabase::assertSchemaConfigured();
})->with(['empty' => [''], 'blank' => ['  ']])->throws(RuntimeException::class, 'DB_SCHEMA');

it('lets a Db2 run with a schema and any other connection through', function (): void {
    config(['database.default' => 'db2', 'database.connections.db2.schema' => 'LARAPLATE']);
    Db2TestDatabase::assertSchemaConfigured();

    config(['database.default' => 'sqlite', 'database.connections.db2.schema' => '']);
    Db2TestDatabase::assertSchemaConfigured();

    expect(true)->toBeTrue();
});
