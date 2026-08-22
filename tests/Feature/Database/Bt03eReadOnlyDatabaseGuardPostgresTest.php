<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\Services\Bt03eReadOnlyDatabaseGuard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bt03eReadOnlyDatabaseGuardPostgresTest extends TestCase
{
    public function test_postgresql_rejects_update_and_insert_inside_the_read_only_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL READ ONLY enforcement test.');
        }

        $before = DB::table('backtest_runs')->count();
        foreach ([
            'UPDATE backtest_runs SET status = status WHERE id = -9223372036854775807',
            'INSERT INTO backtest_runs (id) VALUES (-9223372036854775807)',
        ] as $sql) {
            $guard = new Bt03eReadOnlyDatabaseGuard;
            $guard->begin();
            $setting = DB::selectOne('SHOW transaction_read_only');
            $this->assertSame('on', $setting->transaction_read_only);

            try {
                DB::statement($sql);
                $this->fail('PostgreSQL accepted a write inside the BT-03E READ ONLY transaction.');
            } catch (QueryException $exception) {
                $this->assertSame('25006', $exception->getCode());
            } finally {
                $audit = $guard->rollback();
            }

            $this->assertTrue($audit['db_read_only_transaction']);
            $this->assertTrue($audit['db_transaction_rolled_back']);
            $this->assertSame(0, DB::connection()->transactionLevel());
        }
        $this->assertSame($before, DB::table('backtest_runs')->count());
    }
}
