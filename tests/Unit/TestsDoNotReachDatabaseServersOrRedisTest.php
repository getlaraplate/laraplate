<?php

declare(strict_types=1);

it('points every database server connection of the tests at an address that is not a real server', function (string $connection): void {
    $config = config('database.connections.' . $connection);

    expect($config['url'] ?? null)->toBeEmpty()
        ->and($config['write']['host'])->toBe(['127.0.0.1'])
        ->and($config['read']['host'])->toBe(['127.0.0.1'])
        ->and((string) $config['port'])->toBe('9');
})->with(['mysql', 'mariadb', 'pgsql', 'sqlsrv']);

it('points every Redis connection of the tests at an address that is not a real server', function (string $connection): void {
    $config = config('database.redis.' . $connection);

    expect($config['url'])->toBeEmpty()
        ->and($config['host'])->toBe('127.0.0.1')
        ->and((string) $config['port'])->toBe('9');
})->with(['default', 'cache', 'queues', 'sessions']);
