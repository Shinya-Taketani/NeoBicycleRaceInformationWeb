<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence\Attributes;

use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class ScenarioTiming
{
    public const VERSION = 'STAT39-SIMULATED-TIMING-v2';

    public static function assertScenario(array $scenario): void
    {
        $events = ['response_received_at', 'raw_saved_at', 'validation_completed_at', 'publication_completed_at',
            'prediction_as_of', 'observed_scheduled_start_at'];
        $allowed = ['time_basis', 'clock_evidence', 'schedule_timezone', 'publication_receipt', 'uncertainty_microseconds',
            ...array_diff($events, ['observed_scheduled_start_at'])];
        if (($scenario['time_basis'] ?? null) !== 'SIMULATED_SCENARIO' || array_diff(array_keys($scenario), $allowed) !== []) {
            throw new RuntimeException('Only explicitly simulated clock fields may be persisted.');
        }
        foreach ($scenario as $key => $value) {
            if (! in_array($key, ['publication_receipt', 'uncertainty_microseconds'], true) && $value !== null && ! is_scalar($value)) {
                throw new RuntimeException('Scenario clock fields cannot contain arbitrary nested data.');
            }
        }
        if (isset($scenario['uncertainty_microseconds']) && (! is_array($scenario['uncertainty_microseconds'])
            || array_diff(array_keys($scenario['uncertainty_microseconds']), $events) !== [])) {
            throw new RuntimeException('Invalid scenario clock uncertainty structure.');
        }
        if (isset($scenario['publication_receipt']) && (! is_array($scenario['publication_receipt'])
            || array_diff(array_keys($scenario['publication_receipt']), ['time_basis', 'published_at']) !== []
            || ($scenario['publication_receipt']['time_basis'] ?? null) !== 'SIMULATED_SCENARIO'
            || ! is_string($scenario['publication_receipt']['published_at'] ?? null))) {
            throw new RuntimeException('A supplied scenario receipt must be explicitly simulated.');
        }
    }

    public function classify(array $race, array $scenario): array
    {
        $answer = ['version' => self::VERSION, 'time_basis' => 'SIMULATED_SCENARIO', 'scope' => 'TIMING_CONDITIONS_ONLY',
            'capture_status' => 'TIMING_UNKNOWN', 'capture_reason' => 'UNKNOWN_CLOCK_OR_SCHEDULE',
            'cutoff_status' => 'UNKNOWN', 'cutoff_reason' => 'INCOMPLETE_CLOCK_OR_RECEIPT',
            'physical_historical_availability' => 'NOT_ESTABLISHED',
            'historical_as_of_available' => false, 'prediction_use' => 'NOT_AUTHORIZED', 'formal_adoption' => false];
        $schedule = $race['entries'][0]['attributes']['scheduled_start']['normalized_value'];
        if (($scenario['time_basis'] ?? null) !== 'SIMULATED_SCENARIO'
            || ($scenario['clock_evidence'] ?? null) !== 'SIMULATED_CLOCK_WITH_BOUNDS'
            || ($scenario['schedule_timezone'] ?? null) !== 'Asia/Tokyo' || $schedule === null) {
            return $answer;
        }
        $date = DateTimeImmutable::createFromFormat('!Ymd', $race['race_date']);
        $scheduled = $date->format('Y-m-d').'T'.$schedule.':00+09:00';
        $answer['observed_scheduled_start_at'] = $scheduled;
        $answer['schedule_precision'] = 'MINUTE';
        $received = $this->interval($scenario['response_received_at'] ?? null, $scenario, 'response_received_at');
        $start = $this->interval($scheduled, $scenario, 'observed_scheduled_start_at');
        if ($received === null || $start === null) {
            return $answer;
        }
        if ($received[1] < $start[0]) {
            $answer['capture_status'] = 'PRESTART_EVIDENCE_CANDIDATE';
            $answer['capture_reason'] = 'STRICTLY_BEFORE_SCHEDULED_START';
        } elseif ($received[0] > $start[1]) {
            $answer['capture_status'] = 'POSTSTART_CAPTURE';
            $answer['capture_reason'] = 'AFTER_SCHEDULED_START';
        } elseif ($received[0] === $received[1] && $start[0] === $start[1] && $received[0] === $start[0]) {
            $answer['capture_status'] = 'POSTSTART_CAPTURE';
            $answer['capture_reason'] = 'AT_SCHEDULED_START';
        } else {
            $answer['capture_reason'] = 'CLOCK_INTERVALS_OVERLAP';
        }
        $publication = self::instant($scenario['publication_completed_at'] ?? null);
        if (($scenario['publication_receipt']['time_basis'] ?? null) !== 'SIMULATED_SCENARIO' || $publication === null
            || self::instant($scenario['publication_receipt']['published_at'] ?? null) !== $publication) {
            return $answer;
        }
        $cutoff = $this->interval($scenario['prediction_as_of'] ?? null, $scenario, 'prediction_as_of');
        if ($cutoff === null) {
            return $answer;
        }
        $previous = null;
        foreach (['response_received_at' => 'RECEIPT', 'raw_saved_at' => 'RAW_SAVE',
            'validation_completed_at' => 'VALIDATION', 'publication_completed_at' => 'PUBLICATION'] as $key => $stage) {
            $instant = $this->interval($scenario[$key] ?? null, $scenario, $key);
            if ($instant === null) {
                return $answer;
            }
            if ($previous !== null && $previous[1] > $instant[0]) {
                return array_replace($answer, ['cutoff_reason' => 'CLOCK_ORDER_UNVERIFIED']);
            }
            if ($instant[1] > $cutoff[0]) {
                if ($instant[0] <= $cutoff[1]) {
                    return array_replace($answer, ['cutoff_reason' => 'CUTOFF_'.$stage.'_CLOCK_OVERLAP']);
                }

                return array_replace($answer, ['cutoff_status' => 'UNAVAILABLE', 'cutoff_reason' => 'CUTOFF_'.$stage.'_LATE']);
            }
            $previous = $instant;
        }
        if ($answer['capture_status'] !== 'PRESTART_EVIDENCE_CANDIDATE' || $cutoff[1] >= $start[0]) {
            return array_replace($answer, ['cutoff_status' => 'UNAVAILABLE', 'cutoff_reason' => 'PRESTART_NOT_CONFIRMED']);
        }

        return array_replace($answer, ['cutoff_status' => 'SIMULATED_TIMING_CONDITIONS_SATISFIED', 'cutoff_reason' => 'ALL_COMPLETIONS_AT_OR_BEFORE_CUTOFF']);
    }

    public static function instant(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-](?:0\d|1[0-4]):[0-5]\d)$/D', $value) !== 1) {
            return null;
        }
        try {
            $time = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                return null;
            }

            return $time->getTimestamp() * 1000000 + (int) $time->format('u');
        } catch (Throwable) {
            return null;
        }
    }

    private function interval(mixed $value, array $scenario, string $key): ?array
    {
        $instant = self::instant($value);
        $error = $scenario['uncertainty_microseconds'][$key] ?? null;
        if ($instant === null || ! is_int($error) || $error < 0 || $error > PHP_INT_MAX - abs($instant)) {
            return null;
        }

        return [$instant - $error, $instant + $error];
    }
}
