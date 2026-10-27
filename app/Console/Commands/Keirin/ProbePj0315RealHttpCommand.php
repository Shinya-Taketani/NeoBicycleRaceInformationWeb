<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use Illuminate\Console\Command;
use Throwable;

final class ProbePj0315RealHttpCommand extends Command
{
    protected $signature = 'keirin:prestart:probe-pj0315
        {--execute : Dispatch exactly one POST; default never sends}
        {--evidence= : Existing single-race saved evidence JSON}
        {--evidence-sha= : External SHA-256 of saved evidence}
        {--output= : Existing dedicated private root; never overwritten}';

    protected $description = 'Single current HTTP technical check of a saved 2025 male PJ0315 target; no DB or prediction';

    public function handle(Probe $probe): int
    {
        if (! $this->option('execute') && $this->option('evidence') === null) {
            $this->line(json_encode(['status' => 'PLAN_ONLY_NO_HTTP', 'http_dispatch_count' => 0,
                'prediction_use' => 'NOT_AUTHORIZED', 'permission_status' => 'UNVERIFIED'], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        try {
            $target = Target::load((string) $this->option('evidence'), (string) $this->option('evidence-sha'));
            $result = $probe->run($target, $this->option('output'), (bool) $this->option('execute'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable) {
            $this->line(json_encode(['status' => 'INPUT_OR_PERSISTENCE_ERROR', 'evidence_retained' => true,
                'prediction_use' => 'NOT_AUTHORIZED', 'permission_status' => 'UNVERIFIED'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
