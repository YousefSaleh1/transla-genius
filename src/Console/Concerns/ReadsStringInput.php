<?php

namespace CodingPartners\TranslaGenius\Console\Concerns;

/**
 * Console input as a string or null. argument()/option() are typed as any
 * input value (an array for array options, a bool for flags); the package's
 * commands only take single string values, and treat an empty one as absent.
 *
 * @internal Package wiring; not covered by the backward compatibility promise.
 */
trait ReadsStringInput
{
    protected function stringArgument(string $name): ?string
    {
        return $this->stringOrNull($this->argument($name));
    }

    protected function stringOption(string $name): ?string
    {
        return $this->stringOrNull($this->option($name));
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
