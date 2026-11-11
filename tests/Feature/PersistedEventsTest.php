<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationJobPayload;
use CodingPartners\TranslaGenius\Events\TranslationsPersisted;
use CodingPartners\TranslaGenius\Jobs\TranslateFields;
use CodingPartners\TranslaGenius\Services\TranslationWriter;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/**
 * Translations are written per pair, quietly; observers (Scout, cache
 * busting, auditing) hear about them once per job: the package event
 * always, and the model's own events when TRANSLATION_FIRE_MODEL_EVENTS is
 * on.
 */
class PersistedEventsTest extends TestCase
{
    /** @var array<int, array<string, string>> the name translations each `saved` observer call saw */
    private array $savedSaw = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(fn (Request $request) => Http::response(['choices' => [['message' => ['content' => $request['messages'][1]['content'].'*'], 'finish_reason' => 'stop']]]));
        Queue::fake();
    }

    protected function runJob(): TestTranslatableModel
    {
        $model = TestTranslatableModel::create(['name' => ['en' => 'Lamp'], 'description' => ['en' => 'Bright']]);
        Queue::fake(); // forget the create's own dispatch

        TestTranslatableModel::saved(function (TestTranslatableModel $saved) {
            $this->savedSaw[] = $saved->getTranslations('name');
        });

        (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        return $model;
    }

    public function test_by_default_observers_see_nothing_and_the_package_event_fires_once_with_the_pairs()
    {
        $events = [];
        Event::listen(TranslationsPersisted::class, function (TranslationsPersisted $e) use (&$events) {
            $events[] = $e;
        });

        $model = $this->runJob();

        $this->assertSame([], $this->savedSaw);
        $this->assertCount(1, $events);
        $this->assertTrue($events[0]->model->is($model));
        $this->assertSame('Lamp*', $events[0]->model->getTranslation('name', 'ar', false), 'The event carries the record as written.');
        $this->assertEqualsCanonicalizing(
            ['name:ar', 'name:es', 'description:ar', 'description:es'],
            array_map(fn (array $pair) => "{$pair['field']}:{$pair['locale']}", $events[0]->pairs)
        );
    }

    public function test_with_model_events_on_the_observer_fires_once_with_the_final_translations()
    {
        config(['translagenius.fire_model_events' => true]);

        $this->runJob();

        $this->assertCount(1, $this->savedSaw);
        $this->assertSame('Lamp*', $this->savedSaw[0]['ar']);
        $this->assertSame('Lamp*', $this->savedSaw[0]['es']);
        Queue::assertNothingPushed(); // the package's own updated hook ignored it
    }

    public function test_no_event_when_nothing_was_written()
    {
        $events = 0;
        Event::listen(TranslationsPersisted::class, function () use (&$events) {
            $events++;
        });

        $model = TestTranslatableModel::withoutEvents(fn () => TestTranslatableModel::create([
            'name' => ['en' => 'A', 'ar' => 'ا', 'es' => 'a'], 'description' => ['en' => 'B', 'ar' => 'ب', 'es' => 'b'],
        ]));
        (new TranslateFields(new TranslationJobPayload($model, ['name', 'description'], 'en')))
            ->handle($this->app->make(TranslatorInterface::class), $this->app->make(TranslationWriter::class));

        $this->assertSame(0, $events);
    }
}
