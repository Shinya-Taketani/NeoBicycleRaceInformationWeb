<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;
use Throwable;

class AttributeEvidenceStore
{
    private readonly array $identity;

    private array $created = [];

    public function __construct(private readonly string $root)
    {
        $this->identity = EvidenceFiles::writableRoot($root);
    }

    public function save(string $sourceDirectory, string $expectedSourceHash, array $scenario, ?string $bundleId = null): StoredAttributeEvidence
    {
        $started = BundleFiles::now();
        ScenarioTiming::assertScenario($scenario);
        $seal = $this->codeSeal();
        $bundleId ??= bin2hex(random_bytes(16));
        if (preg_match('/^[a-f0-9]{32}$/D', $bundleId) !== 1
            || EvidenceFiles::writableRoot($this->root) !== $this->identity) {
            throw new RuntimeException('Invalid attribute bundle ID or replaced output root.');
        }
        EvidenceFiles::directory($sourceDirectory);
        if ($this->root === $sourceDirectory || str_starts_with($this->root, $sourceDirectory.'/') || str_starts_with($sourceDirectory, $this->root.'/')) {
            throw new RuntimeException('Original capture and derivative namespace must be disjoint.');
        }
        $verifier = new AttributeEvidenceVerifier;
        $source = $verifier->source($sourceDirectory, $expectedSourceHash);
        $directory = $this->root.'/'.$bundleId;
        if (EvidenceFiles::stat($directory) !== null || ! mkdir($directory, 0700)) {
            throw new RuntimeException('Attribute bundle collision; existing evidence retained.');
        }
        $this->created[$directory] = EvidenceFiles::directory($directory);
        try {
            $files = [
                'source-reference.json' => EvidenceFiles::json($source['reference']),
                'attribute-observations.jsonl' => BundleFiles::jsonl($source['races']),
                'scenario-time.json' => EvidenceFiles::json($scenario),
                'validation.json' => EvidenceFiles::json($verifier->validation($source['races'], $scenario)),
                'execution.json' => EvidenceFiles::json(['time_basis' => 'ACTUAL_SYSTEM_CLOCK', 'started_at' => $started,
                    'source_raw_saved_at' => $source['capture']['raw_saved_at'], 'validation_completed_at' => BundleFiles::now()]),
            ];
            $fileSeals = [];
            foreach ($files as $name => $bytes) {
                $this->write($directory, $name, $bytes);
                $fileSeals[$name] = EvidenceFiles::seal($bytes);
            }
            if ($this->codeSeal() !== $seal) {
                throw new RuntimeException('Code changed before publication.');
            }
            $manifest = EvidenceFiles::json(['version' => BundleFiles::VERSION, 'fixture_kind' => 'SYNTHETIC', 'bundle_id' => $bundleId,
                'files' => $fileSeals, 'code_seal' => $seal, 'restrictions' => BundleFiles::restrictions()]);
            $manifestHash = hash('sha256', $manifest);
            $this->write($directory, 'manifest.json', $manifest);
            $verifier->verifyBeforePublication($directory, $manifestHash, $sourceDirectory, $expectedSourceHash);
            $this->write($directory, 'COMPLETE.json', EvidenceFiles::json(['version' => BundleFiles::VERSION, 'bundle_id' => $bundleId,
                'manifest_sha256' => $manifestHash, 'state' => 'COMPLETE']));
            $verifier->verifyPublished($directory, $manifestHash, $sourceDirectory, $expectedSourceHash);
            // This clock is sampled only AFTER marker publication and its independent verification.
            $receipt = EvidenceFiles::json(['version' => BundleFiles::VERSION, 'bundle_id' => $bundleId,
                'manifest_sha256' => $manifestHash, 'source_manifest_sha256' => $expectedSourceHash,
                'time_basis' => 'ACTUAL_SYSTEM_CLOCK', 'publication_observed_at' => BundleFiles::now(),
                'observed_after' => 'COMPLETE_PUBLISHED_AND_INDEPENDENTLY_VERIFIED']);
            $this->write($directory, 'publication-receipt.json', $receipt);
            $receiptHash = hash('sha256', $receipt);
            $report = $verifier->verify($directory, $manifestHash, $sourceDirectory, $expectedSourceHash, $receiptHash);

            return new StoredAttributeEvidence($directory, $bundleId, $expectedSourceHash, $manifestHash, $receiptHash, $report);
        } catch (Throwable $error) {
            if (EvidenceFiles::directory($this->root) === $this->identity
                && EvidenceFiles::directory($directory) === $this->created[$directory]
                && EvidenceFiles::stat($directory.'/FAILED.json') === null) {
                EvidenceFiles::writeNew($directory, 'FAILED.json', EvidenceFiles::json(['version' => BundleFiles::VERSION,
                    'bundle_id' => $bundleId, 'state' => 'FAILED', 'reason' => 'DERIVED_EVIDENCE_NOT_PUBLISHED_OR_NOT_VERIFIED']));
            }
            throw $error;
        }
    }

    protected function write(string $directory, string $name, string $bytes): void
    {
        if (EvidenceFiles::writableRoot($this->root) !== $this->identity
            || ! isset($this->created[$directory]) || EvidenceFiles::directory($directory) !== $this->created[$directory]) {
            throw new RuntimeException('Output root changed while writing.');
        }
        EvidenceFiles::writeNew($directory, $name, $bytes);
        // Persist the directory entry too, before a subsequent publication stage can claim completion.
        $handle = fopen($directory, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Cannot open attribute directory for durability verification.');
        }
        try {
            if (! fsync($handle)) {
                throw new RuntimeException('Attribute directory persistence failed; partial evidence retained.');
            }
        } finally {
            fclose($handle);
        }
    }

    protected function codeSeal(): array
    {
        return BundleFiles::codeSeal();
    }
}
