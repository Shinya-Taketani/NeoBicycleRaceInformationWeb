<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture;

use App\Domain\Keirin\Scraping\DTO\RaceRosterSnapshotDto;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Exceptions\CharacterEncodingConversionException;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\PrestartEvidence\EvidenceFiles;
use App\Domain\Keirin\Scraping\PrestartEvidence\RawObservationDecoder;
use App\Domain\Keirin\Scraping\PrestartEvidence\SourceValidator;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use stdClass;

final class Projection
{
    public function __construct(private readonly RaceDetailParser $parser = new RaceDetailParser(new EmbeddedJsonExtractor)) {}

    public function evaluate(CaptureRequest $request, CaptureResponse $response, ?string $savedAt): array
    {
        $source = (new SourceValidator)->validate(['source' => 'PJ0315', 'http_method' => 'POST',
            'http_status' => $response->httpStatus, 'redirect_detected' => $response->redirectDetected],
            ['request_uri' => $request->requestUri, 'effective_uri' => $response->effectiveUri,
                'parameters' => $request->parameters, 'policy' => CaptureRequest::policy()]);
        $reasons = $source['reasons'];
        if ($request->captureId !== $response->captureId || $request->attemptId !== $response->attemptId) {
            $reasons[] = 'REQUEST_RESPONSE_ID_MISMATCH';
        }
        if ($response->failureClass !== null) {
            $reasons[] = $response->failureClass;
        }
        $failure = match (true) {
            $response->failureClass !== null => $response->failureClass,
            $response->httpStatus === 429 => 'HTTP_429',
            $response->httpStatus !== null && $response->httpStatus >= 500 => 'HTTP_5XX',
            $response->httpStatus !== null && $response->httpStatus >= 300 && $response->httpStatus < 400 => 'HTTP_3XX',
            $response->httpStatus !== 200 => 'HTTP_RESPONSE_NOT_OK',
            default => null,
        };
        $seal = $response->body === null ? null : EvidenceFiles::seal($response->body);
        $integrity = $seal === null ? 'NOT_PRESENT' : ($seal === $response->publicData()['declared_raw_seal'] ? 'VERIFIED' : 'MISMATCH');
        if ($integrity !== 'VERIFIED' || $response->body === '') {
            $reasons[] = 'RAW_ABSENT_EMPTY_OR_SEAL_MISMATCH';
        }
        if ($request->mode === 'HISTORICAL_RAW_REPLAY') {
            if (! $this->historicalSourceMatches($response, $seal)) {
                $reasons[] = 'HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED';
            }
        } elseif ($response->timingProvenance === 'HISTORICAL_FETCH_LOG' || $response->historicalSource !== null) {
            $reasons[] = 'HISTORICAL_SOURCE_BINDING_NOT_ESTABLISHED';
        }
        $started = Contract::instant($response->requestStartedAt);
        $received = Contract::instant($response->responseReceivedAt);
        $order = $started === null || $received === null ? 'UNKNOWN' : ($started < $received ? 'STRICTLY_ORDERED' : ($started == $received ? 'SAME_INSTANT' : 'REVERSED'));
        if (in_array($order, ['SAME_INSTANT', 'REVERSED'], true)) {
            $reasons[] = 'NON_STRICT_REQUEST_RESPONSE_ORDER';
        }
        $category = 'NOT_CHECKED';
        $dbCategory = (new RaceCategoryPolicy)->classify($request->databaseRaceType);
        $roster = null;
        $schedule = null;
        $skipped = false;
        if ($reasons === []) {
            try {
                [$html] = (new CharacterEncodingConverter)->convertToUtf8($response->body, $response->contentType);
                $context = (new RawObservationDecoder)->embedded($html, 'PC0201')['C0201data'] ?? null;
                if (! $context instanceof stdClass || ! ($context->C0201racedtl ?? null) instanceof stdClass) {
                    throw new RuntimeException('PC0201 roster context missing.');
                }
                $target = $request->target;
                if (! is_scalar($context->selKaisai ?? null) || ! is_scalar($context->selKjyoCd ?? null)
                    || (string) $context->selKaisai !== str_replace('-', '', $target['race_date'])
                    || (string) $context->selKjyoCd !== $target['track_code']
                    || ! is_scalar($context->selRaceNo ?? null) || ! ctype_digit((string) $context->selRaceNo)
                    || (int) $context->selRaceNo !== $target['race_number']) {
                    throw new RuntimeException('Raw race identity mismatch.');
                }
                $type = $context->C0201racedtl->syumoku ?? null;
                $rawCategory = (new RaceCategoryPolicy)->classify(HtmlTextNormalizer::normalize(is_string($type) ? $type : null));
                $category = $rawCategory->value;
                if ($rawCategory !== RaceCategory::Unknown && $dbCategory !== RaceCategory::Unknown && $rawCategory !== $dbCategory) {
                    $reasons[] = 'ROSTER_CATEGORY_MISMATCH';
                } elseif ($rawCategory !== RaceCategory::Men) {
                    $skipped = true;
                } else {
                    // Never run the detailed roster Parser for Girls/Unknown overview categories.
                    $page = $this->parser->parse($html);
                    $entries = array_map(fn ($entry) => ['bike_number' => $entry->bikeNumber, 'frame_number' => $entry->frameNumber,
                        'external_player_id' => $entry->externalPlayerId, 'player_name' => $entry->playerName,
                        'grade' => $entry->grade, 'riding_style' => $entry->ridingStyle, 'race_score' => $entry->raceScore], $page->entries);
                    RaceRosterSnapshotDto::validateEntries($entries);
                    $actual = $expected = [];
                    foreach ($entries as $entry) {
                        $actual[$entry['bike_number']] = $entry['external_player_id'];
                    }
                    foreach ($target['entries'] as $entry) {
                        $expected[$entry['bike_number']] = $entry['external_player_id'];
                    }
                    ksort($actual);
                    ksort($expected);
                    if ($actual !== $expected) {
                        throw new RuntimeException('Full roster target identity mismatch.');
                    }
                    $schedule = $page->startTime === null ? null : DateTimeImmutable::createFromFormat('!Ymd H:i', $page->raceDate.' '.$page->startTime, new DateTimeZone('Asia/Tokyo'));
                    if ($schedule === false) {
                        throw new RuntimeException('Invalid observed scheduled start.');
                    }
                    $roster = [...$target, 'entries' => $entries];
                }
            } catch (CharacterEncodingConversionException|JsonException|InvalidArgumentException|RuntimeException) {
                $reasons[] = 'RAW_SCHEMA_OR_IDENTITY_INVALID';
            }
        }
        $asOf = Contract::instant($request->predictionInputAsOf);
        $saved = Contract::instant($savedAt);
        $prefix = $response->timingProvenance === 'SIMULATED_SCENARIO' ? 'SIMULATED_' : 'RECORDED_';
        $before = $received === null || $schedule === null || $response->timingProvenance === 'UNKNOWN' ? 'UNKNOWN'
            : ($received < $schedule ? $prefix.'BEFORE_SAME_RAW_START' : $prefix.'AT_OR_AFTER_SAME_RAW_START');

        return ['version' => Contract::VERSION, 'capture_id' => $request->captureId, 'attempt_id' => $request->attemptId,
            'mode' => $request->mode, 'status' => $reasons !== [] ? 'FAILED' : ($skipped ? 'SKIPPED_UNSUPPORTED_CATEGORY' : 'VERIFIED'),
            'reasons' => $skipped ? [$category === 'UNKNOWN' ? 'RAW_CATEGORY_UNKNOWN' : 'UNSUPPORTED_RACE_CATEGORY'] : array_values(array_unique($reasons)),
            'failure_classification' => $failure, 'source_validation' => [...$source, 'scope' => 'OFFLINE_REQUEST_RESPONSE_BINDING_ONLY'],
            'original_http_effective_uri_evidence' => 'UNKNOWN', 'original_http_redirect_evidence' => 'UNKNOWN', 'raw_integrity' => $integrity,
            'raw_category' => $category, 'database_category' => $dbCategory->value, 'roster' => $roster,
            'timing' => ['request_started_at' => $response->requestStartedAt, 'response_received_at' => $response->responseReceivedAt,
                'transport_time_provenance' => $response->timingProvenance, 'request_response_order' => $order,
                'raw_saved_at' => $savedAt, 'raw_saved_at_scope' => 'CURRENT_OFFLINE_COPY_AFTER_FSYNC',
                'historical_raw_saved_at' => null, 'observed_scheduled_start_at' => $schedule?->format(DATE_ATOM),
                'schedule_timezone_basis' => 'EXISTING_PJ0315_ASIA_TOKYO_CONVENTION', 'received_vs_schedule' => $before,
                'prediction_input_as_of' => $request->predictionInputAsOf,
                'current_copy_saved_vs_input_as_of' => $saved === null || $asOf === null ? 'UNKNOWN' : ($saved < $asOf ? 'BEFORE' : 'AT_OR_AFTER'),
                'prediction_input_availability' => 'NOT_ESTABLISHED', 'clock_trust' => 'UNKNOWN', 'clock_error_seconds' => null,
                'actual_start_at' => null, 'official_publication_at' => null], ...Contract::restrictions()];
    }

    private function historicalSourceMatches(CaptureResponse $response, ?array $rawSeal): bool
    {
        $historical = $response->historicalSource;
        if ($response->timingProvenance !== 'HISTORICAL_FETCH_LOG' || $response->requestStartedAt !== null || $historical === null
            || ! is_int($historical['fetch_log_id']) || $historical['fetch_log_id'] < 1
            || ! is_string($historical['source_parser_version']) || trim($historical['source_parser_version']) === ''
            || ! is_string($historical['reference_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $historical['reference_sha256']) !== 1
            || $rawSeal !== ['bytes' => $historical['raw_bytes'], 'sha256' => $historical['raw_sha256']]
            || ! is_string($historical['fetched_at']) || $response->responseReceivedAt === null) {
            return false;
        }
        try {
            return Contract::instant($historical['fetched_at']) == Contract::instant($response->responseReceivedAt);
        } catch (RuntimeException) {
            // Invalid historical clock evidence is a failed binding, never a replacement timestamp.
            return false;
        }
    }
}
