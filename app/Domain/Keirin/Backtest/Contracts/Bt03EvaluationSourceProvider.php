<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Contracts;

use App\Domain\Keirin\Backtest\DTO\Bt03EvaluationSourceDto;

interface Bt03EvaluationSourceProvider
{
    public function load(string $foldCode, string $statCode, string $cohortCode): Bt03EvaluationSourceDto;
}
