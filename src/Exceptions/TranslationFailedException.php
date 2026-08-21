<?php

namespace CodingPartners\TranslaGenius\Exceptions;

use RuntimeException;
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
class TranslationFailedException extends RuntimeException
{
    public function __construct(
        string $message,
        protected readonly ?int $statusCode = null,
        protected readonly ?string $responseSnippet = null,
        protected readonly bool $retryable = true,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getStatusCode(): ?int
    {
        return $this->statusCode;
    }

    public function getResponseSnippet(): ?string
    {
        return $this->responseSnippet;
    }

    public function isRetryable(): bool
    {
        return $this->retryable;
    }
}
