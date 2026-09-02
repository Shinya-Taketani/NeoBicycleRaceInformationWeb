<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Calculators\Bt03e02ParameterLayout;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08FistaOptimizer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08OneSeSelector;
use App\Domain\Keirin\Backtest\Calculators\Bt03e08WinnerConditionedP3Objective;
use App\Domain\Keirin\Backtest\DTO\EffectBinDto;
use App\Domain\Keirin\Backtest\Enums\Bt03e03CandidateStatus;
use App\Domain\Keirin\Backtest\Services\Bt03e08Contract;
use App\Domain\Keirin\Backtest\Support\Bt03e08ValidationLossSpool;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class Bt03e08OptimizerSelectionTest extends TestCase
{
    public function test_path_is_strong_to_weak_warm_started_and_p3_only(): void
    {
        $path = $this->optimizer()->fitPath($this->source(), $this->layout());
        $this->assertSame(Bt03e08Contract::FIT_EXECUTION_ORDER, $path['fit_order']);
        $previous = null;
        foreach ($path['fit_order'] as $lambda) {
            $candidate = $path['candidate_statuses'][sprintf('%.17g', $lambda)];
            $this->assertSame('POSITION_3', $candidate['position']);
            $this->assertSame($previous, $candidate['warm_start_from_lambda']);
            if ($candidate['status'] === Bt03e03CandidateStatus::Converged->value) {
                $previous = $lambda;
            }
        }
        foreach ($path['fits'] as $key => $fit) {
            $this->assertSame(Bt03e03CandidateStatus::Converged->value, $path['candidate_statuses'][$key]['status']);
            $this->assertSame('POSITION_3', $fit->diagnostics['position']);
        }
    }

    public function test_selected_refit_uses_exact_selected_lambda_without_fallback(): void
    {
        $full = $this->optimizer()->fitPath($this->source(), $this->layout());
        $selected = $this->optimizer()->fitSelectedViaPath($this->source(), $this->layout(), 1.0);
        $this->assertSame([1.0], $selected['fit_order']);
        $this->assertSame($full['fits']['1']->coefficients, $selected['fit']->coefficients);
    }

    public function test_one_se_is_year_equal_and_excludes_non_converged_candidates(): void
    {
        $first = $this->spool(['0.01' => 1.0, '0.10000000000000001' => 3.0], 1);
        $second = $this->spool(['0.01' => 5.0, '0.10000000000000001' => 3.0], 10);
        try {
            $selection = (new Bt03e08OneSeSelector)->select([2023 => $first, 2024 => $second], 20);
            $this->assertEqualsWithDelta(3.0, $selection['point_losses']['0.01'], 1e-15);
            $this->assertEqualsWithDelta(3.0, $selection['point_losses']['0.10000000000000001'], 1e-15);
            $this->assertSame(0.1, $selection['lambda']);
            $this->assertContains('0', $selection['excluded_lambda_keys']);
        } finally {
            $first->cleanup();
            $second->cleanup();
        }
        $empty = $this->spool([], 1);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('no fully converged');
            (new Bt03e08OneSeSelector)->select([2023 => $empty], 20);
        } finally {
            $empty->cleanup();
        }
    }

    private function optimizer(): Bt03e08FistaOptimizer
    {
        return new Bt03e08FistaOptimizer(new Bt03e08WinnerConditionedP3Objective);
    }

    /** @return callable():array<int,array<string,mixed>> */
    private function source(): callable
    {
        return fn (): array => array_map(fn (int $race): array => $this->race($race), range(1, 12));
    }

    private function layout(): Bt03e02ParameterLayout
    {
        $bins = [];
        foreach (Bt03e08Contract::STAT_CODES as $statCode) {
            $bins[$statCode] = [new EffectBinDto(1, 'CATEGORY', null, null, '0', 1), new EffectBinDto(2, 'CATEGORY', null, null, '1', 4)];
        }

        return new Bt03e02ParameterLayout($bins);
    }

    /** @return array<string,mixed> */
    private function race(int $raceId): array
    {
        $entries = [];
        foreach (range(1, 5) as $bike) {
            $bins = array_fill(0, count(Bt03e08Contract::STAT_CODES), null);
            $bins[0] = $bike === 3 ? 0 : 1;
            $entries[] = ['id' => $raceId * 10 + $bike, 'bike' => $bike, 'anchor' => 0.0, 'bins' => $bins, 'rank' => $bike, 'status' => 'FINISHED'];
        }

        return ['year' => 2023, 'race_id' => $raceId, 'entries' => $entries];
    }

    /** @param array<string,float> $losses */
    private function spool(array $losses, int $raceCount): Bt03e08ValidationLossSpool
    {
        $spool = new Bt03e08ValidationLossSpool(sys_get_temp_dir().'/bt03e08-selection-'.bin2hex(random_bytes(8)).'.bin', array_keys($losses));
        for ($race = 0; $race < $raceCount; $race++) {
            $spool->append($losses);
        }
        $spool->seal();

        return $spool;
    }
}
