<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\DTO\Bt02BaselineFingerprintEntryDto;
use LogicException;

class Bt02BaselineFingerprintManifest
{
    public const VERSION = 'BT02-STAT01-BASELINE-FINGERPRINT-MANIFEST-v1';

    public const HASH = '9c0d8a78ec12fa76b9b973a92d2c7dfc3176f2219e7fc130e8e83d0d35b68a89';

    public const SOURCE_FINGERPRINT_VERSION = 'BT02-SOURCE-FINGERPRINT-v1';

    public const CONTENT_FINGERPRINT_VERSION = 'BT02-SOURCE-CONTENT-FINGERPRINT-v1-PG18.4';

    /** @var array<int, array{int, string, string, string, string, int, int, string, string}> */
    private const SOURCES = [
        2022 => [25, '82a88496-35b4-48fc-81c3-8b46b5eb626f', 'STAT-01-existing-db-v1', '2022-01-01', '2022-12-31', 24868, 174152, 'dc905e8a97adc031d3adf6bd7034af0cf487f57137ef552f8d489fe8aa929c7c', '776cf96ddde3200044d8d3a5c9d557fa2d98997e4f1fde50450a48abb2001c67'],
        2023 => [26, '71c344f6-e09b-4496-9cd0-a68642e2c462', 'STAT-01-existing-db-v1', '2023-01-01', '2023-12-31', 25561, 181548, '1270048aafc3ff0c9b1f058b43078ed79e7a6621432a744c36b210b0855ff228', '3bb80d9b2d61932ccbc9e3b94007334cb37a9358a9cbeb34ad577f67807d6622'],
        2024 => [1, '07f2fc31-0d9c-41d9-95b7-80c7afb396ce', 'STAT-01-existing-db-v1', '2024-01-01', '2024-12-31', 25624, 182004, '97dd6ea911378023767b6c798f60854b83387c4b27602be5ad2c604d0f16d04f', '572457b0fe4a63aec5399d46339edcf90d3a1670ceb261acbf8938daefb31e79'],
        2025 => [27, 'b62ba626-5019-4018-8cd7-7d09c61a8ceb', 'STAT-01-existing-db-v1', '2025-01-01', '2025-12-31', 25273, 180005, '1b7d7889d5ee4201092cea491665cadf9fc8538f6832b1012bf2abf63aa176a0', 'f17f17f308acaf2934bb20407ca6f3f769f06ef28c79b9e6192723098d67513c'],
    ];

    /** @return list<Bt02BaselineFingerprintEntryDto> */
    public function entries(): array
    {
        $entries = [];
        foreach (self::SOURCES as $year => $source) {
            $entries[] = new Bt02BaselineFingerprintEntryDto($year, ...$source);
        }

        return $entries;
    }

    public function forYear(int $year): Bt02BaselineFingerprintEntryDto
    {
        foreach ($this->entries() as $entry) {
            if ($entry->year === $year) {
                return $entry;
            }
        }

        throw new LogicException("No fixed BT-02 baseline fingerprint exists for {$year}.");
    }

    public function computedHash(): string
    {
        $serialized = self::VERSION."\n";
        foreach ($this->entries() as $entry) {
            $serialized .= implode(',', array_map(
                static fn (int|string $value): string => (string) $value,
                array_values($entry->canonical()),
            ))."\n";
        }

        return hash('sha256', $serialized);
    }
}
