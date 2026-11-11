<?php

namespace CodingPartners\TranslaGenius\Tests\Support;

use CodingPartners\TranslaGenius\Contracts\LocaleResolverInterface;

/**
 * A hand-written LocaleResolverInterface implementation for tests - proves
 * locale resolution is a real, swappable seam (Phase 4a Task 3), not just
 * two global functions with no extension point.
 */
class FakeLocaleResolver implements LocaleResolverInterface
{
    /**
     * @param  array<int, string>  $supported
     */
    public function __construct(protected string $current, protected array $supported) {}

    public function currentLocale(): string
    {
        return $this->current;
    }

    public function supportedLocales(): array
    {
        return $this->supported;
    }
}
