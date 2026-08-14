<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextSourceRowDto;
use App\Domain\Keirin\Backtest\DTO\SourceManifestEntryDto;
use App\Domain\Keirin\Backtest\Repositories\Bt02OutcomeContextSnapshotSourceRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02OutcomeContextSnapshotBuilder;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class Bt02OutcomeSnapshotPostgresTest extends TestCase
{
    public function test_snapshot_capture_uses_a_short_repeatable_read_read_only_transaction(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL transaction contract test.');
        }
        $source = new class extends Bt02OutcomeContextSnapshotSourceRepository
        {
            /** @var list<array{isolation: string, read_only: string, feature_run_id: int}> */
            public array $settings = [];

            public function rows(SourceManifestEntryDto $source): iterable
            {
                $isolation = DB::selectOne('SHOW transaction_isolation');
                $readOnly = DB::selectOne('SHOW transaction_read_only');
                $this->settings[] = [
                    'isolation' => (string) $isolation->transaction_isolation,
                    'read_only' => (string) $readOnly->transaction_read_only,
                    'feature_run_id' => $source->featureRunId,
                ];
                foreach (range(1, 5) as $bike) {
                    yield new Bt02OutcomeContextSourceRowDto(
                        $source->year - 2021,
                        "{$source->year}-06-01",
                        null,
                        null,
                        5,
                        'CONFIRMED',
                        'Ａ級予選',
                        $bike,
                        $bike,
                        'FINISHED',
                    );
                }
            }
        };
        $directory = sys_get_temp_dir().'/bt02-pg-outcome-snapshot-'.bin2hex(random_bytes(8));
        $manifest = new Bt01SourceManifest(new CanonicalHasher, array_map(
            fn (int $year): SourceManifestEntryDto => new SourceManifestEntryDto(
                $year,
                $year,
                sprintf('00000000-0000-4000-8000-%012d', $year),
                "{$year}-01-01",
                "{$year}-12-31",
                1,
                5,
            ),
            [2022, 2023, 2024, 2025],
        ));

        try {
            (new Bt02OutcomeContextSnapshotBuilder($manifest, $source, $directory, 'test/bt02/outcome-context'))->build();

            $this->assertSame([
                ['isolation' => 'repeatable read', 'read_only' => 'on', 'feature_run_id' => 2022],
                ['isolation' => 'repeatable read', 'read_only' => 'on', 'feature_run_id' => 2023],
                ['isolation' => 'repeatable read', 'read_only' => 'on', 'feature_run_id' => 2024],
                ['isolation' => 'repeatable read', 'read_only' => 'on', 'feature_run_id' => 2025],
            ], $source->settings);
            $this->assertSame(0, DB::connection()->transactionLevel());
        } finally {
            $this->remove($directory);
        }
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $child = $path.'/'.$entry;
                is_dir($child) ? $this->remove($child) : @unlink($child);
            }
        }
        @rmdir($path);
    }
}
