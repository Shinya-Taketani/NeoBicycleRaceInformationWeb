<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\FeatureEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Contracts\Bt02OutcomeContextSnapshot;
use App\Domain\Keirin\Backtest\DTO\Bt02OutcomeContextRaceDto;
use App\Domain\Keirin\Backtest\DTO\FoldDefinitionDto;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use DateTimeImmutable;
use RuntimeException;
use Throwable;

final class Bt03e06OutcomeEvaluationLoader
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $manifest,
        private readonly BacktestFeatureRepository $features,
        private readonly FeatureEligibilityEvaluator $eligibility,
        private readonly Bt03e06ReadOnlyQueryAudit $audit,
    ) {}

    public function build(int $year, Bt02OutcomeContextSnapshot $snapshot, string $temporaryDirectory = '/tmp'): Bt03e06RaceSpool
    {
        if (! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException('BT-03E-06 outcome year was outside 2024/2025.');
        }
        $spool = new Bt03e06RaceSpool(
            'CONTEXT',
            rtrim($temporaryDirectory, '/').'/bt03e06-context-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl',
        );
        $fold = new FoldDefinitionDto(
            "BT03E06_{$year}",
            0,
            null,
            null,
            new DateTimeImmutable("{$year}-01-01"),
            new DateTimeImmutable("{$year}-12-31"),
        );
        try {
            $this->audit->recordSnapshotYear($year);
            foreach ($snapshot->chunks($fold, self::CHUNK_SIZE) as $chunk) {
                $raceIds = array_map(static fn (Bt02OutcomeContextRaceDto $race): int => $race->context->raceId, $chunk);
                $this->audit->recordFeatureSourceYear($year);
                $baseline = $this->features->forRaces($this->manifest->forYear($year)->featureRunId, $raceIds);
                foreach ($chunk as $snapshotRace) {
                    $race = $this->race($year, $snapshotRace, $baseline);
                    if ($race !== null) {
                        $spool->append($race);
                    }
                }
            }
            $spool->seal();

            return $spool;
        } catch (Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /** @param array<int,list<object>> $baseline @return array<string,mixed>|null */
    private function race(int $year, Bt02OutcomeContextRaceDto $snapshotRace, array $baseline): ?array
    {
        $context = $snapshotRace->context;
        if (! in_array($context->resultStatus, ['CONFIRMED', 'CORRECTED'], true)) {
            return null;
        }
        $features = $baseline[$context->raceId] ?? [];
        if (! $this->eligibility->evaluate($context, $features)->eligible) {
            return null;
        }
        $results = [];
        foreach ($snapshotRace->results as $result) {
            if (isset($results[$result->bikeNumber])) {
                throw new RuntimeException('BT-03E-06 outcome snapshot duplicated a bike number.');
            }
            $results[$result->bikeNumber] = $result;
        }
        $featureBikes = array_map(static fn (object $feature): int => $feature->bikeNumber, $features);
        $resultBikes = array_map('intval', array_keys($results));
        sort($featureBikes, SORT_NUMERIC);
        sort($resultBikes, SORT_NUMERIC);
        if (count($results) !== $context->entrantCount || $featureBikes !== $resultBikes) {
            throw new RuntimeException('BT-03E-06 outcome entrants did not match STAT-01 entrants.');
        }
        $entries = [];
        foreach ($features as $feature) {
            $result = $results[$feature->bikeNumber];
            $entries[] = [
                'id' => $feature->raceEntryId,
                'bike' => $feature->bikeNumber,
                'raw' => (float) $feature->raceScoreRaw,
                'stat01_rank' => $feature->raceScoreRank,
                'rank' => $result->rank,
                'status' => $result->resultStatus,
            ];
        }
        usort($entries, static fn (array $left, array $right): int => $left['bike'] <=> $right['bike']);

        return ['year' => $year, 'race_id' => $context->raceId, 'entries' => $entries];
    }
}
