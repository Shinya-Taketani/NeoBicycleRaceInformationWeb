<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Reader;
use App\Domain\Keirin\Backtest\Experiments\BFixedWinnerJointP23\Sources;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Contract;
use App\Domain\Keirin\Backtest\Experiments\C1Stat35P1Composition\Reader as ParentReader;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistory\JsonlArtifact as Jsonl;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;

// Artificial fixtures only; no real race data or model fitting.
final class BFixedWinnerJointP23Fixture
{
    public static function row(array $input): array
    {
        return app(ParentReader::class)->row($input, C1MarginalP23Fixture::prediction($input), C1Stat35P1CompositionFixture::donor($input));
    }

    public static function make(string $root, ?int $changedYear = null): array
    {
        $bundle = C1Stat35P1CompositionFixture::make($root, $changedYear);
        $b = Files::directory($root.'/saved-B');
        Files::directory($b.'/run-01');
        $seen = Reader::identities($root.'/saved-B-index.sqlite');
        $files = [];
        foreach ([2024, 2025] as $year) {
            $audit = [];
            $path = $b.'/run-01/predictions-'.$year.'.jsonl';
            Jsonl::write($path, app(ParentReader::class)->rows($bundle['source'], $year, $seen, $audit));
            Jsonl::write($b.'/run-01/decisions-'.$year.'.jsonl', (function () use ($path) {
                foreach (Jsonl::read($path) as $row) {
                    unset($row['probabilities']);
                    yield $row;
                }
            })());
        }
        foreach (glob($b.'/run-01/*') as $path) {
            $files[substr($path, strlen($b) + 1)] = Files::identity($path);
        }
        $seen->commit();
        Jsonl::json($b.'/manifest.json', ['contract' => Contract::plan(), 'status' => 'COMPLETED_DEVELOPMENT_COMPARISON_AWAITING_REVIEW',
            'source_start_end_unchanged' => true, 'code_start_end_unchanged' => true, 'source' => $bundle['source'], 'code' => ['artificial' => true], 'files' => $files]);
        Jsonl::json($b.'/COMPLETE.json', Files::identity($b.'/manifest.json'));
        $sources = new Sources($bundle['sources'], hash_file('sha256', $b.'/manifest.json'));
        $paths = [$b, $bundle['input'], $bundle['baseline'], $bundle['compare']];

        return $bundle + ['b' => $b, 'joint_sources' => $sources, 'joint_paths' => $paths, 'joint_source' => $sources->open(...$paths)];
    }
}
