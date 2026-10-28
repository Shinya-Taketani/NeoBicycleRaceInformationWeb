<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Pj0315Probe\Target;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

/** Read-only comparison of the two explicitly authorized 2025 sources, never historical prediction input. */
final class HistoricalComparison
{
    public const BASELINE_SHA = '2041ed47a61c47de039cfc23f81f81240022e734d2cf5154fc76a2a532b710b7';

    public const PROBE_SHA = 'e46900fe80a06c67e989d666d7a6eefcfac8c08508542df9ea1a3a48c71f7e36';

    public function compare(string $baseline, string $baselineSha, string $probe, string $probeManifestSha): array
    {
        $target = Target::load($baseline, $baselineSha);
        if ($target->race['race_date'] !== '2025-01-04' || $target->race['track_code'] !== '28' || $target->race['race_number'] !== 1) {
            throw new RuntimeException('Historical comparison is restricted to the authorized saved 2025 race.');
        }
        $source = ObservationContract::readDocument($baseline, $baselineSha)['cases'][0];
        $old = EvidenceFiles::read(dirname($baseline), $source['fixture_file']);
        $manifest = ObservationContract::readDocument($probe.'/manifest.json', $probeManifestSha);
        if (($manifest['version'] ?? null) !== 'PJ0315-REAL-HTTP-PROBE-v1') {
            throw new RuntimeException('Expected immutable historical probe v1.');
        }
        $startSeals = [];
        foreach ($manifest['files'] as $name => $seal) {
            $bytes = EvidenceFiles::read($probe, $name);
            if (EvidenceFiles::seal($bytes) !== $seal) {
                throw new RuntimeException('Historical probe file seal mismatch.');
            }
            $startSeals[$name] = $seal;
        }
        $complete = EvidenceFiles::object(EvidenceFiles::read($probe, 'COMPLETE.json'));
        if (($complete['state'] ?? null) !== 'COMPLETE' || ($complete['manifest_sha256'] ?? null) !== $probeManifestSha) {
            throw new RuntimeException('Historical probe completion mismatch.');
        }
        $private = EvidenceFiles::object(EvidenceFiles::read($probe, 'private-request.json'));
        if (($private['parameters'] ?? null) !== $target->parameters || ($private['uri'] ?? null) !== ObservationContract::URI) {
            throw new RuntimeException('Historical source request binding mismatch.');
        }
        $new = EvidenceFiles::read($probe, 'raw.bin');
        if (hash('sha256', $old) !== self::BASELINE_SHA || strlen($old) !== 94103
            || hash('sha256', $new) !== self::PROBE_SHA || strlen($new) !== 93721) {
            throw new RuntimeException('Authorized historical Raw SHA or size mismatch.');
        }
        $response = EvidenceFiles::object(EvidenceFiles::read($probe, 'private-response.json'));
        $result = EvidenceFiles::object(EvidenceFiles::read($probe, 'result.json'));
        $before = (new ObservationParser)->parse($old, $source['log']['content_type'], $target->race);
        $after = (new ObservationParser)->parse($new, $response['content_type'], $target->race);
        if ($before['status'] !== 'PARSED_RACE_IDENTITY_VERIFIED' || $after['status'] !== 'PARSED_RACE_IDENTITY_VERIFIED') {
            throw new RuntimeException('Historical parse or race identity failed.');
        }
        $comparison = (new RosterDifferenceEvaluator)->compare($before, $after, true);
        foreach ($startSeals as $name => $seal) {
            if (EvidenceFiles::seal(EvidenceFiles::read($probe, $name)) !== $seal) {
                throw new RuntimeException('Historical original changed during comparison.');
            }
        }
        ObservationContract::readDocument($baseline, $baselineSha);
        ObservationContract::readDocument($probe.'/manifest.json', $probeManifestSha);
        if (hash('sha256', EvidenceFiles::read(dirname($baseline), $source['fixture_file'])) !== self::BASELINE_SHA) {
            throw new RuntimeException('Baseline original changed during comparison.');
        }
        $fetched = $source['log']['fetched_at'] ?? null;
        $baselineUtc = is_string($fetched) && preg_match('/[+-][0-9]{2}(?::[0-9]{2})?$/D', $fetched) === 1
            ? (new \DateTimeImmutable($fetched))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z') : null;

        return ['version' => ObservationContract::VERSION, 'use_class' => 'RETROSPECTIVE_ONLY',
            'race' => $target->race, 'baseline' => ['seal' => EvidenceFiles::seal($old), 'fetch_log_id' => $source['log']['id'],
                'fetched_at' => $fetched, 'fetched_at_utc' => $baselineUtc, 'saved_at' => $source['log']['created_at'] ?? null],
            'observed' => ['seal' => EvidenceFiles::seal($new), 'request_started_at' => $response['request_started_at'],
                'received_at' => $response['response_received_at'], 'saved_at' => $result['raw_saved_at']],
            'baseline_source_sha256' => $baselineSha, 'old_probe_manifest_sha256' => $probeManifestSha,
            'old_probe_version' => $manifest['version'], 'old_probe_code_seal' => $manifest['code_seal'],
            'identity' => 'SAME_RACE', 'comparison' => $comparison, 'source_seals_unchanged' => true,
            'acquisition_interval_microseconds' => $baselineUtc === null ? null
                : Contract::instant($response['response_received_at']) - Contract::instant($baselineUtc),
            'prestart_evidence' => 'NOT_ESTABLISHED', 'http_calls' => 0, ...ObservationContract::restrictions()];
    }

    public function save(string $output, array $sources): array
    {
        EvidenceFiles::writableRoot($output);
        if (scandir($output) !== ['.', '..']) {
            throw new RuntimeException('Historical comparison requires a new empty dedicated output directory.');
        }
        foreach ([dirname($sources[0]), $sources[2]] as $source) {
            EvidenceFiles::directory($source);
            if ($output === $source || str_starts_with($output, $source.'/') || str_starts_with($source, $output.'/')) {
                throw new RuntimeException('Historical source/output overlap forbidden.');
            }
        }
        $code = ObservationContract::codeSeal();
        $result = $this->compare(...$sources);
        ObservationContract::write($output, 'private-sources.json', ['sources' => $sources]);
        ObservationContract::write($output, 'comparison.json', $result);
        $files = [];
        foreach (['private-sources.json', 'comparison.json'] as $name) {
            $files[$name] = EvidenceFiles::seal(EvidenceFiles::read($output, $name));
        }
        if ($code !== ObservationContract::codeSeal()) {
            throw new RuntimeException('Comparison code changed.');
        }
        $sha = ObservationContract::write($output, 'manifest.json', ['version' => ObservationContract::VERSION,
            'kind' => 'HISTORICAL_COMPARISON', 'code_seal' => $code, 'files' => $files]);
        ObservationContract::write($output, 'COMPLETE.json', ['state' => 'COMPLETE', 'manifest_sha256' => $sha]);

        return ['status' => 'COMPARISON_SAVED', 'directory' => $output, 'manifest_sha256' => $sha,
            'comparison' => $result['comparison'], 'http_calls' => 0, ...ObservationContract::restrictions()];
    }

    public function verify(string $directory, string $sha): array
    {
        $manifest = ObservationContract::readDocument($directory.'/manifest.json', $sha);
        if (($manifest['version'] ?? null) !== ObservationContract::VERSION || ($manifest['kind'] ?? null) !== 'HISTORICAL_COMPARISON'
            || $manifest['code_seal'] !== ObservationContract::codeSeal()
            || array_keys($manifest['files']) !== ['private-sources.json', 'comparison.json']) {
            throw new RuntimeException('Comparison manifest or code mismatch.');
        }
        foreach ($manifest['files'] as $name => $seal) {
            if (EvidenceFiles::seal(EvidenceFiles::read($directory, $name)) !== $seal) {
                throw new RuntimeException('Comparison file altered.');
            }
        }
        $sources = EvidenceFiles::object(EvidenceFiles::read($directory, 'private-sources.json'))['sources'];
        $expected = $this->compare(...$sources);
        if (Contract::digest($expected) !== Contract::digest(EvidenceFiles::object(EvidenceFiles::read($directory, 'comparison.json')))
            || EvidenceFiles::object(EvidenceFiles::read($directory, 'COMPLETE.json')) !== ['state' => 'COMPLETE', 'manifest_sha256' => $sha]) {
            throw new RuntimeException('Comparison reproduction or receipt mismatch.');
        }

        return ['status' => 'VERIFIED', 'http_calls' => 0, 'historical_as_of_available' => false];
    }
}
