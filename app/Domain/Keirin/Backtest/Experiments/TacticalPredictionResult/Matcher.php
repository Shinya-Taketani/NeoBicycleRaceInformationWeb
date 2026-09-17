<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult;

use App\Domain\Keirin\Backtest\Calculators\Bt03e05MetricEvaluator;
use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use RuntimeException;

final class Matcher
{
    public function __construct(private readonly Bt03e05MetricEvaluator $metrics) {}

    public function entries(array $row): array
    {
        Contract::race($row);
        $entries = $row['entries'] ?? null;
        if (! is_array($entries) || ! array_is_list($entries) || count($entries) < 5 || count($entries) > 9) {
            throw new RuntimeException('Invalid entrant list.');
        }
        $ids = $bikes = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! is_int($entry['id'] ?? null) || $entry['id'] < 1
                || ! is_int($entry['bike'] ?? null) || $entry['bike'] < 1 || $entry['bike'] > 9
                || isset($ids[$entry['id']]) || isset($bikes[$entry['bike']])) {
                throw new RuntimeException('Invalid or duplicate entry ID/bike.');
            }
            $ids[$entry['id']] = $entry;
            $bikes[$entry['bike']] = true;
        }

        return $ids;
    }

    public function result(array $row): array
    {
        $entries = $this->entries($row);
        $clean = [];
        foreach ($entries as $entry) {
            $status = $entry['status'] ?? null;
            $rank = $entry['rank'] ?? null;
            if (! is_string($status) || RaceEntryResultStatus::tryFrom($status) === null
                || ! array_key_exists('rank', $entry)
                || (in_array($status, ['FINISHED', 'TIED'], true)
                    ? ! is_int($rank) || $rank < 1 || $rank > count($entries)
                    : $rank !== null)) {
                throw new RuntimeException('Invalid result rank/status.');
            }
            $clean[] = ['id' => $entry['id'], 'bike' => $entry['bike'], 'rank' => $rank, 'status' => $status];
        }

        return ['year' => $row['year'], 'race_id' => $row['race_id'], 'entries' => $clean];
    }

    public function prediction(array $input, array $prediction): void
    {
        $entries = $this->entries($input);
        $probabilities = $prediction['probabilities'] ?? [];
        $decision = $prediction['decision'] ?? [];
        $this->sameRace($input, $probabilities);
        $this->sameRace($input, $decision);
        $this->sameEntries($entries, $this->entries($probabilities));
        foreach ($entries as $entry) {
            if ((! is_int($entry['raw'] ?? null) && ! is_float($entry['raw'] ?? null)) || ! is_finite((float) $entry['raw'])
                || array_key_exists('rank', $entry) || array_key_exists('status', $entry)) {
                throw new RuntimeException('Invalid fixed baseline input or outcome contamination.');
            }
        }
        $bikes = array_column($entries, 'bike');
        $groups = [[
            $decision['primary_position_1_bike'] ?? null,
            $decision['primary_position_2_bike'] ?? null,
            $decision['primary_position_3_bike'] ?? null,
        ]];
        foreach (['map_ordered_top3', 'map_top3_set', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $field) {
            $value = $decision[$field] ?? null;
            if (! is_array($value) || ! array_is_list($value) || count($value) !== ($field === 'top2_marginal_bikes' ? 2 : 3)) {
                throw new RuntimeException('Invalid saved decision shape.');
            }
            $groups[] = $value;
        }
        foreach ($groups as $group) {
            if (count(array_unique($group, SORT_REGULAR)) !== count($group)) {
                throw new RuntimeException('Duplicate saved decision bike.');
            }
            foreach ($group as $bike) {
                if (! in_array($bike, $bikes, true)) {
                    throw new RuntimeException('Saved decision bike outside fixed entrants.');
                }
            }
        }
        foreach (['primary_decision_tied', 'primary_technical_tiebreak_used'] as $field) {
            if (! is_bool($decision[$field] ?? null)) {
                throw new RuntimeException('Invalid saved tie flag.');
            }
        }
        foreach (['winner_tie_count', 'second_third_tie_count'] as $field) {
            if (! is_int($decision[$field] ?? null) || $decision[$field] < 1) {
                throw new RuntimeException('Invalid saved tie count.');
            }
        }
    }

    public function join(array $fixed, array $result): array
    {
        $input = $fixed['input'];
        $this->prediction($input, $fixed['prediction']);
        $result = $this->result($result);
        $this->sameRace($input, $result);
        $labels = $this->entries($result);
        $this->sameEntries($this->entries($input), $labels);
        $context = $input;
        foreach ($context['entries'] as &$entry) {
            $entry['rank'] = $labels[$entry['id']]['rank'];
            $entry['status'] = $labels[$entry['id']]['status'];
        }
        unset($entry);

        return ['request_id' => $fixed['request_id'], 'context' => $context, 'prediction' => $fixed['prediction']];
    }

    public function comparison(array $joined): array
    {
        $comparison = $this->metrics->raceComparison($joined['context'], $joined['prediction']['decision']);
        $reasons = [];
        foreach ($comparison['candidate'] as $metric => $value) {
            if ($value['denominator'] === 0.0) {
                $reasons[$metric] = $metric === 'POSITION_HIT_RATE_AT_3' || $metric === 'EXACT_ORDERED_TOP3_RATE'
                    ? 'NO_UNIQUE_OFFICIAL_ORDERED_TOP3' : 'NO_UNIQUE_OFFICIAL_POSITION';
            }
        }

        return ['request_id' => $joined['request_id'], 'year' => $joined['context']['year'], 'race_id' => $joined['context']['race_id'],
            'comparison' => $comparison, 'unevaluable' => $reasons];
    }

    private function sameRace(array $left, array $right): void
    {
        Contract::race($right);
        if ($left['year'] !== $right['year'] || $left['race_id'] !== $right['race_id']) {
            throw new RuntimeException('Race/year mismatch.');
        }
    }

    private function sameEntries(array $left, array $right): void
    {
        if (count($left) !== count($right)) {
            throw new RuntimeException('Entrant set mismatch.');
        }
        foreach ($left as $id => $entry) {
            if (! isset($right[$id]) || $entry['bike'] !== $right[$id]['bike']) {
                throw new RuntimeException('Entry ID/bike mapping mismatch.');
            }
        }
    }
}
