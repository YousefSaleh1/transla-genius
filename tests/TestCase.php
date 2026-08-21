<?php

namespace CodingPartners\TranslaGenius\Tests;

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

        // Configure test database connection
        config(['database.default' => 'testbench']);
        config(['database.connections.testbench' => [
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
        ]]);

        $this->createDatabaseIfNotExists();

        // Set default package configuration
        Config::set('translaGenius', [
            'supported_languages' => ['en', 'ar', 'es'],
            'api' => [
                'key' => 'test-api-key',
                'url' => 'https://test-api-url.com',
                'model' => 'test-model',
            ],
            'settings' => [
                'temperature' => 0.3,
                'max_tokens' => 200,
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
     * Create the test database if it doesn't exist
     *
     * @throws \RuntimeException If database creation fails
     * @return void
     */
    protected function createDatabaseIfNotExists()
    {
        $connection = config('database.connections.testbench');
        $database = $connection['database'];

        // Temporary configuration without database name
        $tempConfig = $connection;
        $tempConfig['database'] = null;

        config(['database.connections.temp' => $tempConfig]);

        try {
            DB::connection('temp')->statement("CREATE DATABASE IF NOT EXISTS $database");
        } catch (\Exception $e) {
            throw new \RuntimeException("Failed to create database '$database': " . $e->getMessage());
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
