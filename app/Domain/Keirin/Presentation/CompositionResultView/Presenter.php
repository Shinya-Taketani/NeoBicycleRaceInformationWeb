<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Presentation\CompositionResultView;

use RuntimeException;

final class Presenter
{
    public function overview(array $saved): array
    {
        $summary = $saved['summary'];
        $metrics = [];
        foreach (['WINNER_HIT_AT_1' => '1着', 'POSITION_2_ACCURACY' => '2着', 'POSITION_3_ACCURACY' => '3着',
            'POSITION_HIT_RATE_AT_3' => '位置Hit@3'] as $code => $label) {
            $metrics[] = $this->metric($label, $summary['metrics'][$code]);
        }
        $metrics[] = $this->metric('Primary完全順序一致', $summary['primary_exact_ordered_top3']);

        return ['evaluation_id' => $saved['manifest']['request']['evaluation_id'],
            'year' => $saved['manifest']['request']['selection']['result_year'],
            'saved_at' => $saved['manifest']['generated_at'], 'matched' => $summary['matched'],
            'metrics' => $metrics, 'races' => array_map($this->race(...), $saved['races'])];
    }

    public function race(array $row): array
    {
        $positions = [];
        foreach ([0, 1, 2] as $index) {
            $positions[] = ['primary' => $row['primary'][$index],
                'actual' => $row['actual_top3_sets'][$index] === [] ? '公式順位なし' : implode('・', $row['actual_top3_sets'][$index]),
                'decision' => match ($row['position_matches'][$index]) {
                    true => '一致', false => '不一致', null => '評価対象外'
                },
                'state' => match ($row['position_matches'][$index]) {
                    true => 'hit', false => 'miss', null => 'excluded'
                },
                'reason' => $row['position_matches'][$index] === null ? '公式順位が一意でないため評価対象外' : null,
                'reason_code' => $row['unevaluable']['POSITION_'.($index + 1).'_ACCURACY'] ?? null];
        }

        return ['race_id' => $row['race_id'], 'request_id' => $row['request_id'], 'positions' => $positions];
    }

    public function detail(array $overview, array $detail): array
    {
        $resultByEntry = [];
        foreach ($detail['context']['entries'] as $entry) {
            $resultByEntry[$entry['id']] = $entry;
        }
        $entries = [];
        foreach ($detail['prediction']['probabilities']['entries'] as $entry) {
            $result = $resultByEntry[$entry['id']] ?? null;
            if ($result === null || $result['bike'] !== $entry['bike']) {
                throw new RuntimeException('Displayed entrant identity mismatch.');
            }
            $entries[] = ['id' => $entry['id'], 'bike' => $entry['bike'],
                'p1' => self::percentage($entry['position_1_probability']),
                'p2' => self::percentage($entry['position_2_probability']),
                'p3' => self::percentage($entry['position_3_probability']),
                'rank' => $result['rank'] ?? '順位なし', 'status' => $result['status']];
        }

        return ['race' => current(array_filter($overview['races'], fn (array $row): bool => $row['race_id'] === $detail['context']['race_id'])),
            'entries' => $entries];
    }

    public static function percentage(int|float|null $value): string
    {
        return $value === null ? '未取得' : number_format($value * 100, 4, '.', '').'%';
    }

    private function metric(string $label, array $value): array
    {
        return ['label' => $label, 'numerator' => $value['numerator'], 'denominator' => $value['denominator'],
            'rate' => self::percentage($value['rate']), 'reason' => $value['reason'],
            'excluded' => $value['excluded_races'] ?? null];
    }
}
