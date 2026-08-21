<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;

/**
 * A hand-written TranslationProviderInterface implementation for tests -
 * not a Mockery double. Proves the provider boundary is real (any class
 * implementing the interface works, nothing OpenRouter-specific leaks
 * through it) rather than just asserting against a mock of the default
 * provider. Records every request it's asked to translate and returns a
 * deterministic, inspectable result.
 */
class FakeTranslationProvider implements TranslationProviderInterface
{
    /** @var TranslationRequest[] */
    public array $requests = [];

    public function __construct(protected string $prefix = 'FAKE:')
    {
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        $this->requests[] = $request;

        return new TranslationResult(
            text: "{$this->prefix}{$request->text}:{$request->targetLocale}",
            provider: 'fake',
        );
    }
}
