<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

final class AttributeEligibility
{
    public const VERSION = 'STAT39-SYNTHETIC-ATTRIBUTE-PURPOSES-v1';

    public const INPUT_CONTRACT_VERSION = 'STAT01-EXISTING-DB-DECIMAL6-2-CANDIDATE-v1';

    public const CONTRACT_SOURCES = [
        'database/migrations/2026_07_18_000001_create_keirin_scraping_tables.php',
        'app/Models/RaceEntry.php',
        'app/Domain/Keirin/Statistics/DTO/Stat01EntryInputDto.php',
        'app/Domain/Keirin/Statistics/Repositories/Stat01InputRepository.php',
        'app/Domain/Keirin/Statistics/Calculators/Stat01Calculator.php',
    ];

    /** The caller supplies a verified exact decimal, not a rounded repository/float value. */
    public function scoreCandidate(?string $normalized): array
    {
        $result = ['stat01_input_contract_version' => self::INPUT_CONTRACT_VERSION, 'positive_decimal_candidate' => null,
            'stat01_input_format_status' => 'UNKNOWN', 'stat01_input_format_reason' => 'NO_VERIFIED_EXACT_DECIMAL'];
        if ($normalized === null || preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $normalized) !== 1) {
            return $result;
        }
        $parts = explode('.', $normalized);
        $integer = ltrim($parts[0], '0');
        $fraction = rtrim($parts[1] ?? '', '0');
        $reason = match (true) {
            strlen($integer) > 4 => 'EXCEEDS_DECIMAL_6_2_INTEGER_DIGITS',
            strlen($fraction) > 2 => 'EXCEEDS_DECIMAL_6_2_SCALE',
            default => 'EXACTLY_REPRESENTABLE_IN_DECIMAL_6_2',
        };

        return array_replace($result, ['positive_decimal_candidate' => $integer !== '' || $fraction !== '',
            'stat01_input_format_status' => strlen($integer) <= 4 && strlen($fraction) <= 2 ? 'COMPATIBLE' : 'INCOMPATIBLE',
            'stat01_input_format_reason' => $reason]);
    }

    /** Only called for entries freshly reprojected from an independently verified capture. */
    public function entry(array $entry, array $timing): array
    {
        $attributes = $entry['attributes'];
        $score = $attributes['race_score'];
        $valueConditions = $score['presence'] === 'PRESENT' && $score['normalization_status'] === 'VERIFIED'
            && $score['positive_decimal_candidate'] === true && $score['stat01_input_format_status'] === 'COMPATIBLE';
        $timingSatisfied = $timing['cutoff_status'] === 'SIMULATED_TIMING_CONDITIONS_SATISFIED';
        $status = match (true) {
            ! $valueConditions => 'NOT_SATISFIED',
            $timingSatisfied => 'SIMULATED_CANDIDATE_CONDITIONS_SATISFIED',
            $timing['cutoff_status'] === 'UNKNOWN' => 'UNKNOWN',
            default => 'NOT_SATISFIED',
        };
        $reasons = [];
        if ($score['presence'] !== 'PRESENT') {
            $reasons[] = 'RACE_SCORE_'.$score['presence'];
        }
        if ($score['normalization_status'] !== 'VERIFIED') {
            $reasons[] = 'RACE_SCORE_NORMALIZATION_UNVERIFIED';
        }
        if ($score['positive_decimal_candidate'] !== true) {
            $reasons[] = 'RACE_SCORE_POSITIVE_DECIMAL_NOT_CONFIRMED';
        }
        if ($score['stat01_input_format_status'] !== 'COMPATIBLE') {
            $reasons[] = $score['stat01_input_format_reason'];
        }
        if (! $timingSatisfied) {
            $reasons[] = $timing['cutoff_reason'];
        }

        return ['bike_number' => $entry['bike_number'], 'external_registration_number' => $entry['external_registration_number'],
            'attributes' => $attributes, 'purposes' => [
                'bike_observation' => $this->observation($attributes['bike_number']),
                'frame_observation' => [...$this->observation($attributes['frame_number']), 'official_applicability' => 'UNKNOWN'],
                'stat01_score_input' => ['status' => $status, 'reasons' => $reasons,
                    'scope' => 'SIMULATED_ENTRY_VALUE_FORMAT_AND_TIMING_ONLY', 'value_conditions_satisfied' => $valueConditions,
                    'timing_conditions_status' => $timing['cutoff_status'], 'input_set_eligibility' => 'NOT_EVALUATED'],
                'official_assignment_correction' => ['status' => 'BLOCKED', 'reason' => 'OFFICIAL_DEFINITION_UNKNOWN'],
            ], ...BundleFiles::restrictions()];
    }

    private function observation(array $attribute): array
    {
        return ['status' => $attribute['presence'] === 'PRESENT' && $attribute['normalization_status'] === 'VERIFIED'
            ? 'VERIFIED_OBSERVATION' : 'UNKNOWN', 'raw_identity_status' => 'VERIFIED',
            'presence' => $attribute['presence'], 'normalization_status' => $attribute['normalization_status']];
    }
}
