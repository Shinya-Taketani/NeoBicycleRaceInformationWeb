<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\AgariRaceRelative\Exporter;
use Illuminate\Console\Command;
use Throwable;

final class ExportAgariRaceRelativeCommand extends Command
{
    protected $signature = 'keirin:stat35:race-relative:export {--from=} {--to=} {--chunk=200} {--output-dir=}';

    protected $description = 'Export 2022-2025 current agari results in one read-only snapshot.';

    public function handle(Exporter $exporter): int
    {
        try {
            $report = $exporter->export((string) $this->option('from'), (string) $this->option('to'),
                (int) $this->option('chunk'), (string) $this->option('output-dir'));
            $this->line(json_encode(['status' => 'EXPORTED', 'race_count' => $report['race_count'], 'result_count' => $report['result_count'],
                'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
