<?php

namespace CodingPartners\TranslaGenius\Contracts;

/**
 * The seam for how "current locale" and "supported locales" are
 * determined. The default implementation (DefaultLocaleResolver) reads the
 * current app locale and the translagenius.supported_languages config -
 * exactly what get_current_locale()/get_supported_languages() and
 * SetLocale did directly before this existed. A consumer wanting a
 * different resolution strategy (subdomain-based, per-user preference,
 * ...) rebinds this interface in their own service provider; nothing else
 * in the package - the trait, the job, SetLocale - needs to change.
 */
interface LocaleResolverInterface
{
    /**
     * The locale content is currently being read/written in.
     *
     * @return string
     */
    public function currentLocale(): string;

    /**
     * Every locale the package is configured to support, in the order
     * configured (the first entry is the default). Does not exclude the
     * current locale - callers that want that do the exclusion themselves
     * (see get_supported_languages()).
     *
     * @return array<int, string>
     */
    public function supportedLocales(): array;
}
