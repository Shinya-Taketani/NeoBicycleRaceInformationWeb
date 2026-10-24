<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\SourceValidator;
use RuntimeException;

final readonly class CaptureRequest
{
    public function __construct(
        public string $captureId,
        public string $attemptId,
        public array $target,
        public string $requestUri,
        public array $parameters,
        public array $allowedManifest,
        public string $allowedManifestSha256,
        public string $manifestReference,
        public string $mode,
        public ?string $predictionInputAsOf = null,
        public ?string $databaseRaceType = null,
        public string $programVersion = Contract::VERSION,
    ) {
        Contract::id($captureId);
        Contract::id($attemptId);
        Contract::target($target);
        Contract::instant($predictionInputAsOf);
        if (! in_array($mode, ['SIMULATED_SCENARIO', 'HISTORICAL_RAW_REPLAY'], true) || $programVersion !== Contract::VERSION
            || preg_match('/^[A-Za-z0-9._:-]{1,160}$/D', $manifestReference) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $allowedManifestSha256) !== 1
            || ! hash_equals($allowedManifestSha256, hash('sha256', EvidenceFiles::json($allowedManifest)))
            || array_keys($allowedManifest) !== ['version', 'targets'] || $allowedManifest['version'] !== 'OFFLINE-ROSTER-TARGETS-v1'
            || ! is_array($allowedManifest['targets']) || ! array_is_list($allowedManifest['targets'])
            || count($allowedManifest['targets']) < 1 || count($allowedManifest['targets']) > 100) {
            throw new RuntimeException('Invalid offline mode, program version or external target manifest seal.');
        }
        $found = false;
        $seen = [];
        foreach ($allowedManifest['targets'] as $candidate) {
            Contract::target($candidate);
            if (isset($seen[$candidate['race_id']])) {
                throw new RuntimeException('Duplicate allowed race identity.');
            }
            $seen[$candidate['race_id']] = true;
            $found = $found || $candidate === $target;
        }
        if (! $found) {
            throw new RuntimeException('Capture target is not bound to the fixed allowed manifest.');
        }
        // Validate the request side before any Transport call; these placeholders are never saved as a response.
        $preflight = (new SourceValidator)->validate(['source' => 'PJ0315', 'http_method' => 'POST', 'http_status' => 200, 'redirect_detected' => false],
            ['request_uri' => $requestUri, 'effective_uri' => $requestUri, 'parameters' => $parameters, 'policy' => self::policy()]);
        if ($preflight['status'] !== 'VERIFIED') {
            throw new RuntimeException('Offline request source/route/parameters rejected before Transport.');
        }
    }

    public static function policy(): array
    {
        return ['route' => Contract::ROUTE, 'method' => 'POST'];
    }

    public function publicData(): array
    {
        return ['version' => Contract::VERSION, 'capture_id' => $this->captureId, 'attempt_id' => $this->attemptId,
            'target' => $this->target, 'external_race_id' => sprintf('%s:%s:%02d', $this->target['track_code'], str_replace('-', '', $this->target['race_date']), $this->target['race_number']),
            'source' => 'PJ0315', 'http_method' => 'POST', 'request_route' => Contract::ROUTE,
            'request_binding_digest' => Contract::binding($this->requestUri, $this->parameters),
            'allowed_manifest_sha256' => $this->allowedManifestSha256, 'allowed_manifest_reference' => $this->manifestReference,
            'program_version' => $this->programVersion, 'mode' => $this->mode,
            'prediction_input_as_of' => $this->predictionInputAsOf, 'database_race_type' => $this->databaseRaceType,
            'database_context_origin' => 'CALLER_FIXED_OFFLINE_CONTEXT_NOT_LIVE_DB'];
    }
}
