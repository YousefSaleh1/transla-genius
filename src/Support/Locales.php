<?php

namespace CodingPartners\TranslaGenius\Support;

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;

/**
 * The package's own way to read locales: always through the bound
 * LocaleResolverInterface. Internals never call the global
 * get_current_locale() / get_supported_languages() helpers, which an app may
 * have defined itself under the same names.
 *
 * @internal
 */
final class Locales
{
    public static function current(): string
    {
        return app(LocaleResolverInterface::class)->currentLocale();
    }

    /**
     * Every supported locale except $source (null: the current locale): the
     * locales a text written in $source is translated into.
     *
     * @return array<int, string>
     */
    public static function targets(?string $source = null): array
    {
        $resolver = app(LocaleResolverInterface::class);
        $source ??= $resolver->currentLocale();

        return array_values(array_diff($resolver->supportedLocales(), [$source]));
    }
}
