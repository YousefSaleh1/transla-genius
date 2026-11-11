<?php

namespace CodingPartners\TranslaGenius\Support;

use Closure;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Job middleware: one translation job per record at a time. A create
 * followed by a quick edit, or a job redelivered while it's still running,
 * would otherwise translate the same pairs twice, in parallel, and pay twice.
 *
 * Like Laravel's WithoutOverlapping, it holds a cache lock keyed by the
 * record. A job that finds the lock taken is not released, though:
 * a release counts as one of the job's $tries, so a long job could make a
 * waiting one fail without ever running. It is deleted and a fresh copy is
 * queued after a short delay instead, so waiting never uses up attempts and
 * the job is never dropped.
 *
 * Needs a cache store that supports locks (redis, database, memcached and
 * dynamodb across workers; file within one machine; array within one
 * process; the null store never blocks). With a store that doesn't, the
 * guard is skipped, with a warning: jobs may then overlap; the row lock and
 * snapshots still keep the data right, but overlapping pairs are paid twice.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class OneJobPerRecord
{
    private static bool $warned = false;

    public function __construct(
        private string $key,
        private int $lockSeconds,
        private int $retryAfterSeconds,
    ) {}

    public function handle(TranslateFields $job, Closure $next): mixed
    {
        // The sync driver runs the job inline: nothing to wait for, and no
        // queue to put a copy back on.
        if ($job->job === null || $job->job instanceof SyncJob) {
            return $next($job);
        }

        $store = Cache::store()->getStore();
        if (! $store instanceof LockProvider) {
            if (! self::$warned) {
                self::$warned = true;
                Log::warning('TranslateFields: the cache store does not support locks, so translation jobs for one record may overlap', [
                    'store' => get_class($store),
                ]);
            }

            return $next($job);
        }

        $lock = $store->lock($this->key, $this->lockSeconds);

        if (! $lock->get()) {
            $job->requeueAfter($this->retryAfterSeconds);

            return null;
        }

        try {
            return $next($job);
        } finally {
            $lock->release();
        }
    }
}
