<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/*
| This test rebuilds the whole schema itself, so it must not sit inside the RefreshDatabase transaction
| the Core feature tests share. It lives here, where tests/Pest.php binds the bare application test case.
*/

it('migrates and seeds every native module', function (): void {
    $exit_code = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true, '--no-interaction' => true]);

    expect($exit_code)->toBe(0);
})->group('db2');
