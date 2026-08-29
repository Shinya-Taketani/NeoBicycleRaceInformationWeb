<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07DirectPositionObjective;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e07OneSeSelector;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e03CandidateStatus;
use App\Domain\Keirin\Backtest\Exceptions\Bt03e07OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Services\Bt03e07Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e07ValidationLossSpool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class Bt03e07OptimizerSelectionTest extends TestCase
{
    public function test_regularization_path_is_strong_to_weak_and_warm_started(): void
    {
        $path = $this->optimizer()->fitPath($this->source(), $this->layout());

        $this->assertSame(Bt03e07Contract::FIT_EXECUTION_ORDER, $path['fit_order']);
        $previous = null;
        foreach ($path['fit_order'] as $lambda) {
            $candidate = $path['candidate_statuses'][sprintf('%.17g', $lambda)];
            $this->assertSame($previous, $candidate['warm_start_from_lambda']);
            if ($candidate['status'] === Bt03e03CandidateStatus::Converged->value) {
                $previous = $lambda;
            }
        }
    }

    public function test_candidate_is_available_only_when_all_positions_converge(): void
    {
        $path = $this->optimizer()->fitPath($this->source(), $this->layout());

        foreach ($path['fits'] as $key => $fit) {
            $this->assertSame(Bt03e03CandidateStatus::Converged->value, $path['candidate_statuses'][$key]['status']);
            $this->assertSame(Bt03e07Contract::POSITIONS, array_keys($fit->coefficients));
            $this->assertSame(Bt03e07Contract::POSITIONS, array_keys($fit->diagnostics));
        }
        $this->assertSame(Bt03e03CandidateStatus::NumericallyNonConverged->value, $path['candidate_statuses']['0']['status']);
        $this->assertArrayNotHasKey('0', $path['fits']);
    }

    public function test_selected_path_stops_at_shared_lambda_and_matches_full_path(): void
    {
        $full = $this->optimizer()->fitPath($this->source(), $this->layout());
        $selected = $this->optimizer()->fitSelectedViaPath($this->source(), $this->layout(), 1.0);

        $this->assertSame([1.0], $selected['fit_order']);
        $this->assertSame($full['fits']['1']->coefficients, $selected['fit']->coefficients);
        $this->assertSame($full['fits']['1']->objectives, $selected['fit']->objectives);
    }

    public function test_monotone_restart_retains_the_backtracked_step_and_is_deterministic(): void
    {
        $first = $this->nonConvergenceDiagnostics(1.0);
        $second = $this->nonConvergenceDiagnostics(1.0);

        $this->assertSame($first, $second);
        $this->assertSame(Bt03e07Contract::MAX_ITERATIONS, $first['iteration']);
        $this->assertSame(Bt03e07Contract::MAX_ITERATIONS, $first['accepted_update_count']);
        $this->assertGreaterThan($first['accepted_update_count'], $first['optimizer_attempt_count']);
        $this->assertSame(
            $first['accepted_update_count'] + $first['monotone_restart_count'],
            $first['optimizer_attempt_count'],
        );
        $this->assertGreaterThan(0, $first['backtracking_iteration_count']);
        $this->assertGreaterThan(0, $first['monotone_restart_count']);
        $this->assertSame($first['monotone_restart_count'], $first['restart_step_retention_count']);
        $this->assertLessThan(Bt03e07Contract::INITIAL_STEP, $first['last_monotone_restart_step']);
        $this->assertSame($first['last_monotone_restart_step'], $first['last_post_restart_iteration_start_step']);
        $this->assertSame($first['last_monotone_restart_step'], $first['current_step']);
        $this->assertSame($first['last_monotone_restart_iteration'], $first['last_post_restart_accepted_update_iteration']);
        $this->assertSame($first['last_monotone_restart_iteration'], $first['iteration']);
        $this->assertTrue(
            $first['relative_objective_change'] > Bt03e07Contract::OBJECTIVE_TOLERANCE
            || $first['maximum_coefficient_change'] > Bt03e07Contract::CONVERGENCE_TOLERANCE,
        );
        $this->assertFalse(
            $first['relative_objective_change'] === 0.0
            && $first['maximum_coefficient_change'] === 0.0,
        );
    }

    public function test_converged_fit_is_deterministic_with_accepted_update_accounting(): void
    {
        $first = $this->optimizer()->fit($this->source(), $this->layout(), 1.0);
        $second = $this->optimizer()->fit($this->source(), $this->layout(), 1.0);

        $this->assertSame($first->coefficients, $second->coefficients);
        $this->assertSame($first->objectives, $second->objectives);
        $this->assertSame($first->iterations, $second->iterations);
        $this->assertSame($first->diagnostics, $second->diagnostics);
        foreach ($first->diagnostics as $position => $diagnostics) {
            $this->assertSame($first->iterations[$position], $diagnostics['iteration']);
            $this->assertSame($diagnostics['iteration'], $diagnostics['accepted_update_count']);
            $this->assertSame(
                $diagnostics['accepted_update_count'] + $diagnostics['monotone_restart_count'],
                $diagnostics['optimizer_attempt_count'],
            );
        }
    }

    public function test_one_se_uses_position_equal_and_year_equal_loss(): void
    {
        $first = $this->spool([
            '0.01' => [1.0, 3.0],
            '0.10000000000000001' => [3.0, 3.0],
        ], 1);
        $second = $this->spool([
            '0.01' => [5.0, 5.0],
            '0.10000000000000001' => [3.0, 3.0],
        ], 10);
        try {
            $selection = (new Bt03e07OneSeSelector)->select([2023 => $first, 2024 => $second], 20);

            $this->assertEqualsWithDelta(3.5, $selection['point_losses']['0.01'], 1e-15);
            $this->assertEqualsWithDelta(3.0, $selection['point_losses']['0.10000000000000001'], 1e-15);
            $this->assertSame(0.1, $selection['lambda']);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
    }

    public function test_one_se_excludes_non_converged_candidates_and_fails_if_none_remain(): void
    {
        $spool = $this->spool(['0.10000000000000001' => [0.5, 0.5]], 2);
        try {
            $selection = (new Bt03e07OneSeSelector)->select([2023 => $spool], 20);
            $this->assertSame(['0.10000000000000001'], $selection['eligible_lambda_keys']);
            $this->assertContains('0', $selection['excluded_lambda_keys']);
        } finally {
            $spool->cleanup();
        }

        $empty = $this->spool([], 1);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no fully converged shared lambda');
            (new Bt03e07OneSeSelector)->select([2023 => $empty], 20);
        } finally {
            $empty->cleanup();
        }
    }

    private function optimizer(): Bt03e07FistaOptimizer
    {
        return new Bt03e07FistaOptimizer(new Bt03e07DirectPositionObjective);
    }

    /** @return callable():array<int,array<string,mixed>> */
    private function source(): callable
    {
        return fn (): array => array_map(fn (int $race): array => $this->race($race), range(1, 12));
    }

    /** @return array<string,int|float|string> */
    private function nonConvergenceDiagnostics(float $lambda): array
    {
        $source = fn (): array => array_map(fn (int $race): array => $this->restartRace($race), range(1, 12));

        try {
            $this->optimizer()->fit($source, $this->layout(), $lambda);
            $this->fail('The fixed restart fixture must reach the frozen iteration limit.');
        } catch (Bt03e07OptimizerNonConvergenceException $exception) {
            return $exception->diagnostics;
        }
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e07Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = [
                new EffectBinDto(1, 'CATEGORY', null, null, '0', 1),
                new EffectBinDto(2, 'CATEGORY', null, null, '1', 4),
            ];
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @return array<string,mixed> */
    private function race(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $bins = array_fill(0, count(Bt03e07Contract::STAT_CODES), null);
            $bins[0] = $bike === 1 ? 0 : 1;
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => 0.0,
                'bins' => $bins,
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2023, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @return array<string,mixed> */
    private function restartRace(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $entries[] = [
                'id' => $raceId * 10 + $bike,
                'bike' => $bike,
                'raw' => 100.0 - $bike,
                'stat01_rank' => $bike,
                'anchor' => 0.0,
                'bins' => array_fill(0, count(Bt03e07Contract::STAT_CODES), $bike === 1 ? 0 : 1),
                'rank' => $bike,
                'status' => 'FINISHED',
            ];
        }

        return ['year' => 2023, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @param array<string,list<float>> $losses */
    private function spool(array $losses, int $raceCount): Bt03e07ValidationLossSpool
    {
        $spool = new Bt03e07ValidationLossSpool(
            sys_get_temp_dir().'/bt03e07-selection-'.bin2hex(random_bytes(8)).'.bin',
            array_keys($losses),
        );
        for ($race = 0; $race < $raceCount; $race++) {
            $row = [];
            foreach ($losses as $lambda => $positionLosses) {
                foreach (Bt03e07Contract::POSITIONS as $offset => $position) {
                    $row[$lambda][$position] = $positionLosses[$offset];
                }
            }
            $spool->append($row);
        }
        $spool->seal();

        return $spool;
    }
}
