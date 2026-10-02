<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\Experiments\Stat36C1Comparison\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Dataset as OldDataset;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\LayoutBuilder as OldLayouts;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Optimizer as OldOptimizer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Predictor;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\Trainer as OldTrainer;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;
use App\Domain\Keirin\Statistics\StartCountC1Candidate\Contract;

final class Stat36C1ComparisonFixture
{
    public static function make(string $root, ?int $changedTeacherYear = null, int $races = 5): array
    {
        Files::directory($root);
        $input = Files::directory($root.'/input');
        $candidate = Files::directory($root.'/candidate');
        $coverage = $invariance = $candidateFiles = [];
        $baseline = Files::directory($root.'/baseline');
        Files::directory($baseline.'/inputs-v2');
        Files::directory($baseline.'/run-01');
        $seals = $paths = $expected = $targets = $manifests = $files = [];
        foreach (InputContract::YEARS as $year) {
            $raw = iterator_to_array(self::races($year, $races));
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
                'sidecar' => $candidate.'/candidates-'.$year.'.jsonl', 'teacher' => $year < 2024 ? $original : $baseline.'/run-01/labels-'.$year.'.jsonl'];
            Jsonl::write($paths[$year]['input'], $raw);
            Jsonl::write($paths[$year]['sidecar'], (function () use ($raw): \Generator {
                foreach ($raw as $race) {
                    foreach ($race['entries'] as $entry) {
                        yield self::candidate($race, $entry);
                    }
                }
            })());
            $order = hash_init('sha256');
            foreach ($raw as $race) {
                foreach ($race['entries'] as $entry) {
                    hash_update($order, Files::canonical(['year' => $year, 'race_id' => $race['race_id'],
                        'entry_id' => $entry['id'], 'bike' => $entry['bike']])."\n");
                }
            }
            $invariance[$year] = ['c1_original_seal' => Files::identity($paths[$year]['input']),
                'non_result_semantic_sha256' => hash_file('sha256', $paths[$year]['input']),
                'cohort_order_sha256' => hash_final($order), 'races' => $races, 'entries' => $races * 5];
            $coverage[$year] = ['races' => $races, 'entries' => $races * 5, 'numeric_candidates' => $races * 4, 'null_candidates' => $races];
            $candidateFiles[basename($paths[$year]['sidecar'])] = Files::identity($paths[$year]['sidecar']);
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
        $candidateContract = Contract::class;
        foreach (['contract.json' => $candidateContract::plan(), 'coverage.json' => ['years' => $coverage],
            'invariance.json' => ['years' => $invariance, 'c1_files_unmodified' => true,
                'all_targets_order_and_types_preserved' => true, 'c1_non_result_values_not_repacked_into_sidecar' => true],
            'timing-status.json' => ['historical_as_of_available' => false], 'verification.json' => ['synthetic' => true]] as $name => $data) {
            Jsonl::json($candidate.'/'.$name, $data);
            $candidateFiles[$name] = Files::identity($candidate.'/'.$name);
        }
        Jsonl::json($candidate.'/manifest.json', ['version' => $candidateContract::VERSION,
            'status' => 'CANDIDATES_PREPARED_NOT_AUTHORIZED', 'restrictions' => $candidateContract::restrictions(),
            'source' => ['c1' => Files::identity($input.'/manifest.json')], 'code' => ['synthetic' => 'fixture'], 'files' => $candidateFiles]);
        Jsonl::json($candidate.'/COMPLETE.json', Files::identity($candidate.'/manifest.json'));
        $sources = new Sources(hash_file('sha256', $input.'/manifest.json'), hash_file('sha256', $baseline.'/report-export-manifest.json'),
            hash_file('sha256', $baseline.'/frozen-experiment-contract.json'), hash_file('sha256', $candidate.'/manifest.json'));

        return ['source' => $sources->open($input, $baseline, $candidate), 'sources' => $sources,
            'input' => $input, 'baseline' => $baseline, 'candidate' => $candidate];
    }

    public static function candidate(array $race, array $entry): array
    {
        $value = $entry['bike'] === 5 ? null : ($entry['bike'] - 1) * 4;

        return ['year' => $race['year'], 'race_id' => $race['race_id'], 'entry_id' => $entry['id'],
            'bike' => $entry['bike'], 'race_date' => $race['year'].'-01-01', 'source' => 'keirin_jp',
            'external_player_id' => sprintf('%06d', $entry['bike']), 'candidate_displayed_start_count' => $value,
            'state' => $value === null ? 'NO_MATCHING_SNAPSHOT' : 'UNIQUE_DISPLAY_VALUE', 'aggregation_period' => null,
            'statistical_as_of' => null, 'correction_as_of' => null, 'timing_status' => 'UNKNOWN_S_PERIOD_BASELINE_CORRECTION',
            ...Contract::restrictions()];
    }

    public static function races(int $year, int $count): \Generator
    {
        for ($i = 0; $i < $count; $i++) {
            $id = $year * 100000 + $count - $i;
            $entries = [];
            for ($bike = 1; $bike <= 5; $bike++) {
                $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                    'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => [$bike % 2, ...array_fill(0, 11, 0)],
                    'history' => array_fill(0, 4, $bike === 5 ? null : $bike % 2), 'history_status' => $bike === 5 ? 'NO_HISTORY' : 'AVAILABLE'];
            }
            yield ['year' => $year, 'race_id' => $id, 'entries' => $entries];
        }
    }
}
