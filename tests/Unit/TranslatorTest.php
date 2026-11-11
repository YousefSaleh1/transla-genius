<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Services\Translator;
use CodingPartners\TranslaGenius\Tests\Support\FakeTranslationProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Translator.
 *
 * Pure PHP, no framework bootstrap - Translator only needs a
 * TranslationProviderInterface instance in its constructor, nothing else.
 * This is the most direct proof of Task 1's whole point: swap the provider,
 * Translator's behavior changes, and Translator itself never changes.
 */
class TranslatorTest extends TestCase
{
    public function test_delegates_to_the_injected_provider_and_returns_its_result()
    {
        $translator = new Translator(new FakeTranslationProvider);

        $result = $translator->translate('Hello', 'en', 'ar');

        $this->assertSame('FAKE:Hello:ar', $result->text);
        $this->assertSame('fake', $result->provider);
    }

    public function test_builds_the_request_from_its_arguments()
    {
        $provider = new FakeTranslationProvider;
        $translator = new Translator($provider);

        $translator->translate('Bonjour', 'fr', 'en');

        $this->assertCount(1, $provider->requests);
        $this->assertSame('Bonjour', $provider->requests[0]->text);
        $this->assertSame('fr', $provider->requests[0]->sourceLocale);
        $this->assertSame('en', $provider->requests[0]->targetLocale);
    }

    public function test_swapping_the_provider_changes_the_result_without_changing_translator()
    {
        $translatorA = new Translator(new FakeTranslationProvider('A:'));
        $translatorB = new Translator(new FakeTranslationProvider('B:'));

        $this->assertSame('A:Hi:ar', $translatorA->translate('Hi', 'en', 'ar')->text);
        $this->assertSame('B:Hi:ar', $translatorB->translate('Hi', 'en', 'ar')->text);
    }
}
