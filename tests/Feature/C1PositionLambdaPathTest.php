<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Keirin\Backtest\Exceptions\Bt03e03OptimizerNonConvergenceException;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Dataset;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Optimizer;
use App\Domain\Keirin\Backtest\Experiments\C1PositionLambda\Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Layout;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Objective;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Services\Bt03e03Contract;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\Stat39C1FieldBikeFixture as Fixture;
use Tests\TestCase;

class C1PositionLambdaPathTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/c1-position-path-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function prepare(string $failure = 'position'): array
    {
        $source = Fixture::make($this->directory.'/fixture')['source'];
        $dataset = new Dataset;
        $teacher = $this->directory.'/teacher.jsonl';
        JsonlArtifact::write($teacher, $dataset->labelled($source, 2022));
        $optimizer = new class(new Objective, $failure) extends Optimizer
        {
            public array $calls = [];

            public function __construct(Objective $o, private readonly string $failure)
            {
                parent::__construct($o);
            }

            public function fitPosition(callable $raceSource, Layout $layout, float $lambda, string $position, ?array $initial): array
            {
                $this->calls[] = [$position, $lambda, $initial];
                if (($lambda === 0.1 && ($position === 'POSITION_1' || $this->failure === 'all'))
                    || ($this->failure === 'all_first_position' && $position === 'POSITION_1')) {
                    if ($this->failure === 'generic') {
                        throw new RuntimeException('Synthetic schema corruption.');
                    }
                    throw new Bt03e03OptimizerNonConvergenceException(['position' => $position, 'lambda' => $lambda]);
                }
                $v = array_fill(0, $layout->size(), 0.0);
                $v[0] = $lambda;

                return ['coefficients' => $layout->project($v), 'objective' => 2.0, 'iterations' => 1, 'eligible_races' => 5,
                    'excluded_races' => 0, 'diagnostics' => ['position' => $position, 'lambda' => $lambda, 'status' => 'CONVERGED']];
            }
        };
        $this->app->instance(Optimizer::class, $optimizer);

        return [$teacher, $optimizer, app(Trainer::class)];
    }

    public function test_position_nonconvergence_does_not_skip_other_positions_or_warm_from_failed_iterates(): void
    {
        [$teacher, $optimizer, $trainer] = $this->prepare();
        $pool = $trainer->pool(fn () => JsonlArtifact::read($teacher), [2022 => $teacher], 'T22', Files::directory($this->directory.'/pool'));
        $this->assertCount(24, $optimizer->calls);
        $this->assertCount(7, $pool['fits']['POSITION_1']);
        $this->assertCount(8, $pool['fits']['POSITION_2']);
        $this->assertCount(8, $pool['fits']['POSITION_3']);
        $this->assertSame('NUMERICALLY_NON_CONVERGED', $pool['statuses']['POSITION_1']['0.10000000000000001']['status']);
        $this->assertSame(1.0, $pool['statuses']['POSITION_1']['0.01']['warm_start_from_lambda']);
        $this->assertSame(0.1, $pool['statuses']['POSITION_2']['0.01']['warm_start_from_lambda']);
        foreach ([0, 8, 16] as $offset) {
            $this->assertNull($optimizer->calls[$offset][2]);
        }
        $this->assertSame($pool['fits']['POSITION_1'][1]['coefficients'], $optimizer->calls[2][2]);
        $this->assertSame($pool['fits']['POSITION_2']['0.10000000000000001']['coefficients'], $optimizer->calls[10][2]);
        $failed = Files::json($pool['directory'].'/POSITION_1-candidate-0.10000000000000001.json');
        $this->assertNull($failed['fit']);
    }

    public function test_generic_corruption_is_not_converted_to_numerical_failure(): void
    {
        [$teacher, $optimizer, $trainer] = $this->prepare('generic');
        $this->expectExceptionMessage('Synthetic schema corruption');
        $trainer->pool(fn () => JsonlArtifact::read($teacher), [2022 => $teacher], 'T22', Files::directory($this->directory.'/pool'));
    }

    public function test_all_first_position_failures_are_audited_without_suppressing_remaining_positions(): void
    {
        [$teacher, $optimizer, $trainer] = $this->prepare('all_first_position');
        $dir = Files::directory($this->directory.'/pool');
        try {
            $trainer->pool(fn () => JsonlArtifact::read($teacher), [2022 => $teacher], 'T22', $dir);
            $this->fail('No first-position candidate accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('No converged candidate', $e->getMessage());
        }
        $this->assertCount(24, $optimizer->calls);
        $this->assertSame(24, Files::json($dir.'/path.json')['attempt_count']);
        $this->assertFileExists($dir.'/pool-sealed.json');
    }

    #[DataProvider('positions')]
    public function test_selected_position_refit_failure_stops_without_fallback(string $position): void
    {
        [$teacher, $optimizer, $trainer] = $this->prepare('all');
        $stop = array_fill_keys(Bt03e03Contract::POSITIONS, 1.0);
        $stop[$position] = 0.1;
        $this->expectExceptionMessage('Selected position lambda did not converge');
        $trainer->pool(fn () => JsonlArtifact::read($teacher), [2022 => $teacher], 'T22', Files::directory($this->directory.'/pool'), $stop);
    }

    public static function positions(): array
    {
        return array_map(fn ($p) => [$p], Bt03e03Contract::POSITIONS);
    }

    #[DataProvider('tampering')]
    public function test_pool_tampering_cannot_reuse_wrong_position_layout_source_or_bin(string $kind): void
    {
        [$teacher, $optimizer, $trainer] = $this->prepare();
        $pool = $trainer->pool(fn () => JsonlArtifact::read($teacher), [2022 => $teacher], 'T22', Files::directory($this->directory.'/pool'));
        match ($kind) {
            'position' => $pool['fits']['POSITION_1'][1]['diagnostics']['position'] = 'POSITION_2',
            'lambda' => $pool['fits']['POSITION_1'][1]['diagnostics']['lambda'] = 0.0,
            'source' => $pool['contract']['teacher_seals'][2022]['sha256'] = str_repeat('0', 64),
            'pool' => $pool['contract']['pool'] = 'T2223',
            'layout' => file_put_contents($pool['directory'].'/layout.json', '{}'),
            'bin' => $pool['fits']['POSITION_1'][1]['coefficients'][0] += 1.0,
            'training' => file_put_contents($pool['directory'].'/training.jsonl', 'x', FILE_APPEND),
        };
        $this->expectException(RuntimeException::class);
        $trainer->verifyPool($pool);
    }

    public static function tampering(): array
    {
        return array_map(fn ($p) => [$p], ['position', 'lambda', 'source', 'pool', 'layout', 'bin', 'training']);
    }
}
