<?php

namespace CodingPartners\TranslaGenius\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Verifies the active database connection can actually do what this
 * package needs (JSON path extraction) before a consumer finds out the
 * hard way, deep inside a queued job. Deliberately an Artisan command, not
 * a boot-time check - a check that only matters once per environment setup
 * shouldn't cost every request/worker boot a query.
 *
 * SQLite is the one driver where this genuinely isn't guaranteed by
 * Laravel's own stated minimum version (3.26.0+) - JSON functions were an
 * optional, not-always-compiled-in extension before SQLite 3.38.0. MySQL,
 * MariaDB, PostgreSQL, and SQL Server all have JSON path functions
 * unconditionally available at this package's stated minimum versions, so
 * those checks exist for completeness/fail-fast convenience, not because
 * they're expected to ever actually fail.
 */
class CheckCommand extends Command
{
    protected $signature = 'translagenius:check {--connection= : The database connection to check (defaults to the app default)}';

    protected $description = 'Verify the published config file and that the database connection supports the JSON queries TranslaGenius needs';

    /**
     * Run the JSON-support check appropriate to the connection's driver.
     *
     * @return int self::SUCCESS if the connection supports the JSON queries
     *         this package relies on, self::FAILURE otherwise (including
     *         for a driver this package hasn't verified compatibility with).
     */
    public function handle(): int
    {
        $configOk = $this->checkPublishedConfig();

        $connection = $this->laravel['db']->connection($this->option('connection'));
        $driver = $connection->getDriverName();

        $this->info("Checking connection [{$connection->getName()}] (driver: {$driver})...");

        $databaseResult = match ($driver) {
            'sqlite' => $this->checkSqlite($connection),
            'mysql' => $this->checkJsonExtract($connection, 'MySQL/MariaDB'),
            'pgsql' => $this->checkPostgres($connection),
            'sqlsrv' => $this->checkSqlServer($connection),
            default => $this->warnUnknownDriver($driver),
        };

        return $configOk ? $databaseResult : self::FAILURE;
    }

    /**
     * Since 4.0 the config file and key are `translagenius` (they were
     * `translaGenius`). Laravel names a config key after its file, so an app
     * that still has a published config/translaGenius.php loads it under the
     * old key - where the package no longer looks - and every setting in it is
     * silently ignored. Compared by exact filename, because on a
     * case-insensitive filesystem (macOS by default) both names open the same
     * file.
     *
     * @return bool False when only the old file is present.
     */
    protected function checkPublishedConfig(): bool
    {
        $directory = $this->laravel->configPath();
        $files = is_dir($directory) ? scandir($directory) : [];

        if (in_array('translaGenius.php', $files, true) && !in_array('translagenius.php', $files, true)) {
            $this->error('Found config/translaGenius.php, but TranslaGenius 4.0 reads config/translagenius.php (all lowercase).');
            $this->line('  Its settings are being ignored. Rename the file to config/translagenius.php and replace');
            $this->line("  config('translaGenius...') with config('translagenius...') in your own code - see UPGRADE.md.");

            return false;
        }

        return true;
    }

    /**
     * Verify SQLite JSON1 functions are available on $connection.
     *
     * @param ConnectionInterface $connection
     * @return int self::SUCCESS or self::FAILURE.
     */
    protected function checkSqlite(ConnectionInterface $connection): int
    {
        try {
            $connection->select("select json_extract('{}', '$') as x");
        } catch (Throwable $e) {
            $this->error('SQLite JSON functions are not available on this connection.');
            $this->line('');
            $this->line('TranslaGenius requires SQLite 3.38.0+, or an earlier 3.26.0+ build with');
            $this->line('JSON1 explicitly compiled in. Laravel\'s own stated 3.26.0+ floor is not');
            $this->line('sufficient on its own - many pre-3.38 builds (older Debian/Ubuntu base');
            $this->line('images, some bundled Windows builds) shipped without JSON1 compiled in.');
            $this->line('');
            $this->line('Underlying error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $version = $connection->select('select sqlite_version() as v')[0]->v ?? 'unknown';
        $this->info("SQLite {$version}: JSON functions available.");

        return self::SUCCESS;
    }

    /**
     * Verify MySQL/MariaDB-style JSON_EXTRACT is available on $connection.
     *
     * @param ConnectionInterface $connection
     * @param string $label Driver name shown in output (e.g. "MySQL/MariaDB").
     * @return int self::SUCCESS or self::FAILURE.
     */
    protected function checkJsonExtract(ConnectionInterface $connection, string $label): int
    {
        try {
            $connection->select("select json_extract('{}', '$') as x");
        } catch (Throwable $e) {
            $this->error("{$label}: JSON functions are not available on this connection.");
            $this->line('Underlying error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$label}: JSON functions available.");

        return self::SUCCESS;
    }

    /**
     * Verify PostgreSQL JSON operators are available on $connection.
     *
     * @param ConnectionInterface $connection
     * @return int self::SUCCESS or self::FAILURE.
     */
    protected function checkPostgres(ConnectionInterface $connection): int
    {
        try {
            $connection->select("select '{}'::json ->> 'x' as x");
        } catch (Throwable $e) {
            $this->error('PostgreSQL: JSON functions are not available on this connection.');
            $this->line('Underlying error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('PostgreSQL: JSON functions available.');

        return self::SUCCESS;
    }

    /**
     * Verify SQL Server's JSON_VALUE is available on $connection.
     *
     * @param ConnectionInterface $connection
     * @return int self::SUCCESS or self::FAILURE.
     */
    protected function checkSqlServer(ConnectionInterface $connection): int
    {
        try {
            $connection->select("select JSON_VALUE('{}', '$.x') as x");
        } catch (Throwable $e) {
            $this->error('SQL Server: JSON_VALUE is not available on this connection.');
            $this->line('TranslaGenius requires SQL Server 2016+ (JSON_VALUE was introduced there).');
            $this->line('Underlying error: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info('SQL Server: JSON_VALUE available.');

        return self::SUCCESS;
    }

    /**
     * Report that this driver hasn't been verified, and fail: an unknown
     * driver should be investigated, not assumed to work.
     *
     * @param string $driver
     * @return int self::FAILURE.
     */
    protected function warnUnknownDriver(string $driver): int
    {
        $this->warn("TranslaGenius hasn't verified compatibility with the [{$driver}] driver.");
        $this->line('Supported drivers: mysql (MySQL/MariaDB), pgsql, sqlite, sqlsrv.');

        return self::FAILURE;
    }
}
