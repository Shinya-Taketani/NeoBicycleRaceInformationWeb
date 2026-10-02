<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat17C1Comparison\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset as OldDataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder as OldLayouts;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer as OldOptimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer as OldTrainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;

final class Stat17C1ComparisonFixture
{
    public static function make(string $root, ?int $changedTeacherYear = null, int $races = 5, bool $allUnavailable = false): array
    {
        Files::directory($root);
        $input = Files::directory($root.'/input');
        $baseline = Files::directory($root.'/baseline');
        Files::directory($baseline.'/inputs-v2');
        Files::directory($baseline.'/run-01');
        $seals = $paths = $expected = $targets = $manifests = $files = [];
        foreach (InputContract::YEARS as $year) {
            $raw = iterator_to_array(self::races($year, $races));
            if ($allUnavailable) {
                foreach ($raw as &$race) {
                    foreach ($race['entries'] as &$entry) {
                        $entry['history'] = [null, null, null, null];
                        $entry['history_status'] = 'NO_HISTORY';
                    }
                    unset($entry);
                }
                unset($race);
            }
            $teacher = $raw;
            foreach ($teacher as $i => &$race) {
                foreach ($race['entries'] as &$entry) {
                    $entry['rank'] = $changedTeacherYear === $year ? $entry['bike'] : ($entry['bike'] + $i - 1) % 5 + 1;
                    $entry['status'] = 'FINISHED';
                    if ($year < 2024) {
                        $entry['labels'] = [];
                    }
                }
                unset($entry);
            }
            unset($race);
            $original = $baseline.'/inputs-v2/inputs-'.$year.'.jsonl';
            $manifests[$year]['inputs'] = Jsonl::write($original, $year < 2024 ? $teacher : $raw);
            $paths[$year] = ['input' => $input.'/c1-'.$year.'.jsonl', 'original' => $original,
                'teacher' => $year < 2024 ? $original : $baseline.'/run-01/labels-'.$year.'.jsonl'];
            Jsonl::write($paths[$year]['input'], $raw);
            if ($year >= 2024) {
                Jsonl::write($paths[$year]['teacher'], $teacher);
                $dir = Files::directory($baseline.'/run-01/C1-fit-'.$year);
                $bins = app(EffectBinBuilder::class);
                $oldData = new OldDataset;
                $layout = app(OldLayouts::class)->build(fn () => $oldData->raw([$baseline.'/inputs-v2/inputs-2022.jsonl'], true), true);
                $fit = app(OldOptimizer::class)->fit(fn () => $oldData->binned(fn () => $oldData->raw([$baseline.'/inputs-v2/inputs-2022.jsonl'], true), $layout, $bins), $layout, 1.0);
                $trainer = app(OldTrainer::class);
                $model = (new \ReflectionMethod($trainer, 'model'))->invoke($trainer, $layout, $fit);
                Jsonl::json($dir.'/model.json', $model);
                Jsonl::json($dir.'/layout.json', $model['layout']);
                Jsonl::json($dir.'/selection.json', ['lambda' => 1.0]);
                Jsonl::json($dir.'/refit-path.json', ['fit_order' => [1.0]]);
                $predictor = app(Predictor::class);
                Jsonl::write($dir.'/predictions.jsonl', (function () use ($oldData, $original, $layout, $bins, $predictor, $fit) {
                    foreach ($oldData->binned(fn () => $oldData->raw([$original], true, true), $layout, $bins) as $race) {
                        yield $predictor->predict($race, $fit);
                    }
                })());
                $paths[$year]['model'] = $dir.'/model.json';
                $paths[$year]['baseline'] = $dir.'/predictions.jsonl';
            }
            foreach (['input'] as $key) {
                $files[basename($paths[$year][$key])] = Files::identity($paths[$year][$key]);
            }
            $expected[$year] = $races;
            $targets[$year] = $races * 5;
        }
        Jsonl::json($baseline.'/inputs-v2/manifest.json', ['calculation_version' => InputContract::C1_VERSION, 'manifests' => $manifests]);
        Jsonl::json($baseline.'/frozen-experiment-contract.json', FinalContract::parentSettings());
        $export = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseline, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $export[substr($file->getPathname(), strlen($baseline) + 1)] = Files::identity($file->getPathname());
        }
        Jsonl::json($baseline.'/report-export-manifest.json', ['included' => $export, 'omitted' => []]);
        Jsonl::json($input.'/manifest.json', ['contract' => InputContract::plan(), 'status' => 'INPUTS_PREPARED', 'code' => ['synthetic' => 'fixture'],
            'source' => ['seals' => [$baseline.'/inputs-v2/manifest.json' => Files::identity($baseline.'/inputs-v2/manifest.json')],
                'expected_rows' => $expected, 'expected_targets' => $targets], 'files' => $files]);
        Jsonl::json($input.'/COMPLETE.json', Files::identity($input.'/manifest.json'));
        $sources = new Sources(hash_file('sha256', $input.'/manifest.json'), hash_file('sha256', $baseline.'/report-export-manifest.json'),
            hash_file('sha256', $baseline.'/frozen-experiment-contract.json'));

        return ['source' => $sources->open($input, $baseline), 'sources' => $sources,
            'input' => $input, 'baseline' => $baseline];
    }

    public static function races(int $year, int $count): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $id = $year * 100000 + $count - $i;
            $entries = [];
            for ($bike = 1; $bike <= 5; $bike++) {
                $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                    'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => [$bike % 2, ...array_fill(0, 11, 0)],
                    'history' => match ($bike) {
                        1 => [8, 0, 0, 0], 2 => [4, 4, 0, 0], 3 => [2, 2, 2, 2], 4 => [0, 0, 0, 0], 5 => [null, null, null, null]
                    }, 'history_status' => $bike === 5 ? 'NO_HISTORY' : 'AVAILABLE'];
            }
            yield ['year' => $year, 'race_id' => $id, 'entries' => $entries];
        }
    }
}
