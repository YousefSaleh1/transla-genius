<?php

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;
use CodingPartners\TranslaGenius\Support\Locales;

if (! function_exists('get_current_locale')) {
    /**
     * Get the current application locale.
     *
     * Kept for code that calls it since 3.x. The package itself never calls
     * it: these names are unprefixed, so an app may define its own function
     * under the same name, and this one is then never defined.
     *
     * @deprecated Resolve LocaleResolverInterface and call currentLocale().
     * @see LocaleResolverInterface::currentLocale()
     *
     * @return string
     */
    function get_current_locale()
    {
        return Locales::current();
    }
}

if (! function_exists('get_supported_languages')) {
    /**
     * Get all supported languages except the current locale.
     *
     * Kept for code that calls it since 3.x; see get_current_locale().
     *
     * @deprecated Resolve LocaleResolverInterface and use supportedLocales().
     * @see LocaleResolverInterface::supportedLocales()
     *
     * @param  string|null  $excludeLang  Language to exclude (default: current locale)
     * @return array<int, string>
     */
    function get_supported_languages(?string $excludeLang = null)
    {
        return Locales::targets($excludeLang);
    }
}
