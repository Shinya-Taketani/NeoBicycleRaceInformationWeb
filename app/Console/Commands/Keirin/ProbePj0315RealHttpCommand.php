<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Probe;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\ProbeFailure;
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
        $inputValidated = false;
        try {
            $target = Target::load((string) $this->option('evidence'), (string) $this->option('evidence-sha'));
            $inputValidated = true;
            $result = $probe->run($target, $this->option('output'), (bool) $this->option('execute'));
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return $result['status'] === 'FAILED' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $facts = $error instanceof ProbeFailure ? $error->evidence : [
                'evidence_status' => $inputValidated ? 'RETENTION_UNKNOWN' : 'NOT_CREATED',
                'evidence_retained' => $inputValidated ? null : false,
                'output_write_started' => $inputValidated ? null : false, 'failed_evidence_confirmed' => false];
            $this->line(json_encode(['status' => 'INPUT_OR_PERSISTENCE_ERROR', ...$facts,
                'evidence_scope' => 'CURRENT_INVOCATION_OUTPUT_ONLY',
                'prediction_use' => 'NOT_AUTHORIZED', 'permission_status' => 'UNVERIFIED'], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
