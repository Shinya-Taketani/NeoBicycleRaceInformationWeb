<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Contract as Request;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionRequest\Store as Requests;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Calculation;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Service;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1CompositionResult\Store;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Publication;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\ProbabilityCalculator;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalPredictionResult\Matcher;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Throwable;

final class CompositionResultFixture
{
    public readonly CompositionResultTemporaryDirectory $temporary;

    public readonly string $root;

    public string $requestRoot;

    public string $selection;

    public string $labels;

    public string $output;

    public array $races;

    public array $labelRows;

    public array $targets;

    public static function environment(): Application
    {
        $app = new Application(dirname(__DIR__, 2));
        $app->instance('env', 'testing');
        $app->instance('config', new Repository(['app' => ['env' => 'testing']]));
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);

        return $app;
    }

    public function __construct(int $count = 7, int $raceCount = 1)
    {
        $this->temporary = CompositionResultTemporaryDirectory::create();
        $this->root = $this->temporary->path();
        try {
            $this->requestRoot = Files::directory($this->root.'/saved-requests');
            Files::directory($this->requestRoot.'/requests');
            Jsonl::json($this->requestRoot.'/STORE.json', ['version' => Request::VERSION, 'kind' => 'DEDICATED_COMPOSITION_REQUEST_STORE']);
            $this->output = $this->root.'/result-store-01-synthetic';
            $this->labels = Files::directory($this->root.'/label-source').'/labels.jsonl';
            $this->selection = $this->root.'/selection.json';
            $this->targets = $this->races = $this->labelRows = [];
            for ($i = 0; $i < $raceCount; $i++) {
                $id = 7000 + $i;
                $requestId = 'dev-composition-2025-r'.$id.'-validation-fix-01';
                $race = self::race($id, $count);
                $this->races[] = $race;
                $path = Files::directory($this->requestRoot.'/requests/'.$requestId);
                $prediction = self::prediction($race);
                $reference = ['artifact' => Request::ARTIFACT_SEAL, 'receipt' => Request::RECEIPT_SEAL, 'input' => Request::INPUT_SEAL];
                $identity = ['request_version' => Request::VERSION, 'request_id' => $requestId, 'mode' => Request::MODE,
                    'year' => 2025, 'race_id' => $id, 'sources' => $reference, 'code' => Request::code()];
                Jsonl::json($path.'/request.json', $identity);
                Input::write($path.'/input.jsonl', [$race]);
                Jsonl::write($path.'/prediction.jsonl', [$prediction]);
                Jsonl::json($path.'/model-reference.json', ['sources' => $reference]);
                Jsonl::json($path.'/runtime.json', ['request_code' => $identity['code']]);
                $files = [];
                foreach (Request::FILES as $name) {
                    $files[$name] = Files::identity($path.'/'.$name);
                }
                Jsonl::json($path.'/manifest.json', ['status' => 'COMPLETE_DEVELOPMENT_REQUEST', 'request' => $identity,
                    'input_as_of' => null, 'observed_at' => null, 'generated_at' => 'SYNTHETIC', 'use' => Request::plan(),
                    'files' => $files, 'entrants' => array_map(fn ($e) => [$e['id'], $e['bike']], $race['entries'])]);
                Jsonl::json($path.'/COMPLETE.json', Files::identity($path.'/manifest.json'));
                $this->targets[] = ['year' => 2025, 'race_id' => $id, 'request_id' => $requestId, 'manifest' => Files::identity($path.'/manifest.json')];
                $this->labelRows[] = ['year' => 2025, 'race_id' => $id, 'entries' => array_map(fn ($e) => [
                    'id' => $e['id'], 'bike' => $e['bike'], 'rank' => $e['bike'], 'status' => 'FINISHED',
                    'raw' => -999.0, 'signals' => ['RESULT_SIDE_MUST_NOT_ENTER_INPUT'],
                ], array_reverse($race['entries']))];
            }
            Jsonl::json($this->selection, ['result_year' => 2025, 'targets' => $this->targets]);
            Jsonl::write($this->labels, $this->labelRows);
        } catch (Throwable $error) {
            $this->temporary->retire($this->temporary->path(), 'TEST_FAILURE');
            throw $error;
        }
    }

    public static function race(int $id, int $count): array
    {
        $scores = array_map(fn (int $bike): float => 100.0 - $bike, range(1, $count));
        $mean = array_sum($scores) / $count;
        $sd = sqrt(array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $scores)) / $count);
        $entries = [];
        foreach (range(1, $count) as $bike) {
            $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => $scores[$bike - 1], 'stat01_rank' => $bike,
                'anchor' => ($scores[$bike - 1] - $mean) / $sd, 'anchor_status' => 'AVAILABLE',
                'signals' => [null, 0, ...array_fill(0, 10, null)], 'history' => [0, 1, 2, 3], 'history_status' => 'AVAILABLE', 'stat35_mean6' => null];
        }

        return ['year' => 2025, 'race_id' => $id, 'entries' => $entries];
    }

    public static function prediction(array $race): array
    {
        $utility = ['year' => $race['year'], 'race_id' => $race['race_id'], 'entries' => array_map(fn (array $entry): array => [
            'id' => $entry['id'], 'bike' => $entry['bike'], 'raw' => $entry['raw'], 'stat01_rank' => $entry['stat01_rank'],
            'anchor' => $entry['anchor'], 'utilities' => ['POSITION_1' => $entry['anchor'], 'POSITION_2' => -$entry['anchor'], 'POSITION_3' => 0.0],
        ], $race['entries'])];
        $probabilities = app(ProbabilityCalculator::class)->predict($utility);

        return ['probabilities' => $probabilities, 'decision' => app(Bt03e06WinnerConditionedDecoder::class)->decode($probabilities)];
    }

    public function service(?Publication $publication = null, ?Sources $sources = null): Service
    {
        return self::connect($this->root, $this->labels, Files::json($this->labels.'.manifest.json'),
            array_column($this->targets, 'race_id'), $publication, $sources);
    }

    public static function connect(string $root, string $labels, array $seal, array $races,
        ?Publication $publication = null, ?Sources $sources = null): Service
    {
        $publication ??= self::publication($root);
        $sources ??= new Sources(app(Requests::class), app(Matcher::class), $labels, $seal, $races);
        $calculation = app(Calculation::class);
        // Adapt only this artificial root; production path restrictions and the safety helper stay untouched.
        $store = new class($publication, $calculation, app(Requests::class), $root) extends Store
        {
            public function __construct(Publication $publication, Calculation $calculation, Requests $requests, private readonly string $parent)
            {
                parent::__construct($publication, $calculation, $requests);
            }

            protected function allowedParent(): string
            {
                return $this->parent;
            }
        };

        return new Service($sources, $store, $calculation, $publication);
    }

    public static function publication(string $root): Publication
    {
        return new class($root) extends Publication
        {
            public function __construct(private readonly string $root) {}

            public function destination(string $path, array $sources = []): string
            {
                Requests::safe($path);
                if (! str_starts_with($path, $this->root.'/') || realpath(dirname($path)) !== dirname($path)
                    || file_exists($path) || is_link($path)) {
                    throw new RuntimeException('Synthetic publication conflict / path.');
                }
                foreach ($sources as $source) {
                    if ($path === $source || str_starts_with($path.'/', $source.'/') || str_starts_with($source.'/', $path.'/')) {
                        throw new RuntimeException('Synthetic publication overlap.');
                    }
                }

                return $path;
            }
        };
    }

    public function execute(?Service $service = null, string $id = 'synthetic-01'): array
    {
        return ($service ?? $this->service())->execute($this->output, $id, $this->requestRoot, $this->selection);
    }

    public function retire(string $reason = 'TEST_COMPLETE'): array
    {
        return $this->temporary->retire($this->temporary->path(), $reason);
    }
}
