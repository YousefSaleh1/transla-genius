<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Support\QueueTimeouts;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * A queue hands a job to another worker once it has run longer than the
 * connection's retry_after (Laravel's database and Redis connections
 * default to 90 s), while translation jobs may run up to 900 s. The check
 * command and the job itself say so.
 */
class RetryAfterCheckTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        QueueTimeouts::resetWarning();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('jobs');

        parent::tearDown();
    }

    protected function useDatabaseQueue(int $retryAfter): void
    {
        config([
            'queue.default' => 'database',
            'queue.connections.database' => ['driver' => 'database', 'connection' => config('database.default'), 'table' => 'jobs', 'queue' => 'default', 'retry_after' => $retryAfter, 'after_commit' => false],
        ]);
    }

    public function test_the_check_command_warns_with_the_line_to_change()
    {
        $this->useDatabaseQueue(90);

        $this->artisan('translagenius:check')
            ->expectsOutputToContain('retry_after (90 s) on queue connection [database] is not above the translation job timeout (up to 900 s)')
            ->expectsOutputToContain("'retry_after' => 930,")
            ->expectsOutputToContain('config/queue.php');
    }

    public function test_the_check_command_uses_the_configured_job_timeout()
    {
        $this->useDatabaseQueue(300);
        config(['translagenius.queue.timeout' => 240]);

        $this->artisan('translagenius:check')
            ->doesntExpectOutputToContain('retry_after (')
            ->expectsOutputToContain('Queue: retry_after (300 s) on [database] is above the job timeout (240 s).');
    }

    public function test_a_job_on_a_connection_with_a_short_retry_after_warns_once_per_process()
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $this->useDatabaseQueue(90);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'x'], 'finish_reason' => 'stop']]])]);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            if ($e->level === 'warning' && str_contains($e->message, 'retry_after')) {
                $warnings[] = $e->message;
            }
        });

        TestTranslatableModel::create(['name' => ['en' => 'A'], 'description' => ['en' => '']]);
        TestTranslatableModel::create(['name' => ['en' => 'B'], 'description' => ['en' => '']]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);
        $this->artisan('queue:work', ['connection' => 'database', '--once' => true]);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('[database]', $warnings[0]);
    }
}
