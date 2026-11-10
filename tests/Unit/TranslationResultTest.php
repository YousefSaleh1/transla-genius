<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\DTOs\TranslationResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for TranslationResult.
 *
 * Pure PHP, no framework bootstrap - the DTO carries no Laravel
 * dependency. Phase 4a Task 4: proves the non-empty-text guarantee is
 * enforced uniformly by the DTO itself, not left to each provider to
 * remember to check.
 */
class TranslationResultTest extends TestCase
{
    public function test_holds_text_and_optional_metadata()
    {
        $result = new TranslationResult('مرحبا', provider: 'openrouter', promptTokens: 10, completionTokens: 3);

        $this->assertSame('مرحبا', $result->text);
        $this->assertSame('openrouter', $result->provider);
        $this->assertSame(10, $result->promptTokens);
        $this->assertSame(3, $result->completionTokens);
    }

    public function test_metadata_defaults_to_null()
    {
        $result = new TranslationResult('Hello');

        $this->assertNull($result->provider);
        $this->assertNull($result->promptTokens);
        $this->assertNull($result->completionTokens);
    }

    public function test_rejects_empty_text()
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationResult('');
    }

    public function test_rejects_whitespace_only_text()
    {
        $this->expectException(InvalidArgumentException::class);

        new TranslationResult('   ');
    }
}
