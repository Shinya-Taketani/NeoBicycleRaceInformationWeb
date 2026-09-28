<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Statistics\AgariC1Input\Builder;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract;
use Illuminate\Console\Command;

final class BuildAgariC1InputCommand extends Command
{
    protected $signature = 'keirin:stat35:c1-input {mode=plan} {--c1-dir=} {--history-dir=} {--context-dir=} {--output-dir=} {--original-dir=}';

    protected $description = 'Prepare outcome-free fixed C1 inputs and a six-meeting STAT35 sidecar offline; never fit or predict.';

    public function handle(Builder $builder): int
    {
        try {
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $report = Contract::plan();
            } else {
                if (! in_array($mode, ['build', 'reproduce'], true) || ($mode === 'reproduce') !== ($this->option('original-dir') !== null)) {
                    throw new \RuntimeException('Use plan/build/reproduce; only reproduce requires --original-dir.');
                }
                $report = $builder->build((string) $this->option('c1-dir'), (string) $this->option('history-dir'),
                    (string) $this->option('output-dir'), $this->option('context-dir'), $this->option('original-dir'));
            }
            $this->line(json_encode($report + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line(json_encode(['status' => 'FAILED', 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
