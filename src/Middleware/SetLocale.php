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
 * The request locale also becomes the source locale of the translation jobs
 * the request dispatches (unless TRANSLATION_SOURCE_LOCALE is set), so this
 * decides which text the package translates from.
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
     * The request's preferences are tried from the highest q-value down (ties
     * in header order). Each matches a supported locale case-insensitively,
     * or, for a region tag such as `pt-BR`, its primary language (`pt`) when
     * only that is supported. `*` matches the first supported locale the
     * header didn't exclude with q=0. With no match, the first supported
     * locale (the configured default) is used. The locale is set exactly as
     * configured, whatever case the header used.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $supported = array_values($this->resolver->supportedLocales());

        app()->setLocale($this->negotiate($request->header('Accept-Language'), $supported) ?? $supported[0]);

        return $next($request);
    }

    /**
     * The supported locale the header asks for, or null if it asks for none.
     *
     * @param  array<int, string>  $supported
     */
    protected function negotiate(?string $header, array $supported): ?string
    {
        $preferences = $this->parseAcceptLanguage($header);

        $excluded = [];
        foreach ($preferences as [$range, $q]) {
            if ($q === 0.0 && $range !== '*') {
                $excluded[] = $range;
            }
        }

        $byLowercase = [];
        foreach ($supported as $locale) {
            $byLowercase[strtolower($locale)] ??= $locale;
        }

        foreach ($preferences as [$range, $q]) {
            if ($q === 0.0) {
                continue;
            }

            if ($range === '*') {
                foreach ($supported as $locale) {
                    if (! in_array(strtolower($locale), $excluded, true)) {
                        return $locale;
                    }
                }

                continue;
            }

            if (isset($byLowercase[$range])) {
                return $byLowercase[$range];
            }

            $primary = explode('-', $range)[0];
            if ($primary !== $range && isset($byLowercase[$primary]) && ! in_array($primary, $excluded, true)) {
                return $byLowercase[$primary];
            }
        }

        return null;
    }

    /**
     * The header's language ranges with their q-values, highest first (ties
     * keep header order), lowercased. Malformed entries - an invalid range,
     * or a q-value that isn't 0 to 1 with at most three decimals - are
     * dropped, as RFC 9110 asks.
     *
     * @return array<int, array{0: string, 1: float}>
     */
    protected function parseAcceptLanguage(?string $header): array
    {
        $preferences = [];

        foreach (explode(',', (string) $header) as $position => $item) {
            $parts = array_map('trim', explode(';', $item));
            $range = strtolower(array_shift($parts));

            if ($range === '' || preg_match('/^(\*|[a-z]{1,8}(-[a-z0-9]{1,8})*)$/', $range) !== 1) {
                continue;
            }

            $q = 1.0;
            foreach ($parts as $parameter) {
                if (preg_match('/^q\s*=\s*(.*)$/i', $parameter, $m) !== 1) {
                    continue;
                }

                if (preg_match('/^(0(\.\d{0,3})?|1(\.0{0,3})?)$/', $m[1]) !== 1) {
                    continue 2;
                }

                $q = (float) $m[1];
            }

            $preferences[] = [$range, $q, $position];
        }

        usort($preferences, fn (array $a, array $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);

        return array_map(fn (array $p) => [$p[0], $p[1]], $preferences);
    }

    /**
     * The header's acceptable language ranges, most preferred first, without
     * the excluded (q=0) ones. Kept for subclasses of the 3.x middleware.
     *
     * @return array<int, string>
     */
    protected function parseAcceptLanguageHeader(?string $header): array
    {
        return array_values(array_map(
            fn (array $p) => $p[0],
            array_filter($this->parseAcceptLanguage($header), fn (array $p) => $p[1] > 0.0)
        ));
    }
}
