<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use PHPUnit\Framework\TestCase;

class Bt02PredictionSpoolTest extends TestCase
{
    public function test_same_prediction_stream_has_deterministic_role_specific_manifests(): void
    {
        $identity = ['fold' => 'WF_2023', 'stat' => 'STAT-07', 'label' => 'IS_WIN'];
        $first = new Bt02PredictionSpool($identity);
        $second = new Bt02PredictionSpool($identity);
        foreach ([
            [1, 10, 1, 0.25, 0.5],
            [1, 11, 0, 0.75, 0.5],
            [2, 20, 0, 0.1, 0.2],
        ] as $row) {
            $first->append(...$row);
            $second->append(...$row);
        }

        $firstHashes = $first->seal();
        $secondHashes = $second->seal();
        $this->assertSame($firstHashes, $secondHashes);
        $this->assertNotSame($firstHashes['baseline'], $firstHashes['incremental']);
        $this->assertCount(2, $first->racePayloads());
        $this->assertSame([1, 0], $first->racePayloads()[0]['labels']);

        $path = $first->path();
        $first->cleanup();
        $second->cleanup();
        $this->assertFileDoesNotExist($path);
    }
}
