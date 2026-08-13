<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt02SignalDefinitionDto;
use App\Domain\Keirin\Backtest\Enums\Bt02AnalysisRole;
use InvalidArgumentException;
use RuntimeException;

class Bt02SignalRegistry
{
    public const VERSION = 'BT02-SIGNAL-REGISTRY-v1';

    public const STAT42_TRANSFORM = 'PAIR_RESIDUAL_WEIGHTED_MEAN_V1';

    /** @var array<string, Bt02SignalDefinitionDto> */
    private array $definitions;

    public function __construct()
    {
        $inMeeting = ['IN_MEETING_RESULT_CONFIRMATION_NOT_RECONSTRUCTED'];
        $this->definitions = [];
        foreach ([
            ['STAT-07', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'DELTA_MEAN_RESIDUAL', 'features.DELTA.mean_score_expectation_residual', 'IDENTITY', []],
            ['STAT-08', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'DELTA_MEAN_RESIDUAL', 'features.DELTA.mean_score_expectation_residual', 'IDENTITY', []],
            ['STAT-10', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'MEAN_RESIDUAL_3_MINUS_10', 'features.SUMMARY.mean_residual_3_minus_10', 'IDENTITY', $inMeeting],
            ['STAT-11', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'ABNORMAL_RATE_10', 'features.COUNT_WINDOWS.10.abnormal_rate', 'IDENTITY', []],
            ['STAT-12', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'CURRENT_GAP_EMPIRICAL_PERCENTILE', 'features.HISTORICAL_PRE_MEETING_GAPS.current_gap_empirical_percentile', 'IDENTITY', $inMeeting],
            ['STAT-23', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'DELTA_MEAN_RESIDUAL', 'features.DELTA.mean_score_expectation_residual', 'IDENTITY', []],
            ['STAT-24', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'PRE_MEETING_RESIDUAL_SD_10', 'features.PRE_MEETING.COUNT_WINDOWS.10.residual_stddev_pop', 'IDENTITY', $inMeeting],
            ['STAT-26', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'STARTED_RACE_COUNT_30D', 'features.DAY_WINDOWS.30.started_race_count', 'IDENTITY', []],
            ['STAT-31', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'SEMIFINAL_OR_FINAL_MEAN_RESIDUAL', 'features.STAGE_EXPERIENCE.SEMIFINAL_OR_FINAL.mean_score_expectation_residual', 'STAT31_AGGREGATE_INTEGRITY_V1', []],
            ['STAT-32', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'DELTA_MEAN_RESIDUAL', 'features.DELTA.mean_score_expectation_residual', 'IDENTITY', []],
            ['STAT-33', 'RACE_ENTRY', Bt02AnalysisRole::DiagnosticOnly, 'MATCHING_TRANSITION_MEAN_NEXT_RESIDUAL', 'features.MATCHING_TRANSITION_HISTORY.mean_next_residual', 'IDENTITY', []],
            ['STAT-39', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'FIELD_BIKE_DELTA_MEAN_RESIDUAL', 'features.FIELD_BIKE_DELTA.mean_score_expectation_residual', 'IDENTITY', ['MISSING_FRAME_NUMBER']],
            ['STAT-42', 'RACE_ENTRY', Bt02AnalysisRole::EntryIncremental, 'PAIR_RESIDUAL_WEIGHTED_MEAN', null, self::STAT42_TRANSFORM, []],
            ['STAT-41', 'RACE', Bt02AnalysisRole::RaceStratifier, 'TOP_SCORE_GAP_RANK1_RANK2', 'features.TOP_SCORE_STRUCTURE.gap_rank1_rank2', 'IDENTITY', []],
        ] as [$stat, $subject, $role, $code, $path, $transform, $reasons]) {
            $this->definitions[$stat] = new Bt02SignalDefinitionDto($stat, $subject, $role, $code, $path, $transform, $reasons);
        }
    }

    /** @return list<Bt02SignalDefinitionDto> */
    public function all(): array
    {
        return array_values($this->definitions);
    }

    public function get(string $statCode): Bt02SignalDefinitionDto
    {
        return $this->definitions[$statCode] ?? throw new InvalidArgumentException("Unknown BT-02 signal {$statCode}.");
    }

    /** @param array<string, mixed> $features */
    public function primary(string $statCode, array $features): ?float
    {
        return match ($statCode) {
            'STAT-31' => $this->stat31($features),
            'STAT-42' => $this->stat42($features),
            default => $this->number($this->path($features, $this->get($statCode)->primaryFeaturePath)),
        };
    }

    /** @param array<string, mixed> $features */
    private function stat31(array $features): ?float
    {
        $stage = $features['STAGE_EXPERIENCE'] ?? null;
        if (! is_array($stage)) {
            return null;
        }
        $derived = $this->weightedSections($stage, ['SEMIFINAL', 'FINAL']);
        $stored = $this->number($stage['SEMIFINAL_OR_FINAL']['mean_score_expectation_residual'] ?? null);
        if ($derived === null && $stored === null) {
            return null;
        }
        if ($derived === null || $stored === null || abs($derived - $stored) > 1e-12 * max(1.0, abs($derived), abs($stored))) {
            throw new RuntimeException('STAT-31 stored aggregate did not match its semifinal/final weighted residual.');
        }

        return $stored;
    }

    /** @param array<string, mixed> $features */
    private function stat42(array $features): ?float
    {
        $pairs = $features['HEAD_TO_HEAD_BY_COENTRANT'] ?? [];
        if (! is_array($pairs)) {
            throw new RuntimeException('STAT-42 coentrant history was invalid.');
        }
        $numerator = 0.0;
        $denominator = 0;
        foreach ($pairs as $pair) {
            if (! is_array($pair) || ! is_array($pair['DIRECT_HISTORY'] ?? null)) {
                throw new RuntimeException('STAT-42 direct history was invalid.');
            }
            $history = $pair['DIRECT_HISTORY'];
            $count = $this->nonNegativeInteger($history['relative_expectation_residual_sample_count'] ?? null, 'STAT-42 residual sample count');
            if ($count === 0) {
                continue;
            }
            $mean = $this->number($history['mean_relative_expectation_residual'] ?? null);
            if ($mean === null) {
                throw new RuntimeException('STAT-42 residual mean was missing for a positive sample count.');
            }
            $numerator += $count * $mean;
            $denominator += $count;
        }

        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @param array<string, mixed> $stage @param list<string> $keys */
    private function weightedSections(array $stage, array $keys): ?float
    {
        $numerator = 0.0;
        $denominator = 0;
        foreach ($keys as $key) {
            $section = $stage[$key] ?? null;
            if (! is_array($section)) {
                throw new RuntimeException("STAT-31 {$key} section was invalid.");
            }
            $count = $this->nonNegativeInteger($section['residual_sample_count'] ?? null, "STAT-31 {$key} residual sample count");
            if ($count === 0) {
                continue;
            }
            $mean = $this->number($section['mean_score_expectation_residual'] ?? null);
            if ($mean === null) {
                throw new RuntimeException("STAT-31 {$key} residual mean was missing for a positive sample count.");
            }
            $numerator += $count * $mean;
            $denominator += $count;
        }

        return $denominator > 0 ? $numerator / $denominator : null;
    }

    /** @param array<string, mixed> $features */
    private function path(array $features, ?string $path): mixed
    {
        if ($path === null) {
            return null;
        }
        $segments = explode('.', preg_replace('/^features\./', '', $path) ?? $path);
        $value = $features;
        foreach ($segments as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    private function number(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new RuntimeException('BT-02 primary feature was not numeric.');
        }
        $number = (float) $value;
        if (! is_finite($number)) {
            throw new RuntimeException('BT-02 primary feature was not finite.');
        }

        return $number;
    }

    private function nonNegativeInteger(mixed $value, string $name): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("{$name} was invalid.");
        }
        $count = (int) $value;
        if ($count < 0) {
            throw new RuntimeException("{$name} was negative.");
        }

        return $count;
    }
}
