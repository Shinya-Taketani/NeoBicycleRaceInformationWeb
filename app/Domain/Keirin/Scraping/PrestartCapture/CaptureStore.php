<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;
use Throwable;

class CaptureStore
{
    private readonly array $rootIdentity;

    private array $created = [];

    public function __construct(private readonly string $root, private readonly CaptureVerifier $verifier = new CaptureVerifier)
    {
        $this->rootIdentity = EvidenceFiles::writableRoot($root);
    }

    public function reserve(CaptureRequest $request): string
    {
        if (EvidenceFiles::writableRoot($this->root) !== $this->rootIdentity) {
            throw new RuntimeException('Offline evidence root changed.');
        }
        $directory = $this->root.'/'.$request->captureId;
        if (file_exists($directory) || is_link($directory)) {
            throw new RuntimeException('Capture collision; existing evidence retained.');
        }
        Contract::writeNew($this->root, 'attempt-'.$request->attemptId.'.json', EvidenceFiles::json([
            'capture_id' => $request->captureId, 'attempt_id' => $request->attemptId, 'reserved_at' => Contract::now()]));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Cannot reserve offline capture directory.');
        }
        $this->created[$directory] = ['identity' => EvidenceFiles::directory($directory), 'code_seal' => $this->codeSeal(),
            'request' => $request->publicData(), 'started_at' => Contract::now()];
        try {
            $this->write($directory, 'request.json', EvidenceFiles::json($request->publicData()));
            $this->write($directory, 'private-request.json', EvidenceFiles::json(['request_uri' => $request->requestUri, 'parameters' => $request->parameters]));
            $this->write($directory, 'allowed-targets.json', EvidenceFiles::json($request->allowedManifest));
        } catch (Throwable $error) {
            $this->fail($directory, 'REQUEST_PERSISTENCE_FAILED', $error);
            throw $error;
        }

        return $directory;
    }

    public function finish(string $directory, CaptureRequest $request, CaptureResponse $response): StoredCapture
    {
        $this->assertOwned($directory);
        try {
            if ($request->publicData() !== $this->created[$directory]['request']) {
                throw new RuntimeException('Reserved request changed before response storage.');
            }
            $savedAt = null;
            if ($response->body !== null) {
                $this->write($directory, 'raw.bin', $response->body);
                if (EvidenceFiles::seal(EvidenceFiles::read($directory, 'raw.bin')) !== EvidenceFiles::seal($response->body)) {
                    throw new RuntimeException('Raw changed across offline persistence.');
                }
                $savedAt = Contract::now();
            }
            $this->write($directory, 'response.json', EvidenceFiles::json([...$response->publicData(), 'raw_saved_at' => $savedAt]));
            $this->write($directory, 'private-response.json', EvidenceFiles::json(['effective_uri' => $response->effectiveUri]));
            $this->write($directory, 'execution.json', EvidenceFiles::json(['time_basis' => 'CURRENT_OFFLINE_SYSTEM_CLOCK',
                'started_at' => $this->created[$directory]['started_at'], 'finished_at' => Contract::now()]));
            $report = $this->verifier->evaluate($directory);
            $this->write($directory, 'validation.json', EvidenceFiles::json($report));
            $files = [...Contract::FILES, ...($response->body === null ? [] : ['raw.bin'])];
            $seals = [];
            foreach ($files as $name) {
                $seals[$name] = EvidenceFiles::seal(EvidenceFiles::read($directory, $name));
            }
            if ($this->codeSeal() !== $this->created[$directory]['code_seal']) {
                throw new RuntimeException('Offline code seal changed before publication.');
            }
            $manifest = EvidenceFiles::json(['version' => Contract::VERSION, 'capture_id' => $request->captureId,
                'files' => $seals, 'code_seal' => $this->created[$directory]['code_seal'], 'restrictions' => Contract::restrictions()]);
            $this->write($directory, 'manifest.json', $manifest);
            $hash = hash('sha256', $manifest);
            $this->verifier->verifyBeforePublication($directory, $hash);
            $marker = $report['status'] === 'FAILED' ? 'FAILED' : 'COMPLETE';
            $this->write($directory, $marker.'.json', EvidenceFiles::json(['version' => Contract::VERSION,
                'capture_id' => $request->captureId, 'manifest_sha256' => $hash, 'state' => $marker]));
            $verified = $this->verifier->verify($directory, $hash);

            return new StoredCapture($directory, $hash, $verified);
        } catch (Throwable $error) {
            $this->fail($directory, 'PERSISTENCE_OR_PUBLICATION_FAILED', $error);
            throw $error;
        }
    }

    public function fail(string $directory, string $reason, Throwable $error): void
    {
        $this->assertOwned($directory);
        if (! file_exists($directory.'/FAILED.json') && ! is_link($directory.'/FAILED.json')) {
            Contract::writeNew($directory, 'FAILED.json', EvidenceFiles::json(['version' => Contract::VERSION,
                'capture_id' => basename($directory), 'state' => 'FAILED', 'reason' => $reason, 'exception' => $error::class]));
        }
    }

    protected function write(string $directory, string $name, string $bytes): void
    {
        $this->assertOwned($directory);
        Contract::writeNew($directory, $name, $bytes);
    }

    protected function codeSeal(): array
    {
        return Contract::codeSeal();
    }

    private function assertOwned(string $directory): void
    {
        if (! isset($this->created[$directory]) || EvidenceFiles::writableRoot($this->root) !== $this->rootIdentity
            || EvidenceFiles::directory($directory) !== $this->created[$directory]['identity']) {
            throw new RuntimeException('Only the recorded offline capture directory can be written.');
        }
    }
}
