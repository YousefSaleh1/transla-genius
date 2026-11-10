<?php

namespace CodingPartners\TranslaGenius\LocaleResolvers;

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;

/**
 * The resolution strategy this package always used before
 * LocaleResolverInterface existed: the current Laravel app locale, and
 * whatever translaGenius.supported_languages is configured to. This is the
 * single place that config key is read from - SetLocale and the
 * get_current_locale()/get_supported_languages() helpers all go through
 * this (via the bound interface) rather than reading config() themselves.
 */
class DefaultLocaleResolver implements LocaleResolverInterface
{
    /**
     * The locale content is currently being read/written in, read from
     * Laravel's own app()->getLocale().
     *
     * @return string
     */
    public function currentLocale(): string
    {
        return app()->getLocale();
    }

    /**
     * Every locale the package is configured to support, read from
     * translaGenius.supported_languages.
     *
     * @return array<int, string>
     */
    public function supportedLocales(): array
    {
        return config('translaGenius.supported_languages', ['en']);
    }
}
