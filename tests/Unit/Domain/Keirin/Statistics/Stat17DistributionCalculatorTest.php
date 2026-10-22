<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Statistics;

use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceDetailParser;
use App\Domain\Keirin\Scraping\Support\CharacterEncodingConverter;
use App\Domain\Keirin\Statistics\Calculators\Stat17DistributionCalculator;
use App\Domain\Keirin\Statistics\DTO\Stat17DistributionInputDto;
use App\Domain\Keirin\Statistics\DTO\Stat17DistributionResultDto;
use App\Domain\Keirin\Statistics\Support\DeterministicJsonHasher;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Stat17DistributionCalculatorTest extends TestCase
{
    public function test_real_raw_four_counts_produce_only_their_observed_composition(): void
    {
        $row = $this->realCounts();
        $out = $this->calculate($row);
        $profile = $out->features['RETROSPECTIVE'];
        $this->assertSame(['escape_count' => 4, 'sprint_count' => 5, 'overtake_count' => 1, 'mark_count' => 0], $profile['counts']);
        $this->assertSame(10, $profile['total_displayed_count']);
        $this->assertSame([0.4, 0.5, 0.1, 0], array_values($profile['composition']));
        $this->assertSame(3, $profile['observed_tactic_types']);
        $this->assertSame('PARTIAL', $out->status->value);
        $this->assertSame('DEGRADED', $out->qualityStatus->value);
        $this->assertFalse($out->evidence['historical_as_of_available']);
        $this->assertNull($out->evidence['official_publication_at']);
        $this->assertSame('NOT_AUTHORIZED', $out->features['prediction_use']);
        $this->assertNull($out->features['points']);
        $this->assertNull($out->features['confidence']);
        $this->assertSame($out->inputHash, $this->calculate($row)->inputHash);
    }

    public function test_control_zero_and_partial_missing_never_become_usage_or_success_rates(): void
    {
        $row = $this->realCounts();
        foreach (['escape_count', 'sprint_count', 'overtake_count', 'mark_count'] as $field) {
            $row[$field] = 0; // Isolated arithmetic boundary, not a replacement historical record.
        }
        $zero = $this->calculate($row)->features['RETROSPECTIVE'];
        $this->assertSame(0, $zero['total_displayed_count']);
        $this->assertSame(0, $zero['observed_tactic_types']);
        $this->assertNull($zero['composition']);
        $this->assertSame('ZERO_TOTAL_UNDEFINED', $zero['distribution_status']);
        $row['sprint_count'] = null;
        $missing = $this->calculate($row);
        $this->assertSame('MISSING_INPUT', $missing->status->value);
        $this->assertSame(['sprint_count'], $missing->features['RETROSPECTIVE']['missing_fields']);
        $this->assertNull($missing->features['RETROSPECTIVE']['total_displayed_count']);
        $this->assertNull($missing->features['RETROSPECTIVE']['composition']);
        $this->assertNotSame($this->calculate($this->realCounts())->inputHash, $missing->inputHash);
    }

    public function test_invalid_counts_and_forbidden_targets_fail_closed(): void
    {
        $row = $this->realCounts();
        foreach (['escape_count' => -1, 'sprint_count' => 10000, 'overtake_count' => '3', 'mark_count' => 1.5,
            'race_date' => '2026-01-01', 'use_class' => 'PREDICTION'] as $key => $value) {
            try {
                $this->calculate(array_replace($row, [$key => $value]));
                $this->fail('Invalid snapshot accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        unset($row['mark_count']);
        $this->expectException(InvalidArgumentException::class);
        $this->calculate($row);
    }

    private function calculate(array $row): Stat17DistributionResultDto
    {
        return (new Stat17DistributionCalculator(new DeterministicJsonHasher))->calculate(
            new Stat17DistributionInputDto($row, new DateTimeImmutable('2025-01-01T00:00:00+09:00')));
    }

    private function realCounts(): array
    {
        $path = getenv('STAT38_TEST_FIXTURE') ?: '/home/shinya/neo-keirin-artifacts/stat38-distance-laps-mvp-01-20261021-01/actual-fixture.json';
        if (! is_file($path) || hash_file('sha256', $path) !== '7ec6fae7e6ff5f8137754986c8f9e8d10bd766b9c65f61aa1252944494ba04dc') {
            throw new RuntimeException('Real tactic fixture unavailable or changed.');
        }
        $case = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR)['cases'][0];
        $bytes = file_get_contents(dirname($path).'/'.$case['fixture_file']);
        if (strlen($bytes) !== $case['original']['bytes'] || hash('sha256', $bytes) !== $case['original']['sha256']) {
            throw new RuntimeException('Real tactic Raw seal mismatch.');
        }
        [$html] = (new CharacterEncodingConverter)->convertToUtf8($bytes, $case['log']['content_type']);
        $entry = (new RaceDetailParser(new EmbeddedJsonExtractor))->parse($html)->entries[0];

        // Observation ID is a local unit-test identifier; values/target/registration are the sealed real capture.
        return ['id' => 1, 'race_date' => $case['target']['race']['race_date'], 'use_class' => 'RETROSPECTIVE_ONLY',
            'escape_count' => $entry->escapeCount, 'sprint_count' => $entry->sprintCount,
            'overtake_count' => $entry->overtakeCount, 'mark_count' => $entry->markCount,
            'race_entry_id' => $case['target']['entries'][0]['id'], 'external_player_id' => $entry->externalPlayerId,
            'scraping_fetch_log_id' => $case['log']['id'], 'raw_sha256' => $case['original']['sha256'],
            'source_metadata_hash' => hash('sha256', json_encode($case['log'], JSON_THROW_ON_ERROR)),
            'parser_version' => 'PJ0315-TACTIC-BHS-OBSERVATION-v1', 'raw_fields' => [],
            'fetched_at' => $case['log']['fetched_at'], 'fetch_log_saved_at' => null];
    }
}
