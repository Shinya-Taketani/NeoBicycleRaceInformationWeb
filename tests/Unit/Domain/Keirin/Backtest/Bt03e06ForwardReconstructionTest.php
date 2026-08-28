<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Contracts\EffectBinBoundaryProvider;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Services\Bt03e06Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e06ForwardReconstructionVerifier;
use App\Domain\Keirin\Backtest\Services\Bt03e06ModelReconstructor;
use App\Domain\Keirin\Backtest\Support\Bt03e03PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e06ForwardReconstructionTest extends TestCase
{
    public function test_artifact_model_reconstruction_is_bit_exact(): void
    {
        [$model, $layout, $fit] = $this->model();
        $race = $this->race($layout, array_fill(0, 12, -0.5));
        $original = (new Bt03e03ProbabilityScorer)->predict($race, $fit);
        $reconstructed = (new Bt03e06ModelReconstructor(new CanonicalHasher))->reconstruct(2024, $model);
        $actual = (new Bt03e03ProbabilityScorer)->predict($race, $reconstructed->fit);

        $this->assertSame($layout->canonicalBins(), $reconstructed->layout->canonicalBins());
        $this->assertSame($fit->coefficients, $reconstructed->fit->coefficients);
        $this->assertSame($original, $actual);
    }

    public function test_reconstructed_prediction_manifest_must_match_every_field(): void
    {
        [, $layout, $fit] = $this->model();
        $prediction = (new Bt03e03ProbabilityScorer)->predict($this->race($layout, array_fill(0, 12, -0.5)), $fit);
        $expected = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $actual = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $expected->append($prediction);
        $actual->append($prediction);
        $verifier = new Bt03e06ForwardReconstructionVerifier;
        $verifier->verifyManifest($expected->seal(), $actual->seal());

        $drifted = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $prediction['entries'][0]['top3_probability'] += PHP_FLOAT_EPSILON;
        $drifted->append($prediction);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('prediction manifest differed');
        $verifier->verifyManifest($expected = $this->manifestForOriginal($layout, $fit), $drifted->seal());
    }

    public function test_bin_boundary_drift_changes_forward_output_and_is_rejected(): void
    {
        [, $layout, $fit] = $this->model();
        $source = (new Bt03e03ProbabilityScorer)->predict($this->race($layout, array_fill(0, 12, -0.5)), $fit);
        $bins = $this->bins();
        $bins['STAT-07'][0] = new EffectBinDto(1, 'NUMERIC_RANGE', null, -1.0, null, 1);
        $bins['STAT-07'][1] = new EffectBinDto(2, 'NUMERIC_RANGE', -1.0, null, null, 1);
        $changed = (new Bt03e03ProbabilityScorer)->predict(
            $this->race(new Bt03e02ParameterLayout($bins), array_fill(0, 12, -0.5)),
            $fit,
        );

        $this->expectException(RuntimeException::class);
        (new Bt03e06ForwardReconstructionVerifier)->verifyRace($this->sourceRace($source), $changed);
    }

    public function test_coefficient_or_feature_value_drift_is_rejected(): void
    {
        [, $layout, $fit] = $this->model();
        $scorer = new Bt03e03ProbabilityScorer;
        $source = $scorer->predict($this->race($layout, array_fill(0, 12, -0.5)), $fit);
        $coefficients = $fit->coefficients;
        $coefficients['POSITION_2'][0] += 1e-12;
        $changedFit = new Bt03e03FitResultDto(
            $fit->lambda,
            $coefficients,
            $fit->objectives,
            $fit->iterations,
            $fit->eligibleRaceCounts,
            $fit->excludedRaceCounts,
        );

        try {
            (new Bt03e06ForwardReconstructionVerifier)->verifyRace(
                $this->sourceRace($source),
                $scorer->predict($this->race($layout, array_fill(0, 12, -0.5)), $changedFit),
            );
            $this->fail('A one-bit coefficient drift must be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('differed', $exception->getMessage());
        }

        $values = array_fill(0, 12, -0.5);
        $values[0] = 0.5;
        $this->expectException(RuntimeException::class);
        (new Bt03e06ForwardReconstructionVerifier)->verifyRace(
            $this->sourceRace($source),
            $scorer->predict($this->race($layout, $values), $fit),
        );
    }

    /** @return array{array<string,mixed>,Bt03e02ParameterLayout,Bt03e03FitResultDto} */
    private function model(): array
    {
        $layout = new Bt03e02ParameterLayout($this->bins());
        $coefficients = [];
        foreach (Bt03e06Contract::POSITIONS as $positionOffset => $position) {
            $coefficients[$position] = [];
            foreach (range(0, $layout->size() - 1) as $index) {
                $sign = $index % 2 === 0 ? -1.0 : 1.0;
                $coefficients[$position][] = $sign * (0.01 + $positionOffset * 0.005);
            }
        }
        $fit = new Bt03e03FitResultDto(
            0.1,
            $coefficients,
            array_fill_keys(Bt03e06Contract::POSITIONS, 1.0),
            array_fill_keys(Bt03e06Contract::POSITIONS, 10),
            array_fill_keys(Bt03e06Contract::POSITIONS, 1),
            array_fill_keys(Bt03e06Contract::POSITIONS, 0),
        );
        $model = [
            'optimizer_version' => Bt03e06Contract::SOURCE_OPTIMIZER_VERSION,
            'probability_version' => Bt03e06Contract::SOURCE_PROBABILITY_VERSION,
            'tie_rule_version' => Bt03e06Contract::SOURCE_TIE_RULE_VERSION,
            'lambda' => 0.1,
            'stat01_anchor_coefficient' => 1.0,
            'bins' => $layout->canonicalBins(),
            'position_coefficients' => $coefficients,
            'weighted_center_means' => array_map(fn (array $values): array => $layout->weightedMeans($values), $coefficients),
            'objectives' => $fit->objectives,
            'iterations' => $fit->iterations,
            'eligible_races' => $fit->eligibleRaceCounts,
            'excluded_races' => $fit->excludedRaceCounts,
            'optimizer_diagnostics' => [],
        ];

        return [$model, $layout, $fit];
    }

    /** @return array<string,list<EffectBinDto>> */
    private function bins(): array
    {
        return array_fill_keys(Bt03e06Contract::STAT_CODES, [
            new EffectBinDto(1, 'NUMERIC_RANGE', null, 0.0, null, 1),
            new EffectBinDto(2, 'NUMERIC_RANGE', 0.0, null, null, 1),
        ]);
    }

    /** @param list<float> $values @return array<string,mixed> */
    private function race(Bt03e02ParameterLayout $layout, array $values): array
    {
        $builder = new EffectBinBuilder(new class implements EffectBinBoundaryProvider
        {
            public function build(iterable $trainingValues): array
            {
                throw new RuntimeException('Bin generation is forbidden in BT-03E-06.');
            }
        });
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = [
                'id' => $bike,
                'bike' => $bike,
                'raw' => 80.0 + $bike,
                'stat01_rank' => $bike,
                'anchor' => ($bike - 3.0) / 2.0,
                'bins' => $layout->assign($values, $builder),
                'rank' => null,
                'status' => 'PREDICTION_ONLY',
            ];
        }

        return ['year' => 2024, 'race_id' => 701, 'entries' => $entries];
    }

    /** @param array<string,mixed> $prediction @return array<string,mixed> */
    private function sourceRace(array $prediction): array
    {
        $prediction['entries'] = array_map(static function (array $entry): array {
            $entry['source_predicted_position'] = $entry['predicted_position'];
            $entry['source_is_map_top3'] = $entry['is_map_top3'];

            return $entry;
        }, $prediction['entries']);

        return $prediction;
    }

    /** @return array<string,mixed> */
    private function manifestForOriginal(Bt03e02ParameterLayout $layout, Bt03e03FitResultDto $fit): array
    {
        $manifest = new Bt03e03PredictionManifestAccumulator(new CanonicalHasher);
        $manifest->append((new Bt03e03ProbabilityScorer)->predict($this->race($layout, array_fill(0, 12, -0.5)), $fit));

        return $manifest->seal();
    }
}
