<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Middleware\SetLocale;
use CodingPartners\TranslaGenius\Tests\TestCase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Feature tests for SetLocale middleware
 *
 * Tests the behavior of the SetLocale middleware which handles:
 * - Language setting from request headers
 * - Default language fallback
 * - Unsupported language handling
 */
class MiddlewareTest extends TestCase
{
    /**
     * Test that middleware sets locale from Accept-Language header
     *
     * Verifies that:
     * 1. Middleware correctly extracts language from header
     * 2. Sets application locale to supported language (ar)
     * 3. Continues request handling
     *
     * @return void
     */
    public function test_middleware_sets_locale_from_header()
    {
        $middleware = $this->app->make(SetLocale::class);
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'ar');

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $this->assertEquals('ar', app()->getLocale());
    }

    /**
     * Test that middleware falls back to default locale for unsupported languages
     *
     * Verifies that:
     * 1. Middleware detects unsupported language (fr)
     * 2. Falls back to default locale (en)
     * 3. Continues request handling
     *
     * @return void
     */
    public function test_middleware_sets_default_locale_for_unsupported_language()
    {
        $middleware = $this->app->make(SetLocale::class);
        $request = Request::create('/', 'GET');
        $request->headers->set('Accept-Language', 'fr'); // French not in supported languages

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $this->assertEquals('en', app()->getLocale());
    }

    /**
     * Test that middleware sets default locale when no header is present
     *
     * Verifies that:
     * 1. Middleware handles missing Accept-Language header
     * 2. Falls back to default locale (en)
     * 3. Continues request handling
     *
     * @return void
     */
    public function test_middleware_sets_default_locale_when_no_header()
    {
        $middleware = $this->app->make(SetLocale::class);
        $request = Request::create('/', 'GET');

        $response = $middleware->handle($request, function ($req) {
            return new Response();
        });

        $this->assertEquals('en', app()->getLocale());
    }
}
