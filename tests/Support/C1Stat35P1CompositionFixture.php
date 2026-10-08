<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Sources;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

final class C1Stat35P1CompositionFixture
{
    public static function donor(array $race): array
    {
        return C1MarginalP23Fixture::prediction($race, utilities: ['POSITION_1' => [-1.0, 2.0, 1.0, 0.5, -0.5, 0.7, 0.1],
            'POSITION_2' => [1.0, 2.0, -1.0, 0.5, -0.5, 0.7, 0.1], 'POSITION_3' => [2.0, 1.0, -1.0, 0.5, -0.5, 0.7, 0.1]]);
    }

    public static function make(string $root, ?int $changedYear = null): array
    {
        $bundle = C1MarginalP23Fixture::make($root, $changedYear);
        $compare = Files::directory($root.'/compare');
        Files::directory($compare.'/run-01');
        $files = [];
        foreach ([2024, 2025] as $year) {
            $dir = Files::directory($compare.'/run-01/C2-fit-'.$year);
            $input = iterator_to_array(Jsonl::read($bundle['source']['paths'][$year]['input']));
            Jsonl::write($dir.'/predictions.jsonl', array_map(self::donor(...), $input));
            $layout = ['synthetic' => true];
            Jsonl::json($dir.'/model.json', ['model_version' => Contract::MODEL_VERSION, 'experiment' => Contract::VERSION,
                'lambda' => 0.1, 'layout' => $layout, 'optimizer_diagnostics' => array_fill_keys(['POSITION_1', 'POSITION_2', 'POSITION_3'], ['status' => 'CONVERGED'])]);
            Jsonl::json($dir.'/layout.json', $layout);
            Jsonl::json($dir.'/selection.json', ['lambda' => 0.1]);
            Jsonl::json($dir.'/refit-path.json', ['synthetic' => true]);
            Jsonl::json($dir.'/sealed.json', ['model' => Files::identity($dir.'/model.json'), 'predictions' => Files::identity($dir.'/predictions.jsonl')]);
            foreach (glob($dir.'/*') as $path) {
                $files[substr($path, strlen($compare.'/run-01/'))] = Files::identity($path);
            }
        }
        $old = $bundle['source'];
        $source = ['paths' => [], 'seals' => $old['seals'] + $old['outcome_seals'], 'expected_rows' => $old['expected_rows'], 'expected_entries' => $old['expected_entries']];
        foreach ([2024, 2025] as $year) {
            $source['paths'][$year] = ['input' => $old['paths'][$year]['input'], 'baseline' => $old['paths'][$year]['prediction'],
                'model' => $old['paths'][$year]['model'], 'teacher' => $old['paths'][$year]['labels']];
        }
        Jsonl::json($compare.'/manifest.json', ['status' => 'COMPLETED_NOT_ADOPTED', 'contract' => Contract::plan(),
            'source' => $source, 'runs' => ['run-01' => $files], 'code' => ['historical_training_synthetic' => true]]);
        Jsonl::json($compare.'/COMPLETE.json', Files::identity($compare.'/manifest.json'));
        $sources = new Sources($bundle['sources'], Files::identity($compare.'/manifest.json'));

        return array_replace($bundle, ['compare' => $compare, 'baseline_sources' => $bundle['sources'], 'sources' => $sources,
            'source' => $sources->open($bundle['input'], $bundle['baseline'], $compare)]);
    }
}
