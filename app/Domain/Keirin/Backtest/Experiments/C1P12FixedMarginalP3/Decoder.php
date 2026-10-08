<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1P12FixedMarginalP3;

use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Decoder as VerifiedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

final class Decoder
{
    public function __construct(private readonly VerifiedDecoder $verified) {}

    public function decode(array $input, array $saved): array
    {
        Reader::year($input['year']);
        $original = $this->verified->baseline($input, $saved);
        $race = $saved['probabilities'];
        $policy = $this->select($race['year'], $race['race_id'], $race['entries'],
            $original['primary_position_1_bike'], $original['primary_position_2_bike'], $original['primary_position_3_bike']);
        $candidate = ['year' => $race['year'], 'race_id' => $race['race_id'], 'decoder_version' => Contract::DECODER,
            'primary_position_1_bike' => $original['primary_position_1_bike'],
            'primary_position_2_bike' => $original['primary_position_2_bike'],
            'primary_position_3_bike' => $policy['selected_bike'],
            'winner_tie_count' => $original['winner_tie_count'], 'second_third_tie_count' => $policy['maximum_count'],
            'primary_decision_tied' => $original['winner_tie_count'] > 1 || $policy['maximum_count'] > 1,
            'primary_technical_tiebreak_used' => $original['winner_tie_count'] > 1 || $policy['hash_selection'],
            'policy' => $policy + ['fixed_P1_P2_source' => 'VERIFIED_ORIGINAL_C1_E06',
                'baseline_decision_sha256' => hash('sha256', Files::canonical($original))]];
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability',
            'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $key) {
            $candidate[$key] = $original[$key];
        }
        $candidate['decoder_tie_diagnostics'] = [];
        foreach (['MAP_ORDERED_TOP3', 'MAP_TOP3_SET', 'TOP2_MARGINAL', 'TOP3_MARGINAL', 'EXPECTED_NDCG'] as $key) {
            $candidate['decoder_tie_diagnostics'][$key] = $original['decoder_tie_diagnostics'][$key];
        }

        return ['year' => $input['year'], 'race_id' => $input['race_id'],
            'cohort' => array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']),
            'probabilities_semantic_sha256' => hash('sha256', Files::canonical($race)),
            'baseline' => $original, 'candidate' => $candidate,
            'model_expected_gain' => $policy['selected_probability'] - $policy['original_probability']];
    }

    public function select(int $year, int $raceId, array $entries, int $first, int $second, int $third): array
    {
        Reader::year($year);
        $scores = [];
        foreach ($entries as $entry) {
            $bike = $entry['bike'] ?? null;
            $score = $entry['position_3_probability'] ?? null;
            if (! is_int($bike) || $bike < 1 || $bike > 9 || array_key_exists($bike, $scores)
                || (! is_float($score) && ! is_int($score)) || ! is_finite((float) $score) || $score < 0 || $score > 1) {
                throw new RuntimeException('Invalid P3 selection input.');
            }
            $scores[$bike] = (float) $score;
        }
        if ($raceId < 1 || count($scores) < 5 || count($scores) > 9 || count(array_unique([$first, $second, $third])) !== 3
            || ! isset($scores[$first], $scores[$second], $scores[$third])) {
            throw new RuntimeException('Invalid fixed P1/P2/P3 cohort.');
        }
        $eligible = $scores;
        unset($eligible[$first], $eligible[$second]);
        $maximum = max($eligible);
        $maxima = array_keys(array_filter($eligible, fn ($score) => $score === $maximum));
        $retained = $scores[$third] === $maximum;
        $selected = $third;
        $hash = null;
        if (! $retained) {
            $keys = [];
            foreach ($maxima as $bike) {
                $keys[$bike] = hash('sha256', implode('|', [Contract::TIE, $year, $raceId, $first, $second, $bike]));
            }
            usort($maxima, fn ($a, $b) => strcmp($keys[$a], $keys[$b]) ?: $a <=> $b);
            $selected = $maxima[0];
            $hash = count($maxima) > 1 ? $keys[$selected] : null;
        }
        if ($scores[$selected] < $scores[$third] || ($selected !== $third && $scores[$selected] <= $scores[$third])) {
            throw new RuntimeException('P3 expected probability invariant violated.');
        }

        return ['decoder_version' => Contract::DECODER, 'tie_version' => Contract::TIE,
            'original_bike' => $third, 'selected_bike' => $selected,
            'original_probability' => $scores[$third], 'selected_probability' => $scores[$selected],
            'changed' => $selected !== $third, 'reason' => $retained ? 'ORIGINAL_P3_IS_EXACT_MAXIMUM' : 'GREATER_UNCONDITIONAL_P3',
            'maximum_count' => count($maxima), 'equal_maximum_retained' => $retained && count($maxima) > 1,
            'hash_selection' => ! $retained && count($maxima) > 1, 'selected_tie_sha256' => $hash];
    }
}
