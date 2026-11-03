<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\InitialHistoryMetadata;

use PDO;

interface MetadataSource
{
    /** Emit(type, scope key, current row or null). Return non-secret connection evidence after disconnect. */
    public function capture(PDO $scope, callable $emit, callable $connectionEvidence): array;
}
