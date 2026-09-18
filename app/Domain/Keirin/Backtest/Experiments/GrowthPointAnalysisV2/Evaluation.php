<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysisV2;

use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Analysis as V1Analysis;
use App\Domain\Keirin\Backtest\Experiments\GrowthPointAnalysis\Workspace;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use Generator;
use RuntimeException;

final class Evaluation
{
    public function __construct(private readonly V1Analysis $v1) {}

    public function details(Workspace $workspace, string $source, array $thresholds, array &$audit): Generator
    {
        $reference = JsonlArtifact::read($source.'/growth-details.jsonl');
        $reference->rewind();
        $update = $workspace->db->prepare('UPDATE observations SET point=?, state=? WHERE entry_id=? AND signal=?');
        $audit = ['entries' => 0, 'input_exact_matches' => 0, 'raw_mismatches' => 0, 'transitions' => [], 'same_meeting_score' => []];
        $rawHash = hash_init('sha256');
        // v1 owns raw computation and its transaction. Only v2's local observation points are replaced.
        $rows = $this->v1->details($this->inputs($workspace, $source, $audit), Files::json($source.'/thresholds.json'), $workspace);
        foreach ($rows as $row) {
            if (! $reference->valid()) {
                throw new RuntimeException('Missing v1 detail.');
            }
            Files::same($reference->current(), $row, 'v1 exact detail including raw, status, previous, same meeting and outcome');
            $raw = $row;
            unset($raw['signals']['COMPOSITE']);
            foreach (['SCORE', 'PERFORMANCE'] as $s) {
                unset($raw['signals'][$s]['point']);
            }
            hash_update($rawHash, Files::canonical($raw)."\n");
            $values = [];
            foreach (['SCORE', 'PERFORMANCE'] as $s) {
                $value = $row['signals'][$s];
                $values[$s] = ['raw' => $value['raw'], 'status' => $value['status'], 'point_v1' => $value['point']]
                    + Contract::point($value['raw'], $thresholds[$row['year']][$s]);
            }
            $a = $values['SCORE']['point'];
            $b = $values['PERFORMANCE']['point'];
            $values['COMPOSITE'] = ['raw' => null, 'status' => $a !== null && $b !== null ? 'VALID' : 'MISSING_COMPONENT',
                'point_v1' => $row['signals']['COMPOSITE']['point'], 'point' => $a !== null && $b !== null ? $a + $b : null,
                'point_status' => $a !== null && $b !== null ? 'VALID' : 'MISSING_COMPONENT'];
            foreach ($values as $signal => $value) {
                $state = $value['point_status'] === 'INSUFFICIENT_SIGN_TRAINING' ? $value['point_status'] : $value['status'];
                $update->execute([$value['point'], $state, $row['entry_id'], $signal]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('Observation identity mismatch.');
                }
                $this->transition($audit, $row, $signal, $value);
            }
            $row['signals'] = $values;
            $audit['entries']++;
            yield $row;
            $reference->next();
        }
        if ($reference->valid()) {
            throw new RuntimeException('Extra v1 detail.');
        }
        $audit['raw_semantic_sha256'] = hash_final($rawHash);
    }

    private function inputs(Workspace $workspace, string $source, array &$audit): Generator
    {
        $stored = JsonlArtifact::read($source.'/growth-input.jsonl');
        $stored->rewind();
        foreach ($workspace->inputs() as $input) {
            if (! $stored->valid()) {
                throw new RuntimeException('Missing v1 input.');
            }
            Files::same($stored->current(), $input, 'v1 input with full prev1/prev2 context');
            $audit['input_exact_matches']++;
            yield $input;
            $stored->next();
        }
        if ($stored->valid()) {
            throw new RuntimeException('Extra v1 input.');
        }
    }

    private function transition(array &$audit, array $row, string $signal, array $value): void
    {
        $key = $row['year'].':'.$signal;
        $audit['transitions'][$key] ??= ['year' => $row['year'], 'signal' => $signal, 'v1' => [], 'v2' => [], 'matrix' => [],
            'zero_n' => 0, 'zero_v1' => [], 'zero_v2' => [], 'sign_violations' => ['zero_nonzero' => 0, 'negative_nonnegative' => 0, 'positive_nonpositive' => 0],
            'missing_v1' => 0, 'missing_v2' => 0, 'insufficient_sign_training' => 0];
        $t = &$audit['transitions'][$key];
        $old = $value['point_v1'] ?? 'MISSING';
        $new = $value['point'] ?? 'MISSING';
        $t['v1'][$old] = ($t['v1'][$old] ?? 0) + 1;
        $t['v2'][$new] = ($t['v2'][$new] ?? 0) + 1;
        $t['matrix'][$old][$new] = ($t['matrix'][$old][$new] ?? 0) + 1;
        $t['missing_v1'] += $old === 'MISSING' ? 1 : 0;
        $t['missing_v2'] += $new === 'MISSING' ? 1 : 0;
        $t['insufficient_sign_training'] += $value['point_status'] === 'INSUFFICIENT_SIGN_TRAINING' ? 1 : 0;
        $raw = $value['raw'];
        $point = $value['point'];
        if ($raw !== null) {
            if ($raw == 0.0) {
                $t['zero_n']++;
                $t['zero_v1'][$old] = ($t['zero_v1'][$old] ?? 0) + 1;
                $t['zero_v2'][$new] = ($t['zero_v2'][$new] ?? 0) + 1;
            }
            $t['sign_violations']['zero_nonzero'] += $raw == 0 && $point !== 0 ? 1 : 0;
            $t['sign_violations']['negative_nonnegative'] += $raw < 0 && $point !== null && $point >= 0 ? 1 : 0;
            $t['sign_violations']['positive_nonpositive'] += $raw > 0 && $point !== null && $point <= 0 ? 1 : 0;
            if (array_sum($t['sign_violations']) > 0) {
                throw new RuntimeException('Sign preservation violation.');
            }
        }
        if ($signal === 'SCORE' && $row['same'] === 'PREVIOUS_SAME_MEETING') {
            $audit['same_meeting_score'][$row['year']] ??= ['entries' => 0, 'raw_zero' => 0, 'point_zero' => 0];
            $audit['same_meeting_score'][$row['year']]['entries']++;
            $audit['same_meeting_score'][$row['year']]['raw_zero'] += $raw !== null && $raw == 0 ? 1 : 0;
            $audit['same_meeting_score'][$row['year']]['point_zero'] += $point === 0 ? 1 : 0;
        }
    }
}
