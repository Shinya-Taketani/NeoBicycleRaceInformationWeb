<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use InvalidArgumentException;
use JsonException;
use RuntimeException;

final class CaptureVerifier
{
    /** The trusted manifest hash comes from the save receipt, not from the bundle being verified. */
    public function verify(string $directory, string $expectedManifestSha256): array
    {
        $report = $this->verifiedPayload($directory, $expectedManifestSha256);
        $markerName = $report['publication_state'].'.json';
        $this->fileSet($directory, [...EvidenceFiles::FILES, 'manifest.json', $markerName]);
        $marker = EvidenceFiles::object(EvidenceFiles::read($directory, $markerName));
        if ($marker !== ['version' => EvidenceFiles::VERSION, 'capture_id' => $report['capture_id'],
            'manifest_sha256' => $expectedManifestSha256, 'state' => $report['publication_state']]) {
            throw new RuntimeException('Publication marker mismatch.');
        }

        return $report;
    }

    /** Publication precondition only: this does not replace verify(), which requires a final marker. */
    public function verifyBeforePublication(string $directory, string $expectedManifestSha256): array
    {
        $this->fileSet($directory, [...EvidenceFiles::FILES, 'manifest.json']);

        return $this->verifiedPayload($directory, $expectedManifestSha256);
    }

    private function verifiedPayload(string $directory, string $expectedManifestSha256): array
    {
        $manifestBytes = EvidenceFiles::read($directory, 'manifest.json');
        if (! hash_equals($expectedManifestSha256, hash('sha256', $manifestBytes))) {
            throw new RuntimeException('Manifest seal mismatch.');
        }
        $manifest = EvidenceFiles::object($manifestBytes);
        if (($manifest['version'] ?? null) !== EvidenceFiles::VERSION || ($manifest['fixture_kind'] ?? null) !== 'SYNTHETIC'
            || array_keys($manifest['files'] ?? []) !== EvidenceFiles::FILES || ($manifest['code_seal'] ?? null) !== EvidenceFiles::codeSeal()) {
            throw new RuntimeException('Manifest contract or code seal mismatch.');
        }
        foreach ($manifest['files'] as $name => $seal) {
            if (EvidenceFiles::seal(EvidenceFiles::read($directory, $name)) !== $seal) {
                throw new RuntimeException('Evidence seal mismatch: '.$name);
            }
        }
        $report = $this->evaluate($directory);
        if (EvidenceFiles::read($directory, 'validation.json') !== EvidenceFiles::json($report)
            || $manifest['capture_id'] !== $report['capture_id']) {
            throw new RuntimeException('Stored validation or capture binding mismatch.');
        }

        return $report;
    }

    private function fileSet(string $directory, array $expected): void
    {
        EvidenceFiles::directory($directory);
        $names = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($names);
        sort($expected);
        if ($names !== $expected) {
            throw new RuntimeException('Missing, extra or contradictory publication evidence.');
        }
    }

    /** Evaluate saved inputs before publication; no caller-supplied observation or status is trusted. */
    public function evaluate(string $directory): array
    {
        $capture = EvidenceFiles::object(EvidenceFiles::read($directory, 'capture.json'));
        $targets = EvidenceFiles::object(EvidenceFiles::read($directory, 'targets.json'));
        $private = EvidenceFiles::object(EvidenceFiles::read($directory, 'private-source.json'));
        $raw = EvidenceFiles::read($directory, 'raw.bin');
        if (($capture['version'] ?? null) !== EvidenceFiles::VERSION || ($capture['fixture_kind'] ?? null) !== 'SYNTHETIC'
            || preg_match('/^[a-f0-9]{32}$/D', $capture['capture_id'] ?? '') !== 1
            || basename($directory) !== $capture['capture_id'] || $capture['raw_path'] !== 'raw.bin'
            || $capture['raw_seal'] !== EvidenceFiles::seal($raw) || $targets['capture_id'] !== $capture['capture_id']
            || $private['capture_id'] !== $capture['capture_id'] || $targets['targets'] === []) {
            throw new RuntimeException('Capture, Raw or target binding mismatch.');
        }
        if ($capture['request_uri_sha256'] !== hash('sha256', $private['request_uri'])
            || $capture['effective_uri_sha256'] !== ($private['effective_uri'] === null ? null : hash('sha256', $private['effective_uri']))) {
            throw new RuntimeException('Private and shared URI binding mismatch.');
        }
        foreach ($targets['targets'] as $target) {
            $registrations = [];
            foreach ($target['entries'] as $entry) {
                if (isset($registrations[$entry['bike_number']])) {
                    throw new RuntimeException('Duplicate saved target entrant.');
                }
                $registrations[$entry['bike_number']] = $entry['external_player_id'];
            }
            new RaceTarget($target['race_date'], $target['track_code'], $target['race_number'], $registrations, $target['race_id']);
        }
        $source = (new SourceValidator)->validate($capture, $private);
        $projector = new ObservationProjector;
        try {
            $observations = $projector->project($capture['source'], $raw);
            $races = $projector->classify($observations, $targets['targets'], $capture, $source);
            $schema = 'VERIFIED';
        } catch (JsonException|InvalidArgumentException|RuntimeException) {
            // Preserve malformed/unavailable artificial responses, but never fabricate normal observations.
            $races = [];
            $schema = 'RAW_SCHEMA_INVALID';
        }
        $failed = $schema !== 'VERIFIED' || $source['status'] !== 'VERIFIED';
        foreach ($races as $race) {
            $failed = $failed || in_array($race['state'], ['FETCH_FAILED', 'VALIDATION_FAILED', 'IDENTITY_MISMATCH'], true);
        }

        return ['version' => EvidenceFiles::VERSION, 'capture_id' => $capture['capture_id'],
            'raw_integrity' => 'VERIFIED', 'source_validation' => $source, 'schema_status' => $schema,
            'races' => $races, 'publication_state' => $failed ? 'FAILED' : 'COMPLETE',
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED'];
    }
}
