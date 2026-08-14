<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Keirin\Backtest;

use App\Domain\Keirin\Backtest\DTO\Bt02BinaryLabelsDto;
use App\Domain\Keirin\Backtest\DTO\Bt02EvaluationRowDto;
use App\Domain\Keirin\Backtest\Support\Bt02EvaluationRowSpool;
use App\Domain\Keirin\Backtest\Support\Bt02PredictionSpool;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use RuntimeException;

class Bt02EvaluationRowSpoolTest extends TestCase
{
    public function test_non_monotonic_race_ids_pass_through_evaluation_and_prediction_spools(): void
    {
        $evaluation = Bt02EvaluationRowSpool::create($this->rows([
            [89238, 100],
            [89238, 101],
            [89019, 200],
            [89019, 201],
            [90000, 300],
        ]));
        $prediction = new Bt02PredictionSpool(['fold' => 'WF_2023']);

        try {
            $actual = [];
            foreach ($evaluation->rows() as $row) {
                $actual[] = [$row->raceId, $row->raceEntryId];
                $prediction->append(
                    $row->raceId,
                    $row->raceEntryId,
                    (int) $row->labels->isWin,
                    0.25,
                    0.5,
                );
            }
            $metadata = $prediction->seal();

            $this->assertSame([
                [89238, 100],
                [89238, 101],
                [89019, 200],
                [89019, 201],
                [90000, 300],
            ], $actual);
            $this->assertSame(5, $metadata->rowCount);
            $this->assertSame(3, $metadata->raceCount);
            $this->assertSame([89238, 89019, 90000], array_column($prediction->racePayloads(), 'race_id'));
        } finally {
            $evaluation->cleanup();
            $prediction->cleanup();
        }
    }

    public function test_same_race_entry_descent_is_rejected_while_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evaluation row spool order or identity');
        Bt02EvaluationRowSpool::create($this->rows([[89238, 101], [89238, 100]]));
    }

    public function test_duplicate_identity_is_rejected_while_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evaluation row spool order or identity');
        Bt02EvaluationRowSpool::create($this->rows([[89238, 100], [89238, 100]]));
    }

    public function test_closed_race_reappearance_is_rejected_while_writing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('evaluation row spool order or identity');
        Bt02EvaluationRowSpool::create($this->rows([[89238, 100], [89019, 200], [89238, 101]]));
    }

    public function test_closed_race_reappearance_is_rejected_during_replay(): void
    {
        $spool = Bt02EvaluationRowSpool::create($this->rows([
            [89238, 100],
            [89019, 200],
            [90000, 300],
        ]));

        try {
            $path = (new ReflectionProperty(Bt02EvaluationRowSpool::class, 'path'))->getValue($spool);
            $contents = file_get_contents($path);
            $this->assertIsString($contents);
            $mutated = str_replace('"race_id":90000', '"race_id":89238', $contents, $count);
            $this->assertSame(1, $count);
            file_put_contents($path, $mutated);

            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('evaluation row spool order or identity');
            iterator_to_array($spool->rows());
        } finally {
            $spool->cleanup();
        }
    }

    /** @param list<array{int, int}> $identities @return list<Bt02EvaluationRowDto> */
    private function rows(array $identities): array
    {
        return array_map(
            fn (array $identity): Bt02EvaluationRowDto => new Bt02EvaluationRowDto(
                $identity[0],
                $identity[1],
                90.5,
                1.25,
                new Bt02BinaryLabelsDto(true, true, true),
            ),
            $identities,
        );
    }
}
