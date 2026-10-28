<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final class ObservationStore
{
    public readonly string $directory;

    private readonly array $identity;

    private readonly array $code;

    public function __construct(string $runRoot, string $attemptId, ObservationManifest $manifest)
    {
        EvidenceFiles::writableRoot($manifest->data['storage_root']);
        EvidenceFiles::writableRoot($runRoot);
        if (! str_starts_with($runRoot, $manifest->data['storage_root'].'/')
            || disk_free_space($runRoot) < $manifest->data['minimum_free_bytes']
            || preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1) {
            throw new RuntimeException('Unapproved storage root, capacity or attempt.');
        }
        $this->directory = $runRoot.'/'.$attemptId;
        if (file_exists($this->directory) || is_link($this->directory) || ! mkdir($this->directory, 0700)) {
            throw new RuntimeException('Exclusive capture directory collision.');
        }
        $this->identity = EvidenceFiles::writableRoot($this->directory);
        $this->code = ObservationContract::codeSeal();
        ObservationContract::write($this->directory, 'CREATED.json', ['attempt_id' => $attemptId,
            'created_at' => Contract::now(), 'identity' => $this->identity, 'code_seal' => $this->code]);
    }

    public function write(string $name, array $data): void
    {
        $this->assertIdentity();
        ObservationContract::write($this->directory, $name, $data);
    }

    public function openRaw(): mixed
    {
        $this->assertIdentity();
        $file = $this->directory.'/raw.bin';
        if (file_exists($file) || is_link($file)) {
            throw new RuntimeException('Raw already exists.');
        }
        $handle = fopen($file, 'x+b');
        if ($handle === false || ! chmod($file, 0600)) {
            throw new RuntimeException('Cannot exclusively create Raw sink.');
        }

        return $handle;
    }

    public function finish(array $result): array
    {
        $this->write('result.json', $result);
        $files = [];
        foreach (['CREATED.json', 'private-input.json', 'private-response.json', 'raw.bin', 'ledger.json', 'result.json'] as $name) {
            $files[$name] = EvidenceFiles::seal(EvidenceFiles::read($this->directory, $name));
        }
        $this->assertIdentity();
        if (ObservationContract::codeSeal() !== $this->code) {
            throw new RuntimeException('Code changed during capture.');
        }
        $sha = ObservationContract::write($this->directory, 'manifest.json', ['version' => ObservationContract::VERSION,
            'files' => $files, 'code_seal' => $this->code, 'restrictions' => ObservationContract::restrictions()]);
        (new ObservationVerifier)->verify($this->directory, $sha, false);
        $state = $result['status'] === 'OBSERVATION_VERIFIED' ? 'COMPLETE' : 'FAILED';
        $this->write($state.'.json', ['state' => $state, 'manifest_sha256' => $sha]);
        (new ObservationVerifier)->verify($this->directory, $sha);

        return ['directory' => $this->directory, 'manifest_sha256' => $sha, 'state' => $state];
    }

    public function retainFailure(): void
    {
        $this->assertIdentity();
        if (! file_exists($this->directory.'/FAILED.json') && ! file_exists($this->directory.'/COMPLETE.json')) {
            $this->write('FAILED.json', ['state' => 'FAILED', 'reason' => 'PERSISTENCE_OR_INTEGRITY_FAILURE', 'partial' => true]);
        }
    }

    private function assertIdentity(): void
    {
        if (EvidenceFiles::writableRoot($this->directory) !== $this->identity) {
            throw new RuntimeException('Created capture directory replaced.');
        }
    }
}
