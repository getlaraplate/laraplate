<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\Db2TestDatabase;

/*
| This test rebuilds the whole schema itself, so it must not sit inside the RefreshDatabase transaction
| the Core feature tests share. It lives here, where tests/Pest.php binds the bare application test case.
| It leaves the database seeded, so it makes the next RefreshDatabase test in the process migrate again.
*/

it('migrates and seeds every native module', function (): void {
    // As an earlier RefreshDatabase test in the same process leaves it.
    RefreshDatabaseState::$migrated = true;

    try {
        $exit_code = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true, '--no-interaction' => true]);
    } finally {
        Db2TestDatabase::forgetRefreshedDatabase();
    }

    expect($exit_code)->toBe(0)
        ->and(RefreshDatabaseState::$migrated)->toBeFalse()
        ->and(RefreshDatabaseState::$inMemoryConnections)->toBe([]);
})->group('db2');
