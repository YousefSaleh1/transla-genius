<?php

namespace CodingPartners\TranslaGenius\Tests\Unit;

use CodingPartners\TranslaGenius\Services\TranslationWriter;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * TranslationWriter's guard clause, on the two methods the job writes
 * through. Pure PHP, no framework bootstrap: the guard fires before any
 * query is built, so no database connection is ever touched for a model
 * that fails it.
 */
class TranslationWriterTest extends TestCase
{
    public function test_fill_missing_rejects_a_model_that_does_not_use_the_translatable_trait()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must use the .*Translatable trait/');

        (new TranslationWriter)->fillMissing(new class extends Model {}, 'name', 'ar', 'x', ['locale' => 'en', 'text' => 'y']);
    }

    public function test_apply_retranslation_rejects_a_model_that_does_not_use_the_translatable_trait()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/must use the .*Translatable trait/');

        (new TranslationWriter)->applyRetranslation(new class extends Model {}, 'name', 'ar', 'x', ['locale' => 'en', 'text' => 'y'], null);
    }

    /** The job writes only through these two; nothing else is public. */
    public function test_the_public_methods_are_the_ones_the_job_uses()
    {
        $methods = array_map(
            fn (\ReflectionMethod $m) => $m->getName(),
            (new \ReflectionClass(TranslationWriter::class))->getMethods(\ReflectionMethod::IS_PUBLIC)
        );
        sort($methods);

        $this->assertSame(['applyRetranslation', 'fillMissing'], $methods);
    }
}
