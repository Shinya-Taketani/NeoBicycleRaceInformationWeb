<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\DTO\PlayerListPageDto;
use App\Domain\Keirin\Scraping\Exceptions\ParserException;
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
        private readonly ScrapingFetchService $fetches,
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
                $previousSha256 = null;
                $previousPlayerSet = null;
                $pagesFetched = 0;

                while (true) {
                    if (($options['limit_players'] ?? null) !== null && $success >= (int) $options['limit_players']) {
                        break 2;
                    }

                    $itemKey = "player-list:{$gradeCode}:".($options['prefecture_code'] ?? 'all').":{$pageNumber}";
                    $item = $this->batchRuns->startItem($run, 'PLAYER_LIST_PAGE', $itemKey);

                    try {
                        if ($pagesFetched >= (int) config('keirin.max_player_pages_per_grade', 100)) {
                            throw new ParserException('Player page limit was exceeded for grade '.$gradeCode.'.');
                        }

                        $response = null;
                        $stored = $this->fetches->fetch(function () use (&$response, $pageNumber, $gradeCode, $options) {
                            $response = $this->listFetcher->fetch(
                                page: $pageNumber,
                                gradeCode: $gradeCode,
                                prefectureCode: $options['prefecture_code'] ?? null,
                                sleepMs: $options['sleep_ms'] ?? null,
                            );

                            return $response;
                        }, (int) $run->id);
                        $page = $this->listParser->parse($stored->utf8Body, $response->url);
                        $this->validatePage($page, $pageNumber, $stored->sha256, $previousSha256, $previousPlayerSet);

                        $pageCounts = $this->persistPlayerPage($page, $response->fetchedAt, $run, $options, $success);
                        $success += $pageCounts['success'];
                        $skipped += $pageCounts['skipped'];
                        $failed += $pageCounts['failed'];
                        $lastPage = $page->lastPage;
                        $this->batchRuns->succeedItem($item, ['players' => count($page->players), 'last_page' => $lastPage]);

                        $pagesFetched++;
                        $previousSha256 = $stored->sha256;
                        $previousPlayerSet = $this->playerSetHash($page);
                        if ($pageNumber >= $lastPage) {
                            break;
                        }

                        $pageNumber++;
                    } catch (\Throwable $throwable) {
                        $failed++;
                        $error = $throwable->getMessage();
                        $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage());
                        break;
                    }
                }
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
        $response = null;
        $stored = $this->fetches->fetch(function () use (&$response, $externalPlayerId, $sleepMs) {
            $response = $this->detailFetcher->fetch($externalPlayerId, $sleepMs);

            return $response;
        }, (int) $run->id);
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
                        $this->batchRuns->skipUnsupportedCategoryItem($item, 'SKIPPED_UNSUPPORTED_CATEGORY');
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

    private function validatePage(PlayerListPageDto $page, int $requestedPage, string $sha256, ?string $previousSha256, ?string $previousPlayerSet): void
    {
        if ($page->currentPage !== $requestedPage) {
            throw new ParserException("Player page mismatch: requested {$requestedPage}, parsed {$page->currentPage}.");
        }

        if ($page->lastPage === null || $page->lastPage < 1 || $page->lastPage < $page->currentPage) {
            throw new ParserException('Player page lastPage was invalid.');
        }

        if ($page->lastPage > (int) config('keirin.max_player_pages_per_grade', 100)) {
            throw new ParserException('Player page lastPage exceeded the configured safety limit.');
        }

        if ($previousSha256 !== null && $previousSha256 === $sha256) {
            throw new ParserException('Player pagination returned the same raw HTML for consecutive pages.');
        }

        $playerSet = $this->playerSetHash($page);
        if ($previousPlayerSet !== null && $previousPlayerSet === $playerSet) {
            throw new ParserException('Player pagination returned the same player set for consecutive pages.');
        }
    }

    private function playerSetHash(PlayerListPageDto $page): string
    {
        $ids = array_map(fn ($player): string => $player->externalPlayerId, $page->players);
        sort($ids);

        return hash('sha256', implode('|', $ids));
    }
}
