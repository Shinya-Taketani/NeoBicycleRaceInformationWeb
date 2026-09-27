<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\AgariPlayerHistory;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract as Relative;
use Brick\Math\BigRational;
use PDO;
use RuntimeException;

final class Meetings
{
    public function build(PDO $db): void
    {
        $rows = $db->query('SELECT e.group_key,e.payload,r.payload AS race,m.conflict FROM entries e
            JOIN races r ON r.id=e.race_id JOIN meetings m ON m.id=r.meeting ORDER BY e.group_key,e.race_id,e.id');
        $put = $db->prepare('INSERT INTO groups VALUES (?,?,?,?,?,?,?,?,NULL)');
        $group = null;
        $sum = BigRational::zero();
        $identified = false;
        $db->beginTransaction();
        while ($record = $rows->fetch()) {
            if ($group !== null && $group['id'] !== $record['group_key']) {
                $this->save($put, $group, $sum, $identified);
                $group = null;
                $sum = BigRational::zero();
                $identified = false;
            }
            $entry = json_decode($record['payload'], true, flags: JSON_THROW_ON_ERROR);
            $race = json_decode($record['race'], true, flags: JSON_THROW_ON_ERROR);
            if ($group === null) {
                $group = [...Contract::DISCLOSURE, 'id' => $record['group_key'], 'source' => 'keirin_jp',
                    'external_player_id' => $entry['external_player_id'], 'measurement_definition_id' => Relative::DEFINITION,
                    'race_class' => $race['classification']['race_class'], 'meeting' => $race['meeting'],
                    'meeting_grade' => $race['classification']['meeting_grade'], 'first_observed_date' => $race['race_date'], 'context_flags' => [],
                    'observed_result_rows' => 0, 'adopted_races' => 0, 'partial_rows' => 0, 'missing_rows' => 0,
                    'abnormal_rows' => 0, 'incomparable_rows' => 0, 'exclusion_reasons' => [], 'evidence' => []];
            }
            $flags = $race['context_flags'];
            $group['first_observed_date'] = min($group['first_observed_date'], $race['race_date']);
            if ($record['conflict']) {
                $flags[] = 'MEETING_METADATA_CONFLICT';
            }
            if ($group['race_class'] === 'UNKNOWN') {
                $flags[] = 'UNKNOWN_RACE_CLASS';
            }
            $group['context_flags'] = array_values(array_unique([...$group['context_flags'], ...$flags]));
            $group['observed_result_rows']++;
            $audit = $entry['audit'];
            $reasons = $flags;
            if ($entry['identity_status'] === 'IDENTIFIED') {
                $identified = true;
            } else {
                $reasons[] = $entry['identity_status'];
            }
            if ($entry['comparison_completeness'] === 'PARTIAL') {
                $group['partial_rows']++;
                $reasons[] = 'PARTIAL_COMPARISON';
            }
            if (! in_array($audit['result_status'], ['FINISHED', 'TIED'], true)) {
                $group['abnormal_rows']++;
                $reasons[] = 'ABNORMAL_RESULT';
            }
            if ($audit['agari_status'] !== 'VALID') {
                $group['missing_rows']++;
                $reasons[] = 'AGARI_'.($audit['agari_status'] ?? 'UNSTORED');
            }
            if ($audit['relative_status'] !== 'CALCULATED') {
                $group['incomparable_rows']++;
                $reasons[] = 'RELATIVE_'.$audit['relative_status'];
            }
            $reasons = array_values(array_unique([...$reasons, ...$entry['race_exclusion_reasons']]));
            sort($reasons);
            if ($reasons === []) {
                $sum = $sum->plus(BigRational::ofFraction($audit['percentile_numerator'], $audit['percentile_denominator']));
                $group['adopted_races']++;
            }
            foreach ($reasons as $reason) {
                $group['exclusion_reasons'][$reason] = ($group['exclusion_reasons'][$reason] ?? 0) + 1;
            }
            $group['evidence'][] = ['race_id' => $race['race_id'], 'result_id' => $audit['result_id'],
                'race_result_import_id' => $audit['race_result_import_id'], 'observation_id' => $audit['observation_id'],
                'bike_number' => $audit['bike_number'], 'reasons' => $reasons];
            // A malformed multi-year "meeting" must not grow an unbounded JSONL row.
            if (count($group['evidence']) > 4096) {
                throw new RuntimeException('Observed player meeting exceeds bounded evidence row capacity.');
            }
        }
        if ($group !== null) {
            $this->save($put, $group, $sum, $identified);
        }
        $db->commit();
    }

    private function save(\PDOStatement $put, array $group, BigRational $sum, bool $identified): void
    {
        // Conflicting rows cannot establish attendance, but must not invalidate identified rows in the same meeting.
        if (! $identified) {
            foreach (['IDENTITY_CONFLICT', 'UNRESOLVED_EXTERNAL_ID'] as $reason) {
                if (isset($group['exclusion_reasons'][$reason])) {
                    $group['context_flags'][] = $reason;
                }
            }
        }
        sort($group['context_flags']);
        ksort($group['exclusion_reasons']);
        $group['meeting_percentile_mean'] = Exact::value($group['adopted_races'] ? $sum->dividedBy($group['adopted_races']) : null);
        // Boundary meetings stay in the observed slots, with a null value, not as complete history.
        $blocking = array_diff($group['context_flags'], ['MEETING_CROSSES_INPUT_START', 'MEETING_CROSSES_INPUT_END']);
        $group['history_context_eligible'] = $blocking === [];
        if ($group['context_flags'] !== []) {
            $group['meeting_percentile_mean'] = null;
        }
        $put->execute([$group['id'], $group['external_player_id'], $group['race_class'], (string) ($group['meeting']['meeting_id'] ?? ''),
            $group['meeting']['starts_on'], $group['meeting']['ends_on'], (int) $group['history_context_eligible'], Files::canonical($group)]);
    }
}
