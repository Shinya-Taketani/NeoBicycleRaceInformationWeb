<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use InvalidArgumentException;

class Bt01SourceManifest
{
    public const VERSION = 'BT01-STAT01-MANIFEST-v1';

    public const STAT_CODE = 'STAT-01';

    public const CALCULATION_VERSION = 'STAT-01-existing-db-v1';

    /** @var list<SourceManifestEntryDto> */
    private array $entries;

    /** @param list<SourceManifestEntryDto>|null $entries */
    public function __construct(private readonly CanonicalHasher $hasher, ?array $entries = null)
    {
        $this->entries = $entries ?? [
            new SourceManifestEntryDto(2022, 25, '82a88496-35b4-48fc-81c3-8b46b5eb626f', '2022-01-01', '2022-12-31', 24868, 174152),
            new SourceManifestEntryDto(2023, 26, '71c344f6-e09b-4496-9cd0-a68642e2c462', '2023-01-01', '2023-12-31', 25561, 181548),
            new SourceManifestEntryDto(2024, 1, '07f2fc31-0d9c-41d9-95b7-80c7afb396ce', '2024-01-01', '2024-12-31', 25624, 182004),
            new SourceManifestEntryDto(2025, 27, 'b62ba626-5019-4018-8cd7-7d09c61a8ceb', '2025-01-01', '2025-12-31', 25273, 180005),
        ];
        usort($this->entries, fn (SourceManifestEntryDto $a, SourceManifestEntryDto $b): int => $a->year <=> $b->year);
        if (array_map(fn (SourceManifestEntryDto $entry): int => $entry->year, $this->entries) !== [2022, 2023, 2024, 2025]) {
            throw new InvalidArgumentException('BT-01 requires exactly the fixed 2022-2025 STAT-01 sources.');
        }
    }

    /** @return list<SourceManifestEntryDto> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function forYear(int $year): SourceManifestEntryDto
    {
        foreach ($this->entries as $entry) {
            if ($entry->year === $year) {
                return $entry;
            }
        }

        throw new InvalidArgumentException("No fixed BT-01 source exists for {$year}.");
    }

    public function hash(): string
    {
        return $this->hasher->hash([
            'version' => self::VERSION,
            'stat_code' => self::STAT_CODE,
            'calculation_version' => self::CALCULATION_VERSION,
            'sources' => array_map(fn (SourceManifestEntryDto $entry): array => $entry->canonical(), $this->entries),
        ]);
    }
}
