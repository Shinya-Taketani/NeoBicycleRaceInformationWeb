<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextSourceRowDto;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeSnapshotPartitionDto;
use App\Domain\Keirin\Backtest\Repositories\Bt02OutcomeContextSnapshotSourceRepository;
use App\Domain\Keirin\Backtest\Support\Bt02OutcomeContextSnapshotArtifact;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class Bt02OutcomeContextSnapshotBuilder
{
    /** @var list<int> */
    private const YEARS = [2022, 2023, 2024, 2025];

    public function __construct(
        private readonly Bt01SourceManifest $targetManifest,
        private readonly Bt02OutcomeContextSnapshotSourceRepository $source,
        private readonly ?string $baseDirectory = null,
        private readonly string $auditPathPrefix = 'private/backtest/bt02/outcome-context',
    ) {}

    public function build(): Bt02OutcomeContextSnapshot
    {
        $base = $this->baseDirectory ?? storage_path('app/private/backtest/bt02/outcome-context');
        $this->createDirectory($base);
        $building = $base.'/.building-'.bin2hex(random_bytes(12));
        $this->createDirectory($building);

        try {
            $partitions = DB::connection()->transaction(function () use ($building): array {
                if (DB::getDriverName() === 'pgsql') {
                    DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                return $this->capture($building);
            }, 1);
            $payload = Bt02OutcomeContextSnapshotArtifact::manifestPayload($partitions);
            $manifestHash = hash('sha256', Bt02OutcomeContextSnapshotArtifact::encode($payload));
            $this->writeFile(
                $building.'/manifest.json',
                Bt02OutcomeContextSnapshotArtifact::encode([...$payload, 'manifest_sha256' => $manifestHash]),
            );

            $final = $base.'/'.$manifestHash;
            $auditPath = trim($this->auditPathPrefix, '/').'/'.$manifestHash;
            $candidate = Bt02OutcomeContextSnapshotArtifact::open($building, $auditPath);
            $this->assertTargetCounts($candidate);
            $candidate->verify();
            if (is_dir($final)) {
                $existing = Bt02OutcomeContextSnapshotArtifact::open($final, $auditPath);
                if (! hash_equals($manifestHash, $existing->manifestHash())) {
                    throw new RuntimeException('Existing BT-02 outcome snapshot artifact had another identity.');
                }
                $this->assertTargetCounts($existing);
                $existing->verify();
                $this->removeDirectory($building);

                return $existing;
            }
            if (! rename($building, $final)) {
                throw new RuntimeException('Could not publish the BT-02 outcome snapshot artifact.');
            }

            return Bt02OutcomeContextSnapshotArtifact::open($final, $auditPath);
        } catch (Throwable $throwable) {
            $this->removeDirectory($building);
            throw $throwable;
        }
    }

    /** @return list<Bt02OutcomeSnapshotPartitionDto> */
    private function capture(string $directory): array
    {
        $states = [];
        try {
            foreach (self::YEARS as $year) {
                $path = $directory."/{$year}.jsonl";
                $handle = fopen($path, 'wb');
                if ($handle === false) {
                    throw new RuntimeException('Could not create a BT-02 outcome snapshot partition.');
                }
                $states[$year] = [
                    'path' => $path,
                    'handle' => $handle,
                    'hash' => hash_init('sha256'),
                    'races' => 0,
                    'results' => 0,
                    'bytes' => 0,
                ];
            }

            $current = null;
            $lastDate = null;
            $lastRaceId = null;
            foreach ($this->source->rows() as $sourceRow) {
                if (! $sourceRow instanceof Bt02OutcomeContextSourceRowDto) {
                    throw new RuntimeException('BT-02 outcome snapshot source row was invalid.');
                }
                $date = $this->date($sourceRow->raceDate);
                $year = (int) substr($date, 0, 4);
                if (! in_array($year, self::YEARS, true)) {
                    throw new RuntimeException('BT-02 outcome snapshot source contained a non-fixed year.');
                }
                if ($current === null || $current['race_id'] !== $sourceRow->raceId) {
                    if ($current !== null) {
                        $this->appendRace($states[(int) substr($current['race_date'], 0, 4)], $current);
                    }
                    if ($lastDate !== null && ($date < $lastDate || ($date === $lastDate && $sourceRow->raceId <= $lastRaceId))) {
                        throw new RuntimeException('BT-02 outcome snapshot source race order or identity was invalid.');
                    }
                    $current = $this->race($sourceRow, $date);
                    $lastDate = $date;
                    $lastRaceId = $sourceRow->raceId;
                } else {
                    $this->assertSameRace($current, $sourceRow, $date);
                }
                $this->appendResult($current, $sourceRow);
            }
            if ($current !== null) {
                $this->appendRace($states[(int) substr($current['race_date'], 0, 4)], $current);
            }

            $partitions = [];
            foreach (self::YEARS as $year) {
                $state = &$states[$year];
                if (! fflush($state['handle']) || (function_exists('fsync') && ! fsync($state['handle']))) {
                    throw new RuntimeException('Could not flush a BT-02 outcome snapshot partition.');
                }
                fclose($state['handle']);
                $state['handle'] = null;
                $expected = $this->targetManifest->forYear($year)->expectedRaceCount;
                if ($state['races'] !== $expected) {
                    throw new RuntimeException("BT-02 outcome snapshot race count differed for {$year}: expected {$expected}, got {$state['races']}.");
                }
                $partitions[] = new Bt02OutcomeSnapshotPartitionDto(
                    $year,
                    "{$year}.jsonl",
                    $state['races'],
                    $state['results'],
                    $state['bytes'],
                    hash_final($state['hash']),
                );
                $state['hash'] = null;
                unset($state);
            }

            return $partitions;
        } finally {
            foreach ($states as $state) {
                if (is_resource($state['handle'])) {
                    fclose($state['handle']);
                }
            }
        }
    }

    /** @return array{format_version: string, race_id: int, race_date: string, scheduled_start_at: ?string, sales_close_at: ?string, entrant_count: int, race_status: string, race_type: string, results: list<array{bike_number: int, rank: ?int, result_status: string}>} */
    private function race(Bt02OutcomeContextSourceRowDto $row, string $date): array
    {
        if ($row->raceId < 1 || $row->entrantCount < 5 || $row->entrantCount > 9
            || $row->raceStatus === '' || preg_match('/\A[ＡＳ]級/u', $row->raceType) !== 1) {
            throw new RuntimeException('BT-02 outcome snapshot source race was invalid.');
        }

        return [
            'format_version' => Bt02OutcomeContextSnapshotArtifact::FORMAT_VERSION,
            'race_id' => $row->raceId,
            'race_date' => $date,
            'scheduled_start_at' => $this->timestamp($row->scheduledStartAt),
            'sales_close_at' => $this->timestamp($row->salesCloseAt),
            'entrant_count' => $row->entrantCount,
            'race_status' => $row->raceStatus,
            'race_type' => $row->raceType,
            'results' => [],
        ];
    }

    /** @param array<string, mixed> $race */
    private function assertSameRace(array $race, Bt02OutcomeContextSourceRowDto $row, string $date): void
    {
        if ($race['race_date'] !== $date
            || $race['scheduled_start_at'] !== $this->timestamp($row->scheduledStartAt)
            || $race['sales_close_at'] !== $this->timestamp($row->salesCloseAt)
            || $race['entrant_count'] !== $row->entrantCount
            || $race['race_status'] !== $row->raceStatus
            || $race['race_type'] !== $row->raceType) {
            throw new RuntimeException('BT-02 outcome snapshot source repeated inconsistent race context.');
        }
    }

    /** @param array<string, mixed> $race */
    private function appendResult(array &$race, Bt02OutcomeContextSourceRowDto $row): void
    {
        if ($row->bikeNumber === null && $row->rank === null && $row->resultStatus === null) {
            return;
        }
        if ($row->bikeNumber === null || $row->bikeNumber < 1 || $row->bikeNumber > 9
            || ($row->rank !== null && $row->rank < 1)
            || $row->resultStatus === null || $row->resultStatus === '') {
            throw new RuntimeException('BT-02 outcome snapshot source result was invalid.');
        }
        $last = $race['results'] === [] ? 0 : $race['results'][array_key_last($race['results'])]['bike_number'];
        if ($row->bikeNumber <= $last) {
            throw new RuntimeException('BT-02 outcome snapshot source result order or bike identity was invalid.');
        }
        $race['results'][] = [
            'bike_number' => $row->bikeNumber,
            'rank' => $row->rank,
            'result_status' => $row->resultStatus,
        ];
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $race */
    private function appendRace(array &$state, array $race): void
    {
        $line = Bt02OutcomeContextSnapshotArtifact::encode($race);
        $offset = 0;
        while ($offset < strlen($line)) {
            $written = fwrite($state['handle'], substr($line, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('Could not write a BT-02 outcome snapshot partition.');
            }
            $offset += $written;
        }
        hash_update($state['hash'], $line);
        $state['races']++;
        $state['results'] += count($race['results']);
        $state['bytes'] += strlen($line);
    }

    private function date(string $value): string
    {
        $date = new DateTimeImmutable($value);
        $canonical = $date->format('Y-m-d');
        if ($canonical < Bt02OutcomeContextSnapshotArtifact::TARGET_FROM
            || $canonical > Bt02OutcomeContextSnapshotArtifact::TARGET_TO) {
            throw new RuntimeException('BT-02 outcome snapshot date was outside the fixed target.');
        }

        return $canonical;
    }

    private function timestamp(?string $value): ?string
    {
        return $value !== null ? (new DateTimeImmutable($value))->format('Y-m-d\TH:i:s.uP') : null;
    }

    private function assertTargetCounts(Bt02OutcomeContextSnapshotArtifact $artifact): void
    {
        $partitions = $artifact->auditParameters()['outcome_snapshot_partitions'];
        foreach ($partitions as $partition) {
            $expected = $this->targetManifest->forYear($partition['year'])->expectedRaceCount;
            if ($partition['race_count'] !== $expected) {
                throw new RuntimeException("BT-02 persisted outcome snapshot race count differed for {$partition['year']}.");
            }
        }
    }

    private function createDirectory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException('Could not create the BT-02 outcome snapshot directory.');
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Could not create the BT-02 outcome snapshot manifest.');
        }
        try {
            $offset = 0;
            while ($offset < strlen($contents)) {
                $written = fwrite($handle, substr($contents, $offset));
                if ($written === false || $written === 0) {
                    throw new RuntimeException('Could not write the BT-02 outcome snapshot manifest.');
                }
                $offset += $written;
            }
            if (! fflush($handle) || (function_exists('fsync') && ! fsync($handle))) {
                throw new RuntimeException('Could not flush the BT-02 outcome snapshot manifest.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $child = $path.'/'.$entry;
                is_dir($child) ? $this->removeDirectory($child) : @unlink($child);
            }
        }
        @rmdir($path);
    }
}
