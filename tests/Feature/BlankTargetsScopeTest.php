<?php

namespace CodingPartners\TranslaGenius\Tests\Feature;

use CodingPartners\TranslaGenius\Tests\TestCase;
use CodingPartners\TranslaGenius\Tests\TestModels\TestTranslatableModel;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The scopes use the job's rule for a missing translation: null, an empty
 * string, or whitespace only. So a blank target is notFullyTranslated, and
 * backfill repairs it. Runs on whichever driver the suite is pointed at.
 */
class BlankTargetsScopeTest extends TestCase
{
    /** @return array<string, array{mixed}> */
    public static function blankTargets(): array
    {
        return [
            'empty string' => [''],
            'spaces' => ['   '],
            'tab and newline' => ["\t\n"],
            'json null' => [null],
        ];
    }

    protected function insert(mixed $ar): int
    {
        DB::table('test_translatable_models')->insert([
            'name' => json_encode(['en' => 'Lamp', 'ar' => $ar, 'es' => 'Lámpara']),
            'description' => json_encode(['en' => 'Bright', 'ar' => 'ساطع', 'es' => 'Brillante']),
        ]);

        return (int) DB::table('test_translatable_models')->max('id');
    }

    #[DataProvider('blankTargets')]
    public function test_a_blank_target_is_not_fully_translated_and_backfill_repairs_it(mixed $ar)
    {
        $id = $this->insert($ar);

        $this->assertSame([$id], TestTranslatableModel::notFullyTranslated('en')->pluck('id')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(0, TestTranslatableModel::fullyTranslated('en')->count());

        Http::fake(fn (Request $r) => Http::response(['choices' => [['message' => ['content' => 'مصباح'], 'finish_reason' => 'stop']]]));
        $this->artisan('translagenius:backfill', ['model' => TestTranslatableModel::class, '--source' => 'en', '--force' => true])
            ->expectsOutputToContain('Found 1 [')
            ->assertSuccessful();

        $this->assertSame('مصباح', TestTranslatableModel::find($id)->getTranslation('name', 'ar', false));
        $this->assertSame(1, TestTranslatableModel::fullyTranslated('en')->count());
        $this->assertSame(0, TestTranslatableModel::notFullyTranslated('en')->count());
    }

    public function test_real_values_count_as_translated()
    {
        foreach (['0', ' x ', 'نص'] as $value) {
            $this->insert($value);
        }

        $this->assertSame(3, TestTranslatableModel::fullyTranslated('en')->count());
        $this->assertSame(0, TestTranslatableModel::notFullyTranslated('en')->count());
    }

    /** @return array<string, array{string, string}> */
    public static function drivers(): array
    {
        return [
            'mysql' => ['mysql', "json_unquote(json_extract(`name`, '$.\"ar\"')) REGEXP ?"],
            'mariadb' => ['mariadb', "json_value(`name`, '$.\"ar\"') REGEXP ?"],
            'pgsql' => ['pgsql', "\"name\"->>'ar' ~ ?"],
            'sqlite' => ['sqlite', "trim(json_extract(\"name\", '$.\"ar\"'), char(32, 9, 10, 11, 13)) = ''"],
            'sqlsrv' => ['sqlsrv', "TRIM(CHAR(32) + CHAR(9) + CHAR(10) + CHAR(11) + CHAR(13) FROM json_value([name], '$.\"ar\"')) = ''"],
        ];
    }

    /** The blank check each driver gets, compiled without a server (MariaDB and SQL Server run only in CI). */
    #[DataProvider('drivers')]
    public function test_compiles_a_blank_check_per_driver(string $driver, string $expected)
    {
        config(["database.connections.blank_{$driver}" => [
            'driver' => $driver, 'host' => '127.0.0.1', 'database' => $driver === 'sqlite' ? ':memory:' : 'x',
            'username' => 'x', 'password' => '',
        ]]);
        $connection = DB::connection("blank_{$driver}");
        $connection->setPdo(new \PDO('sqlite::memory:')); // nothing is executed

        $sql = (new TestTranslatableModel)->setConnection("blank_{$driver}")->newQuery()->notFullyTranslated('en')->toBase()->toSql();

        $this->assertStringContainsString($expected, $sql);

        DB::purge("blank_{$driver}");
    }
}
