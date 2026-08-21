<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Exceptions\TranslationFailedException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for TranslationFailedException.
 *
 * Pure PHP, no framework bootstrap required - the exception class carries
 * no Laravel dependency of its own.
 */
class TranslationFailedExceptionTest extends TestCase
{
    public function test_defaults_to_retryable_with_no_status_or_snippet()
    {
        $e = new TranslationFailedException('Something went wrong');

        $this->assertSame('Something went wrong', $e->getMessage());
        $this->assertNull($e->getStatusCode());
        $this->assertNull($e->getResponseSnippet());
        $this->assertTrue($e->isRetryable());
    }

    public function test_carries_status_code_snippet_and_retryable_flag()
    {
        $e = new TranslationFailedException(
            'Request failed with status 401',
            statusCode: 401,
            responseSnippet: 'Invalid API key',
            retryable: false
        );

        $this->assertSame(401, $e->getStatusCode());
        $this->assertSame('Invalid API key', $e->getResponseSnippet());
        $this->assertFalse($e->isRetryable());
    }

    public function test_preserves_previous_exception_for_debugging()
    {
        $previous = new RuntimeException('Connection reset');
        $e = new TranslationFailedException('Unable to connect', previous: $previous);

        $this->assertSame($previous, $e->getPrevious());
    }

    public function test_is_a_runtime_exception()
    {
        $this->assertInstanceOf(RuntimeException::class, new TranslationFailedException('x'));
    }
}
