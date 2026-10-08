<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\Bt03e06WinnerConditionedDecoder;
use App\Domain\Keirin\Backtest\DTO\Bt03e03FitResultDto;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1MarginalP23Decoder\Sources;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Contract as FinalContract;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariC1Input\Contract as InputContract;

final class C1MarginalP23Fixture
{
    public static function race(int $year = 2024, int $id = 19, int $n = 7): array
    {
        $entries = [];
        foreach (array_slice([1, 2, 3, 4, 6, 7, 8, 9, 5], 0, $n) as $bike) {
            $entries[] = ['id' => $id * 10 + $bike, 'bike' => $bike, 'raw' => 100.0, 'stat01_rank' => 1,
                'anchor' => 0.0, 'anchor_status' => 'ZERO_VARIANCE', 'signals' => array_fill(0, 12, null),
                'history' => [null, null, null, null], 'history_status' => 'NO_HISTORY'];
        }

        return ['year' => $year, 'race_id' => $id, 'entries' => $entries];
    }

    public static function prediction(array $input, bool $tie = false, ?array $utilities = null): array
    {
        $race = $input;
        foreach ($race['entries'] as $i => &$entry) {
            $entry['bins'] = [$i];
            $entry['rank'] = $entry['status'] = null;
        }
        unset($entry);
        $coefficients = $tie ? array_fill(0, count($race['entries']), 0.0) : array_slice([2.0, 1.0, -1.0, 0.5, -0.5, 0.7, 0.1, -0.3, 1.2], 0, count($race['entries']));
        $fit = new Bt03e03FitResultDto(0.1, $utilities ?? ['POSITION_1' => $coefficients, 'POSITION_2' => array_reverse($coefficients),
            'POSITION_3' => array_map(fn ($v) => -$v, $coefficients)], [], [], [], []);
        $p = app(Bt03e03ProbabilityScorer::class)->predict($race, $fit);
        foreach ($p['entries'] as &$entry) {
            unset($entry['rank'], $entry['status']);
        }
        unset($entry);
        $decision = app(Bt03e06WinnerConditionedDecoder::class)->decode($p);
        $decision['reconstruction_verified'] = false;
        $decision['prediction_origin'] = 'EXPERIMENTAL_REFIT';

        return ['probabilities' => $p, 'decision' => $decision];
    }

    public static function make(string $root, ?int $changedYear = null): array
    {
        Files::directory($root);
        $input = Files::directory($root.'/input');
        $baseline = Files::directory($root.'/baseline');
        Files::directory($baseline.'/inputs-v2');
        Files::directory($baseline.'/run-01');
        $files = $expected = $targets = [];
        foreach ([2024, 2025] as $year) {
            $races = [self::race($year, $year * 10 + 9), self::race($year, $year * 10 + 2)];
            $labels = $races;
            foreach ($labels as &$row) {
                foreach ($row['entries'] as $i => &$entry) {
                    $entry['rank'] = $changedYear === $year ? count($row['entries']) - $i : $i + 1;
                    $entry['status'] = 'FINISHED';
                }
                unset($entry);
            }
            unset($row);
            Jsonl::write($input.'/c1-'.$year.'.jsonl', $races);
            $files['c1-'.$year.'.jsonl'] = Files::identity($input.'/c1-'.$year.'.jsonl');
            Jsonl::write($baseline.'/run-01/labels-'.$year.'.jsonl', $labels);
            $dir = Files::directory($baseline.'/run-01/C1-fit-'.$year);
            Jsonl::write($dir.'/predictions.jsonl', array_map(fn ($r) => self::prediction($r), $races));
            $layout = ['synthetic' => true];
            Jsonl::json($dir.'/model.json', ['model_version' => Contract::plan()['model_version'], 'lambda' => 0.1, 'layout' => $layout,
                'optimizer_diagnostics' => array_fill_keys(['POSITION_1', 'POSITION_2', 'POSITION_3'], ['status' => 'CONVERGED'])]);
            Jsonl::json($dir.'/layout.json', $layout);
            Jsonl::json($dir.'/selection.json', ['lambda' => 0.1]);
            Jsonl::json($dir.'/refit-path.json', ['synthetic' => true]);
            $expected[$year] = count($races);
            $targets[$year] = 14;
        }
        Jsonl::json($baseline.'/inputs-v2/manifest.json', ['calculation_version' => InputContract::C1_VERSION]);
        Jsonl::json($baseline.'/frozen-experiment-contract.json', FinalContract::parentSettings());
        $records = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($baseline, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $records[substr($file->getPathname(), strlen($baseline) + 1)] = Files::identity($file->getPathname());
        }
        Jsonl::json($baseline.'/report-export-manifest.json', ['included' => $records, 'omitted' => []]);
        Jsonl::json($input.'/manifest.json', ['contract' => InputContract::plan(), 'status' => 'INPUTS_PREPARED', 'code' => ['synthetic' => true],
            'source' => ['seals' => [$baseline.'/inputs-v2/manifest.json' => Files::identity($baseline.'/inputs-v2/manifest.json')],
                'expected_rows' => $expected, 'expected_targets' => $targets], 'files' => $files]);
        Jsonl::json($input.'/COMPLETE.json', Files::identity($input.'/manifest.json'));
        $sources = new Sources(hash_file('sha256', $input.'/manifest.json'), hash_file('sha256', $baseline.'/report-export-manifest.json'),
            hash_file('sha256', $baseline.'/frozen-experiment-contract.json'));

        return ['input' => $input, 'baseline' => $baseline, 'sources' => $sources, 'source' => $sources->open($input, $baseline)];
    }
}
