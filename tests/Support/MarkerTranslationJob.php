<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

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
class MarkerTranslationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, SerializesModels;

    /** @var array<int, array{0: string, 1: mixed, 2: array}> */
    public static array $handledWith = [];

    public function __construct(protected Model $model, protected array $fields)
    {
    }

    public function handle(): void
    {
        static::$handledWith[] = [get_class($this->model), $this->model->getKey(), $this->fields];
    }
}
