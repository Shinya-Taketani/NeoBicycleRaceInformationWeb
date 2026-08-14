<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\DTO\Bt02PreflightProgressDto;
use App\Domain\Keirin\Backtest\Services\Bt02FingerprintPreflightService;
use Illuminate\Console\Command;
use Throwable;

class Bt02PreflightCommand extends Command
{
    protected $signature = 'keirin:backtest:bt02-preflight';

    protected $description = 'Verify the fixed BT-02 manifest and all PostgreSQL COPY fingerprints.';

    public function handle(Bt02FingerprintPreflightService $service): int
    {
        try {
            $summary = $service->run(function (Bt02PreflightProgressDto $progress): void {
                $index = str_pad((string) $progress->index, 2, '0', STR_PAD_LEFT);
                $stage = str_pad($progress->stage, 8);
                $this->line("[{$index}/{$progress->total}] {$progress->year} {$progress->statCode} {$stage} PASS");
            });
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->info('BT-02 PREFLIGHT PASS');
        $this->line("verified runs={$summary->verifiedRuns}");
        $this->line("source fingerprint match={$summary->sourceFingerprintMatches}/56");
        $this->line("content fingerprint match={$summary->contentFingerprintMatches}/56");
        $this->line("manifest hash={$summary->manifestHash}");

        return self::SUCCESS;
    }
}
