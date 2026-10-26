<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as OfflineContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\DecisionRecord;
use DateTimeImmutable;

/** Entirely synthetic offline approval/identity/clock documents, never actual user approval or real race data. */
final class ApprovalPreflightFixture
{
    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2025-01-01T00:00:00Z');
    }

    public static function make(string $date = '2025-01-01'): array
    {
        $entries = [];
        for ($bike = 1; $bike <= 5; $bike++) {
            $entries[] = ['bike_number' => $bike, 'external_player_id' => sprintf('%06d', $bike), 'frame_number' => null];
        }
        $target = ['race_id' => 41, 'race_date' => $date, 'track_code' => '11', 'race_number' => 1,
            'external_race_id' => '11:'.str_replace('-', '', $date).':01', 'race_type' => 'A級予選', 'entries' => $entries];
        $source = ['source' => 'PJ0315', 'request_uri' => 'https://keirin.jp/pc/racelive',
            'parameters' => ['disp' => 'PJ0315', 'encp' => 'SYNTHETIC_NOT_A_REAL_REQUEST'],
            'policy_reference' => 'synthetic-policy', 'binding_sha256' => ''];
        $source['binding_sha256'] = OfflineContract::binding($source['request_uri'], $source['parameters']);
        $input = ['decisions' => [], 'manifest' => [], 'budget' => [], 'source' => [], 'timing' => [],
            'simulation_evidence' => ['anchors' => [], 'documents' => []]];
        $identitySha = self::document($input, 'synthetic-identity', ['kind' => 'TARGET_IDENTITY',
            'target_sha256' => Contract::digest($target), 'binding_sha256' => $source['binding_sha256']]);
        $source['policy_sha256'] = self::document($input, 'synthetic-policy', ['kind' => 'SOURCE_POLICY', 'source' => 'PJ0315',
            'automatic_access_permitted' => true, 'binding_sha256' => $source['binding_sha256'], 'expires_at' => '2025-01-02T00:00:00Z']);
        $manifest = ['contract_version' => Contract::VERSION, 'revision' => 1, 'previous_manifest_sha256' => null,
            'source' => 'PJ0315', 'target' => $target, 'expires_at' => '2025-01-02T00:00:00Z',
            'approval_reference' => 'synthetic-approval-D1', 'identity_evidence_reference' => 'synthetic-identity',
            'identity_evidence_sha256' => $identitySha, 'request_binding_sha256' => $source['binding_sha256']];
        $limits = ['max_tracks' => 1, 'max_dates' => 1, 'max_races' => 2, 'slots_per_race' => 2, 'max_attempts' => 4,
            'max_concurrent' => 1, 'min_interval_seconds' => 60, 'retry' => 0, 'connect_timeout_seconds' => 5, 'total_timeout_seconds' => 20];
        $values = ['D1' => ['manifest_sha256' => Contract::digest($manifest), 'target_sha256' => Contract::digest($target),
            'revision' => 1, 'previous_manifest_sha256' => null, 'identity_evidence_reference' => 'synthetic-identity', 'identity_evidence_sha256' => $identitySha],
            'D2' => $limits, 'D3' => array_intersect_key($source, array_flip(['policy_reference', 'policy_sha256', 'binding_sha256'])),
            'D4' => ['primary_source' => 'PJ0315', 'second_source_status' => 'SECOND_SOURCE_NOT_CAPTURED', 'jsj017_authorized' => false],
            'D5' => ['change_policy' => 'PRESERVE_UNKNOWN_APPEND_ONLY_NO_EXTRA_FETCH'],
            'D6' => ['clock_policy' => 'SIMULATED_CLOCK_WITH_BOUNDS', 'target_sha256' => Contract::digest($target)],
            'D7' => ['storage_reference' => 'SYNTHETIC_NOT_CREATED', 'retention' => 'NO_AUTOMATIC_DELETION', 'recovery_reference' => 'SYNTHETIC_NOT_RUN'],
            'D8' => ['prediction_use' => 'NOT_AUTHORIZED']];
        foreach (Contract::DECISIONS as $id) {
            $input['decisions'][] = ['decision_id' => $id, 'contract_version' => Contract::VERSION, 'status' => 'APPROVED',
                'proposal_value' => $values[$id], 'approved_value' => $values[$id], 'approval_reference' => 'synthetic-approval-'.$id,
                'evidence_reference' => 'synthetic-evidence-'.$id, 'evidence_sha256' => null, 'evidence_verified' => true,
                'approved_by' => 'SYNTHETIC_OPERATOR_NOT_USER', 'approved_at' => '2024-12-31T00:00:00Z',
                'expires_at' => '2025-01-02T00:00:00Z', 'unresolved_reason' => null];
        }
        $input['manifest'] = $manifest;
        $input['source'] = $source;
        $input['budget'] = ['ledger_known' => true, 'stopped' => false, 'active_attempts' => 0, 'attempts' => [],
            'request_settings' => ['retry' => 0, 'connect_timeout_seconds' => 5, 'total_timeout_seconds' => 20],
            'planned_attempt' => self::attempt(41, 1, '2025-01-01T00:00:00Z')];
        $input['timing'] = ['clock_evidence' => 'SIMULATED_CLOCK_WITH_BOUNDS', 'schedule_timezone' => 'Asia/Tokyo',
            'schedule_precision' => 'MINUTE', 'minute_convention' => 'START_OF_MINUTE',
            'observed_scheduled_start_at' => '2025-01-01T09:01:00+09:00',
            'request_started_at' => '2025-01-01T00:00:00Z', 'response_received_at' => '2025-01-01T00:00:01Z',
            'raw_saved_at' => '2025-01-01T00:00:02Z', 'validation_completed_at' => '2025-01-01T00:00:03Z',
            'publication_completed_at' => '2025-01-01T00:00:04Z', 'prediction_input_as_of' => '2025-01-01T00:00:05Z'];
        $input['timing']['uncertainty_microseconds'] = array_fill_keys(['request_started_at', 'response_received_at', 'raw_saved_at',
            'validation_completed_at', 'publication_completed_at', 'prediction_input_as_of', 'observed_scheduled_start_at'], 0);
        self::sealApprovals($input);

        return $input;
    }

    public static function attempt(int $race, int $slot, string $at, string $state = 'PLANNED'): array
    {
        return ['attempt_id' => 'synthetic-'.$race.'-'.$slot, 'race_id' => $race, 'slot_id' => $slot,
            'race_date' => '2025-01-01', 'track_code' => '11', 'request_started_at' => $at, 'state' => $state];
    }

    public static function futureRequest(): array
    {
        $input = self::make();
        foreach (['request_started_at', 'response_received_at', 'raw_saved_at', 'validation_completed_at',
            'publication_completed_at', 'prediction_input_as_of'] as $key) {
            $input['timing'][$key] = (new DateTimeImmutable($input['timing'][$key]))->modify('+30 seconds')->format(DATE_ATOM);
        }
        $input['budget']['planned_attempt']['request_started_at'] = $input['timing']['request_started_at'];

        return $input;
    }

    public static function document(array &$input, string $ref, array $document): string
    {
        $bytes = json_encode(['scope' => Contract::SIMULATED, ...$document], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $sha = hash('sha256', $bytes);
        $input['simulation_evidence']['documents'][$ref] = $bytes;
        $input['simulation_evidence']['anchors'][$ref] = $sha;

        return $sha;
    }

    public static function sealApprovals(array &$input): void
    {
        foreach ($input['decisions'] as &$data) {
            $record = DecisionRecord::fromArray($data);
            $data['evidence_sha256'] = self::document($input, $record->evidenceReference,
                ['kind' => 'DECISION_APPROVAL', 'status' => 'APPROVED', 'contract_version' => Contract::VERSION,
                    'approval_payload' => $record->approvalPayload()]);
        }
    }
}
