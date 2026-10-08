<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use PDO;
use RuntimeException;

final class Reader
{
    public function __construct(private readonly Decoder $decoder, private readonly Matcher $matcher) {}

    public function decisions(array $source, int $year, string $indexPath, array &$audit): Generator
    {
        yield from $this->decisionsUsing($source, $year, $indexPath, $audit, $this->decoder->decode(...));
    }

    public function decisionsUsing(array $source, int $year, string $indexPath, array &$audit, callable $decode): Generator
    {
        self::year($year);
        $db = new PDO('sqlite:'.$indexPath);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('CREATE TABLE seen (kind TEXT NOT NULL, id INTEGER NOT NULL, PRIMARY KEY(kind,id))');
        $db->beginTransaction();
        $seen = $db->prepare('INSERT INTO seen VALUES (?,?)');
        $predictions = JsonlArtifact::read($source['paths'][$year]['prediction']);
        $predictions->rewind();
        $audit = ['year' => $year, 'races' => 0, 'entries' => 0, 'P1_changes' => 0, 'P2_changes' => 0, 'P3_changes' => 0,
            'expected_gain_sum' => 0.0, 'expected_gain_min' => null, 'expected_gain_max' => null,
            'source_rows_verified' => true, 'E06_decision_verified' => true, 'probabilities_and_supporting_unchanged' => true, 'training_count' => 0];
        try {
            foreach (Artifacts::lines($source['paths'][$year]['input']) as $i => $input) {
                Validator::race($input, $year);
                $seen->execute(['race', $input['race_id']]);
                foreach ($input['entries'] as $entry) {
                    $seen->execute(['entry', $entry['id']]);
                }
                if (! $predictions->valid()) {
                    throw new RuntimeException('Missing saved prediction race.');
                }
                $row = $decode($input, $predictions->current());
                $row['source_row'] = $i + 1;
                $row['model_sha256'] = $source['seals'][$source['paths'][$year]['model']]['sha256'];
                foreach ([1, 2, 3] as $position) {
                    $audit['P'.$position.'_changes'] += (int) ($row['baseline']['primary_position_'.$position.'_bike'] !== $row['candidate']['primary_position_'.$position.'_bike']);
                }
                $audit['expected_gain_sum'] += $row['model_expected_gain'];
                $audit['expected_gain_min'] = min($audit['expected_gain_min'] ?? INF, $row['model_expected_gain']);
                $audit['expected_gain_max'] = max($audit['expected_gain_max'] ?? -INF, $row['model_expected_gain']);
                $audit['races']++;
                $audit['entries'] += count($input['entries']);
                yield $row;
                $predictions->next();
            }
            if ($predictions->valid() || $audit['races'] !== $source['expected_rows'][$year] || $audit['entries'] !== $source['expected_entries'][$year]) {
                throw new RuntimeException('Extra predictions or fixed cohort count mismatch.');
            }
            Files::verify($source['paths'][$year]['input'], $source['seals'][$source['paths'][$year]['input']]);
        } finally {
            $db->rollBack();
        }
    }

    public function labelled(array $source, int $year, array $decisionPaths): Generator
    {
        self::year($year);
        if (array_keys($decisionPaths) !== [2024, 2025]) {
            throw new RuntimeException('Labels forbidden before both prediction seals.');
        }
        foreach ($decisionPaths as $path) {
            Files::verify($path, Files::json($path.'.manifest.json'));
        }
        $labelsPath = $source['paths'][$year]['labels'];
        Files::verify($labelsPath, $source['outcome_seals'][$labelsPath]);
        $labels = JsonlArtifact::read($labelsPath);
        $labels->rewind();
        foreach (Artifacts::lines($source['paths'][$year]['input']) as $input) {
            Validator::race($input, $year);
            if (! $labels->valid()) {
                throw new RuntimeException('Missing label race.');
            }
            $row = $labels->current();
            InputContract::keys($row, ['year', 'race_id', 'entries']);
            if ([$row['year'], $row['race_id']] !== [$input['year'], $input['race_id']]
                || ! is_array($row['entries']) || ! array_is_list($row['entries']) || count($row['entries']) !== count($input['entries'])) {
                throw new RuntimeException('Label cohort mismatch.');
            }
            foreach ($row['entries'] as $i => $entry) {
                InputContract::keys($entry, [...InputContract::ENTRY_KEYS, 'rank', 'status']);
                $fixed = $entry;
                unset($fixed['rank'], $fixed['status']);
                Files::same($input['entries'][$i], $fixed, 'label fixed input fields');
            }
            $clean = $this->matcher->result($row);
            foreach ($clean['entries'] as $i => $entry) {
                $input['entries'][$i]['rank'] = $entry['rank'];
                $input['entries'][$i]['status'] = $entry['status'];
            }
            yield $input;
            $labels->next();
        }
        if ($labels->valid()) {
            throw new RuntimeException('Extra label race.');
        }
        Files::verify($source['paths'][$year]['input'], $source['seals'][$source['paths'][$year]['input']]);
    }

    public static function year(int $year): void
    {
        if (! in_array($year, [2024, 2025], true)) {
            throw new RuntimeException('Forbidden dataset year.');
        }
    }
}
