<?php

declare(strict_types=1);

namespace Tests\Support;

use Dotenv\Dotenv;
use RuntimeException;

/**
 * Refuses to start the test suite when it could reach a real database, a real Redis or a real Elasticsearch.
 *
 * The suite bootstrap runs before Laravel loads `.env`, so the process environment alone hides the hosts
 * `.env` names. {@see self::effectiveEnvironment()} overlays the process environment on the dotenv file
 * Laravel would load, which is what the application ends up seeing.
 */
final class TestEnvironmentGuard
{
    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '::1'];

    private const array TRUTHY = ['1', 'true', 'yes', 'on'];

    /**
     * The environment the application will see: the dotenv file Laravel loads, with the process environment on top.
     *
     * Laravel's dotenv repository is immutable, so a variable already in the process (phpunit.xml `<env>`, the shell)
     * wins over the file. With `APP_ENV` set and a `.env.{APP_ENV}` file present, Laravel reads that file instead of `.env`.
     *
     * @param  array<string, mixed>  $process
     * @return array<string, mixed>
     */
    public static function effectiveEnvironment(string $basePath, array $process): array
    {
        $app_env = self::value($process, 'APP_ENV');
        $file = $basePath . '/.env';

        if ($app_env !== '' && is_file($basePath . '/.env.' . $app_env)) {
            $file = $basePath . '/.env.' . $app_env;
        }

        if (! is_file($file)) {
            return $process;
        }

        $contents = file_get_contents($file);

        return array_merge($contents === false ? [] : Dotenv::parse($contents), $process);
    }

    /**
     * @param  array<string, mixed>  $env
     * @return list<string>
     */
    public static function violations(string $basePath, array $env): array
    {
        $violations = [];

        if (file_exists($basePath . '/bootstrap/cache/config.php')) {
            $violations[] = 'bootstrap/cache/config.php exists: a cached config makes the tests ignore phpunit.xml and use the database from .env. Run `php artisan config:clear`.';
        }

        $connection = self::value($env, 'DB_CONNECTION');
        $host = self::value($env, 'DB_HOST');
        $remote_database_hosts = self::remoteHosts([
            $host,
            self::hostOf(self::value($env, 'DB_URL')),
            ...explode(',', self::value($env, 'DB_READ_HOSTS')),
        ]);
        $database_allowed = self::isTruthy($env, 'ALLOW_REMOTE_TEST_DATABASE');

        if ((($connection !== 'sqlite' && ! in_array($host, self::LOCAL_HOSTS, true)) || $remote_database_hosts !== []) && ! $database_allowed) {
            $violations[] = sprintf(
                'The test database is not safe (DB_CONNECTION="%s", DB_HOST="%s", remote hosts: %s): the default connection must be sqlite or local, and DB_HOST, DB_URL and DB_READ_HOSTS must name only local hosts (127.0.0.1, localhost, ::1), since a test that switches connection reaches them. Fix phpunit.xml/.env, or set ALLOW_REMOTE_TEST_DATABASE=1 to opt out knowingly.',
                $connection,
                $host,
                $remote_database_hosts === [] ? 'none' : implode(', ', $remote_database_hosts),
            );
        }

        $remote_redis_hosts = self::remoteHosts([
            self::hostOf(self::value($env, 'REDIS_HOST')),
            self::hostOf(self::value($env, 'REDIS_URL')),
        ]);

        if ($remote_redis_hosts !== [] && ! self::isTruthy($env, 'ALLOW_REMOTE_TEST_REDIS')) {
            $violations[] = sprintf(
                'The test Redis is not safe (remote hosts: %s): a test that uses a redis store or queue writes to it, so REDIS_HOST and REDIS_URL must name only a local host (127.0.0.1, localhost, ::1). Fix phpunit.xml/.env, or set ALLOW_REMOTE_TEST_REDIS=1 to opt out knowingly.',
                implode(', ', $remote_redis_hosts),
            );
        }

        $elastic = self::value($env, 'ELASTIC_HOST');

        if (self::remoteHosts([self::hostOf($elastic)]) !== [] && ! self::isTruthy($env, 'ALLOW_REMOTE_TEST_ELASTICSEARCH')) {
            $violations[] = sprintf(
                'The test Elasticsearch is not safe (ELASTIC_HOST="%s"): the tests write and empty indexes, so only a local host (127.0.0.1, localhost, ::1) is accepted. Fix phpunit.xml/the shell, or set ALLOW_REMOTE_TEST_ELASTICSEARCH=1 to opt out knowingly.',
                $elastic,
            );
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $env
     */
    public static function assertSafe(string $basePath, array $env): void
    {
        $violations = self::violations($basePath, $env);

        if ($violations !== []) {
            throw new RuntimeException(
                "Unsafe test environment, refusing to run the tests:\n- " . implode("\n- ", $violations),
            );
        }
    }

    /**
     * The host part of a bare host, a host:port pair or a URL, without IPv6 brackets; empty when there is none.
     */
    private static function hostOf(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (filter_var(mb_trim($value, '[]'), FILTER_VALIDATE_IP) !== false) {
            return mb_trim($value, '[]');
        }

        $host = parse_url(str_contains($value, '://') ? $value : 'http://' . $value, PHP_URL_HOST);

        return is_string($host) ? mb_trim($host, '[]') : $value;
    }

    /**
     * The non-empty hosts that are not local. An empty value falls back to the config default, 127.0.0.1.
     *
     * @param  list<string>  $hosts
     * @return list<string>
     */
    private static function remoteHosts(array $hosts): array
    {
        $remote = [];

        foreach ($hosts as $host) {
            $host = mb_trim($host);

            if ($host !== '' && ! in_array($host, self::LOCAL_HOSTS, true)) {
                $remote[] = $host;
            }
        }

        return array_values(array_unique($remote));
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private static function isTruthy(array $env, string $key): bool
    {
        return in_array(mb_strtolower(self::value($env, $key)), self::TRUTHY, true);
    }

    /**
     * @param  array<string, mixed>  $env
     */
    private static function value(array $env, string $key): string
    {
        $value = $env[$key] ?? '';

        return is_scalar($value) ? mb_trim((string) $value) : '';
    }
}
