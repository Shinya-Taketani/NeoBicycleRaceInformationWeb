<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Scraping;

use App\Domain\Keirin\Scraping\PrestartCapture\Contract as OfflineContract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\ApprovalEvidence;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\ApprovalPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\BudgetPreflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\DecisionRecord;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Preflight;
use App\Domain\Keirin\Scraping\PrestartCapture\Operational\TimingPreflight;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\ApprovalPreflightFixture as Fixture;

final class ApprovalPreflightTest extends TestCase
{
    public function test_synthetic_success_is_deterministic_side_effect_free_and_never_live_authorized(): void
    {
        $input = Fixture::make();
        $original = $input;
        $seal = OfflineContract::codeSeal();
        $preflight = new Preflight;
        $report = $preflight->evaluate($input, Contract::SIMULATED, Fixture::now());
        $result = $report->jsonSerialize();
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $result['status']);
        $this->assertSame(Contract::DECISIONS, array_keys($result['decision_results']));
        $this->assertSame([], $result['missing_decisions']);
        $this->assertSame([], $result['missing_evidence']);
        $this->assertSame([], $result['blocking_reasons']);
        foreach (Contract::restrictions() as $key => $value) {
            $this->assertSame($value, $result[$key]);
        }
        foreach (['http_calls', 'database_writes', 'model_updates', 'attempt_reservations'] as $key) {
            $this->assertSame(0, $result[$key]);
        }
        $this->assertSame('SECOND_SOURCE_NOT_CAPTURED', $result['second_source_status']);
        $this->assertSame('NOT_ESTABLISHED', $result['two_source_consistency']);
        $this->assertFalse($result['budget_validation']['reservation_performed']);
        $this->assertSame($report->json(), $preflight->evaluate($input, Contract::SIMULATED, Fixture::now())->json());
        $this->assertStringNotContainsString('SYNTHETIC_NOT_A_REAL_REQUEST', $report->json());
        $this->assertSame($original, $input);
        $this->assertSame($seal, OfflineContract::codeSeal());
    }

    public function test_missing_operational_approvals_are_reported_for_all_eight_decisions(): void
    {
        $report = (new Preflight)->evaluate([], Contract::OPERATIONAL, Fixture::now())->jsonSerialize();
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame(Contract::DECISIONS, $report['missing_decisions']);
        foreach ($report['decision_results'] as $id => $result) {
            $this->assertSame('MISSING', $result['input_status']);
            $this->assertSame(ApprovalPreflight::NEXT[$id], $result['required_next_decision']);
            $this->assertNotEmpty($result['missing_evidence']);
        }
        $this->assertContains('REAL_OPERATION_NOT_AUTHORIZED_IN_THIS_PHASE', $report['blocking_reasons']);
    }

    public function test_self_declared_approved_flags_and_manifest_hashes_are_not_independent_evidence(): void
    {
        $input = Fixture::make();
        $input['simulation_evidence'] = [];
        $report = $this->report($input);
        $this->assertSame(Contract::DECISIONS, $report['missing_decisions']);
        foreach ($report['decision_results'] as $result) {
            $this->assertSame('APPROVED', $result['input_status']);
            $this->assertContains('INDEPENDENT_APPROVAL_NOT_VERIFIED', $result['failure_reasons']);
        }
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
    }

    public function test_synthetic_documents_cannot_be_promoted_by_changing_the_scenario_flag(): void
    {
        $report = (new Preflight)->evaluate(Fixture::make(), Contract::OPERATIONAL, Fixture::now())->jsonSerialize();
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame(Contract::DECISIONS, $report['missing_decisions']);
        $this->assertContains('SIMULATED_EVIDENCE_NOT_OPERATIONAL', $report['blocking_reasons']);
        $this->assertSame('UNKNOWN', $report['timing_validation']['validation_result']);
    }

    #[DataProvider('unapprovedDecisions')]
    public function test_a_single_unapproved_decision_blocks_the_entire_simulation(int $index): void
    {
        $input = Fixture::make();
        $input['decisions'][$index]['status'] = 'PROPOSED_NOT_APPROVED';
        $report = $this->report($input);
        $id = Contract::DECISIONS[$index];
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame([$id], $report['missing_decisions']);
        $this->assertContains('PROPOSED_NOT_APPROVED', $report['decision_results'][$id]['failure_reasons']);
    }

    public static function unapprovedDecisions(): iterable
    {
        foreach (Contract::DECISIONS as $index => $id) {
            yield $id => [$index];
        }
    }

    #[DataProvider('approvalFailures')]
    public function test_approval_records_fail_closed(array $path, mixed $value, string $reason): void
    {
        $input = Fixture::make();
        self::replace($input, $path, $value);
        $result = $this->report($input)['decision_results']['D1'];
        $this->assertSame('NOT_AUTHORIZED', $result['validation_result']);
        $this->assertContains($reason, $result['failure_reasons']);
    }

    public static function approvalFailures(): iterable
    {
        foreach (['UNRESOLVED', 'UNVERIFIED', 'EXPIRED', 'REVOKED', 'CONFLICT'] as $status) {
            yield $status => [['decisions', 0, 'status'], $status, $status];
        }
        yield 'missing proof' => [['decisions', 0, 'evidence_reference'], null, 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'false evidence flag' => [['decisions', 0, 'evidence_verified'], false, 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'SHA mismatch' => [['decisions', 0, 'evidence_sha256'], str_repeat('0', 64), 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'anchor mismatch' => [['simulation_evidence', 'anchors', 'synthetic-evidence-D1'], str_repeat('1', 64), 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'changed proof bytes' => [['simulation_evidence', 'documents', 'synthetic-evidence-D1'], '{}', 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'changed approved value' => [['decisions', 0, 'approved_value', 'revision'], 2, 'INDEPENDENT_APPROVAL_NOT_VERIFIED'];
        yield 'expired at same instant' => [['decisions', 0, 'expires_at'], '2025-01-01T09:00:00+09:00', 'EXPIRED'];
        yield 'invalid date' => [['decisions', 0, 'approved_at'], '2024-02-30T00:00:00Z', 'APPROVAL_TIME_INVALID'];
        yield 'future approval' => [['decisions', 0, 'approved_at'], '2025-01-01T00:00:01Z', 'APPROVAL_TIME_INVALID'];
        yield 'wrong contract' => [['decisions', 0, 'contract_version'], 'OTHER', 'CONTRACT_VERSION_MISMATCH'];
    }

    #[DataProvider('manifestFailures')]
    public function test_fixed_manifest_identity_revision_and_categories_are_verified(array $path, mixed $value, string $reason): void
    {
        $input = Fixture::make();
        self::replace($input, $path, $value);
        $report = $this->report($input);
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertContains($reason, $report['manifest_validation']['failure_reasons']);
    }

    public static function manifestFailures(): iterable
    {
        yield '2026 synthetic target' => [['manifest', 'target', 'race_date'], '2026-01-01', 'TARGET_YEAR_FORBIDDEN'];
        yield 'future year alone' => [['manifest', 'target', 'race_date'], '2027-01-01', 'TARGET_MANIFEST_APPROVAL_MISMATCH'];
        yield 'race ID mismatch' => [['manifest', 'target', 'race_id'], 42, 'TARGET_MANIFEST_APPROVAL_MISMATCH'];
        yield 'race number mismatch' => [['manifest', 'target', 'race_number'], 2, 'TARGET_IDENTITY_INVALID'];
        yield 'invalid date' => [['manifest', 'target', 'race_date'], '2025-02-30', 'TARGET_IDENTITY_INVALID'];
        yield 'wrong registration' => [['manifest', 'target', 'entries', 0, 'external_player_id'], '999999', 'TARGET_IDENTITY_EVIDENCE_NOT_VERIFIED'];
        yield 'duplicate bike' => [['manifest', 'target', 'entries', 0, 'bike_number'], 2, 'TARGET_ROSTER_INVALID'];
        yield 'Girls' => [['manifest', 'target', 'race_type'], 'ガールズ予選', 'UNSUPPORTED_OR_UNKNOWN_CATEGORY'];
        yield 'unknown category' => [['manifest', 'target', 'race_type'], null, 'UNSUPPORTED_OR_UNKNOWN_CATEGORY'];
        yield 'JSJ017' => [['manifest', 'source'], 'JSJ017', 'SOURCE_NOT_APPROVED'];
        yield 'revision mismatch' => [['manifest', 'revision'], 2, 'MANIFEST_REVISION_MISMATCH'];
        yield 'unexpected previous SHA' => [['manifest', 'previous_manifest_sha256'], str_repeat('a', 64), 'MANIFEST_REVISION_MISMATCH'];
        yield 'expired manifest' => [['manifest', 'expires_at'], '2025-01-01T00:00:00Z', 'MANIFEST_EXPIRED_OR_INVALID'];
        yield 'missing identity proof' => [['simulation_evidence', 'documents', 'synthetic-identity'], '{}', 'TARGET_IDENTITY_EVIDENCE_NOT_VERIFIED'];
    }

    #[DataProvider('sourceFailures')]
    public function test_source_policy_is_not_inferred_from_an_existing_route(array $path, mixed $value, string $reason): void
    {
        $input = Fixture::make();
        self::replace($input, $path, $value);
        $this->assertContains($reason, $this->report($input)['source_validation']['failure_reasons']);
    }

    public static function sourceFailures(): iterable
    {
        yield 'unapproved JS' => [['source', 'source'], 'JSJ017', 'SOURCE_NOT_APPROVED'];
        yield 'policy missing' => [['source', 'policy_reference'], null, 'POLICY_UNVERIFIED'];
        yield 'policy hash mismatch' => [['source', 'policy_sha256'], str_repeat('0', 64), 'POLICY_UNVERIFIED'];
        yield 'different host' => [['source', 'request_uri'], 'https://other.invalid/pc/racelive', 'SOURCE_BINDING_MISMATCH'];
        yield 'different encp' => [['source', 'parameters', 'encp'], 'SYNTHETIC_OTHER', 'SOURCE_BINDING_MISMATCH'];
        yield 'extra parameter' => [['source', 'parameters', 'extra'], 'x', 'SOURCE_BINDING_MISMATCH'];
        yield 'response fields in request preflight' => [['source', 'redirect_detected'], false, 'SOURCE_BINDING_MISMATCH'];
    }

    public function test_independently_anchored_but_expired_policy_is_rejected(): void
    {
        $input = Fixture::make();
        $proof = json_decode($input['simulation_evidence']['documents']['synthetic-policy'], true, flags: JSON_THROW_ON_ERROR);
        $proof['expires_at'] = '2025-01-01T00:00:00Z';
        $sha = Fixture::document($input, 'synthetic-policy', $proof);
        $input['source']['policy_sha256'] = $input['decisions'][2]['approved_value']['policy_sha256'] = $sha;
        Fixture::sealApprovals($input);
        $this->assertContains('POLICY_EXPIRED', $this->report($input)['source_validation']['failure_reasons']);
    }

    public function test_unconfirmed_permission_is_not_replaced_with_public_site_or_robots_availability(): void
    {
        $input = Fixture::make();
        $proof = json_decode($input['simulation_evidence']['documents']['synthetic-policy'], true, flags: JSON_THROW_ON_ERROR);
        $proof['automatic_access_permitted'] = false;
        $proof['robots_exists'] = true;
        $sha = Fixture::document($input, 'synthetic-policy', $proof);
        $input['source']['policy_sha256'] = $input['decisions'][2]['approved_value']['policy_sha256'] = $sha;
        Fixture::sealApprovals($input);
        $this->assertContains('POLICY_UNVERIFIED', $this->report($input)['source_validation']['failure_reasons']);
    }

    public function test_future_target_needs_specific_identity_and_independent_synthetic_approval(): void
    {
        $input = Fixture::make('2027-01-01');
        $input['budget']['planned_attempt']['race_date'] = '2027-01-01';
        $result = $this->report($input)['manifest_validation'];
        $this->assertSame('VALIDATED', $result['validation_result']);
        $this->assertSame('NOT_AUTHORIZED', (new Preflight)->evaluate($input, Contract::OPERATIONAL, Fixture::now())->jsonSerialize()['status']);
    }

    public function test_budget_counts_failed_and_reserved_attempts_without_consuming_any_budget(): void
    {
        $input = Fixture::make();
        $snapshot = $input['budget'];
        $snapshot['attempts'] = [Fixture::attempt(42, 1, '2024-12-31T23:55:00Z', 'FAILED'),
            Fixture::attempt(42, 2, '2024-12-31T23:56:00Z', 'INTERRUPTED'), Fixture::attempt(41, 1, '2024-12-31T23:57:00Z', 'RESERVED')];
        $snapshot['planned_attempt'] = Fixture::attempt(41, 2, '2025-01-01T00:00:00Z');
        $limits = $input['decisions'][1]['approved_value'];
        $result = (new BudgetPreflight)->evaluate($snapshot, $limits, $input['manifest']['target']);
        $this->assertSame('VALIDATED', $result['validation_result']);
        $this->assertSame(4, $result['would_be_attempt_count']);
        $this->assertFalse($result['reservation_performed']);
        $this->assertFalse($result['budget_consumed']);
        $snapshot['attempts'][] = $snapshot['planned_attempt'];
        $snapshot['planned_attempt'] = Fixture::attempt(43, 1, '2025-01-01T00:02:00Z');
        $target = [...$input['manifest']['target'], 'race_id' => 43];
        $result = (new BudgetPreflight)->evaluate($snapshot, $limits, $target);
        $this->assertContains('TOTAL_BUDGET_EXCEEDED', $result['failure_reasons']);
    }

    #[DataProvider('budgetFailures')]
    public function test_budget_unknown_duplicate_slots_interval_and_limits_are_not_repaired(array $path, mixed $value, string $reason): void
    {
        $input = Fixture::make();
        self::replace($input, $path, $value);
        $result = $this->report($input)['budget_validation'];
        $this->assertContains($reason, $result['failure_reasons']);
        $this->assertFalse($result['budget_consumed']);
    }

    public static function budgetFailures(): iterable
    {
        yield 'ledger unknown' => [['budget', 'ledger_known'], false, 'BUDGET_STATE_UNKNOWN_OR_STOPPED'];
        yield 'stopped' => [['budget', 'stopped'], true, 'BUDGET_STATE_UNKNOWN_OR_STOPPED'];
        yield 'concurrent attempt' => [['budget', 'active_attempts'], 1, 'CONCURRENCY_LIMIT_EXCEEDED'];
        yield 'retry configured' => [['budget', 'request_settings', 'retry'], 1, 'REQUEST_SETTINGS_EXCEED_LIMITS'];
        yield 'connect timeout exceeded' => [['budget', 'request_settings', 'connect_timeout_seconds'], 6, 'REQUEST_SETTINGS_EXCEED_LIMITS'];
        yield 'total timeout exceeded' => [['budget', 'request_settings', 'total_timeout_seconds'], 21, 'REQUEST_SETTINGS_EXCEED_LIMITS'];
        yield 'missing timeout' => [['budget', 'request_settings', 'connect_timeout_seconds'], null, 'BUDGET_REQUEST_SETTINGS_UNRESOLVED'];
        yield 'unknown snapshot fields' => [['budget', 'reserve'], true, 'BUDGET_FIELDS_INVALID'];
        yield 'missing limit' => [['decisions', 1, 'approved_value', 'max_attempts'], null, 'BUDGET_LIMITS_UNRESOLVED'];
        yield 'third slot' => [['budget', 'planned_attempt', 'slot_id'], 3, 'RACE_SLOT_LIMIT_EXCEEDED'];
        yield 'duplicate slot' => [['budget', 'attempts'], [Fixture::attempt(41, 1, '2024-12-31T23:55:00Z', 'FAILED')], 'DUPLICATE_ATTEMPT_OR_SLOT'];
        yield 'interval under 60 seconds' => [['budget', 'attempts'], [Fixture::attempt(41, 2, '2024-12-31T23:59:01Z', 'SUCCEEDED')], 'ATTEMPT_INTERVAL_OR_ORDER_INVALID'];
        $unknown = Fixture::attempt(41, 2, '2024-12-31T23:50:00Z', 'INTERRUPTED');
        $unknown['request_started_at'] = null;
        yield 'interrupted unknown time' => [['budget', 'attempts'], [$unknown], 'BUDGET_HISTORY_TIME_UNKNOWN'];
    }

    public function test_budget_limits_are_supplied_values_not_default_production_constants(): void
    {
        $input = Fixture::make();
        $limits = $input['decisions'][1]['approved_value'];
        $limits['max_attempts'] = 1;
        $snapshot = $input['budget'];
        $snapshot['planned_attempt'] = Fixture::attempt(41, 2, '2025-01-01T00:00:00Z');
        $snapshot['attempts'] = [Fixture::attempt(41, 1, '2024-12-31T23:50:00Z', 'FAILED')];
        $this->assertContains('TOTAL_BUDGET_EXCEEDED', (new BudgetPreflight)->evaluate($snapshot, $limits, $input['manifest']['target'])['failure_reasons']);
    }

    #[DataProvider('timingFailures')]
    public function test_timing_uncertainty_missing_clocks_and_late_completions_never_prove_prestart(array $path, mixed $value): void
    {
        $input = Fixture::make();
        self::replace($input, $path, $value);
        $report = $this->report($input);
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame('UNKNOWN', $report['timing_validation']['validation_result']);
        $this->assertFalse($report['timing_validation']['historical_as_of_available']);
        $this->assertNull($report['timing_validation']['actual_start_at']);
    }

    public static function timingFailures(): iterable
    {
        yield 'sync evidence unknown' => [['timing', 'clock_evidence'], 'UNKNOWN'];
        yield 'TZ unknown' => [['timing', 'schedule_timezone'], null];
        yield 'rounding convention unknown' => [['timing', 'minute_convention'], null];
        yield 'seconds not zero' => [['timing', 'observed_scheduled_start_at'], '2025-01-01T09:01:01+09:00'];
        yield 'uncertainty not measured' => [['timing', 'uncertainty_microseconds', 'response_received_at'], null];
        yield 'error overlaps schedule' => [['timing', 'uncertainty_microseconds', 'observed_scheduled_start_at'], 59000000];
        yield 'request equals receive' => [['timing', 'request_started_at'], '2025-01-01T00:00:01Z'];
        yield 'save after cutoff' => [['timing', 'raw_saved_at'], '2025-01-01T00:00:06Z'];
        yield 'validation missing' => [['timing', 'validation_completed_at'], null];
        yield 'publication after cutoff' => [['timing', 'publication_completed_at'], '2025-01-01T00:00:06Z'];
        yield 'cutoff at schedule' => [['timing', 'prediction_input_as_of'], '2025-01-01T00:01:00Z'];
        yield 'unproved actual start claim' => [['timing', 'actual_start_at'], '2025-01-01T00:01:00Z'];
    }

    public function test_utc_equivalent_clocks_have_equal_intervals_and_schedule_keeps_minute_precision(): void
    {
        $input = Fixture::make();
        $first = (new TimingPreflight)->evaluate($input['timing'], Contract::SIMULATED);
        $input['timing']['response_received_at'] = '2025-01-01T09:00:01+09:00';
        $second = (new TimingPreflight)->evaluate($input['timing'], Contract::SIMULATED);
        $this->assertSame($first, $second);
        $this->assertSame(59999999, $first['scheduled_start_interval_utc_microseconds'][1] - $first['scheduled_start_interval_utc_microseconds'][0]);
    }

    public function test_reusing_another_decision_proof_or_changing_a_bound_source_is_rejected(): void
    {
        $input = Fixture::make();
        $input['decisions'][0]['evidence_reference'] = $input['decisions'][1]['evidence_reference'];
        $input['decisions'][0]['evidence_sha256'] = $input['decisions'][1]['evidence_sha256'];
        $this->assertContains('INDEPENDENT_APPROVAL_NOT_VERIFIED', $this->report($input)['decision_results']['D1']['failure_reasons']);
        $input = Fixture::make();
        $input['source']['binding_sha256'] = str_repeat('e', 64);
        $this->assertContains('TARGET_SOURCE_BINDING_MISMATCH', $this->report($input)['blocking_reasons']);
    }

    public function test_duplicate_decision_ids_are_not_silently_overwritten(): void
    {
        $input = Fixture::make();
        $input['decisions'][7] = $input['decisions'][0];
        $this->expectException(InvalidArgumentException::class);
        $this->report($input);
    }

    public function test_decision_objects_accept_key_reordering_but_reject_unknown_states(): void
    {
        $input = Fixture::make();
        $record = DecisionRecord::fromArray($input['decisions'][0]);
        $this->assertSame($record->approvalPayload(), DecisionRecord::fromArray(array_reverse($input['decisions'][0], true))->approvalPayload());
        $input['decisions'][0]['status'] = 'LIVE_AUTHORIZED';
        $this->expectException(InvalidArgumentException::class);
        DecisionRecord::fromArray($input['decisions'][0]);
    }

    public function test_no_evidence_catalog_can_verify_operational_approval_in_this_phase(): void
    {
        $input = Fixture::make();
        $evidence = new ApprovalEvidence($input['simulation_evidence']['anchors'], $input['simulation_evidence']['documents']);
        $this->assertTrue($evidence->verifies(DecisionRecord::fromArray($input['decisions'][0]), Contract::SIMULATED));
        $this->assertFalse($evidence->verifies(DecisionRecord::fromArray($input['decisions'][0]), Contract::OPERATIONAL));
    }

    #[DataProvider('unsafeValues')]
    public function test_even_synthetic_approval_cannot_enable_unimplemented_or_unsafe_policies(int $index, array $value): void
    {
        $input = Fixture::make();
        $input['decisions'][$index]['approved_value'] = $value;
        Fixture::sealApprovals($input);
        $report = $this->report($input);
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertContains('APPROVED_VALUE_OUTSIDE_PREFLIGHT_SCOPE', $report['decision_results'][Contract::DECISIONS[$index]]['failure_reasons']);
    }

    public static function unsafeValues(): iterable
    {
        yield 'JS authorization' => [3, ['primary_source' => 'JSJ017', 'second_source_status' => 'MATCHED', 'jsj017_authorized' => true]];
        yield 'overwrite snapshots' => [4, ['change_policy' => 'OVERWRITE']];
        yield 'promote measured clock claim' => [5, ['clock_policy' => 'MEASURED_CLOCK_VERIFIED']];
        yield 'automatic delete' => [6, ['storage_reference' => 'synthetic', 'recovery_reference' => 'synthetic', 'retention' => 'DELETE']];
        yield 'prediction authorization' => [7, ['prediction_use' => 'AUTHORIZED']];
    }

    public function test_target_and_source_verifiers_do_not_accept_unapproved_decision_metadata(): void
    {
        $input = Fixture::make();
        $input['decisions'][0]['status'] = $input['decisions'][2]['status'] = 'PROPOSED_NOT_APPROVED';
        $report = $this->report($input);
        $this->assertContains('TARGET_APPROVAL_NOT_VERIFIED', $report['manifest_validation']['failure_reasons']);
        $this->assertContains('SOURCE_NOT_APPROVED', $report['source_validation']['failure_reasons']);
    }

    public function test_missing_component_evidence_is_in_the_top_level_deficit_inventory(): void
    {
        $input = Fixture::make();
        $input['simulation_evidence']['documents']['synthetic-identity'] = '{}';
        $input['simulation_evidence']['documents']['synthetic-policy'] = '{}';
        $input['timing']['clock_evidence'] = 'UNKNOWN';
        $report = $this->report($input);
        $this->assertContains('manifest:TARGET_IDENTITY_EVIDENCE', $report['missing_evidence']);
        $this->assertContains('source:CURRENT_SOURCE_POLICY_PERMISSION', $report['missing_evidence']);
        $this->assertContains('timing:COMPARABLE_CLOCK_AND_SCHEDULE_EVIDENCE', $report['missing_evidence']);
    }

    public function test_reordered_decision_records_and_object_keys_do_not_change_the_report(): void
    {
        $input = Fixture::make();
        $first = $this->report($input);
        $input['decisions'] = array_reverse($input['decisions']);
        $input['manifest'] = array_reverse($input['manifest'], true);
        $input['manifest']['target'] = array_reverse($input['manifest']['target'], true);
        $this->assertSame($first, $this->report($input));
    }

    public function test_equal_and_after_schedule_times_are_never_prestart_candidates(): void
    {
        foreach ([59, 120] as $offset) {
            $timing = Fixture::make()['timing'];
            foreach (['request_started_at', 'response_received_at', 'raw_saved_at', 'validation_completed_at',
                'publication_completed_at', 'prediction_input_as_of'] as $key) {
                $timing[$key] = (new \DateTimeImmutable($timing[$key]))->modify('+'.$offset.' seconds')->format(DATE_ATOM);
            }
            $result = (new TimingPreflight)->evaluate($timing, Contract::SIMULATED);
            $this->assertSame('UNKNOWN', $result['validation_result']);
            $this->assertContains($offset === 59 ? 'CLOCK_INTERVALS_OVERLAP' : 'AFTER_SCHEDULED_INTERVAL', $result['failure_reasons']);
        }
        $timing = Fixture::make()['timing'];
        $timing['prediction_input_as_of'] = $timing['publication_completed_at'];
        $this->assertSame('VALIDATED', (new TimingPreflight)->evaluate($timing, Contract::SIMULATED)['validation_result']);
    }

    public function test_separate_day_and_track_limits_are_applied_to_the_snapshot(): void
    {
        $input = Fixture::make();
        foreach (['race_date' => '2024-12-31', 'track_code' => '12'] as $key => $value) {
            $snapshot = $input['budget'];
            $attempt = Fixture::attempt(42, 1, '2024-12-31T23:55:00Z', 'SUCCEEDED');
            $attempt[$key] = $value;
            $snapshot['attempts'] = [$attempt];
            $result = (new BudgetPreflight)->evaluate($snapshot, $input['decisions'][1]['approved_value'], $input['manifest']['target']);
            $this->assertContains('TOTAL_BUDGET_EXCEEDED', $result['failure_reasons']);
        }
    }

    public function test_schedule_date_and_planned_attempt_are_bound_to_the_same_fixed_target(): void
    {
        $input = Fixture::make();
        $input['timing']['observed_scheduled_start_at'] = '2025-01-02T09:01:00+09:00';
        $this->assertContains('TIMING_TARGET_BINDING_NOT_ESTABLISHED', $this->report($input)['blocking_reasons']);
        $input = Fixture::make();
        $input['budget']['planned_attempt']['request_started_at'] = '2025-01-01T00:00:01Z';
        $this->assertContains('PLANNED_REQUEST_CLOCK_MISMATCH', $this->report($input)['blocking_reasons']);
    }

    #[DataProvider('historyIntervals')]
    public function test_scope_wide_history_intervals_include_every_attempt_without_reordering_input(
        string $second, int $secondRace, bool $reverse, bool $valid, string $state = 'RESERVED',
    ): void {
        $input = Fixture::make();
        $input['budget']['attempts'] = [Fixture::attempt(42, 1, '2024-12-31T23:55:00Z', 'FAILED'),
            Fixture::attempt($secondRace, 2, $second, $state)];
        if ($reverse) {
            $input['budget']['attempts'] = array_reverse($input['budget']['attempts']);
        }
        $original = $input;
        $report = $this->report($input);
        $this->assertSame($valid ? 'OFFLINE_SIMULATION_VALIDATED' : 'NOT_AUTHORIZED', $report['status']);
        $this->assertSame($valid ? [] : ['ATTEMPT_INTERVAL_OR_ORDER_INVALID'], $report['budget_validation']['failure_reasons']);
        $this->assertSame(3, $report['budget_validation']['would_be_attempt_count']);
        $this->assertSame($original, $input);
    }

    public static function historyIntervals(): iterable
    {
        yield 'one second' => ['2024-12-31T23:55:01Z', 42, false, false];
        yield 'exactly sixty seconds' => ['2024-12-31T23:56:00Z', 42, false, true];
        yield 'one microsecond short' => ['2024-12-31T23:55:59.999999Z', 42, false, false];
        yield 'same instant' => ['2024-12-31T23:55:00Z', 42, false, false];
        yield 'different race' => ['2024-12-31T23:55:01Z', 41, false, false];
        yield 'reversed violating history' => ['2024-12-31T23:55:01Z', 42, true, false];
        yield 'reversed valid history' => ['2024-12-31T23:56:00Z', 42, true, true];
        yield 'future historical attempt' => ['2025-01-01T00:01:00Z', 42, false, false];
        yield 'equivalent offset same instant' => ['2025-01-01T08:55:00+09:00', 42, false, false];
        yield 'offset exact boundary' => ['2025-01-01T08:56:00+09:00', 42, false, true];
        yield 'failed attempt' => ['2024-12-31T23:55:01Z', 42, false, false, 'FAILED'];
        yield 'interrupted attempt' => ['2024-12-31T23:55:01Z', 42, false, false, 'INTERRUPTED'];
    }

    #[DataProvider('plannedApprovalExpirations')]
    public function test_all_eight_resealed_approvals_must_remain_valid_at_planned_request(int $index, string $expiry): void
    {
        $input = Fixture::futureRequest();
        $input['decisions'][$index]['expires_at'] = $expiry;
        if ($index === 0) {
            $input['manifest']['expires_at'] = $expiry;
            $input['decisions'][0]['approved_value']['manifest_sha256'] = Contract::digest($input['manifest']);
        }
        Fixture::sealApprovals($input);
        $this->assertApprovalProofsVerified($input);
        $report = $this->report($input);
        $id = Contract::DECISIONS[$index];
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame([$id], $report['missing_decisions']);
        $this->assertSame(['APPROVAL_EXPIRED_AT_PLANNED_REQUEST'], $report['decision_results'][$id]['failure_reasons']);
        $this->assertNotContains('PLANNED_REQUEST_CLOCK_MISMATCH', $report['blocking_reasons']);
        $this->assertSame('VALIDATED', $report['timing_validation']['validation_result']);
        $this->assertSame('NOT_AUTHORIZED', (new Preflight)->evaluate($input, Contract::OPERATIONAL, Fixture::now())->jsonSerialize()['status']);
    }

    public static function plannedApprovalExpirations(): iterable
    {
        foreach (Contract::DECISIONS as $index => $id) {
            yield $id.' before planned' => [$index, '2025-01-01T00:00:10Z'];
            yield $id.' equal planned' => [$index, '2025-01-01T09:00:30+09:00'];
        }
    }

    #[DataProvider('plannedDocumentExpirations')]
    public function test_resealed_policy_and_manifest_report_their_own_planned_expiration(string $component, ?string $expiry, string $reason): void
    {
        $input = Fixture::futureRequest();
        if ($component === 'source') {
            $proof = json_decode($input['simulation_evidence']['documents']['synthetic-policy'], true, flags: JSON_THROW_ON_ERROR);
            $proof['expires_at'] = $expiry;
            $sha = Fixture::document($input, 'synthetic-policy', $proof);
            $input['source']['policy_sha256'] = $input['decisions'][2]['approved_value']['policy_sha256'] = $sha;
        } else {
            $input['manifest']['expires_at'] = $expiry;
            $input['decisions'][0]['approved_value']['manifest_sha256'] = Contract::digest($input['manifest']);
        }
        Fixture::sealApprovals($input);
        $this->assertApprovalProofsVerified($input);
        $report = $this->report($input);
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame([], $report['missing_decisions']);
        $this->assertSame([$reason], $report[$component === 'source' ? 'source_validation' : 'manifest_validation']['failure_reasons']);
        $this->assertSame('VALIDATED', $report['timing_validation']['validation_result']);
    }

    public static function plannedDocumentExpirations(): iterable
    {
        foreach (['source' => 'POLICY', 'manifest' => 'MANIFEST'] as $component => $prefix) {
            yield $component.' before planned' => [$component, '2025-01-01T00:00:10Z', $prefix.'_EXPIRED_AT_PLANNED_REQUEST'];
            yield $component.' equal planned' => [$component, '2025-01-01T09:00:30+09:00', $prefix.'_EXPIRED_AT_PLANNED_REQUEST'];
            $invalid = $component === 'source' ? 'POLICY_EXPIRED' : 'MANIFEST_EXPIRED_OR_INVALID';
            yield $component.' missing expiry' => [$component, null, $invalid];
            yield $component.' invalid expiry' => [$component, 'invalid', $invalid];
        }
    }

    #[DataProvider('approvalTimeFailures')]
    public function test_evaluation_and_planned_approval_times_are_checked_independently(?string $start, ?string $expiry, array $reasons): void
    {
        $input = Fixture::futureRequest();
        $input['decisions'][1]['approved_at'] = $start;
        $input['decisions'][1]['expires_at'] = $expiry;
        Fixture::sealApprovals($input);
        $this->assertApprovalProofsVerified($input);
        $report = $this->report($input);
        $this->assertSame('NOT_AUTHORIZED', $report['status']);
        $this->assertSame($reasons, $report['decision_results']['D2']['failure_reasons']);
    }

    public static function approvalTimeFailures(): iterable
    {
        yield 'starts between evaluation and planned' => ['2025-01-01T00:00:10Z', '2025-01-02T00:00:00Z', ['APPROVAL_TIME_INVALID']];
        yield 'starts after planned' => ['2025-01-01T00:00:31Z', '2025-01-02T00:00:00Z',
            ['APPROVAL_TIME_INVALID', 'APPROVAL_NOT_YET_VALID_AT_PLANNED_REQUEST']];
        yield 'already expired' => ['2024-12-31T00:00:00Z', '2025-01-01T00:00:00Z', ['EXPIRED', 'APPROVAL_EXPIRED_AT_PLANNED_REQUEST']];
        yield 'missing expiry' => ['2024-12-31T00:00:00Z', null, ['APPROVAL_TIME_INVALID']];
        yield 'invalid expiry' => ['2024-12-31T00:00:00Z', 'invalid', ['APPROVAL_TIME_INVALID']];
        yield 'reversed validity' => ['2025-01-01T00:01:00Z', '2024-12-31T00:00:00Z', ['APPROVAL_TIME_INVALID']];
    }

    public function test_future_request_accepts_equivalent_offsets_and_all_valid_approvals_but_never_authorizes_operation(): void
    {
        $input = Fixture::futureRequest();
        $first = $this->report($input);
        $this->assertSame('OFFLINE_SIMULATION_VALIDATED', $first['status']);
        foreach ($input['decisions'] as &$record) {
            foreach (['approved_at', 'expires_at'] as $key) {
                $record[$key] = (new \DateTimeImmutable($record[$key]))->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format(DATE_ATOM);
            }
        }
        unset($record);
        $input['manifest']['expires_at'] = '2025-01-02T09:00:00+09:00';
        $input['decisions'][0]['approved_value']['manifest_sha256'] = Contract::digest($input['manifest']);
        $proof = json_decode($input['simulation_evidence']['documents']['synthetic-policy'], true, flags: JSON_THROW_ON_ERROR);
        $proof['expires_at'] = '2025-01-02T09:00:00+09:00';
        $sha = Fixture::document($input, 'synthetic-policy', $proof);
        $input['source']['policy_sha256'] = $input['decisions'][2]['approved_value']['policy_sha256'] = $sha;
        $input['budget']['planned_attempt']['request_started_at'] = '2025-01-01T09:00:30+09:00';
        Fixture::sealApprovals($input);
        $this->assertApprovalProofsVerified($input);
        $this->assertSame($first, (new Preflight)->evaluate($input, Contract::SIMULATED,
            new \DateTimeImmutable('2025-01-01T09:00:00+09:00'))->jsonSerialize());
        $this->assertSame('NOT_AUTHORIZED', (new Preflight)->evaluate($input, Contract::OPERATIONAL, Fixture::now())->jsonSerialize()['status']);
    }

    private function assertApprovalProofsVerified(array $input): void
    {
        $evidence = new ApprovalEvidence($input['simulation_evidence']['anchors'], $input['simulation_evidence']['documents']);
        foreach ($input['decisions'] as $record) {
            $this->assertTrue($evidence->verifies(DecisionRecord::fromArray($record), Contract::SIMULATED));
        }
    }

    private function report(array $input): array
    {
        return (new Preflight)->evaluate($input, Contract::SIMULATED, Fixture::now())->jsonSerialize();
    }

    private static function replace(array &$input, array $path, mixed $value): void
    {
        $cursor = &$input;
        foreach ($path as $key) {
            $cursor = &$cursor[$key];
        }
        $cursor = $value;
    }
}
