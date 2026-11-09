<?php

declare(strict_types=1);

use Tests\Support\TestEnvironmentGuard;

beforeEach(function (): void {
    $this->base_path = sys_get_temp_dir() . '/guard-' . bin2hex(random_bytes(6));
    mkdir($this->base_path . '/bootstrap/cache', 0777, true);
});

afterEach(function (): void {
    @unlink($this->base_path . '/bootstrap/cache/config.php');
    @rmdir($this->base_path . '/bootstrap/cache');
    @rmdir($this->base_path . '/bootstrap');
    @rmdir($this->base_path);
});

it('flags a cached config and points to config:clear', function (): void {
    file_put_contents($this->base_path . '/bootstrap/cache/config.php', '<?php return [];');

    $violations = TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'sqlite']);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('config:clear')
        ->and($violations[0])->toContain('.env');
});

it('accepts sqlite without a cached config', function (): void {
    expect(TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'sqlite']))->toBe([]);
});

it('flags mysql on a remote host', function (): void {
    $violations = TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.com']);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('ALLOW_REMOTE_TEST_DATABASE');
});

it('accepts mysql on a local host', function (string $host): void {
    expect(TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $host]))->toBe([]);
})->with(['127.0.0.1', 'localhost', '::1']);

it('accepts a remote database when explicitly allowed', function (string $flag): void {
    $env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.com', 'ALLOW_REMOTE_TEST_DATABASE' => $flag];

    expect(TestEnvironmentGuard::violations($this->base_path, $env))->toBe([]);
})->with(['1', 'true', 'yes', 'on']);

it('rejects a falsy opt-out value', function (): void {
    $env = ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.com', 'ALLOW_REMOTE_TEST_DATABASE' => '0'];

    expect(TestEnvironmentGuard::violations($this->base_path, $env))->toHaveCount(1);
});

it('flags an empty or missing DB_CONNECTION', function (): void {
    expect(TestEnvironmentGuard::violations($this->base_path, []))->toHaveCount(1)
        ->and(TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => '']))->toHaveCount(1);
});

it('throws with every reason when two rules fail', function (): void {
    file_put_contents($this->base_path . '/bootstrap/cache/config.php', '<?php return [];');

    try {
        TestEnvironmentGuard::assertSafe($this->base_path, ['DB_CONNECTION' => 'mysql', 'DB_HOST' => 'db.example.com']);
        $this->fail('Expected RuntimeException');
    } catch (RuntimeException $e) {
        expect($e->getMessage())->toContain('config:clear')->toContain('ALLOW_REMOTE_TEST_DATABASE');
    }
});

it('does not throw when the environment is safe', function (): void {
    TestEnvironmentGuard::assertSafe($this->base_path, ['DB_CONNECTION' => 'sqlite']);

    expect(true)->toBeTrue();
});

it('flags a remote Elasticsearch', function (string $host): void {
    $violations = TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'sqlite', 'ELASTIC_HOST' => $host]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('ALLOW_REMOTE_TEST_ELASTICSEARCH');
})->with(['https://164.92.237.51:9200', 'es.example.com:9200', 'http://es.internal']);

it('accepts a local Elasticsearch, or none', function (string $host): void {
    expect(TestEnvironmentGuard::violations($this->base_path, ['DB_CONNECTION' => 'sqlite', 'ELASTIC_HOST' => $host]))->toBe([]);
})->with(['http://127.0.0.1:9', 'localhost:9200', 'http://[::1]:9200', '']);

it('accepts a remote Elasticsearch when explicitly allowed, and rejects a falsy opt-out', function (): void {
    $env = ['DB_CONNECTION' => 'sqlite', 'ELASTIC_HOST' => 'https://es.example.com:9200'];

    expect(TestEnvironmentGuard::violations($this->base_path, [...$env, 'ALLOW_REMOTE_TEST_ELASTICSEARCH' => '1']))->toBe([])
        ->and(TestEnvironmentGuard::violations($this->base_path, [...$env, 'ALLOW_REMOTE_TEST_ELASTICSEARCH' => '0']))->toHaveCount(1);
});
