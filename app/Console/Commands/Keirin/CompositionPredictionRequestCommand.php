<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Service;
use Illuminate\Console\Command;
use Throwable;

final class CompositionPredictionRequestCommand extends Command
{
    protected $signature = 'keirin:c1:composition-request {mode=plan} {--year=} {--race-id=} {--request-id=} {--store-root=} {--artifact=} {--json}';

    protected $description = 'Create, show, reuse or reproduce a fixed development composition prediction request.';

    public function handle(): int
    {
        try {
            FinalC1Stat35CompositionCommand::denyExternalAccess();
            $mode = $this->argument('mode');
            if ($mode === 'plan') {
                $result = Contract::plan();
            } else {
                $service = app(Service::class);
                $root = (string) $this->option('store-root');
                $id = (string) $this->option('request-id');
                $result = match ($mode) {
                    'create' => $service->create($root, $id, (string) $this->option('year'), (string) $this->option('race-id')),
                    'show' => $service->show($root, $id),
                    'reproduce' => $service->reproduce($root, $id, $this->option('artifact')),
                    default => throw new \RuntimeException('Use plan, create, show or reproduce.'),
                };
            }
            if ($this->option('json') || ! isset($result['marginals'])) {
                $this->line(json_encode($result + ['peak_memory_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES));
            } else {
                $this->line($result['status'].' '.$result['request']['request_id'].' year='.$result['request']['year'].' race_id='.$result['request']['race_id']);
                $this->line('Primary: '.implode(' / ', $result['primary']).' | P1=C2; P2/P3=C1');
                $this->table(['Bike', 'Entry ID', 'P1 marginal', 'P2 marginal', 'P3 marginal'], array_map(static fn (array $e): array => [
                    $e['bike'], $e['id'], sprintf('%.6f%%', 100 * $e['p1']), sprintf('%.6f%%', 100 * $e['p2']), sprintf('%.6f%%', 100 * $e['p3']),
                ], $result['marginals']));
                $this->line('Saved: '.$result['generated_at'].' | DEVELOPMENT only; historical input_as_of UNKNOWN; LIVE not authorized.');
                $this->line('Model artifact SHA-256: '.$result['request']['sources']['artifact']['sha256']);
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e::class.': '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
