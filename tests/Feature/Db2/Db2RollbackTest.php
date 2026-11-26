<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| A level-1 commit and rollback, the way production runs them. It lives here, under the bare application
| test case, because RefreshDatabase would wrap the test in an outer transaction and turn the rollback
| into a savepoint. The scratch table is created with Schema::create so it is journaled like a migrated one.
*/
it('rolls a level-1 transaction back for real', function (): void {
    $table = 'db2_rollback_probe';
    Schema::dropIfExists($table);
    Schema::create($table, static function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });

    try {
        $connection = DB::connection();
        $connection->beginTransaction();
        $connection->table($table)->insert(['label' => 'inside']);

        expect($connection->transactionLevel())->toBe(1);

        $connection->rollBack();

        expect($connection->transactionLevel())->toBe(0)
            ->and($connection->table($table)->count())->toBe(0);
    } finally {
        Schema::dropIfExists($table);
    }
})->group('db2');
