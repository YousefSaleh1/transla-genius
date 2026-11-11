<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\TranslaGeniusServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Feature test for the translagenius:check Artisan command (Phase 4a
 * Task 7), run for real against SQLite in-memory - the driver Task 3
 * flagged as the one whose JSON support genuinely isn't guaranteed.
 */
class CheckCommandTest extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [TranslaGeniusServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    public function test_check_command_passes_on_a_modern_sqlite_with_json_support()
    {
        $this->artisan('translagenius:check')
            ->expectsOutputToContain('JSON functions available')
            ->assertExitCode(0);
    }

    public function test_check_command_is_registered_and_listed()
    {
        $this->assertTrue($this->app->make('Illuminate\Contracts\Console\Kernel')->all()['translagenius:check'] !== null);
    }

    public function test_check_command_reports_a_config_file_published_under_the_old_name()
    {
        $directory = sys_get_temp_dir().'/translagenius-check-'.uniqid();
        mkdir($directory);
        file_put_contents("{$directory}/translaGenius.php", "<?php return [];\n");
        $this->app->useConfigPath($directory);

        try {
            $this->artisan('translagenius:check')
                ->expectsOutputToContain('Found config/translaGenius.php')
                ->expectsOutputToContain('Rename the file to config/translagenius.php')
                ->assertFailed();

            // Renamed: the check passes again.
            rename("{$directory}/translaGenius.php", "{$directory}/translagenius.php");
            $this->artisan('translagenius:check')
                ->doesntExpectOutputToContain('Found config/translaGenius.php')
                ->assertSuccessful();
        } finally {
            array_map('unlink', glob("{$directory}/*.php"));
            rmdir($directory);
        }
    }
}
