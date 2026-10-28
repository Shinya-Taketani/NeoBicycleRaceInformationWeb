<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final class ObservationVerifier
{
    public function verify(string $directory, string $expectedSha, bool $terminal = true): array
    {
        $manifest = ObservationContract::readDocument($directory.'/manifest.json', $expectedSha);
        if (($manifest['version'] ?? null) !== ObservationContract::VERSION
            || ($manifest['code_seal'] ?? null) !== ObservationContract::codeSeal()
            || ($manifest['restrictions'] ?? null) !== ObservationContract::restrictions()
            || array_keys($manifest['files'] ?? []) !== ['CREATED.json', 'private-input.json', 'private-response.json', 'raw.bin', 'ledger.json', 'result.json']) {
            throw new RuntimeException('Manifest contract, code seal or inventory mismatch.');
        }
        foreach ($manifest['files'] as $name => $seal) {
            if (EvidenceFiles::seal(EvidenceFiles::read($directory, $name)) !== $seal) {
                throw new RuntimeException('Saved file integrity mismatch.');
            }
        }
        $input = EvidenceFiles::object(EvidenceFiles::read($directory, 'private-input.json'));
        $created = EvidenceFiles::object(EvidenceFiles::read($directory, 'CREATED.json'));
        if ($created['attempt_id'] !== $input['attempt_id'] || $created['code_seal'] !== $manifest['code_seal']) {
            throw new RuntimeException('Creation record or code seal binding mismatch.');
        }
        $targetManifest = new ObservationManifest($input['manifest']);
        $preflight = (new ObservationPreflight)->evaluate($targetManifest, $input['race_id'], $input['slot_id'], $input['preflight_input'],
            $input['scenario'], new \DateTimeImmutable($input['evaluation_at']));
        if ($preflight['status'] !== 'OFFLINE_SIMULATION_VALIDATED' || $input['scenario'] !== Contract::SIMULATED) {
            throw new RuntimeException('Saved authorization or timing not established.');
        }
        $raw = EvidenceFiles::read($directory, 'raw.bin');
        $response = EvidenceFiles::object(EvidenceFiles::read($directory, 'private-response.json'));
        $start = Contract::instant($response['request_started_at'] ?? null);
        $received = Contract::instant(($response['failure'] ?? null) === null
            ? ($response['response_received_at'] ?? null) : ($response['failed_at'] ?? null));
        $saved = Contract::instant($response['raw_saved_at'] ?? null);
        if ($start === null || $received === null || $saved === null || $start >= $received || $received > $saved) {
            throw new RuntimeException('Capture wall-clock order invalid.');
        }
        $analysis = ObservationCaptureService::analyze($targetManifest->target($input['race_id']), $raw, $response);
        $expected = [...$analysis, 'raw_seal' => EvidenceFiles::seal($raw), 'attempt_id' => $input['attempt_id'],
            'timing_provenance' => 'SIMULATED_SCENARIO', ...ObservationContract::restrictions()];
        if (Contract::digest($expected) !== Contract::digest(EvidenceFiles::object(EvidenceFiles::read($directory, 'result.json')))) {
            throw new RuntimeException('Independent analysis differs.');
        }
        $ledger = EvidenceFiles::object(EvidenceFiles::read($directory, 'ledger.json'));
        AttemptLedger::validate($ledger);
        if ($ledger['header']['manifest_sha256'] !== $targetManifest->digest()
            || $ledger['header']['authorization_id'] !== $targetManifest->data['authorization_id']
            || $ledger['header']['approval_scope'] !== $targetManifest->data['approval_scope']) {
            throw new RuntimeException('Ledger manifest binding mismatch.');
        }
        $matching = array_values(array_filter($ledger['events'], fn ($event) => ($event['attempt_id'] ?? null) === $input['attempt_id']));
        if (count($matching) !== 3 || $matching[0]['race_id'] !== $input['race_id'] || $matching[0]['slot_id'] !== $input['slot_id']
            || $matching[2]['kind'] !== 'OUTCOME' || $matching[2]['raw_sha256'] !== hash('sha256', $raw)
            || $matching[2]['http_status'] !== $response['http_status']
            || $matching[0]['manifest_revision'] !== $targetManifest->data['revision']
            || Contract::instant($matching[0]['at']) !== Contract::instant($input['preflight_input']['budget']['planned_attempt']['request_started_at'])
            || Contract::instant($matching[1]['at']) !== Contract::instant($matching[0]['at'])
            || Contract::instant($matching[2]['at']) !== Contract::instant($input['preflight_input']['timing']['response_received_at'])
            || ($matching[2]['state'] === 'SUCCEEDED') !== ($analysis['status'] === 'OBSERVATION_VERIFIED')) {
            throw new RuntimeException('Attempt, slot or Raw binding mismatch.');
        }
        $last = end($ledger['events']);
        if ($analysis['stop_scope'] && ($last['kind'] !== 'STOP' || $last['reason'] !== $analysis['stop_reason'])) {
            throw new RuntimeException('Required whole-scope STOP missing.');
        }
        $state = $analysis['status'] === 'OBSERVATION_VERIFIED' ? 'COMPLETE' : 'FAILED';
        $allowed = [...array_keys($manifest['files']), 'manifest.json'];
        if ($terminal) {
            $allowed[] = $state.'.json';
            if (EvidenceFiles::object(EvidenceFiles::read($directory, $state.'.json')) !== ['state' => $state, 'manifest_sha256' => $expectedSha]) {
                throw new RuntimeException('Terminal evidence mismatch.');
            }
        }
        $actual = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($actual);
        sort($allowed);
        if ($actual !== $allowed) {
            throw new RuntimeException('Unexpected or partial capture inventory.');
        }

        return ['status' => $state === 'COMPLETE' ? 'VERIFIED' : 'FAILED_EVIDENCE_VERIFIED', 'state' => $state,
            'attempt_id' => $input['attempt_id'], 'http_calls' => 0, ...ObservationContract::restrictions()];
    }
}
