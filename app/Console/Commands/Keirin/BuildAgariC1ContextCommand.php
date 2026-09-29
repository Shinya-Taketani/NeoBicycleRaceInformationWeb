<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\AgariC1Context\Builder;
use App\Domain\Keirin\Statistics\AgariC1Context\Contract;
use App\Domain\Keirin\Statistics\AgariC1Context\Extractor;
use Illuminate\Console\Command;
use RuntimeException;

final class BuildAgariC1ContextCommand extends Command
{
    protected $signature = 'keirin:stat35:c1-context {mode=plan} {--authorize-read-only-extract} {--output-dir=} {--extraction-dir=} {--original-dir=}';

    protected $description = 'Extract only fixed C1 racecard metadata read-only; build/reproduce unreviewed context offline.';

    public function handle(Extractor $extractor, Builder $builder): int
    {
        $started = microtime(true);
        $start = date(DATE_ATOM);
        try {
            $mode = $this->argument('mode');
            $report = match ($mode) {
                'plan' => Contract::plan(),
                'extract' => $extractor->extract(Contract::C1_DIRECTORY, (string) $this->option('output-dir'),
                    (bool) $this->option('authorize-read-only-extract')),
                'build', 'reproduce' => $this->build($builder, $mode),
                default => throw new RuntimeException('Use plan/extract/build/reproduce.'),
            };
            $this->line(json_encode($report + ['started_at' => $start, 'ended_at' => date(DATE_ATOM),
                'elapsed_seconds' => microtime(true) - $started, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line(json_encode(['status' => 'FAILED', 'started_at' => $start, 'ended_at' => date(DATE_ATOM),
                'elapsed_seconds' => microtime(true) - $started, 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }

    private function build(Builder $builder, string $mode): array
    {
        if (($mode === 'reproduce') !== ($this->option('original-dir') !== null) || $this->option('authorize-read-only-extract')) {
            throw new RuntimeException('Offline mode cannot authorize DB access; reproduce requires original-dir.');
        }

        return $builder->build((string) $this->option('extraction-dir'), (string) $this->option('output-dir'), $this->option('original-dir'));
    }
}
