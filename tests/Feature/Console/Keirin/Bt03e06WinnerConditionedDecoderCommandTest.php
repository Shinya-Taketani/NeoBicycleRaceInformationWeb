<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Keirin;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bt03e06WinnerConditionedDecoderCommandTest extends TestCase
{
    public function test_plan_prints_the_frozen_contract_without_database_access(): void
    {
        $queries = 0;
        DB::listen(static function () use (&$queries): void {
            $queries++;
        });

        $this->artisan('keirin:backtest:bt03e06', ['--plan' => true])
            ->expectsOutputToContain('BT-03E-06 PLAN')
            ->expectsOutputToContain('decoder_version=BT03E06-WINNER-CONDITIONED-SEQUENTIAL-v1')
            ->expectsOutputToContain('model_fitting=FORBIDDEN')
            ->expectsOutputToContain('2026_access=FORBIDDEN')
            ->assertSuccessful();

        $this->assertSame(0, $queries);
    }

    public function test_exactly_one_mode_and_execute_source_are_required(): void
    {
        $this->artisan('keirin:backtest:bt03e06')->assertFailed();
        $this->artisan('keirin:backtest:bt03e06', ['--plan' => true, '--execute' => true])->assertFailed();
        $this->artisan('keirin:backtest:bt03e06', ['--execute' => true])->assertFailed();
    }
}
