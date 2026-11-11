<?php

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;

if (! function_exists('get_current_locale')) {
    /**
     * Get the current application locale.
     *
     * Thin wrapper around the bound LocaleResolverInterface - kept for
     * backward compatibility (every part of this package used these
     * function names before the interface existed), not the seam itself.
     * To change locale-resolution behavior, rebind LocaleResolverInterface
     * rather than redefining this function.
     *
     * @return string
     */
    function get_current_locale()
    {
        return app(LocaleResolverInterface::class)->currentLocale();
    }
}

if (! function_exists('get_supported_languages')) {
    /**
     * Get all supported languages except the current locale.
     *
     * Thin wrapper around the bound LocaleResolverInterface - see
     * get_current_locale() for why this exists as a function rather than
     * requiring callers to resolve the interface themselves.
     *
     * @param  string|null  $excludeLang  Language to exclude (default: current locale)
     * @return array<int, string>
     */
    function get_supported_languages(?string $excludeLang = null)
    {
        $resolver = app(LocaleResolverInterface::class);
        $excludeLang = $excludeLang ?? $resolver->currentLocale();

        return array_values(array_diff($resolver->supportedLocales(), [$excludeLang]));
    }
}
