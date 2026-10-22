<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Statistics\OfficialMethodHistory;

use App\Domain\Keirin\Scraping\Enums\RaceEntryResultStatus;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class Contract
{
    public const VERSION = 'OFFICIAL-TOP2-METHOD-HISTORY-v1';

    public const METHODS = ['逃げ' => 'ESCAPE', '捲り' => 'MAKURI', '差し' => 'SASHI', 'マーク' => 'MARK'];

    public static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, new DateTimeZone('Asia/Tokyo'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new RuntimeException('Invalid official history date.');
        }

        return $date;
    }

    public static function outcome(?int $rank, string $status, ?string $method): array
    {
        $state = RaceEntryResultStatus::tryFrom($status);
        $method = HtmlTextNormalizer::normalize($method);
        if ($state === null) {
            return ['participation' => 'UNKNOWN', 'method' => null, 'method_missing' => false];
        }
        $finished = in_array($state, [RaceEntryResultStatus::Finished, RaceEntryResultStatus::Tied], true);
        if (($finished && ($rank === null || $rank < 1 || $rank > 9)) || (! $finished && $rank !== null)
            || ($method !== null && (! $finished || $rank > 2 || ($method === 'マーク' && $rank !== 2)))) {
            throw new RuntimeException('Contradictory official rank/status/method.');
        }
        $nonstart = in_array($state, [RaceEntryResultStatus::DidNotStart, RaceEntryResultStatus::Withdrawn], true);

        return ['participation' => $nonstart ? 'NON_START' : 'STARTED',
            'method' => $finished && $rank <= 2 ? (self::METHODS[$method ?? ''] ?? null) : null,
            'method_missing' => $finished && $rank <= 2 && ! isset(self::METHODS[$method ?? ''])];
    }
}
