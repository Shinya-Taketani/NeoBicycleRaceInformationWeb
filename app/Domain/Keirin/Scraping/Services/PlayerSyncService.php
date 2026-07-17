<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

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
        $run = $this->batchRuns->start('players_sync', $options, 'keirin:players:sync');
        $success = 0;
        $skipped = 0;
        $failed = 0;
        $error = null;

        try {
            $sourceUrl = (string) ($options['source_url'] ?? '');
            $html = null;
            $fetchedAt = new DateTimeImmutable('now');

            if (($options['raw_file'] ?? null) !== null) {
                $sourceUrl = $sourceUrl !== '' ? $sourceUrl : 'file://'.$options['raw_file'];
                $html = File::get((string) $options['raw_file']);
            } else {
                $response = $this->listFetcher->fetch(
                    page: (int) ($options['page'] ?? 1),
                    gradeCode: $options['grade_code'] ?? null,
                    prefectureCode: $options['prefecture_code'] ?? null,
                    sleepMs: $options['sleep_ms'] ?? null,
                );
                $stored = $this->rawStorage->store($response, (int) $run->id);
                $html = $stored->utf8Body;
                $sourceUrl = $response->url;
                $fetchedAt = $response->fetchedAt;
            }

            $page = $this->listParser->parse($html, $sourceUrl);

            foreach ($page->players as $summary) {
                $player = $this->players->upsertSummary($summary, $page->sourceUpdatedAt, $fetchedAt);
                $success++;

                if (($options['with_detail'] ?? false) === true) {
                    if ($summary->gender !== 'male') {
                        $skipped++;

                        continue;
                    }

                    $this->syncDetail($summary->externalPlayerId, $run, $options['sleep_ms'] ?? null);
                }

                if (($options['limit_players'] ?? null) !== null && $success >= (int) $options['limit_players']) {
                    break;
                }
            }
        } catch (\Throwable $throwable) {
            $failed++;
            $error = $throwable->getMessage();
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
}
