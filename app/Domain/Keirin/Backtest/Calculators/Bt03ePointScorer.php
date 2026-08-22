<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Calculators;

use App\Domain\Keirin\Backtest\DTO\Bt03eCandidateDto;
use App\Domain\Keirin\Backtest\Services\Bt03eContract;
use RuntimeException;

class Bt03ePointScorer
{
    /**
     * @param  list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>  $entries
     * @return array{entries: list<array<string, mixed>>, tied_entries: int, stat01_tie_breaks: int, tied: bool}
     */
    public function rank(array $entries, Bt03eCandidateDto $candidate): array
    {
        $maximumStat01Rank = $this->maximumStat01Rank($entries);
        $ranked = [];
        foreach ($entries as $entry) {
            $this->assertEntry($entry);
            $points = ($maximumStat01Rank - $entry['stat01_rank']) * $candidate->baseStep;
            foreach (Bt03eContract::STAT_CODES as $index => $statCode) {
                $points += $entry['directions'][$index] * ($candidate->weights[$statCode] ?? 0);
            }
            $ranked[] = [...$entry, 'score' => $points];
        }
        usort($ranked, static fn (array $left, array $right): int => [
            -$left['score'], -$left['raw'], $left['bike'],
        ] <=> [
            -$right['score'], -$right['raw'], $right['bike'],
        ]);

        $groups = [];
        foreach ($ranked as $entry) {
            $groups[(string) $entry['score']][] = $entry;
        }
        $tiedEntries = $stat01TieBreaks = 0;
        foreach ($groups as $group) {
            if (count($group) < 2) {
                continue;
            }
            $tiedEntries += count($group);
            if (count(array_unique(array_map(static fn (array $entry): float => (float) $entry['raw'], $group))) > 1) {
                $stat01TieBreaks++;
            }
        }

        return [
            'entries' => $ranked,
            'tied_entries' => $tiedEntries,
            'stat01_tie_breaks' => $stat01TieBreaks,
            'tied' => $tiedEntries > 0,
        ];
    }

    /**
     * @param  list<array{id: int, bike: int, raw: float, stat01_rank: int, directions: list<int>, rank: ?int, status: string}>  $entries
     * @return array{entries: list<array<string, mixed>>, tied_entries: int, stat01_tie_breaks: int, tied: bool}
     */
    public function rankBaseline(array $entries): array
    {
        foreach ($entries as $entry) {
            $this->assertEntry($entry);
        }
        usort($entries, static fn (array $left, array $right): int => [
            -$left['raw'], $left['bike'],
        ] <=> [
            -$right['raw'], $right['bike'],
        ]);

        $rawGroups = [];
        foreach ($entries as $entry) {
            $rawGroups[(string) $entry['raw']] = ($rawGroups[(string) $entry['raw']] ?? 0) + 1;
        }
        $tiedEntries = array_sum(array_filter($rawGroups, static fn (int $count): bool => $count >= 2));

        return [
            'entries' => $entries,
            'tied_entries' => $tiedEntries,
            'stat01_tie_breaks' => 0,
            'tied' => $tiedEntries > 0,
        ];
    }

    /** @param list<array<string, mixed>> $entries */
    private function maximumStat01Rank(array $entries): int
    {
        if ($entries === []) {
            throw new RuntimeException('BT-03E scoring entries were empty.');
        }
        foreach ($entries as $entry) {
            $this->assertEntry($entry);
        }
        if (max(array_column($entries, 'stat01_rank')) > count($entries)) {
            throw new RuntimeException('BT-03E stored STAT-01 rank exceeded the entrant count.');
        }

        return max(array_column($entries, 'stat01_rank'));
    }

    /** @param array<string, mixed> $entry */
    private function assertEntry(array $entry): void
    {
        if (! is_int($entry['id'] ?? null) || ! is_int($entry['bike'] ?? null)
            || ! is_numeric($entry['raw'] ?? null) || ! is_array($entry['directions'] ?? null)
            || ! is_int($entry['stat01_rank'] ?? null) || $entry['stat01_rank'] < 1
            || count($entry['directions']) !== count(Bt03eContract::STAT_CODES)
            || array_filter($entry['directions'], static fn (mixed $value): bool => ! is_int($value) || $value < -2 || $value > 2) !== []) {
            throw new RuntimeException('BT-03E scoring entry was invalid.');
        }
    }
}
