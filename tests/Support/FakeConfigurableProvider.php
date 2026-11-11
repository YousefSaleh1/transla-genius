<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;

/**
 * A TranslationProviderInterface fake constructed from `array $config`
 * exactly like a real driver (OpenAiCompatibleProvider, FallbackProvider) - so,
 * unlike FakeTranslationProvider, it can be resolved through
 * TranslaGeniusServiceProvider's normal `providers.{name}.driver`
 * container-resolution path (`$app->make($driver, ['config' => $config])`)
 * rather than only ever being instantiated directly.
 *
 * Records a call count per `id` in a static registry, since config-driven
 * resolution builds fresh instances the test has no direct reference to -
 * call FakeConfigurableProvider::reset() in setUp()/tearDown() to avoid
 * counts leaking between tests.
 */
class FakeConfigurableProvider implements TranslationProviderInterface
{
    /** @var array<string, int> */
    public static array $callCounts = [];

    protected string $id;

    protected string $prefix;

    protected bool $shouldFail;

    protected bool $retryable;

    protected ?int $statusCode;

    protected ?int $promptTokens;

    protected ?int $completionTokens;

    /**
     * @param  array{id?: string, prefix?: string, fail?: bool, retryable?: bool, status_code?: int|null, prompt_tokens?: int|null, completion_tokens?: int|null}  $config
     */
    public function __construct(array $config)
    {
        $this->id = $config['id'] ?? 'unnamed';
        $this->prefix = $config['prefix'] ?? 'FAKE:';
        $this->shouldFail = $config['fail'] ?? false;
        $this->retryable = $config['retryable'] ?? true;
        $this->statusCode = $config['status_code'] ?? null;
        $this->promptTokens = $config['prompt_tokens'] ?? null;
        $this->completionTokens = $config['completion_tokens'] ?? null;
    }

    public function translate(TranslationRequest $request): TranslationResult
    {
        self::$callCounts[$this->id] = self::callCount($this->id) + 1;

        if ($this->shouldFail) {
            throw new TranslationFailedException(
                "{$this->id} failed",
                statusCode: $this->statusCode,
                retryable: $this->retryable
            );
        }

        return new TranslationResult(
            text: "{$this->prefix}{$request->text}:{$request->targetLocale}",
            provider: $this->id,
            promptTokens: $this->promptTokens,
            completionTokens: $this->completionTokens,
        );
    }

    public static function callCount(string $id): int
    {
        return self::$callCounts[$id] ?? 0;
    }

    public static function reset(): void
    {
        self::$callCounts = [];
    }
}
