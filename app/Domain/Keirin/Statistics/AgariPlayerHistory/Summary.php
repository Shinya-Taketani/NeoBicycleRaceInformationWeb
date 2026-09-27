<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use Generator;

final class Summary
{
    private array $totals;

    private array $groups = [];

    public function __construct()
    {
        $this->totals = self::counters();
    }

    public static function counters(): array
    {
        $counts = array_fill_keys(['races', 'zero_result_races', 'result_rows', 'identified_rows', 'unresolved_rows',
            'identity_conflict_rows', 'player_meetings', 'valid_player_meetings', 'trend_calculated', 'trend_null'], 0);
        foreach (Contract::WINDOWS as $n) {
            foreach (['eligible', 'full_observed', 'full_valid', 'no_timing_history', 'insufficient_observed_meetings',
                'insufficient_valid_meetings', 'missing_meeting_values', 'input_left_truncated', 'blocked'] as $name) {
                $counts['window_'.$n.'_'.$name] = 0;
            }
        }

        return $counts;
    }

    public function add(array $dimensions, array $counts): void
    {
        foreach ($counts as $key => $value) {
            $this->totals[$key] += $value;
        }
        foreach ([['YEAR_GRADE', $dimensions['year'], $dimensions['meeting_grade']], ['RACE_CLASS', $dimensions['race_class']]] as $axis) {
            $key = json_encode($axis, JSON_THROW_ON_ERROR);
            $this->groups[$key] ??= ['dimensions' => $axis, 'counts' => self::counters()];
            foreach ($counts as $name => $value) {
                $this->groups[$key]['counts'][$name] += $value;
            }
        }
    }

    public function data(array $inventory, array $reasons): array
    {
        ksort($this->groups);
        foreach ($reasons as &$values) {
            ksort($values);
        }
        unset($values);

        return [...Contract::DISCLOSURE, 'version' => Contract::VERSION, 'totals' => $this->totals,
            'inventory' => $inventory, 'groups' => array_values($this->groups), 'reasons' => $reasons,
            'flags_are_nonexclusive' => true, 'attendance_coverage' => 'OBSERVED_RESULT_ROWS_ONLY'];
    }

    public static function csv(array $data): Generator
    {
        $stream = fopen('php://temp', 'w+');
        try {
            $header = ['dimensions', ...array_keys(self::counters()), ...array_keys(Contract::DISCLOSURE)];
            $rows = (function () use ($data) {
                yield ['dimensions' => ['TOTAL'], 'counts' => $data['totals']];
                yield from $data['groups'];
            })();
            foreach ([$header] as $row) {
                fputcsv($stream, $row, ',', '"', '', "\n");
            }
            foreach ($rows as $row) {
                fputcsv($stream, [json_encode($row['dimensions'], JSON_THROW_ON_ERROR), ...array_values($row['counts']),
                    ...array_map(fn ($v) => $v === null ? 'null' : ($v === false ? 'false' : $v), array_values(Contract::DISCLOSURE))], ',', '"', '', "\n");
            }
            rewind($stream);
            while (($line = fgets($stream)) !== false) {
                yield $line;
            }
        } finally {
            fclose($stream);
        }
    }
}
