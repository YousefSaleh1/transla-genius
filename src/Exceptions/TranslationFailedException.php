<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use Throwable;

/**
 * Raised when a translation request to the external provider cannot be
 * completed. Carries enough context (HTTP status, a bounded response
 * snippet, and whether the failure is worth retrying) for TranslateFields
 * to decide between a queue retry and an immediate permanent failure.
 *
 * Never construct this with the API key, full request/response bodies, or
 * anything else that could belong in a stack trace destined for logs.
 */
class TranslationFailedException extends TranslaGeniusException
{
    /**
     * @param  string  $message  Human-readable failure description (safe to log).
     * @param  int|null  $statusCode  HTTP status code from the provider, if any.
     * @param  string|null  $responseSnippet  A bounded, already-redacted excerpt
     *                                        of the provider's response body/error message, if any.
     * @param  bool  $retryable  Whether TranslateFields should let the queue
     *                           retry this job ($tries/backoff()) rather than failing it
     *                           immediately. Defaults to true (the safer default when a caller
     *                           constructs this without specifying).
     * @param  Throwable|null  $previous  The original exception this wraps, if any.
     * @param  int|null  $retryAfter  Seconds the provider asked us to wait before
     *                                retrying (its Retry-After header), if it sent one.
     */
    public function __construct(
        string $message,
        protected readonly ?int $statusCode = null,
        protected readonly ?string $responseSnippet = null,
        protected readonly bool $retryable = true,
        ?Throwable $previous = null,
        protected readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The HTTP status code returned by the provider, if the failure
     * happened at the HTTP layer.
     */
    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    /**
     * A bounded, redacted excerpt of the provider's response, if captured.
     */
    public function getResponseSnippet(): ?string
    {
        return $this->responseSnippet;
    }

    /**
     * Whether TranslateFields should retry the job for this failure
     * (true) or call $this->fail() immediately (false).
     */
    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * Seconds the provider asked to wait before retrying (from Retry-After),
     * if it said. TranslateFields uses this as the queue release delay.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }
}
