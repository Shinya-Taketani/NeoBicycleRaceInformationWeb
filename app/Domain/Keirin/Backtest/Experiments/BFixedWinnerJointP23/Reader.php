<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23;

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
    public function __construct(private readonly Decoder $decoder, private readonly SharedReader $labels) {}

    public static function year(int $year): void
    {
        SharedReader::year($year);
    }

    public static function identities(string $path): PDO
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot exclusively create identity index.');
        }
        fclose($handle);
        $db = new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        // No implicit temporary database/journal with destructive cleanup; retain the index.
        $db->exec('PRAGMA journal_mode=OFF');
        $db->exec('PRAGMA cache_size=-1024');
        $db->exec('PRAGMA temp_store=MEMORY');
        $db->exec('CREATE TABLE seen (kind TEXT, id INTEGER, PRIMARY KEY(kind,id))');
        $db->beginTransaction();

        return $db;
    }

    public function rows(array $source, int $year, PDO $seen, array &$audit): Generator
    {
        self::year($year);
        $streams = [];
        foreach (['b_predictions', 'b_decisions'] as $name) {
            $streams[$name] = JsonlArtifact::read($source['paths'][$year][$name]);
            $streams[$name]->rewind();
        }
        $audit = ['races' => 0, 'entries' => 0, 'P1_changes' => 0, 'P2_changes' => 0, 'P3_changes' => 0,
            'pair_changes' => 0, 'B_retained' => 0, 'joint_probability_invariant' => true, 'supporting_unchanged' => true,
            'probabilities_unchanged' => true, 'zero_score_pairs' => 0];
        $insert = $seen->prepare('INSERT INTO seen VALUES (?,?)');
        foreach (Artifacts::lines($source['paths'][$year]['input']) as $i => $input) {
            Validator::race($input, $year);
            $insert->execute(['race', $input['race_id']]);
            foreach ($input['entries'] as $entry) {
                $insert->execute(['entry', $entry['id']]);
            }
            foreach ($streams as $stream) {
                if (! $stream->valid()) {
                    throw new RuntimeException('Missing fixed B row.');
                }
            }
            $b = $streams['b_predictions']->current();
            $decision = $b;
            unset($decision['probabilities']);
            Files::same($decision, $streams['b_decisions']->current(), 'B saved decision stream');
            Files::same([$input['year'], $input['race_id'], array_map(fn ($e) => [$e['id'], $e['bike']], $input['entries']), $i + 1],
                [$b['year'], $b['race_id'], $b['cohort'], $b['source_row']], 'B ordered cohort');
            $c = $this->decoder->decode($input, $b);
            $changed = false;
            foreach ([1, 2, 3] as $p) {
                $different = $b['candidate']['primary_position_'.$p.'_bike'] !== $c['primary_position_'.$p.'_bike'];
                $audit['P'.$p.'_changes'] += (int) $different;
                $changed = $changed || $different;
            }
            if ($audit['P1_changes'] !== 0 || $c['joint_score'] < $c['B_joint_score']) {
                throw new RuntimeException('Winner or joint probability invariant violated.');
            }
            $audit['pair_changes'] += (int) $changed;
            $audit['B_retained'] += (int) $c['decoder_tie_diagnostics']['PRIMARY_SECOND_THIRD']['B_retained'];
            $audit['zero_score_pairs'] += $c['joint_zero_score_pairs'];
            $audit['races']++;
            $audit['entries'] += count($input['entries']);
            yield ['year' => $year, 'race_id' => $input['race_id'], 'cohort' => $b['cohort'], 'A' => $b['baseline'],
                'B' => $b['candidate'], 'C' => $c, 'source_row' => $i + 1,
                'probabilities_semantic_sha256' => hash('sha256', Files::canonical($b['probabilities'])), 'B_provenance' => $b['provenance']];
            foreach ($streams as $stream) {
                $stream->next();
            }
        }
        foreach ($streams as $stream) {
            if ($stream->valid()) {
                throw new RuntimeException('Extra fixed B row.');
            }
        }
        if ([$audit['races'], $audit['entries']] !== [$source['expected_rows'][$year], $source['expected_entries'][$year]]) {
            throw new RuntimeException('Cohort count mismatch.');
        }
        Files::verify($source['paths'][$year]['input'], $source['seals'][$source['paths'][$year]['input']]);
    }

    public function labelled(array $source, int $year, array $paths): Generator
    {
        yield from $this->labels->labelled($source, $year, $paths);
    }
}
