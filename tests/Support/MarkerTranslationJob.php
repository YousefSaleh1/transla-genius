<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use CodingPartners\TranslaGenius\Contracts\TranslationJob;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A stand-in job for proving Translatable::translationJobClass() is a real
 * override point (Phase 4a Task 2). Deliberately does not translate
 * anything - it just records that it, specifically, ran - so the test
 * doesn't need a provider/Translator in the picture, only proof that this
 * class (not TranslateFields) was the one dispatched.
 */
class MarkerTranslationJob implements ShouldQueue, TranslationJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @var array<int, array{0: string, 1: mixed, 2: array}> */
    public static array $handledWith = [];

    /** @var array<int, TranslationJobPayload> Payloads seen by handle(), in order. */
    public static array $payloads = [];

    protected Model $model;

    protected array $fields;

    public function __construct(protected TranslationJobPayload $payload)
    {
        $this->model = $payload->model;
        $this->fields = $payload->fields;
    }

    public function handle(): void
    {
        static::$handledWith[] = [get_class($this->model), $this->model->getKey(), $this->fields];
        static::$payloads[] = $this->payload;
    }
}
