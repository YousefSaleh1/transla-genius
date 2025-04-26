<?php

namespace CodingPartners\TranslaGenius\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request and set application locale.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLanguages = config('translaGenius.supported_languages', ['en']);
        $defaultLanguage = $supportedLanguages[0];

        $requestedLanguages = $this->parseAcceptLanguageHeader($request->header('Accept-Language'));

        $locale = $defaultLanguage;

        foreach ($requestedLanguages as $lang) {
            if (in_array($lang, $supportedLanguages)) {
                $locale = $lang;
                break;
            }
        }

        app()->setLocale($locale);

        return $next($request);
    }

    /**
     * Parse the Accept-Language header into an array of languages.
     *
     * @param  string|null  $header
     * @return array
     */
    protected function parseAcceptLanguageHeader(?string $header): array
    {
        if (empty($header)) {
            return [];
        }

        $languages = [];

        foreach (explode(',', $header) as $part) {
            $lang = explode(';', $part)[0];
            $languages[] = strtolower(trim($lang));
        }

        return $languages;
    }
}
