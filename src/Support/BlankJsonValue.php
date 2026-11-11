<?php

namespace CodingPartners\TranslaGenius\Support;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;

/**
 * "This JSON value is a blank string", per driver, for the scopes. With
 * whereNull() on the same path (null), it matches what Support\Text::isBlank()
 * calls missing: an empty string or whitespace only (space, tab, LF, CR and
 * vertical tab: the characters PHP's trim() strips, except NUL). There's no
 * portable SQL for that, so each driver gets its own expression around the
 * grammar's own JSON extraction of the path.
 *
 * @internal Support\ is package wiring; not covered by the backward compatibility promise.
 */
final class BlankJsonValue
{
    /**
     * Add "$path is blank" ($blank true) or "$path is not blank" to $query.
     *
     * @template TBuilder of Builder|EloquentBuilder<*>
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function where(Builder|EloquentBuilder $query, string $path, bool $blank, string $boolean = 'and'): Builder|EloquentBuilder
    {
        $base = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
        $value = $base->getGrammar()->wrap($path);
        $connection = $base->getConnection();
        $driver = $connection instanceof Connection ? $connection->getDriverName() : 'sqlite';

        [$sql, $bindings] = match ($driver) {
            'mysql', 'mariadb' => ["{$value} REGEXP ?", ['^[[:space:]]*$']],
            'pgsql' => ["{$value} ~ ?", ['^[[:space:]]*$']],
            'sqlsrv' => ["TRIM(CHAR(32) + CHAR(9) + CHAR(10) + CHAR(11) + CHAR(13) FROM {$value}) = ''", []],
            default => ["trim({$value}, char(32, 9, 10, 11, 13)) = ''", []], // sqlite
        };

        $sql = $blank ? $sql : "NOT ({$sql})";

        // @phpstan-ignore argument.type (not a literal string, but safe: the grammar quotes the path, the rest is fixed text per driver, and the pattern is bound)
        $query->whereRaw($sql, $bindings, $boolean);

        return $query;
    }
}
