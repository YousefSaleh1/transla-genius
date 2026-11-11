<?php

namespace CodingPartners\TranslaGenius\Providers;

use CodingPartners\TranslaGenius\Contracts\BatchTranslationProviderInterface;
use CodingPartners\TranslaGenius\Contracts\TranslationProviderInterface;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationBatchResult;
use CodingPartners\TranslaGenius\DTOs\TranslationRequest;
use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use CodingPartners\TranslaGenius\Exceptions\AllProvidersFailedException;
use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * A pure TranslationProviderInterface decorator over an ordered chain of
 * other providers: try each in order, return the first success, and only
 * throw once every provider has failed. Nothing about this class is
 * OpenRouter- or HTTP-specific - it composes any TranslationProviderInterface
 * implementations, including a mix of different backends (an LLM provider
 * chained with DeepL, say).
 *
 * Falls through to the next provider on ANY TranslationFailedException from
 * the current one, not just a retryable() one. isRetryable() encodes
 * whether retrying the SAME provider (same credentials, same endpoint) is
 * worth it - a 401 from provider A says nothing about whether provider B,
 * with entirely different credentials, would also fail. Gating fallback on
 * provider A's own retry semantics would also mean the chain never helps
 * with exactly the failure it exists for: provider A being completely
 * unusable (bad key, expired quota) is a non-retryable failure, and that's
 * precisely when falling through to B matters most.
 *
 * Constructed via config - see translagenius.providers.*.driver /
 * translagenius.providers.*.chain and TranslaGeniusServiceProvider - rather
 * than ever being instantiated directly outside that resolution path.
 *
 * @see TranslationProviderInterface
 * @see AllProvidersFailedException
 */
final class FallbackProvider implements BatchTranslationProviderInterface
{
    /** @var array<int, TranslationProviderInterface> */
    protected array $providers;

    /**
     * @param  array<int, TranslationProviderInterface>  $providers  Ordered
     *                                                               chain, tried first-to-last. At least 2 required - a single
     *                                                               provider needs no fallback wrapper.
     *
     * @throws InvalidArgumentException If fewer than 2 providers are given,
     *                                  or any element doesn't implement TranslationProviderInterface.
     */
    public function __construct(array $providers)
    {
        if (count($providers) < 2) {
            throw new InvalidArgumentException(
                'FallbackProvider requires at least 2 providers in its chain, got '.count($providers).'.'
            );
        }

        foreach ($providers as $provider) {
            if (! $provider instanceof TranslationProviderInterface) {
                throw new InvalidArgumentException(
                    'Every entry in a FallbackProvider chain must implement TranslationProviderInterface, got '
                    .get_debug_type($provider).'.'
                );
            }
        }

        $this->providers = array_values($providers);
    }

    /**
     * Try each provider in order and return the first success. Providers
     * after the first success are never called - see this class's
     * docblock for why fallback happens on any failure, not only a
     * retryable one.
     *
     *
     * @throws AllProvidersFailedException If every provider in the chain fails.
     */
    public function translate(TranslationRequest $request): TranslationResult
    {
        $failures = [];

        foreach ($this->providers as $position => $provider) {
            try {
                $result = $provider->translate($request);

                if ($position > 0) {
                    Log::info('FallbackProvider: request served by a non-primary provider', [
                        'provider' => get_class($provider),
                        'position' => $position,
                        'prior_failures' => count($failures),
                    ]);
                }

                return $result;
            } catch (TranslationFailedException $e) {
                Log::warning('FallbackProvider: provider failed, trying next in chain', [
                    'provider' => get_class($provider),
                    'position' => $position,
                    'status_code' => $e->getStatusCode(),
                    'retryable' => $e->isRetryable(),
                    'message' => $e->getMessage(),
                ]);

                $failures[] = [
                    'provider' => get_class($provider),
                    'message' => $e->getMessage(),
                    'status_code' => $e->getStatusCode(),
                    'retryable' => $e->isRetryable(),
                ];
            }
        }

        throw new AllProvidersFailedException($failures);
    }

    /**
     * True if at least one member can batch right now.
     */
    public function supportsBatching(): bool
    {
        foreach ($this->providers as $provider) {
            if ($this->canBatch($provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The batch rule for a chain:
     *
     * - Members that can batch are tried in order; the first one that returns
     *   wins, even with a partial batch. Its missing locales are then
     *   translated one at a time through the whole chain (translate()), so
     *   they get every member's chance, primary first.
     * - A member that throws (an HTTP failure) or returns a truncated reply
     *   hands over to the next batch-capable member, exactly like translate().
     *   A truncated attempt's tokens aren't carried over: like any failed
     *   attempt in a chain, it isn't logged.
     * - Members that can't batch are skipped here; they still serve the
     *   one-at-a-time calls.
     * - If no batch attempt succeeds: when some member can't batch, or any
     *   attempt was truncated, an empty batch is returned so every locale goes
     *   through the chain one at a time; when every member could batch and
     *   every attempt threw, AllProvidersFailedException.
     *
     *
     * @throws AllProvidersFailedException
     */
    public function translateBatch(TranslationBatchRequest $request): TranslationBatchResult
    {
        $failures = [];
        $hasSingleOnlyMember = false;
        $anyTruncated = false;

        foreach ($this->providers as $position => $provider) {
            if (! $this->canBatch($provider)) {
                $hasSingleOnlyMember = true;

                continue;
            }

            try {
                $result = $provider->translateBatch($request);

                if (! $result->truncated) {
                    return $result;
                }

                $anyTruncated = true;
                Log::warning('FallbackProvider: provider returned a truncated batch, trying next in chain', [
                    'provider' => get_class($provider),
                    'position' => $position,
                ]);
            } catch (TranslationFailedException $e) {
                Log::warning('FallbackProvider: provider failed a batch, trying next in chain', [
                    'provider' => get_class($provider),
                    'position' => $position,
                    'status_code' => $e->getStatusCode(),
                    'retryable' => $e->isRetryable(),
                    'message' => $e->getMessage(),
                ]);

                $failures[] = [
                    'provider' => get_class($provider),
                    'message' => $e->getMessage(),
                    'status_code' => $e->getStatusCode(),
                    'retryable' => $e->isRetryable(),
                ];
            }
        }

        if ($hasSingleOnlyMember || $anyTruncated || $failures === []) {
            return TranslationBatchResult::empty();
        }

        throw new AllProvidersFailedException($failures);
    }

    protected function canBatch(TranslationProviderInterface $provider): bool
    {
        return $provider instanceof BatchTranslationProviderInterface && $provider->supportsBatching();
    }
}
