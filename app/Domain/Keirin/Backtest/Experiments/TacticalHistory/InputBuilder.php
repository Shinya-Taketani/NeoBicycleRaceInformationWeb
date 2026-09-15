<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Experiments\TacticalHistory;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Services\Bt01SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt02SourceManifest;
use App\Domain\Keirin\Backtest\Services\Bt03e02Contract;
use App\Domain\Keirin\Backtest\Services\Bt03e02DatasetBuilder;
use Generator;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

final class InputBuilder
{
    public function __construct(
        private readonly Bt03e02DatasetBuilder $training,
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly HistoryAggregator $aggregator,
        private readonly HistoryReader $history,
    ) {}

    public function build(string $directory, Bt02OutcomeContextSnapshot $snapshot, string $sourceCsv): array
    {
        if (file_exists($directory) || ! mkdir($directory, 0755)) {
            throw new RuntimeException('Input directory must be new.');
        }
        $cache = new PDO('sqlite:'.$directory.'/history-cache.sqlite');
        $cache->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cache->exec('PRAGMA cache_size=-4096');
        $cache->exec('CREATE TABLE windows (cache_key TEXT PRIMARY KEY, audit TEXT NOT NULL)');
        $cache->exec('CREATE TABLE history_entries (id INTEGER PRIMARY KEY, player_id INTEGER NOT NULL, race_date TEXT NOT NULL)');
        $insertEntry = $cache->prepare('INSERT INTO history_entries (id,player_id,race_date) VALUES (?,?,?)');
        $cache->beginTransaction();
        $entryHash = hash_init('sha256');
        $entryCount = 0;
        // Only identity metadata is indexed here; outcome rows remain window-scoped reads.
        foreach (DB::table('race_entries as e')->join('races as r', 'r.id', '=', 'e.race_id')
            ->whereBetween('r.race_date', ['2022-01-01', '2025-12-31'])->whereNotNull('e.player_id')
            ->select(['e.id', 'e.player_id', 'r.race_date'])->lazyById(1000, 'e.id', 'id') as $row) {
            $identity = [(int) $row->id, (int) $row->player_id, $row->race_date];
            $insertEntry->execute($identity);
            hash_update($entryHash, json_encode($identity, JSON_THROW_ON_ERROR)."\n");
            $entryCount++;
        }
        $cache->exec('CREATE INDEX history_entries_player_date ON history_entries (player_id,race_date)');
        $cache->commit();
        JsonlArtifact::json($directory.'/history-entry-index.json', ['rows' => $entryCount, 'sha256' => hash_final($entryHash), 'contains_outcomes' => false]);
        $this->history->useEntryIndex($cache);
        echo json_encode(['phase' => 'LOCAL_ENTRY_INDEX_READY', 'rows' => $entryCount])."\n";
        $get = $cache->prepare('SELECT audit FROM windows WHERE cache_key=?');
        $put = $cache->prepare('INSERT INTO windows (cache_key,audit) VALUES (?,?)');
        $manifests = $coverage = [];
        foreach ([2022, 2023, 2024, 2025] as $year) {
            $original = null;
            if ($year <= 2023) {
                $original = $this->training->buildRaw($year, $snapshot, $directory);
                $raw = $original->races();
            } else {
                $raw = $this->predictionRows($year, $sourceCsv);
            }
            $auditPath = $directory.'/history-'.$year.'.jsonl';
            $auditHandle = fopen($auditPath.'.partial', 'xb');
            $auditCount = 0;
            try {
                $rows = $this->enrich($raw, $year, $get, $put, $auditHandle, $auditCount, $coverage);
                $manifests[$year]['inputs'] = JsonlArtifact::write($directory.'/inputs-'.$year.'.jsonl', $rows);
                if (! fflush($auditHandle) || ! fsync($auditHandle)) {
                    throw new RuntimeException('History evidence flush failed.');
                }
            } finally {
                fclose($auditHandle);
            }
            $manifests[$year]['history'] = ['rows' => $auditCount, 'bytes' => filesize($auditPath.'.partial'), 'sha256' => hash_file('sha256', $auditPath.'.partial')];
            rename($auditPath.'.partial', $auditPath);
            JsonlArtifact::json($auditPath.'.manifest.json', $manifests[$year]['history']);
            unset($original);
            echo json_encode(['phase' => 'INPUT_YEAR_SEALED', 'year' => $year, 'coverage' => $coverage[$year], 'peak_bytes' => memory_get_peak_usage(true)])."\n";
        }
        $cache = null;
        $report = ['manifests' => $manifests, 'coverage' => $coverage, 'history_cache_sha256' => hash_file('sha256', $directory.'/history-cache.sqlite')];
        JsonlArtifact::json($directory.'/manifest.json', $report);

        return $report;
    }

    private function enrich(iterable $races, int $year, \PDOStatement $get, \PDOStatement $put, $auditHandle, int &$auditCount, array &$coverage): Generator
    {
        $chunk = [];
        foreach ($races as $race) {
            $chunk[] = $race;
            if (count($chunk) === 100) {
                yield from $this->enrichChunk($chunk, $year, $get, $put, $auditHandle, $auditCount, $coverage);
                $chunk = [];
            }
        }
        if ($chunk !== []) {
            yield from $this->enrichChunk($chunk, $year, $get, $put, $auditHandle, $auditCount, $coverage);
        }
    }

    private function enrichChunk(array $races, int $year, \PDOStatement $get, \PDOStatement $put, $auditHandle, int &$auditCount, array &$coverage): Generator
    {
        $ids = array_column($races, 'race_id');
        $baseline = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $ids);
        $targets = DB::table('races as r')->leftJoin('race_days as d', 'd.id', '=', 'r.race_day_id')
            ->leftJoin('race_meetings as m', 'm.id', '=', 'd.race_meeting_id')->join('race_entries as e', 'e.race_id', '=', 'r.id')
            ->whereIn('r.id', $ids)->whereBetween('r.race_date', ["{$year}-01-01", "{$year}-12-31"])
            ->select(['r.id as race_id', 'r.race_date', 'm.id as meeting_id', 'm.starts_on as meeting_start', 'm.ends_on as meeting_end',
                'e.id as entry_id', 'e.player_id', 'e.bike_number as bike'])->get()->keyBy('entry_id');
        foreach ($races as $race) {
            $features = [];
            foreach ($baseline[$race['race_id']] ?? [] as $feature) {
                $features[$feature->raceEntryId] = $feature;
            }
            foreach ($race['entries'] as &$entry) {
                $row = $targets->get($entry['id']);
                $feature = $features[$entry['id']] ?? null;
                $target = $row === null ? ['race_id' => $race['race_id']] : (array) $row;
                foreach (['race_id', 'meeting_id', 'entry_id', 'player_id', 'bike'] as $key) {
                    $target[$key] = isset($target[$key]) ? (int) $target[$key] : null;
                }
                if ($feature === null || ($row !== null && ($target['race_id'] !== $race['race_id'] || $target['bike'] !== $entry['bike']
                    || ($feature->playerId !== null && $feature->playerId !== $target['player_id'])))) {
                    throw new RuntimeException('Target feature/entry identity disagreed for entry '.$entry['id']);
                }
                $target['input_as_of'] = $feature->inputAsOf?->format(DATE_ATOM);
                $target['feature_input_hash'] = $feature->inputHash;
                // Validate every target's time before reusing its player/meeting window.
                $empty = $this->aggregator->aggregate($target, []);
                $cacheKey = null;
                if ($empty['status'] === 'NO_HISTORY') {
                    $cacheKey = $target['player_id'].':'.$target['meeting_id'].':'.$target['meeting_start'];
                    $get->execute([$cacheKey]);
                    $encoded = $get->fetchColumn();
                    $get->closeCursor();
                    if ($encoded === false) {
                        $references = [];
                        $source = (function () use ($target, &$references): Generator {
                            foreach ($this->history->rows($target['player_id'], $target['meeting_start'], $target['meeting_id']) as $history) {
                                $references[] = $history;
                                yield $history;
                            }
                        })();
                        $aggregate = $this->aggregator->aggregate($target, $source);
                        $encoded = json_encode(['aggregate' => $aggregate, 'source_rows' => $references], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
                        $put->execute([$cacheKey, $encoded]);
                    }
                    $cached = json_decode($encoded, true, flags: JSON_THROW_ON_ERROR);
                    $aggregate = $cached['aggregate'];
                    $sourceHash = hash('sha256', $encoded);
                } else {
                    $aggregate = $empty;
                    $sourceHash = null;
                }
                $coverage[$year][$aggregate['status']] = ($coverage[$year][$aggregate['status']] ?? 0) + 1;
                $entry['history'] = $aggregate['values'];
                $entry['history_status'] = $aggregate['status'];
                $audit = ['target' => $target, 'cache_key' => $cacheKey, 'source_sha256' => $sourceHash, 'aggregate' => $aggregate];
                $line = json_encode($audit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                if (fwrite($auditHandle, $line) !== strlen($line)) {
                    throw new RuntimeException('History evidence write failed.');
                }
                $auditCount++;
            }
            unset($entry);
            yield $race;
        }
        echo json_encode(['phase' => 'INPUT_CHUNK', 'year' => $year, 'last_race_id' => end($races)['race_id'], 'entries' => $auditCount, 'peak_bytes' => memory_get_peak_usage(true)])."\n";
    }

    private function predictionRows(int $year, string $sourceCsv): Generator
    {
        $handle = fopen($sourceCsv, 'rb');
        $header = fgetcsv($handle, escape: '');
        $ids = [];
        $identities = new PDO('sqlite::memory:');
        $identities->exec('CREATE TABLE seen (id INTEGER PRIMARY KEY)');
        $insert = $identities->prepare('INSERT INTO seen (id) VALUES (?)');
        try {
            while (($cells = fgetcsv($handle, escape: '')) !== false) {
                $row = array_combine($header, $cells);
                if ((int) $row['year'] !== $year) {
                    continue;
                }
                $id = (int) $row['race_id'];
                if ($id < 1) {
                    throw new RuntimeException('E06 candidate identity was invalid.');
                }
                $insert->execute([$id]);
                $ids[] = $id;
                if (count($ids) === 100) {
                    yield from $this->predictionChunk($year, $ids);
                    $ids = [];
                }
            }
            if ($ids !== []) {
                yield from $this->predictionChunk($year, $ids);
            }
        } finally {
            fclose($handle);
        }
    }

    private function predictionChunk(int $year, array $raceIds): Generator
    {
        $baselines = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $raceIds);
        $signals = [];
        foreach (Bt03e02Contract::STAT_CODES as $code) {
            $signals[$code] = $this->signalFeatures->forRaces($this->signalManifest->for($year, $code)->featureRunId, $code, $raceIds);
        }
        foreach ($raceIds as $raceId) {
            $baseline = $baselines[$raceId] ?? [];
            $bikes = array_map(static fn ($f): int => $f->bikeNumber, $baseline);
            if (count($baseline) < 5 || count($baseline) > 9 || count(array_unique($bikes)) !== count($bikes) || min($bikes) < 1 || max($bikes) > 9) {
                throw new RuntimeException('Prediction entrants were incomplete for race '.$raceId);
            }
            $maps = [];
            foreach (Bt03e02Contract::STAT_CODES as $code) {
                foreach ($signals[$code][$raceId] ?? [] as $signal) {
                    if (isset($maps[$code][$signal->raceEntryId])) {
                        throw new RuntimeException('Duplicate signal identity.');
                    }
                    $maps[$code][$signal->raceEntryId] = $signal;
                }
            }
            $raws = array_map(static fn ($f): float => (float) $f->raceScoreRaw, $baseline);
            $mean = array_sum($raws) / count($raws);
            $sd = sqrt(array_sum(array_map(static fn (float $v): float => ($v - $mean) ** 2, $raws)) / count($raws));
            $entries = [];
            foreach ($baseline as $feature) {
                $values = [];
                foreach (Bt03e02Contract::STAT_CODES as $code) {
                    $signal = $maps[$code][$feature->raceEntryId] ?? null;
                    $values[] = $signal !== null && $this->signalEligibility->eligible($code, Bt02SignalCohort::Operational, $signal) ? $signal->primaryValue : null;
                }
                $entries[] = ['id' => $feature->raceEntryId, 'bike' => $feature->bikeNumber, 'raw' => (float) $feature->raceScoreRaw,
                    'stat01_rank' => $feature->raceScoreRank, 'anchor' => $sd > 0.0 ? ((float) $feature->raceScoreRaw - $mean) / $sd : 0.0,
                    'anchor_status' => $sd > 0.0 ? 'AVAILABLE' : 'ZERO_VARIANCE', 'signals' => $values];
            }
            yield ['year' => $year, 'race_id' => $raceId, 'entries' => $entries];
        }
    }
}
