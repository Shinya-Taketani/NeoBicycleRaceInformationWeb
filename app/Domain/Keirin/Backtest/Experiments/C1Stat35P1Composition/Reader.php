<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition;

use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Decoder as BaselineDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Reader as SharedReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Validator;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use Generator;
use PDO;
use RuntimeException;

final class Reader
{
    public function __construct(private readonly ProbabilityCalculator $calculator, private readonly BaselineDecoder $baseline,
        private readonly Bt03e06WinnerConditionedDecoder $decoder) {}

    public static function year(int $year): void
    {
        SharedReader::year($year);
    }

    public function compose(array $c1, array $c2): array
    {
        $race = ['year' => $c1['year'], 'race_id' => $c1['race_id'], 'entries' => []];
        foreach ($c1['entries'] as $i => $entry) {
            $fixed = array_intersect_key($entry, array_flip(['id', 'bike', 'raw', 'stat01_rank', 'anchor']));
            $fixed['utilities'] = ['POSITION_1' => $c2['entries'][$i]['utilities']['POSITION_1'],
                'POSITION_2' => $entry['utilities']['POSITION_2'], 'POSITION_3' => $entry['utilities']['POSITION_3']];
            $race['entries'][] = $fixed;
        }

        return $this->calculator->predict($race);
    }

    public function forward(array $saved): array
    {
        return $this->compose($saved, $saved);
    }

    public function row(array $input, array $c1, array $c2): array
    {
        Validator::race($input, $input['year']);
        self::year($input['year']);
        $baseline = $this->baseline->baseline($input, $c1);
        $donor = $this->baseline->baseline($input, $c2);
        Files::same($c1['probabilities'], $this->forward($c1['probabilities']), 'C1 utility forward exact');
        Files::same($c2['probabilities'], $this->forward($c2['probabilities']), 'C2 utility forward exact');
        $probabilities = $this->compose($c1['probabilities'], $c2['probabilities']);
        foreach ($probabilities['entries'] as $i => $entry) {
            foreach (['position_1_probability', 'position_1_log_probability'] as $key) {
                Files::same([$c2['probabilities']['entries'][$i][$key]], [$entry[$key]], 'composed P1 '.$key);
            }
            Files::same([$c2['probabilities']['entries'][$i]['utilities']['POSITION_1'],
                $c1['probabilities']['entries'][$i]['utilities']['POSITION_2'], $c1['probabilities']['entries'][$i]['utilities']['POSITION_3']],
                array_values($entry['utilities']), 'position utility sources');
        }
        $candidate = $this->decoder->decode($probabilities);
        // Replace source-run audit metadata only; never change the decoder's mathematical output.
        unset($candidate['reconstruction_verified']);
        $candidate['prediction_origin'] = 'FROZEN_POSITION_MODEL_COMPOSITION';
        $candidate['utility_forward_controls_verified'] = true;
        $candidate['calculation_version'] = Contract::CALCULATION;
        $primary = static fn ($d) => array_map(fn ($p) => $d['primary_position_'.$p.'_bike'], [1, 2, 3]);
        if ($primary($candidate)[0] !== $primary($donor)[0]) {
            throw new RuntimeException('Candidate winner differs from C2.');
        }
        $sameWinner = $primary($baseline)[0] === $primary($donor)[0];
        if ($sameWinner) {
            Files::same($primary($baseline), $primary($candidate), 'same winner Primary invariant');
        }

        return ['year' => $input['year'], 'race_id' => $input['race_id'],
            'cohort' => array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']),
            'baseline' => $baseline, 'candidate' => $candidate, 'probabilities' => $probabilities,
            'winner_same' => $sameWinner, 'model_expected_gain' => null];
    }

    public function rows(array $source, int $year, PDO $seen, array &$audit): Generator
    {
        self::year($year);
        $streams = [];
        foreach (['prediction', 'c2_prediction'] as $key) {
            $streams[$key] = JsonlArtifact::read($source['paths'][$year][$key]);
            $streams[$key]->rewind();
        }
        $insert = $seen->prepare('INSERT INTO seen VALUES (?,?)');
        $audit = ['races' => 0, 'entries' => 0, 'P1_changes' => 0, 'P2_changes' => 0, 'P3_changes' => 0,
            'winner_same' => 0, 'winner_changed' => 0, 'C1_forward_exact' => true, 'C2_forward_exact' => true,
            'P1_C2_exact' => true, 'same_winner_primary_exact' => true, 'utility_sources_exact' => true];
        $parents = [];
        foreach (['C1' => ['model', 'prediction'], 'C2' => ['c2_model', 'c2_prediction']] as $name => [$model, $prediction]) {
            $parents[$name] = ['year' => $year, 'model_version' => Contract::plan()['model_versions'][$name],
                'model_path' => $source['paths'][$year][$model], 'model_seal' => $source['seals'][$source['paths'][$year][$model]],
                'prediction_path' => $source['paths'][$year][$prediction], 'prediction_seal' => $source['seals'][$source['paths'][$year][$prediction]]];
        }
        foreach (Artifacts::lines($source['paths'][$year]['input']) as $i => $input) {
            Validator::race($input, $year);
            $insert->execute(['race', $input['race_id']]);
            foreach ($input['entries'] as $entry) {
                $insert->execute(['entry', $entry['id']]);
            }
            foreach ($streams as $stream) {
                if (! $stream->valid()) {
                    throw new RuntimeException('Missing saved parent prediction.');
                }
            }
            $row = $this->row($input, $streams['prediction']->current(), $streams['c2_prediction']->current());
            $row['source_row'] = $i + 1;
            $row['provenance'] = ['experiment' => Contract::VERSION, 'candidate' => Contract::CANDIDATE,
                'calculation_version' => Contract::CALCULATION, 'artifact_role' => 'FROZEN_POSITION_MODEL_COMPOSITION',
                'position_sources' => Contract::plan()['position_sources'], 'parents' => $parents,
                'source_row' => $i + 1, 'composition_contract_sha256' => hash('sha256', Files::canonical(Contract::plan()))];
            foreach ([1, 2, 3] as $p) {
                $audit['P'.$p.'_changes'] += (int) ($row['baseline']['primary_position_'.$p.'_bike'] !== $row['candidate']['primary_position_'.$p.'_bike']);
            }
            $audit[$row['winner_same'] ? 'winner_same' : 'winner_changed']++;
            $audit['races']++;
            $audit['entries'] += count($input['entries']);
            yield $row;
            foreach ($streams as $stream) {
                $stream->next();
            }
        }
        foreach ($streams as $stream) {
            if ($stream->valid()) {
                throw new RuntimeException('Extra saved parent prediction.');
            }
        }
        if ([$audit['races'], $audit['entries']] !== [$source['expected_rows'][$year], $source['expected_entries'][$year]]) {
            throw new RuntimeException('Fixed cohort count mismatch.');
        }
        Files::verify($source['paths'][$year]['input'], $source['seals'][$source['paths'][$year]['input']]);
    }

    public static function identities(): PDO
    {
        $db = new PDO('sqlite:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('PRAGMA temp_store=FILE');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY(kind,id))');
        $db->beginTransaction();

        return $db;
    }
}
