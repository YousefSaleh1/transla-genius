<?php

namespace CodingPartners\TranslaGenius\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Dispatches a translation job with the package's queue settings applied
 * (translaGenius.queue: connection, queue, after_commit). Used by every
 * dispatch point - the Translatable trait's create/update hooks and
 * `translagenius:backfill` - so they behave the same way.
 *
 * The settings are applied through the job's Queueable methods
 * (onConnection(), onQueue(), afterCommit()). A custom job from
 * translationJobClass() should use Illuminate\Bus\Queueable to honor them;
 * one that doesn't is still dispatched, on the app's defaults.
 */
final class TranslationJobDispatcher
{
    /**
     * @param class-string $jobClass A job constructed as new $jobClass($model, $fields).
     * @param Model $model
     * @param array<int, string> $fields
     * @return void
     */
    public static function dispatch(string $jobClass, Model $model, array $fields): void
    {
        $job = new $jobClass($model, $fields);
        $settings = (array) config('translaGenius.queue', []);

        $connection = $settings['connection'] ?? null;
        if ($connection !== null && $connection !== '' && method_exists($job, 'onConnection')) {
            $job->onConnection($connection);
        }

        $queue = $settings['queue'] ?? null;
        if ($queue !== null && $queue !== '' && method_exists($job, 'onQueue')) {
            $job->onQueue($queue);
        }

        // Default on: a job dispatched inside a DB transaction must not run
        // before the commit (the model may not exist yet for SerializesModels)
        // or at all after a rollback (it would translate discarded data).
        $afterCommit = filter_var($settings['after_commit'] ?? true, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;
        if ($afterCommit && method_exists($job, 'afterCommit')) {
            $job->afterCommit();
        }

        dispatch($job);
    }
}
