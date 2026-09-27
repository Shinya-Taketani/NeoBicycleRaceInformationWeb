<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use App\Domain\Keirin\Statistics\AgariPlayerHistory\Source;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Artifacts;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Builder;
use App\Domain\Keirin\Statistics\AgariRaceRelative\Contract;

/** Synthetic only; existing relative Builder is used only to generate artificial sealed fixtures. */
final class AgariPlayerHistoryFixture
{
    public static function make(string $root, iterable $rows): Source
    {
        Artifacts::create($root.'/input');
        $races = $entries = 0;
        $seal = Artifacts::write($root.'/input', 'races.jsonl', (function () use ($rows, &$races, &$entries) {
            foreach ($rows as $row) {
                $races++;
                $entries += count($row['results']);
                yield Files::canonical($row)."\n";
            }
        })());
        Artifacts::publish($root.'/input', [...Contract::DISCLOSURE, 'kind' => 'INPUT', 'version' => Contract::VERSION,
            'source' => 'keirin_jp', 'order' => 'race_id_ASC', 'from' => '2022-01-01', 'to' => '2025-12-31',
            'race_count' => $races, 'result_count' => $entries, 'code' => ['synthetic-fixture' => hash('sha256', 'fixture-v1')],
            'files' => ['races.jsonl' => $seal]]);
        app(Builder::class)->build($root.'/input', 'v2', $root.'/relative');

        return self::source($root);
    }

    public static function source(string $root): Source
    {
        return new Source(Files::identity($root.'/input/races.jsonl'), Files::identity($root.'/relative/details.jsonl'));
    }

    public static function race(int $id, string $date, ?int $meeting = null, ?string $start = null, ?string $end = null, array $times = ['12', '14']): array
    {
        $row = AgariRaceRelativeFixture::race($id, $date, $times);
        $row['context']['meeting_id'] = $meeting ?? $id;
        $row['context']['starts_on'] = $start ?? $date;
        $row['context']['ends_on'] = $end ?? $date;

        return $row;
    }

    public static function reseal(string $directory): void
    {
        $manifest = Files::json($directory.'/manifest.json');
        foreach ($manifest['files'] as $name => $_) {
            $manifest['files'][$name] = Files::identity($directory.'/'.$name);
        }
        file_put_contents($directory.'/manifest.json', Files::canonical($manifest)."\n");
        file_put_contents($directory.'/COMPLETE.json', Files::canonical(Files::identity($directory.'/manifest.json'))."\n");
    }
}
