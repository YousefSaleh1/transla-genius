<?php

namespace CodingPartners\TranslaGenius\Support;

/**
 * The app's configured locale (`app.locale`), read when it's used, so a
 * multi-tenant app can set it per tenant before a job runs.
 *
 * app()->setLocale() also writes `app.locale`, so the value in config may
 * be a request's locale, or one a job switched to, rather than the
 * configured one. setLocale() announces itself (LocaleUpdated), so this
 * class knows which value it wrote: while config still holds that value,
 * the last configured value applies; once something else is written to
 * config directly (a tenant hook), that is the configured locale.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class ConfiguredLocale
{
    /** The value the last setLocale() wrote, if any. */
    private ?string $lastSet = null;

    public function __construct(private string $configured) {}

    /** Called for every LocaleUpdated event. */
    public function localeWasSet(string $locale, ?string $previous): void
    {
        // Config held a value setLocale() didn't write: it was configured.
        // (Laravel 12.0 doesn't report the previous locale.)
        if ($previous !== null && $previous !== $this->lastSet) {
            $this->configured = $previous;
        }

        $this->lastSet = $locale;
    }

    public function get(): string
    {
        $current = (string) config('app.locale', $this->configured);

        if ($this->lastSet !== null && $current === $this->lastSet) {
            return $this->configured;
        }

        return $this->configured = $current;
    }
}
