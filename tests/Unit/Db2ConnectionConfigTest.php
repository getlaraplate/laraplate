<?php

declare(strict_types=1);

use Easi\DB2\Database\DB2Connection;
use Easi\DB2\Database\Schema\Blueprint;

/**
 * A Db2 connection built from the application's `db2` configuration, with no PDO behind it.
 */
function db2_config_connection(): DB2Connection
{
    $connection = new DB2Connection(
        static fn (): PDO => throw new LogicException('No PDO: only the SQL is compiled'),
        '',
        '',
        config('database.connections.db2'),
    );
    $connection->useDefaultSchemaGrammar();

    return $connection;
}

it('creates character and text columns in UTF-8 on Db2 by default', function (): void {
    $blueprint = new Blueprint(db2_config_connection(), 'db2_ccsid_probe', static function (Blueprint $table): void {
        $table->create();
        $table->string('title', 20);
        $table->text('body');
        $table->integer('qty');
    });

    expect(config('database.connections.db2.ccsid'))->toBe(1208)
        ->and($blueprint->toSql()[0])->toContain('title varchar(20) ccsid 1208')
        ->and($blueprint->toSql()[0])->toContain('body clob ccsid 1208')
        ->and($blueprint->toSql()[0])->toContain('qty int not null');
});
