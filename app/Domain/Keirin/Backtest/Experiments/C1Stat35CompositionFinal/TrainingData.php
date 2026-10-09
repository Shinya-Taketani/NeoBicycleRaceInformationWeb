<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as C1;
use App\Domain\Keirin\Statistics\AgariC1Input\SourceProjector;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use RuntimeException;

final class TrainingData
{
    private bool $released = false;

    public array $events = [];

    public function features(array $source, int $year): Generator
    {
        if (! in_array($year, [2022, 2023, 2024, 2025], true)) {
            throw new RuntimeException('Forbidden training feature year.');
        }
        $paths = $source['paths'][$year];
        $original = Artifacts::lines($paths['original']);
        $sidecar = Artifacts::lines($paths['sidecar']);
        $original->rewind();
        $sidecar->rewind();
        $races = $entries = 0;
        foreach (Artifacts::lines($paths['input']) as $race) {
            if (! $original->valid() || ! $sidecar->valid()) {
                throw new RuntimeException('Fixed feature universe incomplete.');
            }
            Files::same($race, SourceProjector::project($original->current(), $year, C1::C1_VERSION), 'original C1 features');
            $extra = $sidecar->current();
            C1::keys($extra, ['year', 'race_id', 'entries']);
            if ($extra['year'] !== $year || $extra['race_id'] !== $race['race_id'] || count($extra['entries']) !== count($race['entries'])) {
                throw new RuntimeException('STAT35 sidecar race/cohort mismatch.');
            }
            foreach ($race['entries'] as $i => &$entry) {
                C1::keys($extra['entries'][$i], ['id', 'bike', 'stat35_mean6']);
                if ([$entry['id'], $entry['bike']] !== [$extra['entries'][$i]['id'], $extra['entries'][$i]['bike']]) {
                    throw new RuntimeException('STAT35 ordered entry identity mismatch.');
                }
                $entry['stat35_mean6'] = $extra['entries'][$i]['stat35_mean6'];
                $entries++;
            }
            unset($entry);
            Input::validate($race, [2022, 2023, 2024, 2025]);
            $races++;
            yield $race;
            $original->next();
            $sidecar->next();
        }
        if ($original->valid() || $sidecar->valid() || $races !== $source['expected_rows'][$year] || $entries !== $source['expected_entries'][$year]) {
            throw new RuntimeException('Fixed final-training cohort counts mismatch.');
        }
        foreach (['input', 'sidecar', 'original'] as $kind) {
            Files::verify($paths[$kind], $source['seals'][$paths[$kind]]);
        }
    }

    public function labelled(array $source, int $year): Generator
    {
        if ($year === 2025 && ! $this->released) {
            throw new RuntimeException('2025 teacher cannot open before OOF3 candidate seals.');
        }
        $this->events[] = ['event' => 'TEACHER_READ', 'year' => $year, 'purpose' => $year === 2025 ? 'SEALED_OOF3_VALIDATION_AND_SELECTED_FINAL_TRAINING' : 'FINAL_DEVELOPMENT_TRAINING'];
        $base = Jsonl::read($source['paths'][$year]['teacher']);
        $derived = Jsonl::read($source['paths'][$year]['c2_teacher']);
        $base->rewind();
        $derived->rewind();
        foreach ($this->features($source, $year) as $input) {
            if (! $base->valid() || ! $derived->valid()) {
                throw new RuntimeException('Final teacher incomplete.');
            }
            $teacher = $base->current();
            $projection = $teacher;
            if ($year >= 2024) {
                C1::keys($projection, ['year', 'race_id', 'entries']);
                foreach ($projection['entries'] as &$entry) {
                    C1::keys($entry, [...C1::ENTRY_KEYS, 'rank', 'status']);
                    unset($entry['rank'], $entry['status']);
                }
                unset($entry);
            }
            Files::same(SourceProjector::project($projection, $year, C1::C1_VERSION), (function () use ($input) {
                foreach ($input['entries'] as &$entry) {
                    unset($entry['stat35_mean6']);
                }
                unset($entry);

                return $input;
            })(), 'original teacher non-outcome fields');
            $race = Input::modelRace($input);
            foreach ($race['entries'] as $i => &$entry) {
                $label = $teacher['entries'][$i];
                if (($label['rank'] !== null && (! is_int($label['rank']) || $label['rank'] < 1 || $label['rank'] > 9)) || ! is_string($label['status'])) {
                    throw new RuntimeException('Invalid original teacher rank/status.');
                }
                $entry['rank'] = $label['rank'];
                $entry['status'] = $label['status'];
            }
            unset($entry);
            Files::same($race, $derived->current(), 'sealed C2 teacher features/cohort/labels');
            yield $race;
            $base->next();
            $derived->next();
        }
        if ($base->valid() || $derived->valid()) {
            throw new RuntimeException('Final teacher had extra rows.');
        }
        foreach (['teacher', 'c2_teacher'] as $kind) {
            Files::verify($source['paths'][$year][$kind], $source['seals'][$source['paths'][$year][$kind]]);
        }
    }

    public function release2025(string $directory): void
    {
        foreach (['path.json', 'layout.json', 'training.jsonl'] as $name) {
            Files::verify($directory.'/'.$name, Files::json($directory.'/candidate-seals.json')[$name]);
        }
        $this->released = true;
        $this->events[] = ['event' => 'OOF3_CANDIDATES_SEALED_2025_VALIDATION_RELEASE', 'seals' => Files::json($directory.'/candidate-seals.json')];
    }

    public static function training(array $paths): callable
    {
        return static function () use ($paths): Generator {
            foreach ($paths as $path) {
                yield from Jsonl::read($path);
            }
        };
    }
}
