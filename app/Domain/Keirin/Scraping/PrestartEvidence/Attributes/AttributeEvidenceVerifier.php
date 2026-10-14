<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\CaptureVerifier;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\RaceTarget;
use RuntimeException;

final class AttributeEvidenceVerifier
{
    public function verify(string $directory, string $expectedManifestHash, string $sourceDirectory,
        string $expectedSourceHash, string $expectedReceiptHash): array
    {
        BundleFiles::expectedHash($expectedReceiptHash);
        $report = $this->check($directory, $expectedManifestHash, $sourceDirectory, $expectedSourceHash, 'RECEIPT');
        $bytes = EvidenceFiles::read($directory, 'publication-receipt.json');
        if (! hash_equals($expectedReceiptHash, hash('sha256', $bytes))) {
            throw new RuntimeException('External publication receipt hash mismatch.');
        }
        $receipt = EvidenceFiles::object($bytes);
        $published = ScenarioTiming::instant($receipt['publication_observed_at'] ?? null);
        if ($published === null || $published < ScenarioTiming::instant($report['execution']['validation_completed_at'])
            || $published > ScenarioTiming::instant(BundleFiles::now())
            || $receipt !== ['version' => BundleFiles::VERSION, 'bundle_id' => basename($directory),
                'manifest_sha256' => $expectedManifestHash, 'source_manifest_sha256' => $expectedSourceHash,
                'time_basis' => 'ACTUAL_SYSTEM_CLOCK', 'publication_observed_at' => $receipt['publication_observed_at'],
                'observed_after' => 'COMPLETE_PUBLISHED_AND_INDEPENDENTLY_VERIFIED']) {
            throw new RuntimeException('Invalid publication receipt identity, clock or publication sequence.');
        }
        $cutoff = ScenarioTiming::instant($report['scenario']['prediction_as_of'] ?? null);

        return ['version' => BundleFiles::VERSION, 'validation' => $report['validation'], 'receipt' => $receipt,
            'physical_publication_vs_simulated_cutoff' => $cutoff === null ? 'UNKNOWN' : ($published > $cutoff ? 'AFTER' : 'AT_OR_BEFORE'),
            ...BundleFiles::restrictions()];
    }

    public function verifyBeforePublication(string $directory, string $expectedManifestHash, string $sourceDirectory, string $expectedSourceHash): void
    {
        $this->check($directory, $expectedManifestHash, $sourceDirectory, $expectedSourceHash, 'MANIFEST');
    }

    public function verifyPublished(string $directory, string $expectedManifestHash, string $sourceDirectory, string $expectedSourceHash): void
    {
        $this->check($directory, $expectedManifestHash, $sourceDirectory, $expectedSourceHash, 'COMPLETE');
    }

    public function source(string $sourceDirectory, string $expectedSourceHash): array
    {
        BundleFiles::expectedHash($expectedSourceHash);
        $verified = (new CaptureVerifier)->verify($sourceDirectory, $expectedSourceHash);
        if ($verified['publication_state'] !== 'COMPLETE' || $verified['source_validation']['status'] !== 'VERIFIED'
            || $verified['schema_status'] !== 'VERIFIED') {
            throw new RuntimeException('Original synthetic capture did not pass independent verification.');
        }
        $capture = EvidenceFiles::object(EvidenceFiles::read($sourceDirectory, 'capture.json'));
        $targetFile = EvidenceFiles::object(EvidenceFiles::read($sourceDirectory, 'targets.json'));
        $targets = [];
        foreach ($targetFile['targets'] as $target) {
            $registrations = [];
            foreach ($target['entries'] as $entry) {
                if (isset($registrations[$entry['bike_number']])) {
                    throw new RuntimeException('Duplicate immutable target identity.');
                }
                $registrations[$entry['bike_number']] = $entry['external_player_id'];
            }
            $targets[] = new RaceTarget($target['race_date'], $target['track_code'], $target['race_number'], $registrations, $target['race_id']);
        }
        $raw = EvidenceFiles::read($sourceDirectory, 'raw.bin');
        $races = (new AttributeProjector)->project($capture['source'], $raw, $targets, $capture['capture_id'], $expectedSourceHash);

        return ['capture' => $capture, 'races' => $races, 'reference' => ['capture_id' => $capture['capture_id'],
            'manifest_sha256' => $expectedSourceHash, 'raw_seal' => EvidenceFiles::seal($raw), 'raw_path' => 'raw.bin']];
    }

    public function validation(array $races, array $scenario): array
    {
        $timings = [];
        foreach ($races as $race) {
            $timing = (new ScenarioTiming)->classify($race, $scenario);
            $timings[] = ['race_date' => $race['race_date'], 'track_code' => $race['track_code'], 'race_number' => $race['race_number'],
                'timing' => $timing, 'entries' => array_map(fn (array $entry): array => (new AttributeEligibility)->entry($entry, $timing), $race['entries'])];
        }

        return ['version' => BundleFiles::VERSION, 'eligibility_version' => AttributeEligibility::VERSION,
            'scope' => 'SYNTHETIC_OBSERVATIONS_AND_ENTRY_CANDIDATES_ONLY', 'raw_and_identity' => 'VERIFIED', 'source_verification' => 'VERIFIED',
            'races' => $timings, ...BundleFiles::restrictions()];
    }

    private function check(string $directory, string $expectedManifestHash, string $sourceDirectory, string $expectedSourceHash, string $stage): array
    {
        BundleFiles::expectedHash($expectedManifestHash);
        EvidenceFiles::directory($directory);
        if (preg_match('/^[a-f0-9]{32}$/D', basename($directory)) !== 1) {
            throw new RuntimeException('Invalid attribute bundle identifier.');
        }
        $names = [...BundleFiles::FILES, 'manifest.json'];
        if ($stage !== 'MANIFEST') {
            $names[] = 'COMPLETE.json';
        }
        if ($stage === 'RECEIPT') {
            $names[] = 'publication-receipt.json';
        }
        sort($names);
        $actual = array_values(array_diff(scandir($directory), ['.', '..']));
        sort($actual);
        if ($actual !== $names) {
            throw new RuntimeException('Unexpected, missing or failed attribute evidence files.');
        }
        $bytes = EvidenceFiles::read($directory, 'manifest.json');
        if (! hash_equals($expectedManifestHash, hash('sha256', $bytes))) {
            throw new RuntimeException('External derived manifest hash mismatch.');
        }
        $manifest = EvidenceFiles::object($bytes);
        if (($manifest['version'] ?? null) !== BundleFiles::VERSION || ($manifest['fixture_kind'] ?? null) !== 'SYNTHETIC'
            || ($manifest['bundle_id'] ?? null) !== basename($directory) || ($manifest['code_seal'] ?? null) !== BundleFiles::codeSeal()
            || array_keys($manifest['files'] ?? []) !== BundleFiles::FILES || ($manifest['restrictions'] ?? null) !== BundleFiles::restrictions()) {
            throw new RuntimeException('Attribute manifest contract or code seal mismatch.');
        }
        foreach (BundleFiles::FILES as $name) {
            if ($manifest['files'][$name] !== EvidenceFiles::seal(EvidenceFiles::read($directory, $name))) {
                throw new RuntimeException('Derived file seal mismatch.');
            }
        }
        $source = $this->source($sourceDirectory, $expectedSourceHash);
        $scenario = EvidenceFiles::object(EvidenceFiles::read($directory, 'scenario-time.json'));
        ScenarioTiming::assertScenario($scenario);
        $validation = $this->validation($source['races'], $scenario);
        if (EvidenceFiles::read($directory, 'source-reference.json') !== EvidenceFiles::json($source['reference'])
            || EvidenceFiles::read($directory, 'attribute-observations.jsonl') !== BundleFiles::jsonl($source['races'])
            || EvidenceFiles::read($directory, 'validation.json') !== EvidenceFiles::json($validation)) {
            throw new RuntimeException('Independent attribute/identity/token/timing reprojection mismatch.');
        }
        $execution = EvidenceFiles::object(EvidenceFiles::read($directory, 'execution.json'));
        $began = ScenarioTiming::instant($execution['started_at'] ?? null);
        $validated = ScenarioTiming::instant($execution['validation_completed_at'] ?? null);
        if ($began === null || $validated === null || $began > $validated || $validated > ScenarioTiming::instant(BundleFiles::now())
            || $execution !== ['time_basis' => 'ACTUAL_SYSTEM_CLOCK', 'started_at' => $execution['started_at'],
                'source_raw_saved_at' => $source['capture']['raw_saved_at'], 'validation_completed_at' => $execution['validation_completed_at']]) {
            throw new RuntimeException('Actual execution clock evidence is invalid.');
        }
        if ($stage !== 'MANIFEST' && EvidenceFiles::object(EvidenceFiles::read($directory, 'COMPLETE.json')) !== [
            'version' => BundleFiles::VERSION, 'bundle_id' => basename($directory), 'manifest_sha256' => $expectedManifestHash, 'state' => 'COMPLETE',
        ]) {
            throw new RuntimeException('Invalid COMPLETE publication marker.');
        }

        return ['validation' => $validation, 'scenario' => $scenario, 'execution' => $execution];
    }
}
