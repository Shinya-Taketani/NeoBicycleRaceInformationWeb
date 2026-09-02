<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08P1Q2FrozenDecoder;
use App\Domain\Keirin\Backtest\Services\Bt03e06ForwardReconstructionVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e08FrozenP1Q2SourceAssembler;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Bt03e08FrozenP1Q2SourceAssemblerTest extends TestCase
{
    public function test_source_probabilities_are_authoritative_and_only_verified_utilities_are_attached(): void
    {
        $reconstructed = $this->reconstructedRace();
        $source = $this->sourceRace($reconstructed);
        $assembled = $this->assembler()->assemble($source, $reconstructed);

        $this->assertSame($source['year'], $assembled['year']);
        $this->assertSame($source['race_id'], $assembled['race_id']);
        foreach ($source['entries'] as $offset => $sourceEntry) {
            foreach (['position_1_probability', 'position_2_probability', 'position_3_probability', 'top2_probability', 'top3_probability'] as $field) {
                $this->assertSame($sourceEntry[$field], $assembled['entries'][$offset][$field]);
            }
            $this->assertSame($reconstructed['entries'][$offset]['utilities'], $assembled['entries'][$offset]['utilities']);
        }
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability'] as $field) {
            $this->assertSame($source[$field], $assembled[$field]);
        }
    }

    public function test_probability_mismatch_fails_closed_before_frozen_decoder_can_run(): void
    {
        $reconstructed = $this->reconstructedRace();
        $source = $this->sourceRace($reconstructed);
        $reconstructed['entries'][0]['position_1_probability'] += PHP_FLOAT_EPSILON;
        $decoderCalled = false;

        try {
            $assembled = $this->assembler()->assemble($source, $reconstructed);
            $decoderCalled = true;
            $this->decoder()->decode($assembled);
            $this->fail('A mismatched reconstruction must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('position_1_probability differed', $exception->getMessage());
        }

        $this->assertFalse($decoderCalled);
    }

    public function test_final_p1_q2_and_supporting_outputs_use_source_authority_and_verified_u2(): void
    {
        $reconstructed = $this->reconstructedRace();
        $source = $this->sourceRace($reconstructed);
        $assembled = $this->assembler()->assemble($source, $reconstructed);
        $decoder = $this->decoder();
        $frozen = $decoder->decode($assembled);
        $winnerOffset = array_search($frozen['primary_position_1_bike'], array_column($assembled['entries'], 'bike'), true);
        $conditionals = $decoder->conditionals($reconstructed['entries'], $winnerOffset);
        $p3 = ['year' => 2024, 'race_id' => 808, 'winner_bike' => $frozen['primary_position_1_bike'], 'entries' => []];
        foreach ($assembled['entries'] as $offset => $entry) {
            $p3['entries'][] = ['id' => $offset + 1, 'bike' => $entry['bike'], 'r3_probability' => $conditionals['q3_marginal'][$offset]];
        }
        $decision = (new Bt03e08P1Q2FrozenDecoder($decoder, new CanonicalHasher))->decode($assembled, $p3);

        $this->assertSame($source['entries'][$winnerOffset]['position_1_probability'], $decision['source_p1']);
        $this->assertSame($conditionals['q2'], array_column($frozen['q2_given_winner'], 'probability'));
        foreach (['map_ordered_top3', 'map_ordered_probability', 'map_top3_set', 'map_top3_set_probability', 'top2_marginal_bikes', 'top3_marginal_bikes', 'expected_ndcg_top3'] as $field) {
            $this->assertSame($frozen[$field], $decision[$field]);
        }
    }

    private function assembler(): Bt03e08FrozenP1Q2SourceAssembler
    {
        return new Bt03e08FrozenP1Q2SourceAssembler(new Bt03e06ForwardReconstructionVerifier);
    }

    private function decoder(): Bt03e06WinnerConditionedDecoder
    {
        return new Bt03e06WinnerConditionedDecoder(new Bt03e03ProbabilityScorer, new CanonicalHasher);
    }

    /** @return array<string,mixed> */
    private function reconstructedRace(): array
    {
        $entries = [];
        $probabilities = [0.45, 0.25, 0.15, 0.10, 0.05];
        foreach (range(1, 5) as $offset => $bike) {
            $p1 = $probabilities[$offset];
            $p2 = [0.10, 0.30, 0.25, 0.20, 0.15][$offset];
            $p3 = [0.08, 0.12, 0.35, 0.25, 0.20][$offset];
            $entries[] = [
                'bike' => $bike,
                'position_1_probability' => $p1,
                'position_2_probability' => $p2,
                'position_3_probability' => $p3,
                'top2_probability' => $p1 + $p2,
                'top3_probability' => $p1 + $p2 + $p3,
                'predicted_position' => $bike,
                'is_map_top3' => $bike <= 3,
                'utilities' => ['POSITION_1' => 1.0 - $offset, 'POSITION_2' => $offset / 3.0, 'POSITION_3' => -$offset / 4.0],
            ];
        }

        return ['year' => 2024, 'race_id' => 808, 'entries' => $entries, 'map_ordered_top3' => [1, 2, 3], 'map_ordered_probability' => 0.07, 'map_top3_set' => [1, 2, 3], 'map_top3_set_probability' => 0.21];
    }

    /** @param array<string,mixed> $reconstructed @return array<string,mixed> */
    private function sourceRace(array $reconstructed): array
    {
        $source = $reconstructed;
        $source['entries'] = array_map(static function (array $entry): array {
            $entry['source_predicted_position'] = $entry['predicted_position'];
            $entry['source_is_map_top3'] = $entry['is_map_top3'];
            unset($entry['predicted_position'], $entry['is_map_top3'], $entry['utilities']);

            return $entry;
        }, $source['entries']);

        return $source;
    }
}
