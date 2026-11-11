<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Services\TranslationWriter;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for TranslationWriter's Phase 4a Task 5 guard clause.
 *
 * Pure PHP, no framework bootstrap - the guard fires before any query is
 * built (no DB connection is ever touched for a model that fails it), so
 * this doesn't need Eloquent's connection resolver set up at all.
 */
class TranslationWriterTest extends TestCase
{
    public function test_rejects_a_model_that_does_not_use_the_translatable_trait()
    {
        $model = new class extends Model {};

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must use the .*Translatable trait/');

        (new TranslationWriter)->apply($model, ['name' => ['ar' => 'test']]);
    }

    public function test_empty_translations_short_circuits_before_the_guard()
    {
        // Passing an empty array is a no-op regardless of the model - the
        // guard (and any DB interaction) is never reached.
        $model = new class extends Model {};

        (new TranslationWriter)->apply($model, []);

        $this->addToAssertionCount(1); // reaching here without an exception is the assertion
    }
}
