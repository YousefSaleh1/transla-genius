<?php

namespace CodingPartners\TranslaGenius\Middleware;

use Closure;
use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the application locale for the current request from its
 * Accept-Language header, constrained to the resolver's supported locales.
 *
 * @see LocaleResolverInterface
 */
class SetLocale
{
    /**
     * @param  LocaleResolverInterface  $resolver  Supplies the supported
     *                                             locale list this middleware matches Accept-Language against.
     */
    public function __construct(protected LocaleResolverInterface $resolver) {}

    /**
     * Handle an incoming request and set application locale.
     *
     * Picks the first of the request's Accept-Language preferences that is
     * in the resolver's supported locale list; falls back to the first
     * supported locale (the configured default) if none match.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supportedLanguages = $this->resolver->supportedLocales();
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
