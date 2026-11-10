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
}
