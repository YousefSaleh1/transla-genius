<?php

namespace CodingPartners\TranslaGenius\Tests;

use CodingPartners\TranslaGenius\Providers\OpenRouterProvider;
use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case class for TranslaGenius package testing
 *
 * This class provides the foundation for all test cases in the TranslaGenius package.
 * It handles database setup and configuration for testing environment.
 */
class TestCase extends Orchestra
{
    /**
     * Which connection driver the suite runs against. Defaults to SQLite
     * in-memory - fast, needs no external service, and (per this package's
     * own cross-driver coverage) genuinely exercises the same
     * driver-agnostic JSON-path query code the other supported databases
     * do. Override with TRANSLAGENIUS_TEST_DB_CONNECTION to run the tracked
     * suite against a real service instead (e.g. in CI, or to reproduce a
     * driver-specific failure locally).
     *
     * @var string
     */
    protected string $connection;

    /**
     * Set up the test environment
     *
     * This method:
     * 1. Configures the database connection for testing
     * 2. Creates the test database if it doesn't exist
     * 3. Sets up default package configuration
     * 4. Prepares the database schema
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = env('TRANSLAGENIUS_TEST_DB_CONNECTION', 'sqlite');

        // Configure test database connection
        config(['database.default' => 'testbench']);
        config(['database.connections.testbench' => $this->connectionConfig($this->connection)]);

        $this->createDatabaseIfNotExists();

        // Set default package configuration
        Config::set('translaGenius', [
            'supported_languages' => ['en', 'ar', 'es'],
            'default' => 'openrouter',
            'providers' => [
                'openrouter' => [
                    'driver' => OpenRouterProvider::class,
                    'key' => 'test-api-key',
                    'url' => 'https://test-api-url.com',
                    'model' => 'test-model',
                    'timeout' => 10,
                    'temperature' => 0.3,
                    'max_tokens' => 200,
                ],
            ],
        ]);

        $this->setUpDatabase();
    }

    /**
     * Clean up the test environment
     *
     * Drops all test tables after each test to ensure a clean state
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $this->dropTables();
        parent::tearDown();
    }

    /**
     * Get package service providers
     *
     * @param  \Illuminate\Foundation\Application  $app
     * @return array
     */
    protected function getPackageProviders($app)
    {
        return [
            TranslaGeniusServiceProvider::class,
        ];
    }

    /**
     * Build the `database.connections.testbench` array for the given
     * connection driver, reading connection details from the same DB_*
     * environment variables regardless of driver (their defaults vary by
     * driver, e.g. the default port).
     *
     * @param string $connection One of: mysql, mariadb, pgsql, sqlsrv, sqlite.
     * @return array<string, mixed>
     *
     * @throws \RuntimeException If $connection isn't a recognized driver.
     */
    protected function connectionConfig(string $connection): array
    {
        return match ($connection) {
            'sqlite' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            // MariaDB is wire-compatible with the mysql PDO driver, so it
            // reuses the same connection shape as 'mysql' below.
            'mysql', 'mariadb' => [
                'driver' => 'mysql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '3306'),
                'database' => env('DB_DATABASE', 'translagenius_test'),
                'username' => env('DB_USERNAME', 'root'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
                'engine' => null,
            ],
            'pgsql' => [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '5432'),
                'database' => env('DB_DATABASE', 'translagenius_test'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
                'schema' => 'public',
            ],
            'sqlsrv' => [
                'driver' => 'sqlsrv',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => env('DB_PORT', '1433'),
                'database' => env('DB_DATABASE', 'translagenius_test'),
                'username' => env('DB_USERNAME', 'sa'),
                'password' => env('DB_PASSWORD', ''),
                'charset' => 'utf8',
                'prefix' => '',
            ],
            default => throw new \RuntimeException(
                "Unsupported TRANSLAGENIUS_TEST_DB_CONNECTION value '$connection'. "
                . 'Supported: mysql, mariadb, pgsql, sqlsrv, sqlite.'
            ),
        };
    }

    /**
     * Create the test database if it doesn't exist.
     *
     * SQLite in-memory has no separate database to create, so this is a
     * no-op for that driver. Every other supported driver needs its own
     * "does it exist" check and creation syntax - none of them share
     * MySQL's `CREATE DATABASE IF NOT EXISTS` shorthand.
     *
     * @throws \RuntimeException If database creation fails
     * @return void
     */
    protected function createDatabaseIfNotExists()
    {
        if ($this->connection === 'sqlite') {
            return;
        }

        $connection = config('database.connections.testbench');
        $database = $connection['database'];

        // Temporary configuration without database name, pointed at each
        // driver's own administrative default database.
        $tempConfig = $connection;
        $tempConfig['database'] = match ($connection['driver']) {
            'pgsql' => 'postgres',
            'sqlsrv' => 'master',
            default => null,
        };

        config(['database.connections.temp' => $tempConfig]);

        try {
            match ($connection['driver']) {
                'pgsql' => $this->createPostgresDatabaseIfNotExists($database),
                'sqlsrv' => DB::connection('temp')->statement(
                    "IF NOT EXISTS (SELECT * FROM sys.databases WHERE name = N'$database') CREATE DATABASE [$database]"
                ),
                default => DB::connection('temp')->statement("CREATE DATABASE IF NOT EXISTS $database"),
            };
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create database '$database': " . $e->getMessage());
        }
    }

    /**
     * PostgreSQL has no `CREATE DATABASE IF NOT EXISTS` shorthand, so
     * existence has to be checked explicitly first.
     *
     * @param string $database
     * @return void
     */
    protected function createPostgresDatabaseIfNotExists(string $database): void
    {
        $exists = DB::connection('temp')->selectOne(
            'SELECT 1 FROM pg_database WHERE datname = ?',
            [$database]
        );

        if (!$exists) {
            DB::connection('temp')->statement("CREATE DATABASE \"$database\"");
        }
    }

    /**
     * Set up the test database schema
     *
     * Drops existing tables and runs migrations
     *
     * @return void
     */
    protected function setUpDatabase()
    {
        $this->dropTables();

        $migration = include __DIR__ . '/database/migrations/2024_01_01_000000_create_test_tables.php';
        $migration->up();
    }

    /**
     * Drop all test tables
     *
     * Safely drops all test tables while handling foreign key constraints
     * Silently catches any exceptions to prevent test failures during cleanup
     *
     * @return void
     */
    protected function dropTables()
    {
        Schema::disableForeignKeyConstraints();

        $tables = ['test_translatable_models'];

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::drop($table);
            }
        }

        Schema::enableForeignKeyConstraints();
    }
}
