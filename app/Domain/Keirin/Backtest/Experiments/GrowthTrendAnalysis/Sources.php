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

    protected function meetingFiles(): array
    {
        return [
            'metadata.jsonl' => ['rows' => 50078, 'bytes' => 18138704, 'sha256' => '949e86a4fdd5c509ce890289de96633b48d5c4c589acc184f305e55cdd161b3c'],
            'metadata.jsonl.manifest.json' => ['bytes' => 127, 'sha256' => '7ba87058c083358f7fd5e82d7d8687e682649a1261d1460024413c86475d4374'],
            'meetings.json' => ['bytes' => 767205, 'sha256' => 'e698b563b562ac1a4a8dab039bb5bbdfc05c115199450c4f8f25427924635503'],
        ];
    }

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
        $fixed = $this->meetingFiles();
        $projection = [];
        foreach (['metadata.jsonl', 'metadata.jsonl.manifest.json', 'meetings.json'] as $name) {
            $projection[$name] = $files[$meetingPath.'/'.$name] = $fixed[$name];
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
