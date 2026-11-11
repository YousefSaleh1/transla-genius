<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Contracts\TranslatorInterface;
use CodingPartners\TranslaGenius\Providers\CachingProvider;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Cache\ArrayStore;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/**
 * The translation cache is an optimization. A cache that's down (Redis
 * refusing connections) is a miss on read and a skipped write: the
 * translation still happens and is saved.
 */
class CacheOutageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CachingProvider::resetCacheWarning();
        Cache::extend('down', fn () => Cache::repository(new class extends ArrayStore
        {
            public function get($key)
            {
                throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }

            public function put($key, $value, $seconds)
            {
                throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }

            public function forever($key, $value)
            {
                throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }

            public function forget($key)
            {
                throw new \RuntimeException('Connection refused [tcp://127.0.0.1:6379]');
            }
        }));
        config([
            'cache.stores.down' => ['driver' => 'down'],
            'translagenius.cache.enabled' => true,
            'translagenius.cache.store' => 'down',
        ]);
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => 'مرحبا'], 'finish_reason' => 'stop']]])]);
    }

    public function test_a_cache_that_throws_on_get_and_put_still_lets_the_translation_complete()
    {
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$warnings) {
            if ($e->level === 'warning') {
                $warnings[] = $e->message;
            }
        });

        $model = TestTranslatableModel::create(['name' => ['en' => 'Hello'], 'description' => ['en' => 'World']]);

        $fresh = TestTranslatableModel::find($model->id);
        $this->assertSame('مرحبا', $fresh->getTranslation('name', 'ar', false));
        $this->assertSame('مرحبا', $fresh->getTranslation('description', 'es', false));
        Http::assertSentCount(4);

        // One warning, not one per read and write.
        $this->assertCount(1, array_filter($warnings, fn ($m) => str_contains($m, 'cache')));
    }

    public function test_the_facade_translates_through_a_broken_cache()
    {
        $this->assertSame('مرحبا', $this->app->make(TranslatorInterface::class)->translate('Hello', 'en', 'ar')->text);
    }
}
