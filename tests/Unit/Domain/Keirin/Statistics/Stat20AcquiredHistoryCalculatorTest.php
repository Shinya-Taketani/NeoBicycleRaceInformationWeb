<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Statistics\Calculators\Stat20AcquiredHistoryCalculator;
use App\Domain\Keirin\Statistics\Calculators\Stat20Calculator;
use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20AcquiredHistoryOptionsDto;
use App\Domain\Keirin\Statistics\DTO\Stat20RaceInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat20SourceInputDto;
use App\Domain\Keirin\Statistics\Services\AcquiredHistoryFeatureBuildService;
use App\Domain\Keirin\Statistics\Support\AcquiredHistoryLineage;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistoryContract as Contract;
use App\Domain\Keirin\Statistics\Support\Stat20AcquiredHistorySourceAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\Stat20Fixture;
use UnexpectedValueException;

final class Stat20AcquiredHistoryCalculatorTest extends TestCase
{
    public function test_twenty_sources_have_172_physical_rows_and_reproduce(): void
    {
        $input = $this->fixture();
        $this->assertCount(172, $input->race->sources);
        $this->assertCount(1, array_filter($input->race->sources, fn ($s) => $s->statCode === 'STAT-41'));
        $out = $this->calculator()->calculate($input);
        $this->assertCount(9, $out);
        $this->assertEquals($out, $this->calculator()->calculate($input));
        foreach ($out as $result) {
            $this->assertCount(20, $result->features['STAT_QUALITY']);
            $this->assertSame(20, $result->features['SOURCE_RESULT_COVERAGE']['counts']['VALID']);
            $this->assertSame(1, $result->features['SOURCE_RESULT_COVERAGE']['valid_ratio']);
            $this->assertNull($result->features['CONFIDENCE']);
            $this->assertSame('NOT_AUTHORIZED', $result->evidence['prediction_use']);
        }
        $this->assertCount(15, Stat20Calculator::SOURCE_STAT_CODES);
    }

    public function test_pr128_v2_literal_contract_and_frozen_semantic_digest_are_unchanged(): void
    {
        $this->assertSame('STAT-20-acquired-history-v2', Contract::VERSION);
        $this->assertSame('STAT-09-acquired-career-v2', Contract::VERSIONS['STAT-09']);
        $rows = $this->calculator()->calculate($this->fixture(7));
        $digest = (new DeterministicJsonHasher)->hash(array_map(fn ($r) => ['features' => $r->features, 'evidence' => $r->evidence,
            'status' => $r->status->value, 'quality' => $r->qualityStatus->value, 'hash' => $r->inputHash], $rows));
        $this->assertSame('2f156c255d20945514b9521360c81bd854a10188e56f75121dbc1ef1ca57dd53', $digest);
        $this->assertSame('1ec4fe70ff18931499c3c252a6f207ffe02e7fe083ba370ab78a836237f383ec', $rows[0]->inputHash);
    }

    public function test_v3_accepts_exact_new09_metadata_and_does_not_relax_the_other_nineteen_versions(): void
    {
        $this->assertSame('ACQUIRED-HISTORY-RACE-AUDIT-v3', Contract::BUILD_SERVICE_VERSION);
        $versions = Contract::versions('STAT-20-acquired-history-v3');
        $this->assertSame('STAT-09-acquired-career-grade-breakdown-v3', $versions['STAT-09']);
        $this->assertSame(array_diff_key(Contract::VERSIONS, ['STAT-09' => true]), array_diff_key($versions, ['STAT-09' => true]));
        $i = $this->fixture(7);
        $metadata = ['stat09_profile' => 'grade-breakdown-v3', 'grade_projection_contract' => 'STAT09-SAVED-HISTORY-GRADES-v1'];
        $sources = array_map(fn ($s) => $s->statCode === 'STAT-09' ? new Stat20SourceInputDto(...array_replace(get_object_vars($s), [
            'calculationVersion' => 'STAT-09-acquired-career-grade-breakdown-v3', 'evidence' => $s->evidence + $metadata])) : $s, $i->race->sources);
        $race = new Stat20RaceInputDto(...array_replace(get_object_vars($i->race), ['sources' => $sources]));
        $audits = $i->sourceRunAudits;
        $audits['STAT-09']['version'] = 'STAT-09-acquired-career-grade-breakdown-v3';
        $audits['STAT-09']['parameters'] += $metadata;
        $new = new Stat20AcquiredHistoryInputDto($race, $audits, 'STAT-20-acquired-history-v3');
        $out = $this->calculator()->calculate($new);
        $this->assertSame('STAT-20-acquired-history-v3', $out[0]->evidence['source_contract']);
        $this->assertEquals($out, $this->calculator()->calculate($new));
        $bad = [new Stat20AcquiredHistoryInputDto($race, $audits), new Stat20AcquiredHistoryInputDto($i->race, $i->sourceRunAudits, 'STAT-20-acquired-history-v3')];
        foreach (['stat09_profile' => 'legacy-v2', 'grade_projection_contract' => 'wrong', 'build_service_version' => 'future'] as $key => $value) {
            $a = $audits;
            $a['STAT-09']['parameters'][$key] = $value;
            $bad[] = new Stat20AcquiredHistoryInputDto($race, $a, 'STAT-20-acquired-history-v3');
        }
        foreach (['stat09_profile' => 'legacy-v2', 'grade_projection_contract' => 'wrong', 'stat01_input_hash' => str_repeat('f', 64)] as $key => $value) {
            $s = array_values(array_filter($sources, fn ($s) => $s->statCode === 'STAT-09'))[0];
            $bad[] = new Stat20AcquiredHistoryInputDto(Stat20Fixture::change($race, 'STAT-09', 1,
                ['evidence' => array_replace($s->evidence, [$key => $value])]), $audits, 'STAT-20-acquired-history-v3');
        }
        $bad[] = new Stat20AcquiredHistoryInputDto(Stat20Fixture::change($race, 'STAT-09', 1,
            ['calculationVersion' => 'STAT-09-acquired-career-v2']), $audits, 'STAT-20-acquired-history-v3');
        foreach ($bad as $input) {
            try {
                $this->calculator()->calculate($input);
                $this->fail('Cross-contract, spoofed metadata or mismatched parent accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    #[DataProvider('invalidSources')]
    public function test_invalid_source_identity_contract_and_versions_are_rejected(array $changes): void
    {
        $i = $this->fixture();
        $r = Stat20Fixture::change($i->race, 'STAT-16', 1, $changes);
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits));
    }

    public static function invalidSources(): array
    {
        return array_map(fn ($c) => [$c], [
            ['calculationVersion' => 'STAT-16-six-condition-performance-v2'], ['runId' => 999], ['raceId' => 999],
            ['raceEntryId' => 999], ['playerId' => 999], ['bikeNumber' => 9], ['subjectType' => 'RACE'], ['subjectKey' => 'race_entry:999'],
            ['inputHash' => 'invalid'], ['status' => 'UNKNOWN'], ['qualityStatus' => 'UNKNOWN'],
            ['inputAsOf' => new \DateTimeImmutable('2024-01-02T11:55:01+09:00')],
            ['evidence' => ['stat01_run_id' => 999]], ['evidence' => ['lineage_contract' => 'old']],
        ]);
    }

    public function test_duplicate_and_excess_sources_are_rejected(): void
    {
        $i = $this->fixture();
        foreach ([[$i->race->sources[0]], [$i->race->sources[0], $i->race->sources[1]]] as $extra) {
            $r = new Stat20RaceInputDto(...array_replace(get_object_vars($i->race), ['sources' => [...$i->race->sources, ...$extra]]));
            try {
                $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits));
                $this->fail('Duplicate/excess sources accepted.');
            } catch (UnexpectedValueException $e) {
                $this->assertStringContainsString('bound', $e->getMessage());
            }
        }
        $small = $this->fixture(5);
        $sources = [...$small->race->sources, $small->race->sources[0]];
        $this->expectException(UnexpectedValueException::class);
        $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto(
            new Stat20RaceInputDto(...array_replace(get_object_vars($small->race), ['sources' => $sources])), $small->sourceRunAudits));
    }

    public function test_missing_unselected_failed_and_not_applicable_have_distinct_denominators(): void
    {
        $i = $this->fixture();
        $sources = array_values(array_filter($i->race->sources, fn ($s) => ! in_array($s->statCode, ['STAT-09', 'STAT-25'], true)));
        $runs = $i->race->sourceRuns;
        unset($runs['STAT-09']);
        $r = new Stat20RaceInputDto(...array_replace(get_object_vars($i->race), ['sources' => $sources, 'sourceRuns' => $runs]));
        $r = Stat20Fixture::change($r, 'STAT-44', 1, ['status' => 'NOT_APPLICABLE']);
        $i = new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits);
        $out = $this->calculator()->calculate($i)[0];
        $audit = $out->features['STAT_QUALITY'];
        $this->assertSame('SOURCE_RUN_NOT_SELECTED', $audit['STAT-09']['reason']);
        $this->assertSame('SOURCE_RESULT_MISSING', $audit['STAT-25']['reason']);
        $this->assertSame(19, $out->features['SOURCE_RESULT_COVERAGE']['applicable_stat_count']);
        $this->assertSame(2, $out->features['SOURCE_RESULT_COVERAGE']['counts']['DATA_SOURCE_PENDING']);
        $this->assertNotSame('INVALID_INPUT', $out->status->value);
        $audits = $i->sourceRunAudits;
        $audits['STAT-25']['status'] = 'PARTIALLY_SUCCEEDED';
        $audits['STAT-25']['error_count'] = 1;
        $audits['STAT-25']['race_item'] = ['status' => 'FAILED', 'error_message' => 'Synthetic failure'];
        $failed = $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $audits))[0];
        $this->assertSame('INVALID_INPUT', $failed->status->value);
        $this->assertContains('UPSTREAM_FAILURE_OR_UNATTRIBUTED_MISSING:STAT-25', $failed->evidence['blocked_reasons']);
    }

    public function test_saved_history_time_is_distinct_from_retrospective_fetch_time(): void
    {
        $i = $this->fixture();
        $r = Stat20Fixture::change($i->race, 'STAT-27', 1, ['sourceFetchedAt' => new \DateTimeImmutable('2026-01-01T00:00:00+09:00')]);
        $out = $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits))[0];
        $this->assertSame('AFTER', $out->features['STAT_QUALITY']['STAT-27']['fetch_vs_input_as_of']);
        $this->assertNotSame('INVALID_INPUT', $out->status->value);
        $s = array_values(array_filter($r->sources, fn ($s) => $s->statCode === 'STAT-27'))[0];
        $r = Stat20Fixture::change($r, 'STAT-27', 1, ['evidence' => array_replace($s->evidence, ['history_max_at' => '2024-01-02T11:55:00+09:00'])]);
        $out = $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits))[0];
        $this->assertSame('INVALID_INPUT', $out->status->value);
    }

    public function test_run_and_result_lineage_and_service_version_must_both_match(): void
    {
        $i = $this->fixture();
        foreach (['lineage_contract', 'build_service_version', 'stat01_run_id'] as $key) {
            $runs = $i->sourceRunAudits;
            $runs['STAT-09']['parameters'][$key] = 'wrong';
            try {
                $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($i->race, $runs));
                $this->fail('Incompatible run accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
        $s = array_values(array_filter($i->race->sources, fn ($s) => $s->statCode === 'STAT-09'))[0];
        foreach (['stat01_run_id' => 2, 'stat01_input_hash' => str_repeat('0', 64), 'lineage_contract' => 'old'] as $key => $value) {
            $r = Stat20Fixture::change($i->race, 'STAT-09', 1, ['evidence' => array_replace($s->evidence, [$key => $value])]);
            try {
                $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits));
                $this->fail('Incompatible result accepted.');
            } catch (UnexpectedValueException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_lineage_conflicts_are_not_overwritten(): void
    {
        $hash = str_repeat('a', 64);
        $this->assertSame(1, AcquiredHistoryLineage::attach([], 1, $hash)['stat01_run_id']);
        $this->expectException(UnexpectedValueException::class);
        AcquiredHistoryLineage::attach(['stat01_run_id' => 2], 1, $hash);
    }

    #[DataProvider('upstreamStatuses')]
    public function test_each_acquired_upstream_status_is_retained(string $status): void
    {
        $i = $this->fixture(5);
        $r = Stat20Fixture::change($i->race, 'STAT-09', 1, ['status' => $status]);
        $out = $this->calculator()->calculate(new Stat20AcquiredHistoryInputDto($r, $i->sourceRunAudits))[0];
        $this->assertSame($status, $out->features['STAT_QUALITY']['STAT-09']['status']);
        $this->assertSame($status === 'INVALID_INPUT', $out->status->value === 'INVALID_INPUT');
        $this->assertSame(1, $out->features['SOURCE_RESULT_COVERAGE']['counts'][$status]);
    }

    public static function upstreamStatuses(): array
    {
        return array_map(fn ($s) => [$s], ['NO_HISTORY', 'PARTIAL_HISTORY', 'PARTIAL', 'MISSING_INPUT', 'INVALID_INPUT', 'NOT_APPLICABLE']);
    }

    public function test_new_options_allow_19_sources_but_not_duplicate_or_twentieth(): void
    {
        $this->assertCount(19, (new Stat20AcquiredHistoryOptionsDto(1, range(2, 20), null, null, 42))->sourceRunIds);
        $this->expectException(\InvalidArgumentException::class);
        new Stat20AcquiredHistoryOptionsDto(1, range(2, 21), null, null, 42);
    }

    private function calculator(): Stat20AcquiredHistoryCalculator
    {
        $hasher = new DeterministicJsonHasher;

        return new Stat20AcquiredHistoryCalculator(new Stat20Calculator($hasher), $hasher, new Stat20AcquiredHistorySourceAudit);
    }

    private function fixture(int $n = 9): Stat20AcquiredHistoryInputDto
    {
        $r = Stat20Fixture::race($n);
        $sources = $r->sources;
        $runs = $r->sourceRuns;
        foreach (Contract::NEW_STATS as $index => $code) {
            $runs[$code] = 16 + $index;
            foreach (array_slice($r->sources, 0, $n) as $target) {
                $sources[] = new Stat20SourceInputDto(...array_replace(get_object_vars($target), [
                    'id' => 99000 + $index * 10 + $target->bikeNumber, 'runId' => $runs[$code], 'statCode' => $code,
                    'calculationVersion' => Contract::VERSIONS[$code],
                    'evidence' => AcquiredHistoryLineage::attach(['data_role' => 'RETROSPECTIVE_ONLY', 'prediction_use' => 'NOT_AUTHORIZED',
                        'historical_as_of_available' => false, 'history_max_at' => '2023-12-31T12:00:00+09:00'], 1, $target->inputHash),
                ]));
            }
        }
        $audits = [];
        foreach ($runs as $code => $id) {
            $audits[$code] = ['run_id' => $id, 'status' => 'SUCCEEDED', 'version' => Contract::VERSIONS[$code], 'error_count' => 0,
                'parameters' => ['stat01_run_id' => 1, 'lineage_contract' => AcquiredHistoryLineage::CONTRACT,
                    'build_service_version' => AcquiredHistoryFeatureBuildService::VERSION], 'race_item' => ['status' => 'PARTIAL']];
        }

        return new Stat20AcquiredHistoryInputDto(new Stat20RaceInputDto(...array_replace(get_object_vars($r), ['sources' => $sources, 'sourceRuns' => $runs])), $audits);
    }
}
