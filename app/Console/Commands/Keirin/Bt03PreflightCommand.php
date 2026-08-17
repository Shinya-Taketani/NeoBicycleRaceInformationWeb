<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Backtest\Services\Bt03PreflightService;
use Illuminate\Console\Command;
use Throwable;

class Bt03PreflightCommand extends Command
{
    protected $signature = 'keirin:backtest:bt03-preflight';

    protected $description = 'Verify the fixed BT-02 run 5 source artifacts for BT-03 bin effects.';

    public function handle(Bt03PreflightService $service): int
    {
        try {
            $summary = $service->run();
        } catch (Throwable $throwable) {
            $this->error($throwable->getMessage());

            return self::FAILURE;
        }

        $source = $summary->source;
        $this->info('BT-03 PREFLIGHT PASS');
        $this->line("source BT-02 run_id={$source->sourceRunId}");
        $this->line('run identity match=PASS');
        $this->line("folds={$source->foldCount}/3");
        $this->line("signal specs={$source->signalSpecCount}/14");
        $this->line("models={$source->modelCount}/432");
        $this->line("metrics={$source->metricCount}/648");
        $this->line("effect bins={$source->effectBinCount}/668");
        $this->line("objective version match={$source->objectiveVersionMatches}/432");
        $this->line("optimizer version match={$source->optimizerVersionMatches}/432");
        $this->line('run/fold fingerprint=PASS');
        $this->line('signal spec fingerprint=PASS');
        $this->line('model fingerprint=PASS');
        $this->line('metric fingerprint=PASS');
        $this->line('effect bin fingerprint=PASS');
        $this->line('outcome snapshot manifest=PASS');
        $this->line("BT-02 baseline fingerprint=PASS ({$summary->baselineFingerprintMatches}/4)");
        $this->line("BT-02 source fingerprint={$summary->bt02->sourceFingerprintMatches}/56");
        $this->line("BT-02 content fingerprint={$summary->bt02->contentFingerprintMatches}/56");
        $this->line("source manifest hash={$summary->sourceManifestHash}");

        return self::SUCCESS;
    }
}
