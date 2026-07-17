<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Enums\FetchErrorType;
use App\Domain\Keirin\Scraping\Exceptions\KeirinHttpException;
use App\Domain\Keirin\Scraping\Fetchers\PlayerDetailFetcher;
use App\Domain\Keirin\Scraping\Fetchers\PlayerListFetcher;
use App\Domain\Keirin\Scraping\Parsers\PlayerDetailParser;
use App\Domain\Keirin\Scraping\Parsers\PlayerListParser;
use App\Models\BatchRun;
use App\Repositories\PlayerRepository;
use DateTimeImmutable;
use Illuminate\Support\Facades\File;

class PlayerSyncService
{
    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly PlayerListFetcher $listFetcher,
        private readonly PlayerDetailFetcher $detailFetcher,
        private readonly RawResponseStorageService $rawStorage,
        private readonly PlayerListParser $listParser,
        private readonly PlayerDetailParser $detailParser,
        private readonly PlayerRepository $players,
    ) {}

    /**
     * @return array{batch_run:BatchRun,success:int,skipped:int,failed:int}
     */
    public function sync(array $options): array
    {
        $gradePart = $options['grade_code'] ?? 'all';
        $prefecturePart = $options['prefecture_code'] ?? 'all';
        $lockKey = "keirin:players:sync:{$gradePart}:{$prefecturePart}";
        $run = $this->batchRuns->start('players_sync', $options, $lockKey);
        $success = 0;
        $skipped = 0;
        $failed = 0;
        $error = null;

        try {
            if (($options['raw_file'] ?? null) !== null) {
                $sourceUrl = (string) ($options['source_url'] ?? '');
                $sourceUrl = $sourceUrl !== '' ? $sourceUrl : 'file://'.$options['raw_file'];
                $item = $this->batchRuns->startItem($run, 'PLAYER_LIST_PAGE', 'raw-file:'.$options['raw_file']);
                try {
                    $page = $this->listParser->parse(File::get((string) $options['raw_file']), $sourceUrl);
                    $pageCounts = $this->persistPlayerPage($page, new DateTimeImmutable('now'), $run, $options, $success);
                    $success += $pageCounts['success'];
                    $skipped += $pageCounts['skipped'];
                    $failed += $pageCounts['failed'];
                    $this->batchRuns->succeedItem($item, ['players' => count($page->players)]);
                } catch (\Throwable $throwable) {
                    $failed++;
                    $error = $throwable->getMessage();
                    $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                }

                return ['batch_run' => $this->batchRuns->finish($run, $success, $skipped, $failed, $error), 'success' => $success, 'skipped' => $skipped, 'failed' => $failed];
            }

            $grades = ($options['grade_code'] ?? null) !== null
                ? [(string) $options['grade_code']]
                : config('keirin.male_grade_codes', []);

            foreach ($grades as $gradeCode) {
                $pageNumber = (int) ($options['page'] ?? 1);
                $lastPage = null;

                do {
                    if (($options['limit_players'] ?? null) !== null && $success >= (int) $options['limit_players']) {
                        break 2;
                    }

                    $itemKey = "player-list:{$gradeCode}:".($options['prefecture_code'] ?? 'all').":{$pageNumber}";
                    $item = $this->batchRuns->startItem($run, 'PLAYER_LIST_PAGE', $itemKey);

                    try {
                        $response = $this->listFetcher->fetch(
                            page: $pageNumber,
                            gradeCode: $gradeCode,
                            prefectureCode: $options['prefecture_code'] ?? null,
                            sleepMs: $options['sleep_ms'] ?? null,
                        );
                        $stored = $this->rawStorage->store($response, (int) $run->id, $this->httpErrorType($response->httpStatus), $this->httpErrorMessage($response->httpStatus));
                        $this->throwIfHttpError($response->httpStatus, $response->url);
                        $page = $this->listParser->parse($stored->utf8Body, $response->url);
                        $pageCounts = $this->persistPlayerPage($page, $response->fetchedAt, $run, $options, $success);
                        $success += $pageCounts['success'];
                        $skipped += $pageCounts['skipped'];
                        $failed += $pageCounts['failed'];
                        $lastPage = $page->lastPage ?? $page->currentPage;
                        $this->batchRuns->succeedItem($item, ['players' => count($page->players), 'last_page' => $lastPage]);
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $error = $throwable->getMessage();
                        $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                    }

                    $pageNumber++;
                } while ($lastPage === null || $pageNumber <= $lastPage);
            }
        } catch (\Throwable $throwable) {
            $failed++;
            $error = $throwable->getMessage();
        } finally {
            $this->batchRuns->releaseLock($lockKey);
        }

        $run = $this->batchRuns->finish($run, $success, $skipped, $failed, $error);

        return ['batch_run' => $run, 'success' => $success, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function syncDetail(string $externalPlayerId, BatchRun $run, ?int $sleepMs): void
    {
        $response = $this->detailFetcher->fetch($externalPlayerId, $sleepMs);
        $stored = $this->rawStorage->store($response, (int) $run->id);
        $detail = $this->detailParser->parse($stored->utf8Body, $response->url);

        if ($detail->gender === 'female') {
            return;
        }

        $this->players->upsertDetail($detail, $response->fetchedAt);
    }

    /**
     * @return array{success:int,skipped:int,failed:int}
     */
    private function persistPlayerPage($page, DateTimeImmutable $fetchedAt, BatchRun $run, array $options, int $alreadySucceeded): array
    {
        $success = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($page->players as $summary) {
            if (($options['limit_players'] ?? null) !== null && ($alreadySucceeded + $success) >= (int) $options['limit_players']) {
                break;
            }

            $this->players->upsertSummary($summary, $page->sourceUpdatedAt, $fetchedAt);
            $success++;

            if (($options['with_detail'] ?? false) === true) {
                $item = $this->batchRuns->startItem($run, 'PLAYER_DETAIL', 'player-detail:'.$summary->externalPlayerId);
                try {
                    if ($summary->gender !== 'male') {
                        $this->batchRuns->skipItem($item, 'SKIPPED_UNSUPPORTED_CATEGORY');
                        $skipped++;

                        continue;
                    }

                    $this->syncDetail($summary->externalPlayerId, $run, $options['sleep_ms'] ?? null);
                    $this->batchRuns->succeedItem($item);
                } catch (\Throwable $throwable) {
                    $failed++;
                    $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                }
            }
        }

        return ['success' => $success, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function throwIfHttpError(?int $status, string $url): void
    {
        if ($status === null || $status < 400) {
            return;
        }

        throw new KeirinHttpException($this->httpErrorType($status) ?? FetchErrorType::HttpError, $url, "KEIRIN.JP returned HTTP {$status}.", $status);
    }

    private function httpErrorType(?int $status): ?FetchErrorType
    {
        return match (true) {
            $status === 429 => FetchErrorType::TooManyRequests,
            $status !== null && $status >= 500 => FetchErrorType::ServerError,
            $status !== null && $status >= 400 => FetchErrorType::HttpError,
            default => null,
        };
    }

    private function httpErrorMessage(?int $status): ?string
    {
        return $status !== null && $status >= 400 ? "KEIRIN.JP returned HTTP {$status}." : null;
    }
}
