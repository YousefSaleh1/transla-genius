<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Models\TranslationReview;
use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\UlidTranslatableModel;
use CodingPartners\TranslaGenius\Tests\TestModels\UuidTranslatableModel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * pendingReview() is one correlated subquery - no list of ids is bound - and
 * compares the string translatable_id to the owner's key portably, for
 * integer, UUID and ULID keys.
 */
class PendingReviewScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (include __DIR__.'/../../src/database/migrations/create_translation_reviews_table.php.stub')->up();
        Schema::create('test_string_key_models', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->json('name');
            $table->json('description');
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('translation_reviews');
        Schema::dropIfExists('test_string_key_models');

        parent::tearDown();
    }

    /** A pending (or other) review row for $owner, written directly. */
    protected function reviewFor($owner, string $field = 'name', string $locale = 'ar', string $status = 'pending'): void
    {
        DB::table('translation_reviews')->insert($this->reviewRow($owner->getMorphClass(), (string) $owner->getKey(), $field, $locale, $status));
    }

    protected function reviewRow(string $type, string $id, string $field = 'name', string $locale = 'ar', string $status = 'pending'): array
    {
        return [
            'translatable_type' => $type, 'translatable_id' => $id, 'field' => $field, 'locale' => $locale,
            'status' => $status, 'text' => 'x', 'source_locale' => 'en', 'source_text' => 'y', 'source_hash' => 'h',
            'published_snapshot' => '', 'cached' => false, 'created_at' => now(), 'updated_at' => now(),
        ];
    }

    protected function owners(string $class, int $count): array
    {
        return $class::withoutEvents(fn () => collect(range(1, $count))
            ->map(fn ($i) => $class::create(['name' => ['en' => "n{$i}"], 'description' => ['en' => "d{$i}"]]))
            ->all());
    }

    // --- compiled SQL on all five grammars -------------------------------------

    public static function drivers(): array
    {
        return [
            'mysql' => ['mysql', 'CAST(`test_translatable_models`.`id` AS CHAR)'],
            'mariadb' => ['mariadb', 'CAST(`test_translatable_models`.`id` AS CHAR)'],
            'pgsql' => ['pgsql', 'CAST("test_translatable_models"."id" AS VARCHAR)'],
            'sqlite' => ['sqlite', 'CAST("test_translatable_models"."id" AS TEXT)'],
            'sqlsrv' => ['sqlsrv', 'CAST([test_translatable_models].[id] AS NVARCHAR(64))'],
        ];
    }

    #[DataProvider('drivers')]
    public function test_compiles_to_a_correlated_subquery_with_no_bound_id_list(string $driver, string $cast)
    {
        config(["database.connections.grammar_{$driver}" => [
            'driver' => $driver, 'host' => '127.0.0.1', 'database' => $driver === 'sqlite' ? ':memory:' : 'x',
            'username' => 'x', 'password' => '',
        ]]);
        $connection = DB::connection("grammar_{$driver}");
        // A stand-in PDO, so no server is contacted; nothing is executed.
        $connection->setPdo(new PDO('sqlite::memory:'));

        $query = (new TestTranslatableModel)->setConnection("grammar_{$driver}")->newQuery()->pendingReview('name', 'ar')->toBase();
        $sql = $query->toSql();

        $this->assertStringContainsStringIgnoringCase('exists (select 1 from', $sql);
        $this->assertStringContainsString($cast, $sql);
        $this->assertStringNotContainsStringIgnoringCase(' in (', $sql);
        // Only the fixed filters are bound - type, status, field, locale - never ids.
        $this->assertSame([(new TestTranslatableModel)->getMorphClass(), 'pending', 'name', 'ar'], $query->getBindings());

        DB::purge("grammar_{$driver}");
    }

    // --- executed: integer, UUID and ULID keys -----------------------------------

    public function test_integer_keys()
    {
        [$a, $b, $c] = $this->owners(TestTranslatableModel::class, 3);
        $this->reviewFor($a);
        $this->reviewFor($b, 'description', 'es');
        $this->reviewFor($c, status: 'approved');

        $this->assertEqualsCanonicalizing([$a->id, $b->id], TestTranslatableModel::pendingReview()->pluck('id')->all());
        $this->assertSame([$a->id], TestTranslatableModel::pendingReview('name')->pluck('id')->all());
        $this->assertSame([$b->id], TestTranslatableModel::pendingReview(null, 'es')->pluck('id')->all());
        $this->assertSame([], TestTranslatableModel::pendingReview('name', 'es')->pluck('id')->all());
    }

    public function test_uuid_keys()
    {
        [$a, $b] = $this->owners(UuidTranslatableModel::class, 2);
        $this->reviewFor($a);

        $this->assertSame([$a->id], UuidTranslatableModel::pendingReview()->pluck('id')->all());
        $this->assertSame(36, strlen($a->id));
    }

    public function test_ulid_keys()
    {
        [$a, $b] = $this->owners(UlidTranslatableModel::class, 2);
        $this->reviewFor($b);

        $this->assertSame([$b->id], UlidTranslatableModel::pendingReview()->pluck('id')->all());
        $this->assertSame(26, strlen($b->id));
    }

    public function test_reviews_of_another_model_type_do_not_match()
    {
        [$owner] = $this->owners(TestTranslatableModel::class, 1);
        DB::table('translation_reviews')->insert($this->reviewRow('App\\Other', (string) $owner->id));

        $this->assertSame(0, TestTranslatableModel::pendingReview()->count());
    }

    // --- larger than SQL Server's parameter limit --------------------------------

    public function test_more_pending_rows_than_sql_servers_parameter_limit()
    {
        $count = 2500;
        $type = (new TestTranslatableModel)->getMorphClass();

        // Let the database assign the owner ids (SQL Server rejects explicit
        // values for an identity column), then read them back.
        foreach (array_chunk(range(1, $count), 50) as $chunk) {
            DB::table('test_translatable_models')->insert(array_map(
                fn ($i) => ['name' => json_encode(['en' => "n{$i}"]), 'description' => json_encode(['en' => ''])],
                $chunk
            ));
        }

        $ids = DB::table('test_translatable_models')->orderBy('id')->pluck('id')->all();
        $this->assertCount($count, $ids);

        foreach (array_chunk($ids, 50) as $chunk) {
            DB::table('translation_reviews')->insert(array_map(fn ($id) => $this->reviewRow($type, (string) $id), $chunk));
        }

        DB::enableQueryLog();
        $this->assertSame($count, TestTranslatableModel::pendingReview()->count());
        $this->assertSame($count, TranslationReview::pending()->count());
        $bindings = collect(DB::getQueryLog())->first()['bindings'];
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(2, count($bindings), 'No id list may be bound.');
    }
}
