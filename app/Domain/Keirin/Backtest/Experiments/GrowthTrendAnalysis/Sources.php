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

    protected function meetingProjectionHash(): string
    {
        return '4b7cf990540583e98d4ff3431023f43b9048a92ee0642b36265a66233b463707';
    }

    public function open(string $scorePath, string $outerRoot, string $meetingPath): array
    {
        $outer = $this->outer->openOutcomeFree($outerRoot);
        $score = $this->bundle->verify($scorePath, ScoreContract::plan());
        $files = $outer['files'];
        foreach ($score['files'] as $name => $seal) {
            $files[$scorePath.'/'.$name] = $seal;
        }
        foreach (['manifest.json', 'LOCKED.json'] as $name) {
            $files[$scorePath.'/'.$name] = Files::identity($scorePath.'/'.$name);
        }
        $universe = Files::json($scorePath.'/target-universe.json');
        Files::same($outer, $universe['source_projection'], 'captured Outer identity');
        $meeting = Files::json($meetingPath.'/manifest.json');
        $projection = [];
        foreach (['metadata.jsonl', 'metadata.jsonl.manifest.json', 'meetings.json'] as $name) {
            $projection[$name] = $files[$meetingPath.'/'.$name] = $meeting['files'][$name];
        }
        $hash = hash('sha256', Files::canonical($projection));
        if (! hash_equals($this->meetingProjectionHash(), $hash)) {
            throw new RuntimeException('Meeting metadata projection mismatch.');
        }
        OuterSource::verify($files);

        return ['kind' => 'PRESEAL_OUTCOME_FREE_SOURCES', 'outer' => $outer, 'files' => $files, 'score' => $scorePath,
            'meeting' => $meetingPath, 'meeting_metadata_projection' => $projection, 'meeting_metadata_projection_sha256' => $hash];
    }
}
