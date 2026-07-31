<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Exceptions\RetiredPlayerProfileNotRetiredException;
use App\Domain\Keirin\Scraping\Fetchers\PlayerDetailFetcher;
use App\Domain\Keirin\Scraping\Parsers\RetiredPlayerDetailParser;
use App\Models\BatchRun;
use App\Models\Player;
use App\Models\RaceEntry;
use App\Repositories\PlayerRepository;
use Illuminate\Support\Facades\DB;
use Throwable;

class RetiredPlayerBackfillService
{
    public function __construct(
        private readonly BatchRunService $batchRuns,
        private readonly PlayerDetailFetcher $detailFetcher,
        private readonly ScrapingFetchService $fetches,
        private readonly RetiredPlayerDetailParser $parser,
        private readonly PlayerRepository $players,
    ) {}

    /**
     * @return array{
     *   batch_run:BatchRun,
     *   success:int,
     *   skipped:int,
     *   failed:int,
     *   players:int,
     *   linked_entries:int,
     *   details:list<array<string,mixed>>
     * }
     */
    public function backfill(array $options): array
    {
        $lockKey = 'keirin:players:backfill-retired';
        $run = $this->batchRuns->start('retired_players_backfill', $options, $lockKey);
        $success = $skipped = $failed = $playerCount = $linkedEntries = 0;
        $lastError = null;
        $outerException = null;
        $details = [];

        try {
            foreach ($this->candidates($options) as $candidate) {
                $externalPlayerId = $candidate['external_player_id'];
                $item = $this->batchRuns->startItem(
                    $run,
                    'RETIRED_PLAYER_DETAIL',
                    "player-detail:{$externalPlayerId}",
                    ['external_player_id' => $externalPlayerId, 'dry_run' => (bool) $options['dry_run']],
                );

                if ($candidate['total_entries'] === 0) {
                    $skipped++;
                    $metadata = [
                        'external_player_id' => $externalPlayerId,
                        'dry_run' => (bool) $options['dry_run'],
                    ];
                    $this->batchRuns->skipItem($item, 'NO_UNRESOLVED_ENTRIES', $metadata);
                    $details[] = [...$metadata, 'status' => 'skipped', 'skip_reason' => 'NO_UNRESOLVED_ENTRIES'];

                    continue;
                }
                if ($candidate['unresolved_count'] === 0) {
                    $skipped++;
                    $player = Player::query()
                        ->where('source', (string) config('keirin.source'))
                        ->where('external_player_id', $externalPlayerId)
                        ->first();
                    $metadata = [
                        'external_player_id' => $externalPlayerId,
                        'player_id' => $player?->id,
                        'linked_entries' => 0,
                        'dry_run' => (bool) $options['dry_run'],
                    ];
                    $this->batchRuns->skipItem($item, 'PLAYER_ALREADY_LINKED', $metadata);
                    $details[] = [...$metadata, 'status' => 'skipped', 'skip_reason' => 'PLAYER_ALREADY_LINKED'];

                    continue;
                }

                $response = null;
                $stored = null;
                try {
                    $stored = $this->fetches->fetch(function () use (&$response, $externalPlayerId, $options) {
                        $response = $this->detailFetcher->fetch($externalPlayerId, $options['sleep_ms']);

                        return $response;
                    }, (int) $run->id);
                    $dto = $this->parser->parse($stored->utf8Body, $response->url, $externalPlayerId);
                    $plan = $this->players->retiredDetailLinkPlan($dto);

                    if ($options['dry_run']) {
                        $result = [
                            'player' => $plan['player'],
                            'linked_entries' => $plan['linkable_entries'],
                            'created' => $plan['would_create'],
                        ];
                    } else {
                        $result = $this->players->upsertRetiredDetailAndLinkEntries($dto, $response->fetchedAt);
                        $playerCount++;
                    }

                    $success++;
                    if (! $options['dry_run']) {
                        $linkedEntries += $result['linked_entries'];
                    }
                    $metadata = [
                        'external_player_id' => $externalPlayerId,
                        'player_id' => $result['player']?->id,
                        'name' => $dto->name,
                        'retired_on' => $dto->retiredOn->format('Y-m-d'),
                        'created' => $result['created'],
                        'linked_entries' => $result['linked_entries'],
                        'profile_url' => $dto->sourceUrl,
                        'raw_file_path' => $stored->rawFilePath,
                        'dry_run' => (bool) $options['dry_run'],
                    ];
                    $this->batchRuns->succeedItem($item, $metadata);
                    $details[] = [
                        ...$metadata,
                        'status' => 'retired',
                        'registration_number' => $dto->registrationNumber,
                        'prefecture' => $dto->prefecture,
                        'age' => $dto->age,
                        'graduation_period' => $dto->graduationPeriod,
                        'grade' => $dto->grade,
                        'source_updated_at' => $dto->sourceUpdatedAt?->format('Y-m-d H:i'),
                        'would_create_player' => (bool) $options['dry_run'] && $plan['would_create'],
                        'would_update_player' => (bool) $options['dry_run'] && $plan['would_update'],
                        'would_link_entries' => (bool) $options['dry_run'] ? $plan['linkable_entries'] : null,
                    ];
                } catch (RetiredPlayerProfileNotRetiredException $exception) {
                    $skipped++;
                    $metadata = [
                        'external_player_id' => $externalPlayerId,
                        'profile_url' => $response?->url,
                        'raw_file_path' => $stored?->rawFilePath,
                        'dry_run' => (bool) $options['dry_run'],
                    ];
                    $this->batchRuns->skipItem($item, 'PROFILE_NOT_RETIRED', $metadata);
                    $details[] = [...$metadata, 'status' => 'skipped', 'skip_reason' => 'PROFILE_NOT_RETIRED'];
                } catch (Throwable $throwable) {
                    $failed++;
                    $lastError = $throwable->getMessage();
                    $metadata = [
                        'external_player_id' => $externalPlayerId,
                        'profile_url' => $response?->url,
                        'raw_file_path' => $stored?->rawFilePath,
                        'dry_run' => (bool) $options['dry_run'],
                    ];
                    $this->batchRuns->failItem($item, $throwable::class, $throwable->getMessage(), $metadata);
                    $details[] = [...$metadata, 'status' => 'failed', 'error' => $throwable->getMessage()];
                }
            }
        } catch (Throwable $throwable) {
            $failed++;
            $lastError = $throwable->getMessage();
            $outerException = $throwable;
        } finally {
            try {
                $run = $this->batchRuns->finish($run, $success, $skipped, $failed, $lastError);
            } finally {
                $this->batchRuns->releaseLock($lockKey);
            }
        }

        if ($outerException instanceof Throwable) {
            throw $outerException;
        }

        return [
            'batch_run' => $run,
            'success' => $success,
            'skipped' => $skipped,
            'failed' => $failed,
            'players' => $playerCount,
            'linked_entries' => $linkedEntries,
            'details' => $details,
        ];
    }

    /**
     * @return list<array{external_player_id:string,unresolved_count:int,total_entries:int}>
     */
    private function candidates(array $options): array
    {
        if ($options['external_player_id'] !== null) {
            $externalPlayerId = $options['external_player_id'];

            return [[
                'external_player_id' => $externalPlayerId,
                'unresolved_count' => RaceEntry::query()
                    ->whereNull('player_id')
                    ->where('external_player_id', $externalPlayerId)
                    ->count(),
                'total_entries' => RaceEntry::query()
                    ->where('external_player_id', $externalPlayerId)
                    ->count(),
            ]];
        }

        $rows = DB::table('race_entries')
            ->join('races', 'races.id', '=', 'race_entries.race_id')
            ->whereNull('race_entries.player_id')
            ->whereNotNull('race_entries.external_player_id')
            ->whereDate('races.race_date', '>=', $options['from'])
            ->whereDate('races.race_date', '<=', $options['to'])
            ->groupBy('race_entries.external_player_id')
            ->select('race_entries.external_player_id')
            ->selectRaw('COUNT(*) AS unresolved_count')
            ->orderByDesc('unresolved_count')
            ->orderBy('race_entries.external_player_id')
            ->get()
            ->filter(fn ($row): bool => preg_match('/^\d{6}$/', (string) $row->external_player_id) === 1)
            ->take($options['limit'] ?? PHP_INT_MAX);

        return $rows->map(fn ($row): array => [
            'external_player_id' => (string) $row->external_player_id,
            'unresolved_count' => (int) $row->unresolved_count,
            'total_entries' => (int) $row->unresolved_count,
        ])->values()->all();
    }
}
