<?php

declare(strict_types=1);

namespace App\Console\Commands\Keirin;

use App\Domain\Keirin\Scraping\PrestartCapture\Observation\HistoricalComparison;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationCaptureService;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationManifest;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\ObservationVerifier;
use App\Domain\Keirin\Scraping\PrestartCapture\Observation\SlotPlanner;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use Illuminate\Console\Command;
use Throwable;

final class ObservePj0315Command extends Command
{
    protected $signature = 'keirin:prestart:observe-pj0315
        {--action=plan : plan, preflight, compare or verify}
        {--execute : Explicit request; real operation remains NOT_AUTHORIZED}
        {--manifest= : Private observation manifest}
        {--manifest-sha= : External SHA-256}
        {--preflight= : Private preflight document}
        {--preflight-sha= : External SHA-256}
        {--race= : Scope race ID}
        {--slot= : Approved slot ID}
        {--baseline= : Saved 2025 single-race evidence}
        {--baseline-sha= : External evidence SHA-256}
        {--probe= : Immutable saved probe v1 directory}
        {--probe-sha= : External probe manifest SHA-256}
        {--output= : Existing dedicated private comparison directory}
        {--bundle= : Saved observation or comparison directory}
        {--bundle-sha= : External bundle manifest SHA-256}';

    protected $description = 'Plan PJ0315 observations or compare/verify saved evidence offline; real dispatch disabled';

    public function handle(ObservationCaptureService $runner): int
    {
        try {
            $action = $this->option('action');
            if ($this->option('execute')) {
                if ($this->option('manifest') === null || $this->option('preflight') === null) {
                    $result = ['status' => 'NOT_AUTHORIZED', 'reason' => 'INDEPENDENT_OPERATIONAL_APPROVAL_NOT_ESTABLISHED', 'http_calls' => 0];
                } else {
                    $manifest = new ObservationManifest(ObservationContract::readDocument((string) $this->option('manifest'), (string) $this->option('manifest-sha')));
                    $input = ObservationContract::readDocument((string) $this->option('preflight'), (string) $this->option('preflight-sha'));
                    $result = $runner->run($manifest, (int) $this->option('race'), (int) $this->option('slot'), $input,
                        Contract::OPERATIONAL, new \DateTimeImmutable('now'), null, (string) $this->option('output'));
                }
            } elseif ($action === 'compare') {
                $result = (new HistoricalComparison)->save((string) $this->option('output'), [
                    (string) $this->option('baseline'), (string) $this->option('baseline-sha'),
                    (string) $this->option('probe'), (string) $this->option('probe-sha')]);
            } elseif ($action === 'verify') {
                $bundle = (string) $this->option('bundle');
                $sha = (string) $this->option('bundle-sha');
                $document = ObservationContract::readDocument($bundle.'/manifest.json', $sha);
                $result = ($document['kind'] ?? null) === 'HISTORICAL_COMPARISON'
                    ? (new HistoricalComparison)->verify($bundle, $sha) : (new ObservationVerifier)->verify($bundle, $sha);
            } elseif (in_array($action, ['plan', 'preflight'], true)) {
                if ($this->option('manifest') === null) {
                    $result = ['status' => $action === 'plan' ? 'PLAN_ONLY_NO_HTTP' : 'NOT_AUTHORIZED', 'http_calls' => 0];
                } else {
                    $manifest = new ObservationManifest(ObservationContract::readDocument((string) $this->option('manifest'), (string) $this->option('manifest-sha')));
                    $result = $action === 'plan' ? ['status' => 'PLAN_ONLY_NO_HTTP', 'slots' => (new SlotPlanner)->plan($manifest), 'http_calls' => 0]
                        : (new ObservationPreflight)->evaluate($manifest, (int) $this->option('race'), (int) $this->option('slot'),
                            ObservationContract::readDocument((string) $this->option('preflight'), (string) $this->option('preflight-sha')),
                            Contract::OPERATIONAL, new \DateTimeImmutable('now'));
                }
            } else {
                throw new \RuntimeException('Unsupported action.');
            }
            $this->line(json_encode([...$result, ...ObservationContract::restrictions()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return ($result['status'] ?? null) === 'NOT_AUTHORIZED' ? self::FAILURE : self::SUCCESS;
        } catch (Throwable $error) {
            $this->line(json_encode(['status' => 'FAILED', 'exception_class' => $error::class, 'http_calls' => 0,
                ...ObservationContract::restrictions()], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
