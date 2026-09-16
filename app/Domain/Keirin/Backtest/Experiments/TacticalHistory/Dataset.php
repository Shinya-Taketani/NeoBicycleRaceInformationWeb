<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use DateTimeImmutable;
use Generator;
use RuntimeException;

final class Dataset
{
    public function raw(array $paths, bool $history, bool $prediction = false): Generator
    {
        foreach ($paths as $path) {
            foreach (JsonlArtifact::read($path) as $race) {
                if (! in_array($race['year'], [2022, 2023, 2024, 2025], true)) {
                    throw new RuntimeException('Dataset year was forbidden.');
                }
                foreach ($race['entries'] as &$entry) {
                    if (count($entry['signals']) !== 12 || count($entry['history']) !== 4) {
                        throw new RuntimeException('Fixed feature vector size drifted.');
                    }
                    foreach ($entry['history'] as $value) {
                        if ($value !== null && (! is_int($value) || $value < 0)) {
                            throw new RuntimeException('History input was not a nullable unsigned count.');
                        }
                    }
                    if ($prediction && (array_key_exists('rank', $entry) || array_key_exists('status', $entry) || array_key_exists('labels', $entry))) {
                        throw new RuntimeException('Prediction artifact contained outcomes.');
                    }
                    if ($history) {
                        $entry['signals'] = [...$entry['signals'], ...$entry['history']];
                    }
                    unset($entry['history'], $entry['history_status']);
                }
                unset($entry);
                yield $race;
            }
        }
    }

    public function binned(callable $raw, Layout $layout, EffectBinBuilder $bins): Generator
    {
        foreach ($raw() as $race) {
            foreach ($race['entries'] as &$entry) {
                $entry['bins'] = $layout->assign($entry['signals'], $bins);
                unset($entry['signals']);
            }
            unset($entry);
            yield $race;
        }
    }

    public function releaseLabels(int $year, string $input, array $sealedPredictions, Bt02OutcomeContextSnapshot $snapshot, string $output): array
    {
        if (! in_array($year, [2024, 2025], true) || count($sealedPredictions) !== 2 || count(array_unique($sealedPredictions)) !== 2) {
            throw new RuntimeException('Both C0/C1 candidates must be sealed before outer labels.');
        }
        foreach ($sealedPredictions as $path) {
            $inputs = JsonlArtifact::read($input);
            $inputs->rewind();
            foreach (JsonlArtifact::read($path) as $prediction) {
                if ($prediction['probabilities']['year'] !== $year || ! $inputs->valid()
                    || $inputs->current()['race_id'] !== $prediction['probabilities']['race_id']) {
                    throw new RuntimeException('Prediction seal year disagreed.');
                }
                $inputs->next();
            }
            if ($inputs->valid()) {
                throw new RuntimeException('Sealed predictions omitted input races.');
            }
        }

        return JsonlArtifact::write($output, $this->labelledRows($year, $input, $snapshot));
    }

    private function labelledRows(int $year, string $input, Bt02OutcomeContextSnapshot $snapshot): Generator
    {
        $fold = new FoldDefinitionDto('TACTICAL_HISTORY_'.$year, 0, null, null, new DateTimeImmutable($year.'-01-01'), new DateTimeImmutable($year.'-12-31'));
        $inputs = JsonlArtifact::read($input);
        $inputs->rewind();
        foreach ($snapshot->chunks($fold, 100) as $chunk) {
            foreach ($chunk as $outcome) {
                if (! $inputs->valid()) {
                    continue;
                }
                $race = $inputs->current();
                if ($race['race_id'] !== $outcome->context->raceId) {
                    continue;
                }
                $results = [];
                foreach ($outcome->results as $result) {
                    if (isset($results[$result->bikeNumber])) {
                        throw new RuntimeException('Duplicated frozen result.');
                    }
                    $results[$result->bikeNumber] = $result;
                }
                $bikes = array_column($race['entries'], 'bike');
                $expected = array_keys($results);
                sort($bikes);
                sort($expected);
                if ($bikes !== $expected || count($bikes) !== $outcome->context->entrantCount) {
                    throw new RuntimeException('Outcome and prediction entrant sets disagreed.');
                }
                foreach ($race['entries'] as &$entry) {
                    $entry['rank'] = $results[$entry['bike']]->rank;
                    $entry['status'] = $results[$entry['bike']]->resultStatus;
                }
                unset($entry);
                yield $race;
                $inputs->next();
            }
        }
        if ($inputs->valid()) {
            throw new RuntimeException('Outcome snapshot omitted prediction races.');
        }
    }
}
