<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Support;

use App\Domain\Keirin\Backtest\DTO\LogisticTrainingRowDto;
use RuntimeException;

class Bt02TrainingSpoolFactory
{
    public function __construct(private readonly ?string $temporaryDirectory = null) {}

    /** @param iterable<LogisticTrainingRowDto> $rows */
    public function create(iterable $rows): ImmutableBt02Spool
    {
        $spool = new ImmutableBt02Spool($this->temporaryDirectory);

        try {
            foreach ($rows as $row) {
                if (! $row instanceof LogisticTrainingRowDto) {
                    throw new RuntimeException('BT-02 training spool input row was invalid.');
                }
                $spool->append($row);
            }
            $spool->seal();

            return $spool;
        } catch (\Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }
}
