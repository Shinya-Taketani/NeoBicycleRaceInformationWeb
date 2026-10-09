<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Forward;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\Input;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35CompositionFinal\TrainingData;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\ModelLoader as C2Loader;
use App\Domain\Keirin\Backtest\Experiments\Stat35C1Comparison\Trainer as C2Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer as C1Trainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as C1Contract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\ModelLoader as C1Loader;

final class C1Stat35CompositionFinalFixture
{
    public static function make(string $root, ?int $changedYear = null): array
    {
        $source = Stat35C1ComparisonFixture::make($root, $changedYear)['source'];
        $teachers = Files::directory($root.'/teachers');
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $features = new TrainingData;
            $base = Jsonl::read($source['paths'][$year]['teacher']);
            $base->rewind();
            $path = $teachers.'/'.$year.'.jsonl';
            Jsonl::write($path, (function () use ($features, $source, $year, $base): \Generator {
                foreach ($features->features($source, $year) as $race) {
                    $row = Input::modelRace($race);
                    foreach ($row['entries'] as $i => &$entry) {
                        $entry['rank'] = $base->current()['entries'][$i]['rank'];
                        $entry['status'] = $base->current()['entries'][$i]['status'];
                    }
                    unset($entry);
                    yield $row;
                    $base->next();
                }
            })());
            $source['paths'][$year]['c2_teacher'] = $path;
        }
        $training = static fn (array $years) => TrainingData::training(array_map(static fn ($y) => $teachers.'/'.$y.'.jsonl', $years));
        $reuse = Files::directory($root.'/reused');
        foreach ([2023 => [2022], 2024 => [2022, 2023]] as $year => $years) {
            $dir = Files::directory($reuse.'/C2-inner-'.($year === 2023 ? 'A' : 'B'));
            $grid = app(C2Trainer::class)->grid($training($years), $training([$year]), $dir);
            $grid['losses']->cleanup();
            $source['reused_folds'][$year] = $dir;
        }
        $final = Files::directory($root.'/existing-final-c1');
        $fit = app(C1Trainer::class)->refit([$source['paths'][2022]['original']], $source['paths'][2025]['original'], true, 0.1, $final);
        Jsonl::json($final.'/artifact.json', ['contract' => C1Contract::plan(), 'model_file' => 'model.json', 'model' => Files::identity($final.'/model.json')]);
        $source['c1_artifact'] = $final.'/artifact.json';
        unset($fit);
        $reference = Files::directory($root.'/reference');
        Files::directory($reference.'/run-01');
        foreach ([2024, 2025] as $year) {
            $dir = Files::directory($root.'/outer-c2-'.$year);
            $features = static function () use ($source, $year): \Generator {
                foreach ((new TrainingData)->features($source, $year) as $race) {
                    yield Input::modelRace($race);
                }
            };
            app(C2Trainer::class)->refit($training([2022]), $features, 1.0, $dir);
            $source['outer_c2'][$year] = $dir.'/model.json';
            $c1 = app(C1Loader::class)->load($source['paths'][$year]['model'], Files::identity($source['paths'][$year]['model']));
            $c2 = app(C2Loader::class)->load($dir.'/model.json', Files::identity($dir.'/model.json'));
            Jsonl::write($reference.'/run-01/predictions-'.$year.'.jsonl', (function () use ($source, $year, $c1, $c2): \Generator {
                foreach ((new TrainingData)->features($source, $year) as $race) {
                    $prediction = app(Forward::class)->predict($race, $c1, $c2);
                    yield ['probabilities' => $prediction['probabilities'], 'candidate' => $prediction['decision']];
                }
            })());
        }
        $source['reference'] = $reference;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $source['seals'][$file->getPathname()] = Files::identity($file->getPathname());
        }

        return $source;
    }

    public static function feature(int $id, int $year = 2025, int $count = 5): array
    {
        $race = iterator_to_array(Stat35C1ComparisonFixture::races($year, 1))[0];
        $race['race_id'] = $id;
        $entries = [];
        foreach (range(1, $count) as $bike) {
            $entry = $race['entries'][($bike - 1) % 5];
            $entry['id'] = $id * 10 + $bike;
            $entry['bike'] = $bike;
            $entry['stat35_mean6'] = $bike === 5 ? null : $bike / 3.0;
            $entries[] = $entry;
        }
        $race['entries'] = $entries;

        return $race;
    }

    public static function provenance(string $c1Artifact): array
    {
        return ['c1_model_sha256' => Files::identity(dirname($c1Artifact).'/model.json')['sha256'],
            'reference_manifest_sha256' => Contract::REFERENCE_SHA, 'training_years' => [2022, 2023, 2024, 2025]];
    }
}
