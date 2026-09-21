<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Audit\Stat35DataReadiness\Contract;
use App\Domain\Keirin\Audit\Stat35DataReadiness\RawReader;
use App\Domain\Keirin\Scraping\Enums\ParsedRaceResultPageStatus;
use App\Domain\Keirin\Scraping\Enums\RaceCategory;
use App\Domain\Keirin\Scraping\Parsers\EmbeddedJsonExtractor;
use App\Domain\Keirin\Scraping\Parsers\RaceLiveResultParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultPageParser;
use App\Domain\Keirin\Scraping\Support\HtmlTextNormalizer;
use App\Domain\Keirin\Scraping\Support\RaceCategoryPolicy;
use App\Models\Race;
use App\Models\RaceEntry;
use App\Models\RaceResult;
use App\Models\RaceResultImport;
use App\Models\Racetrack;
use App\Models\ScrapingFetchLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\DomCrawler\Crawler;
use Throwable;

class AgariBackfillService
{
    public const VERSION = 'STAT35-AGARI-BACKFILL-v1';

    public function __construct(
        private readonly RawReader $raw,
        private readonly RaceLiveResultParser $live,
        private readonly RaceResultPageParser $manual,
        private readonly EmbeddedJsonExtractor $json,
        private readonly RaceEntrantExpectationResolver $expectations,
        private readonly RaceResultCompletenessValidator $completeness,
        private readonly AgariObservationService $observations,
        private readonly BatchRunService $batches,
        private readonly RaceCategoryPolicy $categories,
    ) {}

    public function plan(string $from, string $to, int $chunk): array
    {
        Contract::date($from);
        Contract::date($to);
        if ($from > $to || $chunk < 1 || $chunk > 1000) {
            throw new RuntimeException('Invalid date range or chunk (1..1000).');
        }

        return ['version' => self::VERSION, 'from' => $from, 'to' => $to, 'chunk' => $chunk,
            'origin' => 'BACKFILLED_FINAL_RESULT', 'publication_timestamp' => 'UNKNOWN',
            'historical_as_of_available' => false, 'network_access' => false];
    }

    public function run(string $from, string $to, int $chunk, bool $dryRun, ?callable $report = null): array
    {
        $plan = $this->plan($from, $to, $chunk);
        $totals = ['success' => 0, 'skipped' => 0, 'failed' => 0, 'observations' => 0, 'current_updates' => 0,
            'NO_IMPORT' => 0, 'NO_IMPORT_UNSUPPORTED' => 0];
        $lockKey = 'stat35-agari-backfill';
        $run = $dryRun ? null : $this->batches->start('STAT35_AGARI_BACKFILL', $plan, $lockKey);
        try {
            $races = Race::query()->where('source', config('keirin.source'))->whereBetween('race_date', [$from, $to]);
            $missingImports = (clone $races)->whereNotExists(function ($query): void {
                $query->selectRaw('1')->from('race_result_imports')->whereColumn('race_result_imports.race_id', 'races.id');
            })->select(['id', 'race_type']);
            foreach ($missingImports->lazyById($chunk) as $race) {
                $key = $this->categories->classify($race->race_type) === RaceCategory::Men ? 'NO_IMPORT' : 'NO_IMPORT_UNSUPPORTED';
                $totals[$key]++;
            }
            $imports = RaceResultImport::query()->whereIn('race_id', (clone $races)->select('races.id'));
            foreach ($imports->lazyById($chunk) as $import) {
                $item = $run === null ? null : $this->batches->startItem($run, 'AGARI_IMPORT', 'import:'.$import->id);
                try {
                    $outcome = DB::transaction(function () use ($import, $from, $to, $dryRun, $item): array {
                        $outcome = $this->process((int) $import->id, $from, $to, $dryRun);
                        if ($item !== null) {
                            if ($outcome['reason'] === null) {
                                $this->batches->succeedItem($item, $outcome);
                            } else {
                                $this->batches->skipItem($item, $outcome['reason'], $outcome);
                            }
                        }

                        return $outcome;
                    });
                    $totals[$outcome['reason'] === null ? 'success' : 'skipped']++;
                    $totals['observations'] += $outcome['observations'];
                    $totals['current_updates'] += $outcome['current_updates'];
                    $event = ['import_id' => $import->id, ...$outcome];
                } catch (Throwable $error) {
                    $totals['failed']++;
                    if ($item !== null) {
                        $this->batches->failItem($item, $error::class, $error->getMessage(), ['import_id' => $import->id]);
                    }
                    $event = ['import_id' => $import->id, 'error' => $error->getMessage()];
                }
                if ($report !== null) {
                    $report($event);
                }
            }
            if ($run !== null) {
                $this->batches->finish($run, $totals['success'], $totals['skipped'], $totals['failed']);
            }
        } catch (Throwable $error) {
            if ($run !== null) {
                $this->batches->finish($run, $totals['success'], $totals['skipped'], $totals['failed'] + 1, $error->getMessage());
            }
            throw $error;
        } finally {
            $this->batches->releaseLock($lockKey);
        }

        return [...$totals, 'dry_run' => $dryRun, 'batch_run_id' => $run?->id, 'peak_memory_bytes' => memory_get_peak_usage(true)];
    }

    private function process(int $id, string $from, string $to, bool $dryRun): array
    {
        $import = RaceResultImport::query()->findOrFail($id);
        $raceQuery = Race::query()->whereKey($import->race_id)->where('source', config('keirin.source'))->whereBetween('race_date', [$from, $to]);
        $race = ($dryRun ? $raceQuery : $raceQuery->lockForUpdate())->firstOrFail();
        if (! $dryRun) {
            $import = RaceResultImport::query()->lockForUpdate()->findOrFail($id);
        }
        if ((int) $import->race_id !== (int) $race->id) {
            throw new RuntimeException('IMPORT_RACE_DRIFT');
        }
        if ($this->categories->classify($race->race_type) !== RaceCategory::Men) {
            return ['reason' => 'UNSUPPORTED_RACE_CATEGORY', 'observations' => 0, 'current_updates' => 0];
        }
        $source = $this->source($import, $race);
        $html = $this->raw->read($source);
        $crawler = new Crawler;
        $crawler->addHtmlContent($html, 'UTF-8');
        $hasLive = $crawler->filter('script')->reduce(fn (Crawler $script): bool => str_contains($script->text('', false), 'PJ0326'))->count() > 0;
        $cancelledRows = 0;
        $unknownStatus = false;
        if ($hasLive) {
            $parsed = $this->live->parse($html);
            $track = Racetrack::query()->findOrFail($race->racetrack_id);
            if ($parsed->raceDate !== $race->race_date->format('Ymd') || $parsed->trackCode !== $track->external_track_id
                || $parsed->raceNumber !== (int) $race->race_number) {
                throw new RuntimeException('RAW_RACE_IDENTITY_MISMATCH');
            }
            $page = $parsed->resultPage;
            $unknownStatus = $parsed->detectedStatus === null;
            if ($page->pageStatus === ParsedRaceResultPageStatus::Cancelled) {
                $cancelledRows = $this->cancelledRows($html);
            }
        } else {
            $page = $this->manual->parse($html);
            if ($page->pageStatus === ParsedRaceResultPageStatus::Cancelled) {
                $this->assertManualCancelledRowsEmpty($crawler);
            }
        }
        $outcome = ['reason' => null, 'observations' => 0, 'current_updates' => 0,
            'raw_file_path' => $import->raw_file_path, 'source_hash' => $import->source_hash,
            'cancelled_blank_rows' => $cancelledRows];
        if ($page->pageStatus !== ParsedRaceResultPageStatus::ResultsAvailable) {
            $this->raw->verify($source);

            return [...$outcome, 'reason' => $unknownStatus ? 'RESULT_STATUS_UNDETERMINED' : $page->pageStatus->value];
        }
        // Historical import counts, not a later corrected entry list, describe this version.
        $count = $import->import_status === 'SUCCEEDED' && (int) $import->result_count > 0
            ? (int) $import->result_count : $race->entrant_count;
        $this->completeness->validate($page, $this->expectations->resolveFromValues($count, []));
        $entries = RaceEntry::query()->where('race_id', $race->id)->get()->keyBy('bike_number');
        $current = RaceResult::query()->where('race_id', $race->id)->where('race_result_import_id', $import->id)->get()->keyBy('bike_number');
        foreach ($current as $bike => $row) {
            if (! in_array((int) $bike, array_map(fn ($result): int => $result->bikeNumber, $page->results), true)) {
                throw new RuntimeException('CURRENT_RESULT_BIKE_MISMATCH');
            }
        }
        foreach ($page->results as $result) {
            $entry = $entries->get($result->bikeNumber);
            if ($entry !== null && ($result->externalPlayerId === null || $entry->external_player_id !== $result->externalPlayerId)) {
                $entry = null;
            }
            $observation = $this->observations->record($import, $result, $entry, backfilled: true, dryRun: $dryRun);
            $outcome['observations'] += (int) $observation['created'];
            $row = $current->get($result->bikeNumber);
            if ($row === null) {
                continue;
            }
            if ($row->result_status !== $result->status->value || $row->rank !== $result->rank) {
                throw new RuntimeException('CURRENT_RESULT_STATUS_MISMATCH');
            }
            $values = $observation['values'];
            $stored = array_intersect_key($row->getAttributes(), $values);
            if (array_filter($stored, fn ($value): bool => $value !== null) === []) {
                if (! $dryRun) {
                    DB::table('race_results')->where('id', $row->id)->where('race_result_import_id', $id)->update($values);
                }
                $outcome['current_updates']++;
            } elseif ($stored !== $values) {
                // Compare by keys; DB column order is not semantic.
                foreach ($values as $key => $value) {
                    if ($row->getAttribute($key) !== $value) {
                        throw new RuntimeException('CURRENT_AGARI_CONFLICT');
                    }
                }
            }
        }
        $this->raw->verify($source);

        return $outcome;
    }

    private function source(RaceResultImport $import, Race $race): array
    {
        $path = $import->raw_file_path;
        if (! is_string($path) || $path === '' || str_starts_with($path, '/') || str_contains($path, '\\')
            || str_contains($path, "\0") || in_array('..', explode('/', $path), true)) {
            throw new RuntimeException('RAW_PATH_INVALID');
        }
        $log = $import->scraping_fetch_log_id === null ? null : ScrapingFetchLog::query()->findOrFail($import->scraping_fetch_log_id);
        if ($log !== null && $log->raw_file_path !== $path) {
            throw new RuntimeException('RAW_PATH_CONFLICT');
        }
        if (! $import->utf8_conversion_succeeded || ! is_string($import->converted_hash)) {
            throw new RuntimeException('CONVERSION_PROVENANCE_MISSING');
        }

        return ['race_date' => $race->race_date->format('Y-m-d'), 'absolute_path' => Storage::disk(config('keirin.raw_disk'))->path($path),
            'source_hash' => $import->source_hash, 'raw_response_size' => (int) $import->raw_response_size,
            'converted_hash' => $import->converted_hash, 'content_type' => $log?->content_type,
            'fetch_hash' => $log?->sha256, 'fetch_bytes' => $log === null ? null : (int) $log->response_size];
    }

    private function assertManualCancelledRowsEmpty(Crawler $crawler): void
    {
        foreach ($crawler->filter('#pitbodyBs tr') as $node) {
            $row = new Crawler($node);
            if ($row->ancestors()->filter('thead')->count() === 0 && $row->filter('td')->count() > 0
                && preg_match('/[^\s\p{Z}]/u', $row->text('', false)) === 1) {
                throw new RuntimeException('MANUAL_CANCELLED_RESULT_ROWS_PRESENT');
            }
        }
    }

    private function cancelledRows(string $html): int
    {
        $data = $this->json->extract($html, 'PJ0326');
        $rows = $data['tyakujyunItemSubData'] ?? [];
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('CANCELLED_ROWS_INVALID');
        }
        $seen = [];
        foreach ($rows as $row) {
            $bike = is_array($row) ? ($row['syaban'] ?? null) : null;
            $value = is_array($row) ? ($row['agari'] ?? null) : null;
            if ((! is_int($bike) && ! is_string($bike)) || preg_match('/^[1-9]$/D', (string) $bike) !== 1
                || isset($seen[$bike]) || ($value !== null && ! is_string($value)) || HtmlTextNormalizer::normalize($value) !== null) {
                throw new RuntimeException('CANCELLED_ROW_NOT_BLANK_OR_INVALID');
            }
            $seen[$bike] = true;
        }

        return count($rows);
    }
}
