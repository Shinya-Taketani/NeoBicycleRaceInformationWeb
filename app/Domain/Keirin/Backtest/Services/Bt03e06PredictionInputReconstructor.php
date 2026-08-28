<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Backtest\Services;

use App\Domain\Keirin\Backtest\Calculators\Bt02SignalEligibilityEvaluator;
use App\Domain\Keirin\Backtest\Calculators\Bt03e03ProbabilityScorer;
use App\Domain\Keirin\Backtest\Calculators\EffectBinBuilder;
use App\Domain\Keirin\Backtest\DTO\Bt03e06ReconstructedModelDto;
use App\Domain\Keirin\Backtest\Enums\Bt02SignalCohort;
use App\Domain\Keirin\Backtest\Repositories\BacktestFeatureRepository;
use App\Domain\Keirin\Backtest\Repositories\Bt02SignalFeatureRepository;
use App\Domain\Keirin\Backtest\Support\Bt03e03PredictionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\Bt03e05RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06RaceSpool;
use App\Domain\Keirin\Backtest\Support\Bt03e06ReconstructionManifestAccumulator;
use App\Domain\Keirin\Backtest\Support\CanonicalHasher;
use RuntimeException;
use Throwable;

final class Bt03e06PredictionInputReconstructor
{
    private const CHUNK_SIZE = 200;

    public function __construct(
        private readonly Bt01SourceManifest $baselineManifest,
        private readonly Bt02SourceManifest $signalManifest,
        private readonly BacktestFeatureRepository $baselineFeatures,
        private readonly Bt02SignalFeatureRepository $signalFeatures,
        private readonly Bt02SignalEligibilityEvaluator $signalEligibility,
        private readonly EffectBinBuilder $binBuilder,
        private readonly Bt03e03ProbabilityScorer $scorer,
        private readonly Bt03e06ForwardReconstructionVerifier $forwardVerifier,
        private readonly CanonicalHasher $hasher,
        private readonly Bt03e06ReadOnlyQueryAudit $audit,
        private readonly Bt03e06ReconstructionManifestAccumulator $reconstructionManifest,
    ) {}

    /** @return array{spool:Bt03e06RaceSpool,prediction_manifest:array<string,mixed>,reconstruction_manifest:array<string,mixed>} */
    public function reconstruct(
        int $year,
        Bt03e05RaceSpool $source,
        Bt03e06ReconstructedModelDto $model,
        array $sourcePredictionManifest,
        string $featureFingerprintDigest,
        string $temporaryDirectory = '/tmp',
    ): array {
        if ($model->year !== $year || ! in_array($year, Bt03e06Contract::DEVELOPMENT_YEARS, true)) {
            throw new RuntimeException('BT-03E-06 reconstruction year was invalid.');
        }
        $spool = new Bt03e06RaceSpool(
            'RECONSTRUCTED',
            rtrim($temporaryDirectory, '/').'/bt03e06-reconstructed-'.$year.'-'.bin2hex(random_bytes(8)).'.jsonl',
        );
        $manifest = new Bt03e03PredictionManifestAccumulator($this->hasher);
        try {
            $chunk = [];
            foreach ($source->races() as $sourceRace) {
                $chunk[] = $sourceRace;
                if (count($chunk) === self::CHUNK_SIZE) {
                    $this->reconstructChunk($year, $chunk, $model, $spool, $manifest);
                    $chunk = [];
                }
            }
            if ($chunk !== []) {
                $this->reconstructChunk($year, $chunk, $model, $spool, $manifest);
            }
            $spool->seal();
            $predictionManifest = $manifest->seal();
            $this->forwardVerifier->verifyManifest($sourcePredictionManifest, $predictionManifest);

            return [
                'spool' => $spool,
                'prediction_manifest' => $predictionManifest,
                'reconstruction_manifest' => $this->reconstructionManifest->seal(
                    $year,
                    $model->canonicalHash,
                    $featureFingerprintDigest,
                    $sourcePredictionManifest,
                    $predictionManifest,
                ),
            ];
        } catch (Throwable $throwable) {
            $spool->cleanup();
            throw $throwable;
        }
    }

    /**
     * @param  array<string,mixed>  $sourceRace
     * @param  list<object>  $baseline
     * @param  array<string,list<object>>  $signals
     * @return array<string,mixed>
     */
    public function reconstructRace(
        array $sourceRace,
        array $baseline,
        array $signals,
        Bt03e06ReconstructedModelDto $model,
    ): array {
        $sourceEntries = $sourceRace['entries'] ?? null;
        if (! is_array($sourceEntries) || count($sourceEntries) < 5 || count($sourceEntries) > 9
            || count($baseline) !== count($sourceEntries)) {
            throw new RuntimeException('BT-03E-06 source and STAT-01 entrant counts differed.');
        }
        $sourceBikes = array_map(static fn (array $entry): int => (int) ($entry['bike'] ?? 0), $sourceEntries);
        $baselineBikes = array_map(static fn (object $feature): int => $feature->bikeNumber, $baseline);
        if ($sourceBikes !== $baselineBikes) {
            throw new RuntimeException('BT-03E-06 source and STAT-01 entrant order differed.');
        }
        $seenEntries = [];
        foreach ($baseline as $feature) {
            if (isset($seenEntries[$feature->raceEntryId])
                || $feature->status !== 'VALID' || $feature->qualityStatus !== 'FULL'
                || ! $feature->raceScoreAvailable || $feature->raceScoreRaw === null
                || ! is_numeric($feature->raceScoreRaw) || (float) $feature->raceScoreRaw <= 0.0
                || $feature->raceScoreRank === null || $feature->inputAsOf === null) {
                throw new RuntimeException('BT-03E-06 fixed STAT-01 input was invalid.');
            }
            $seenEntries[$feature->raceEntryId] = true;
        }

        $signalMaps = [];
        foreach (Bt03e06Contract::STAT_CODES as $statCode) {
            foreach ($signals[$statCode] ?? [] as $signal) {
                if (isset($signalMaps[$statCode][$signal->raceEntryId])) {
                    throw new RuntimeException("BT-03E-06 {$statCode} duplicated a race entry.");
                }
                $signalMaps[$statCode][$signal->raceEntryId] = $signal;
            }
        }
        $raws = array_map(static fn (object $feature): float => (float) $feature->raceScoreRaw, $baseline);
        $mean = array_sum($raws) / count($raws);
        $variance = array_sum(array_map(static fn (float $value): float => ($value - $mean) ** 2, $raws)) / count($raws);
        $deviation = sqrt($variance);
        $entries = [];
        foreach ($baseline as $feature) {
            $values = [];
            foreach (Bt03e06Contract::STAT_CODES as $statCode) {
                $signal = $signalMaps[$statCode][$feature->raceEntryId] ?? null;
                $values[] = $signal !== null && $this->signalEligibility->eligible($statCode, Bt02SignalCohort::Operational, $signal)
                    ? $signal->primaryValue
                    : null;
            }
            $entries[] = [
                'id' => $feature->raceEntryId,
                'bike' => $feature->bikeNumber,
                'raw' => (float) $feature->raceScoreRaw,
                'stat01_rank' => $feature->raceScoreRank,
                'anchor' => $deviation > 0.0 ? ((float) $feature->raceScoreRaw - $mean) / $deviation : 0.0,
                'bins' => $model->layout->assign($values, $this->binBuilder),
                'rank' => null,
                'status' => 'PREDICTION_ONLY',
            ];
        }
        $prediction = $this->scorer->predict([
            'year' => $sourceRace['year'],
            'race_id' => $sourceRace['race_id'],
            'entries' => $entries,
        ], $model->fit);
        $this->forwardVerifier->verifyRace($sourceRace, $prediction);

        return $prediction;
    }

    /** @param list<array<string,mixed>> $sourceRaces */
    private function reconstructChunk(
        int $year,
        array $sourceRaces,
        Bt03e06ReconstructedModelDto $model,
        Bt03e06RaceSpool $spool,
        Bt03e03PredictionManifestAccumulator $manifest,
    ): void {
        $raceIds = array_map(static fn (array $race): int => (int) $race['race_id'], $sourceRaces);
        $this->audit->recordFeatureSourceYear($year);
        $baseline = $this->baselineFeatures->forRaces($this->baselineManifest->forYear($year)->featureRunId, $raceIds);
        $signals = [];
        foreach (Bt03e06Contract::STAT_CODES as $statCode) {
            $this->audit->recordFeatureSourceYear($year);
            $signals[$statCode] = $this->signalFeatures->forRaces(
                $this->signalManifest->for($year, $statCode)->featureRunId,
                $statCode,
                $raceIds,
            );
        }
        foreach ($sourceRaces as $sourceRace) {
            $raceId = (int) $sourceRace['race_id'];
            $raceSignals = [];
            foreach (Bt03e06Contract::STAT_CODES as $statCode) {
                $raceSignals[$statCode] = $signals[$statCode][$raceId] ?? [];
            }
            $prediction = $this->reconstructRace($sourceRace, $baseline[$raceId] ?? [], $raceSignals, $model);
            $manifest->append($prediction);
            $spool->append($prediction);
        }
    }
}
