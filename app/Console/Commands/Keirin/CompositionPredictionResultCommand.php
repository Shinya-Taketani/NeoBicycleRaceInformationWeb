<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Service;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

final class CompositionPredictionResultCommand extends Command
{
    protected $signature = 'keirin:c1:composition-result {mode=plan} {--evaluation-id=} {--request-store-root=} {--requests=} {--output-root=} {--json}';

    protected $description = 'Match fixed saved composition requests to fixed development results, without inference.';

    public function handle(): int
    {
        try {
            FinalC1Stat35CompositionCommand::denyExternalAccess();
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $service = app(Service::class);
                $root = (string) $this->option('output-root');
                $id = (string) $this->option('evaluation-id');
                $result = match ($mode) {
                    'execute' => $service->execute($root, $id, (string) $this->option('request-store-root'), (string) $this->option('requests')),
                    'show' => $service->show($root, $id),
                    'reproduce' => $service->reproduce($root, $id),
                    default => throw new RuntimeException('Use plan, execute, show or reproduce.'),
                };
            }
            $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)],
                JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error::class.': '.$error->getMessage());
            $this->line(json_encode(['status' => 'FAILED_NOT_PUBLISHED', 'peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
