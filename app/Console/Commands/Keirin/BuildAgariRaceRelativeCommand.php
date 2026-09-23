<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\AgariRaceRelative\Builder;
use Illuminate\Console\Command;
use Throwable;

final class BuildAgariRaceRelativeCommand extends Command
{
    protected $signature = 'keirin:stat35:race-relative:build {--input-dir=} {--master-version=} {--output-dir=}';

    protected $description = 'Build descriptive within-race agari metrics from sealed files, without DB or HTTP.';

    public function handle(Builder $builder): int
    {
        try {
            $report = $builder->build((string) $this->option('input-dir'), (string) $this->option('master-version'), (string) $this->option('output-dir'));
            $this->line(json_encode(['status' => 'BUILT', ...$report['totals'], 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
