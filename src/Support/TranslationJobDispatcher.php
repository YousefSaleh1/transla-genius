<?php

namespace CodingPartners\TranslaGenius\Support;

use CodingPartners\TranslaGenius\Contracts\HasTranslatableAttributes;
use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Exceptions\InvalidTranslationJobException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Dispatches a translation job with the package's queue settings applied
 * (translagenius.queue: connection, queue, after_commit). Used by every
 * dispatch point - the Translatable trait's create/update hooks and
 * `translagenius:backfill` - so they behave the same way.
 *
 * The settings are applied through the job's Queueable methods
 * (onConnection(), onQueue(), afterCommit()). A custom job from
 * translationJobClass() should use Illuminate\Bus\Queueable to honor them;
 * one that doesn't is still dispatched, on the app's defaults, and a
 * warning naming the class is logged once per class per process.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class TranslationJobDispatcher
{
    /** @var array<class-string, true> Job classes already warned about in this process. */
    private static array $warned = [];

    /**
     * $jobClass is constructed with one TranslationJobPayload built from the
     * other arguments. $retranslate lists the fields whose source text changed
     * in this save - see Translatable.
     *
     * @param  class-string  $jobClass
     * @param  Model&HasTranslatableAttributes  $model
     * @param  array<int, string>  $fields
     * @param  array<string, array{locale: string, source_hash: string, keep: array<int, string>, target_hashes?: array<string, string>}>  $retranslate
     * @param  string|null  $sourceLocale  The locale the content was written in;
     *                                     null = self::sourceLocale(), resolved now, at dispatch time.
     * @param  array<string, mixed>  $options  Extra job options (TranslateFields::$options).
     */
    public static function dispatch(string $jobClass, Model $model, array $fields, array $retranslate = [], ?string $sourceLocale = null, array $options = []): void
    {
        $sourceLocale ??= self::sourceLocale();

        // Every job gets the same single payload; a class that doesn't take it
        // is rejected here, never silently given less.
        if (! is_a($jobClass, TranslationJob::class, true)) {
            throw new InvalidTranslationJobException($jobClass);
        }

        $job = new $jobClass(new TranslationJobPayload($model, $fields, $sourceLocale, $retranslate, $options));
        $settings = (array) config('translagenius.queue', []);

        $connection = $settings['connection'] ?? null;
        $queue = $settings['queue'] ?? null;
        $hasConnection = $connection !== null && $connection !== '';
        $hasQueue = $queue !== null && $queue !== '';

        if (($hasConnection && ! method_exists($job, 'onConnection')) || ($hasQueue && ! method_exists($job, 'onQueue'))) {
            self::warnOnce($jobClass);
        }

        if ($hasConnection && method_exists($job, 'onConnection')) {
            $job->onConnection($connection);
        }

        if ($hasQueue && method_exists($job, 'onQueue')) {
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

    /**
     * The locale content is authored in, decided when the job is dispatched
     * (never inside the job, where the queue worker's locale is unrelated to
     * the request that saved the record): TRANSLATION_SOURCE_LOCALE when set,
     * otherwise the current (request) locale.
     */
    public static function sourceLocale(): string
    {
        $fixed = trim((string) config('translagenius.source_locale'));

        return $fixed !== '' ? $fixed : Locales::current();
    }

    /**
     * Source locale for contexts with no request (backfill, and jobs queued
     * before the locale was carried): TRANSLATION_SOURCE_LOCALE, then the
     * app's configured locale (config('app.locale') as it was at boot).
     * Deterministic - not the process's current locale, which
     * app()->setLocale() may have changed (along with config('app.locale')).
     */
    public static function defaultSourceLocale(): string
    {
        $fixed = trim((string) config('translagenius.source_locale'));

        if ($fixed !== '') {
            return $fixed;
        }

        return app()->bound(ConfiguredLocale::class)
            ? app(ConfiguredLocale::class)->get()
            : (string) config('app.locale', 'en');
    }

    /**
     * @param  class-string  $jobClass
     */
    private static function warnOnce(string $jobClass): void
    {
        if (isset(self::$warned[$jobClass])) {
            return;
        }

        self::$warned[$jobClass] = true;

        Log::warning(
            "TranslaGenius: {$jobClass} does not use Illuminate\\Bus\\Queueable, so TRANSLATION_QUEUE_CONNECTION / "
            .'TRANSLATION_QUEUE are ignored for it and it is dispatched on the app defaults.',
            ['job_class' => $jobClass]
        );
    }

    /**
     * Forget which classes were warned about (for tests).
     */
    public static function resetWarnings(): void
    {
        self::$warned = [];
    }
}
