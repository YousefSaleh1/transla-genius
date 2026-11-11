<?php

namespace CodingPartners\TranslaGenius\Support;

use Illuminate\Contracts\Database\Query\Expression as ExpressionContract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Expression;

/**
 * A column cast to a string type, in the active driver's own syntax.
 *
 * translation_reviews.translatable_id is a string column so that integer,
 * UUID and ULID keys all fit. Comparing it to an owner's integer key inside
 * SQL needs one side cast; PostgreSQL rejects varchar = bigint outright.
 * Casting the *owner key to a string* is the direction that works for every
 * key type (a string key casts to itself), and it leaves translatable_id
 * uncast, so the review table's owner index still serves the lookup. There's
 * no CAST target all five drivers share, hence one per driver.
 */
final class KeyCast
{
    public static function asString(Connection $connection, string $column): ExpressionContract
    {
        $wrapped = $connection->getQueryGrammar()->wrap($column);

        $type = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'CHAR',
            'pgsql' => 'VARCHAR',
            'sqlsrv' => 'NVARCHAR(64)',
            default => 'TEXT', // sqlite and anything else that follows its rules
        };

        return new Expression("CAST({$wrapped} AS {$type})"); // @phpstan-ignore argument.type (not a literal string, but safe: wrap() quotes the column, and $type is one of the four fixed strings above)
    }
}
