<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Builder;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionArchive\Contract;
use Illuminate\Console\Command;
use Throwable;

final class CompositionPredictionArchiveCommand extends Command
{
    protected $signature = 'keirin:c1:composition-archive {action=plan} {--output=}';

    protected $description = 'Build a local in-sample archive from fixed saved 2025 predictions, without inference.';

    public function handle(Builder $builder): int
    {
        try {
            $result = match ($this->argument('action')) {
                'plan' => Contract::plan(),
                'build' => $builder->build((string) $this->option('output')),
                default => throw new \RuntimeException('Only plan and build are supported.'),
            };
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }
    }
}
