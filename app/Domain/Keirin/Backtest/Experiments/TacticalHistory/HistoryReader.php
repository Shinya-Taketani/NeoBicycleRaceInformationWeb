<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class HistoryReader
{
    private ?\PDO $entryIndex = null;

    public function __construct(private readonly RaceCategoryPolicy $categories) {}

    public function useEntryIndex(\PDO $index): void
    {
        $this->entryIndex = $index;
    }

    /** @return \Generator<int,array<string,mixed>> */
    public function rows(int $playerId, string $meetingStart, int $meetingId): \Generator
    {
        $cutoff = new DateTimeImmutable($meetingStart, new DateTimeZone('Asia/Tokyo'));
        if ($playerId < 1 || $meetingId < 1 || $cutoff->format('Y-m-d') !== $meetingStart || $meetingStart > '2025-12-31' || $meetingStart < '2022-01-01') {
            throw new RuntimeException('Invalid bounded history request.');
        }
        $start = max('2022-01-01', $cutoff->modify('-120 days')->format('Y-m-d'));
        $entryIds = null;
        if ($this->entryIndex !== null) {
            $lookup = $this->entryIndex->prepare('SELECT id FROM history_entries WHERE player_id=? AND race_date>=? AND race_date<? ORDER BY id');
            $lookup->execute([$playerId, $start, $meetingStart]);
            $entryIds = $lookup->fetchAll(\PDO::FETCH_COLUMN);
            if ($entryIds === []) {
                return;
            }
        }
        $query = DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
            ->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
            ->leftJoin('race_results as rr', function ($join): void {
                $join->on('rr.race_id', '=', 'e.race_id')->on('rr.bike_number', '=', 'e.bike_number');
            })
            ->leftJoin('race_result_imports as i', 'i.id', '=', 'rr.race_result_import_id')
            ->where('e.player_id', $playerId)->where('r.race_date', '>=', $start)
            ->when($entryIds !== null, fn ($q) => $q->whereIn('e.id', $entryIds))
            ->where('r.race_date', '<', $meetingStart)->where('r.race_date', '<=', '2025-12-31')
            ->where(fn ($q) => $q->whereNull('d.race_meeting_id')->orWhere('d.race_meeting_id', '<>', $meetingId))
            ->orderBy('e.id')->select([
                'r.id as race_id', 'r.race_date', 'r.scheduled_start_at', 'r.race_type', 'r.result_status as race_status',
                'd.race_meeting_id as meeting_id', 'e.id as entry_id', 'e.player_id', 'e.bike_number as bike', 'e.external_player_id',
                'rr.id as result_id', 'rr.player_id as result_player_id', 'rr.race_entry_id as result_entry_id',
                'rr.rank', 'rr.result_status', 'rr.winning_technique', 'rr.raw_result_text', 'rr.fetched_at',
                'rr.race_result_import_id as import_id', 'i.race_id as import_race_id', 'i.source_hash', 'i.import_status',
            ]);
        foreach ($query->lazy(100) as $object) {
            $row = (array) $object;
            foreach (['race_id', 'meeting_id', 'entry_id', 'player_id', 'bike', 'result_id', 'result_player_id', 'result_entry_id', 'rank', 'import_id', 'import_race_id'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
            $row['male_category'] = $this->categories->classify($row['race_type']) === RaceCategory::Men;
            if (! $row['male_category']) {
                continue;
            }
            if ($row['import_id'] !== null && ($row['import_race_id'] !== $row['race_id'] || $row['import_status'] !== 'SUCCEEDED'
                || preg_match('/\A[0-9a-f]{64}\z/', (string) $row['source_hash']) !== 1)) {
                throw new RuntimeException('History import identity was invalid for race '.$row['race_id']);
            }
            $rawText = $row['raw_result_text'];
            $row['raw_result_sha256'] = $rawText === null ? null : hash('sha256', $rawText);
            if (is_string($rawText) && str_starts_with(ltrim($rawText), '{')) {
                $raw = json_decode($rawText, true, flags: JSON_THROW_ON_ERROR);
                if (isset($raw['syaban']) && (! ctype_digit((string) $raw['syaban']) || (int) $raw['syaban'] !== $row['bike'])) {
                    throw new RuntimeException('History raw bike identity disagreed for race '.$row['race_id']);
                }
                if (isset($raw['sensyuRegistNo']) && (string) $raw['sensyuRegistNo'] !== (string) $row['external_player_id']) {
                    throw new RuntimeException('History raw registration identity disagreed for race '.$row['race_id']);
                }
                $structured = HtmlTextNormalizer::normalize($row['winning_technique']);
                $rawMethod = HtmlTextNormalizer::normalize($raw['kimarite'] ?? null);
                if ($structured !== null && array_key_exists('kimarite', $raw) && $rawMethod !== $structured) {
                    throw new RuntimeException('History raw and structured technique disagreed for race '.$row['race_id']);
                }
                if ($structured === null && $rawMethod !== null) {
                    if (! isset($raw['syaban'], $raw['sensyuRegistNo'])) {
                        throw new RuntimeException('History raw fallback lacked identity fields.');
                    }
                    $row['winning_technique'] = $rawMethod;
                }
            }
            unset($row['raw_result_text']);
            $row['correction_effective_at'] = null;
            $row['publication_time_status'] = 'UNKNOWN';
            $row['formal_provenance_verified'] = $row['import_id'] !== null;
            yield $row;
        }
    }
}
