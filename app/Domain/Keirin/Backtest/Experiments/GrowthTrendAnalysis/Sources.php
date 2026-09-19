<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\GrowthTrendAnalysis;

use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Bundle;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\Contract as ScoreContract;
use App\Domain\Keirin\Backtest\Experiments\GrowthTrendScoreSource\OuterSource;
use App\Domain\Keirin\Backtest\Experiments\TacticalHistoryFinal\Files;
use RuntimeException;

class Sources
{
    public function __construct(private readonly OuterSource $outer, private readonly Bundle $bundle) {}

    public function open(string $scorePath, string $outerRoot, string $meetingPath): array
    {
        $outer = $this->outer->open($outerRoot);
        $score = $this->bundle->verify($scorePath, ScoreContract::plan());
        $files = $outer['files'];
        foreach ($score['files'] as $name => $seal) {
            $files[$scorePath.'/'.$name] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            $files[$scorePath.'/'.$name] = Files::identity($scorePath.'/'.$name);
        }
        $universe = Files::json($scorePath.'/target-universe.json');
        Files::same($outer, $universe['source'], 'captured Outer identity');
        Files::verify($meetingPath.'/manifest.json', Files::json($meetingPath.'/LOCKED.json'));
        if (Files::identity($meetingPath.'/manifest.json')['sha256'] !== '61d1718f29f1683a34698a40f25d89273a52f976c4d475641b6bfc4fd14e05fc') {
            throw new RuntimeException('Expected fixed meeting metadata.');
        }
        $meeting = Files::json($meetingPath.'/manifest.json');
        foreach (['metadata.jsonl', 'metadata.jsonl.manifest.json', 'meetings.json'] as $name) {
            $files[$meetingPath.'/'.$name] = $meeting['files'][$name];
        }
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            $files[$meetingPath.'/'.$name] = Files::identity($meetingPath.'/'.$name);
        }
        OuterSource::verify($files);

        return ['outer' => $outer, 'files' => $files, 'score' => $scorePath, 'meeting' => $meetingPath];
    }
}
