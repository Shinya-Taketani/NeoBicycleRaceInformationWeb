<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\AgariPlayerHistory\Builder;
use Illuminate\Console\Command;
use Throwable;

final class BuildAgariPlayerHistoryCommand extends Command
{
    protected $signature = 'keirin:stat35:player-history:build {--source-input-dir=} {--source-result-dir=} {--output-dir=}';

    protected $description = 'Build descriptive player meeting histories from fixed offline race-relative files.';

    public function handle(Builder $builder): int
    {
        try {
            $report = $builder->build((string) $this->option('source-input-dir'), (string) $this->option('source-result-dir'), (string) $this->option('output-dir'));
            $this->line(json_encode(['status' => 'BUILT', ...$report['totals'], 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            $this->line(json_encode(['status' => 'FAILED', 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
