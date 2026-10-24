<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use RuntimeException;

final readonly class CaptureResponse
{
    public function __construct(
        public string $captureId,
        public string $attemptId,
        public ?string $requestStartedAt,
        public ?string $responseReceivedAt,
        public ?int $httpStatus,
        public ?string $effectiveUri,
        public ?bool $redirectDetected,
        public ?string $body,
        public ?string $contentType,
        public string $timingProvenance,
        public ?string $failureClass = null,
        public ?array $declaredRawSeal = null,
        public ?array $historicalSource = null,
    ) {
        Contract::id($captureId);
        Contract::id($attemptId);
        Contract::instant($requestStartedAt);
        Contract::instant($responseReceivedAt);
        if (! in_array($timingProvenance, ['SIMULATED_SCENARIO', 'HISTORICAL_FETCH_LOG', 'UNKNOWN'], true)
            || ($body !== null && strlen($body) > 4194304) || ($httpStatus !== null && ($httpStatus < 100 || $httpStatus > 599))
            || ($failureClass !== null && ! in_array($failureClass, ['CONNECTION_FAILURE', 'TIMEOUT', 'TRANSPORT_FAILURE'], true))) {
            throw new RuntimeException('Invalid bounded offline response contract.');
        }
        if ($historicalSource !== null) {
            $keys = array_keys($historicalSource);
            sort($keys);
            if ($keys !== ['fetch_log_id', 'fetched_at', 'raw_bytes', 'raw_sha256', 'reference_sha256', 'source_parser_version']) {
                throw new RuntimeException('Unexpected historical source fields; private request data forbidden.');
            }
        }
    }

    public function publicData(): array
    {
        return ['capture_id' => $this->captureId, 'attempt_id' => $this->attemptId,
            'request_started_at' => $this->requestStartedAt, 'response_received_at' => $this->responseReceivedAt,
            'timing_provenance' => $this->timingProvenance, 'http_status' => $this->httpStatus,
            'effective_uri_sha256' => $this->effectiveUri === null ? null : hash('sha256', $this->effectiveUri),
            'redirect_detected' => $this->redirectDetected, 'content_type' => $this->contentType,
            'body_present' => $this->body !== null,
            'declared_raw_seal' => $this->declaredRawSeal ?? ($this->body === null ? null : EvidenceFiles::seal($this->body)),
            'failure_class' => $this->failureClass, 'historical_source' => $this->historicalSource];
    }
}
