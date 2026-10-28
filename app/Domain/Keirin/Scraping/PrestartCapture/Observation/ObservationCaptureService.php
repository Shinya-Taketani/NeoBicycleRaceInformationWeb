<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as CaptureContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ObservationCaptureService
{
    public function __construct(private readonly Pj0315ObservationTransport $transport) {}

    public function run(ObservationManifest $manifest, int $race, int $slot, array $input, string $scenario,
        DateTimeImmutable $at, ?AttemptLedger $ledger, string $output): array
    {
        $preflight = (new ObservationPreflight)->evaluate($manifest, $race, $slot, $input, $scenario, $at);
        if ($preflight['status'] !== 'OFFLINE_SIMULATION_VALIDATED') {
            return $preflight;
        }

        return $this->simulate($manifest, $race, $slot, $input, $at, $ledger ?? new AttemptLedger($manifest), $output);
    }

    public function simulate(ObservationManifest $manifest, int $race, int $slot, array $input, DateTimeImmutable $at,
        AttemptLedger $ledger, string $output): array
    {
        $preflight = (new ObservationPreflight)->evaluate($manifest, $race, $slot, $input, Contract::SIMULATED, $at);
        if ($preflight['status'] !== 'OFFLINE_SIMULATION_VALIDATED') {
            return $preflight;
        }
        $ledger->requireManifest($manifest);

        return $ledger->exclusive(function (AttemptLedger $ledger) use ($manifest, $race, $slot, $input, $at, $output): array {
            $planned = $input['budget']['planned_attempt']['request_started_at'];
            $reservation = $ledger->reserve($race, $slot, $planned);
            $store = null;
            $sink = null;
            $ended = $input['timing']['response_received_at'];
            try {
                $store = new ObservationStore($output, $reservation['attempt_id'], $manifest);
                $private = ['manifest' => $manifest->data, 'race_id' => $race, 'slot_id' => $slot, 'preflight_input' => $input,
                    'evaluation_at' => $at->format('Y-m-d\TH:i:s.uP'), 'scenario' => Contract::SIMULATED,
                    'attempt_id' => $reservation['attempt_id']];
                $store->write('private-input.json', $private);
                $sink = $store->openRaw();
                $ledger->dispatch($reservation, $planned);
                $received = $this->transport->exchange($manifest->target($race)['parameters'], $sink);
                $response = $received->metadata;
                if (! is_resource($sink) || ! fflush($sink) || ! fsync($sink)) {
                    throw new RuntimeException('Original bytes not durable.');
                }
                $response['raw_saved_at'] = CaptureContract::now();
                fclose($sink);
                $sink = null;
                $store->write('private-response.json', $response);
                $bytes = EvidenceFiles::read($store->directory, 'raw.bin');
                $analysis = self::analyze($manifest->target($race), $bytes, $response);
                $state = $analysis['status'] === 'OBSERVATION_VERIFIED' ? 'SUCCEEDED'
                    : (($response['failure'] ?? null) === 'TIMEOUT' ? 'TIMEOUT' : 'FAILED');
                $ledger->outcome($reservation, $ended, $state, $response['http_status'], hash('sha256', $bytes));
                if ($analysis['stop_scope']) {
                    $ledger->stop($analysis['stop_reason'], $ended);
                }
                $store->write('ledger.json', $ledger->snapshot());
                $saved = $store->finish([...$analysis, 'raw_seal' => EvidenceFiles::seal($bytes),
                    'attempt_id' => $reservation['attempt_id'], 'timing_provenance' => 'SIMULATED_SCENARIO',
                    ...ObservationContract::restrictions()]);

                return [...$saved, 'status' => $analysis['status'], 'stop_scope' => $analysis['stop_scope'],
                    'http_calls' => 1, 'real_http_calls' => 0, ...ObservationContract::restrictions()];
            } catch (Throwable $error) {
                if (is_resource($sink)) {
                    fclose($sink);
                }
                try {
                    $events = $ledger->snapshot()['events'];
                    if (($events === [] ? null : end($events)['kind']) !== 'STOP') {
                        $ledger->stop('PERSISTENCE_OR_INTEGRITY_FAILURE', $ended);
                    }
                } finally {
                    $store?->retainFailure();
                }
                // An incomplete reservation remains spent. Do not infer that a request was never sent.
                throw $error;
            }
        });
    }

    public static function analyze(array $target, string $bytes, array $response): array
    {
        $reason = $response['failure'] ?? null;
        if ($reason === null && $response['http_status'] !== 200) {
            $reason = 'HTTP_STATUS_NOT_200';
        }
        if ($reason === null && ($response['effective_uri'] ?? null) !== ObservationContract::URI) {
            $reason = 'SOURCE_NOT_VERIFIED';
        }
        if ($reason === null && (strlen($bytes) > 4194304 || $bytes === '')) {
            $reason = 'RESPONSE_SIZE_INVALID';
        }
        $parser = $reason === null ? (new ObservationParser)->parse($bytes, $response['content_type'], $target['race'])
            : ['status' => 'NOT_PARSED_SOURCE_OR_FETCH_FAILURE'];
        if ($reason === null && $parser['status'] !== 'PARSED_RACE_IDENTITY_VERIFIED') {
            $reason = $parser['status'];
        }
        $difference = $reason === null ? (new RosterDifferenceEvaluator)->roster($target['entries'], $parser['entries']) : null;
        $schedule = (new DateTimeImmutable($target['scheduled_start_at']))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('H:i');
        $stop = $reason ?? (($difference['status'] ?? null) === 'ROSTER_CHANGED' ? 'ROSTER_DIFFERENCE_UNKNOWN_REASON'
            : (($parser['scheduled_start'] ?? null) !== $schedule ? 'SCHEDULE_REVISED_OR_UNKNOWN' : null));

        return ['status' => $reason === null ? 'OBSERVATION_VERIFIED' : 'FAILED', 'failure_reason' => $reason,
            'raw_complete' => ($response['failure'] ?? null) === null,
            'parser' => $parser, 'roster_difference' => $difference, 'stop_scope' => $stop !== null, 'stop_reason' => $stop,
            'official_change_reason' => 'UNKNOWN'];
    }
}
