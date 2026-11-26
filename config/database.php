<?php

declare(strict_types=1);

use Illuminate\Support\Str;

$read_hosts = array_values(array_unique(array_merge(
    [env('DB_HOST', '127.0.0.1')],
    array_filter(explode(',', (string) env('DB_READ_HOSTS', ''))),
)));

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    | Read/Write semantics (mysql, mariadb, pgsql, sqlsrv):
    | - DB_HOST: primary read-write instance; used for both read and write
    |   when DB_READ_HOSTS is not set.
    | - DB_READ_HOSTS: optional comma-separated list of read replicas; when set,
    |   DB_HOST is always included in the read pool so the primary shares read
    |   load (optimal with one or few replicas).
    | - sticky => true: after a write, all reads in the same request use the
    |   write connection to avoid replica lag.
    |
    | Oracle (yajra/laravel-oci8): connection is only registered when the PHP
    | extension oci8 is loaded. Use DB_CONNECTION=oracle only on environments
    | where OCI8 and Oracle Instant Client are installed.
    |
    */

    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'read' => [
                'host' => $read_hosts,
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'read' => [
                'host' => $read_hosts,
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'read' => [
                'host' => $read_hosts,
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
            // One round trip per query instead of prepare + execute; see Modules\Core\Overrides\PostgresConnection.
            'options' => extension_loaded('pdo_pgsql') ? [PDO::ATTR_EMULATE_PREPARES => (bool) env('DB_EMULATE_PREPARES', false)] : [],
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'read' => [
                'host' => $read_hosts,
            ],
            'write' => [
                'host' => [env('DB_HOST', '127.0.0.1')],
            ],
            'sticky' => true,
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        'oracle' => [
            'driver' => 'oracle',
            'tns' => env('DB_TNS', ''),
            'host' => env('DB_HOST', ''),
            'port' => env('DB_PORT', '1521'),
            'database' => env('DB_DATABASE', ''),
            'service_name' => env('DB_SERVICE_NAME', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'AL32UTF8'),
            'prefix' => env('DB_PREFIX', ''),
            'prefix_schema' => env('DB_SCHEMA_PREFIX', ''),
            'edition' => env('DB_EDITION', 'ora$base'),
            'server_version' => env('DB_SERVER_VERSION', '11g'),
            'load_balance' => env('DB_LOAD_BALANCE', 'yes'),
            'dynamic' => [],
            'max_name_len' => env('ORA_MAX_NAME_LEN', 30),
            'read' => [
                'host' => $read_hosts,
            ],
            'write' => [
                'host' => [env('DB_HOST', '')],
            ],
            'sticky' => true,
        ],

        'db2' => [
            'driver' => env('DB2_DRIVER', 'db2_ibmi_odbc'),
            'driverName' => env('DB2_ODBC_DRIVER', '{IBM i Access ODBC Driver}'),
            'host' => env('DB_HOST', ''),
            'port' => env('DB_PORT', '446'),
            'database' => env('DB_DATABASE', ''),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'prefix' => env('DB_PREFIX', ''),
            'schema' => env('DB_SCHEMA', ''),
            'quote_reserved_words' => true,
            'schema_transactions' => true,
            'date_format' => 'Y-m-d H:i:s',
            'version' => env('DB2_SERVER_VERSION', '7.5'),
            'odbc_keywords' => [
                'CommitMode' => env('DB2_COMMIT_MODE', 2),
                'Naming' => 0,
                'CCSID' => 1208,
                'DateFormat' => 5,
                'TimeFormat' => 0,
                'DefaultLibraries' => env('DB_SCHEMA', ''),
            ],
            'options' => [
                PDO::ATTR_CASE => PDO::CASE_LOWER,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laraplate')) . ':'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laraplate')) . ':cache:'),
        ],

        'queues' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laraplate')) . ':queues:'),
        ],

        'sessions' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '3'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laraplate')) . ':sessions:'),
        ],
    ],
];
