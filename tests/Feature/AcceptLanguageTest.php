<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Middleware\SetLocale;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * SetLocale reads Accept-Language per RFC 9110 (section 12.5.4): the
 * highest q-value wins, ties keep header order, q=0 means "not this one",
 * and `*` means any. Configured locales match case-insensitively and are
 * returned exactly as configured; a region tag falls back to its primary
 * language when only that is configured. Nothing usable: the first
 * configured locale.
 */
class AcceptLanguageTest extends TestCase
{
    /** @return array<string, array{string|null, string}> */
    public static function headers(): array
    {
        // Configured: en (default), ar, pt-BR, fr
        return [
            'single exact' => ['ar', 'ar'],
            'q-values beat order' => ['fr;q=0.1, ar;q=0.9', 'ar'],
            'equal q keeps header order' => ['fr;q=0.5, ar;q=0.5', 'fr'],
            'no q means 1' => ['ar;q=0.8, fr', 'fr'],
            'region tag, any case' => ['PT-br', 'pt-BR'],
            'region tag exactly as configured' => ['pt-BR,en;q=0.5', 'pt-BR'],
            'region falls back to its primary language' => ['fr-CA', 'fr'],
            'unsupported then supported' => ['de-DE, de;q=0.9, ar;q=0.7', 'ar'],
            'q=0 excludes, so the next one wins' => ['ar;q=0, fr;q=0.2', 'fr'],
            'wildcard is the first configured locale not excluded' => ['de, *;q=0.5', 'en'],
            'wildcard skips an excluded locale' => ['en;q=0, *', 'ar'],
            'only exclusions' => ['ar;q=0', 'en'],
            'spaces and empty items' => [' ,  ar ; q=0.7 ,, ', 'ar'],
            'malformed q is ignored' => ['ar;q=abc, fr;q=0.3', 'fr'],
            'q above 1 is ignored' => ['ar;q=2, fr;q=0.3', 'fr'],
            'malformed tag is ignored' => ['ar_EG!, fr;q=0.4', 'fr'],
            'nothing supported' => ['de, ja', 'en'],
            'garbage' => [';;;q=;', 'en'],
            'empty header' => ['', 'en'],
            'no header' => [null, 'en'],
        ];
    }

    #[DataProvider('headers')]
    public function test_accept_language(?string $header, string $expected)
    {
        config(['translagenius.supported_languages' => ['en', 'ar', 'pt-BR', 'fr']]);
        app()->setLocale('xx');

        $request = new Request;
        if ($header !== null) {
            $request->headers->set('Accept-Language', $header);
        }

        app(SetLocale::class)->handle($request, fn () => new Response);

        $this->assertSame($expected, app()->getLocale());
    }
}
