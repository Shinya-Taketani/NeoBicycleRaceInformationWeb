<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Services;

use App\Domain\Keirin\Scraping\Parsers\PayoutParser;
use App\Domain\Keirin\Scraping\Parsers\RaceResultParser;
use App\Models\Race;
use App\Models\RacePayout;
use App\Models\RaceResult;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class RaceResultImportService
{
    public function __construct(
        private readonly RaceResultParser $results,
        private readonly PayoutParser $payouts,
    ) {}

    /**
     * @return array{results:int,payouts:int,race:Race}
     */
    public function importRawFile(?int $raceId, ?string $externalRaceId, string $rawFile, string $sourceUrl, string $resultStatus): array
    {
        $html = File::get($rawFile);
        $results = $this->results->parse($html);
        $payouts = $this->payouts->parse($html);
        $fetchedAt = new DateTimeImmutable('now');

        return DB::transaction(function () use ($raceId, $externalRaceId, $sourceUrl, $resultStatus, $results, $payouts, $fetchedAt): array {
            $race = Race::query()
                ->when($raceId !== null, fn (Builder $query): Builder => $query->whereKey($raceId))
                ->when($raceId === null && $externalRaceId !== null, fn (Builder $query): Builder => $query->where('external_race_id', $externalRaceId))
                ->lockForUpdate()
                ->first();

            if (! $race instanceof Race) {
                throw new \RuntimeException('Target race was not found.');
            }

            $seenResultBikeNumbers = [];
            foreach ($results as $result) {
                if ($result->bikeNumber === null) {
                    continue;
                }

                $seenResultBikeNumbers[] = $result->bikeNumber;
                RaceResult::query()->updateOrCreate(
                    [
                        'race_id' => $race->id,
                        'bike_number' => $result->bikeNumber,
                    ],
                    [
                        'rank' => $result->rank,
                        'result_status' => $result->status->value,
                        'winning_technique' => $result->winningTechnique,
                        'raw_result_text' => $result->rawText,
                        'source_url' => $sourceUrl,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            RaceResult::query()
                ->where('race_id', $race->id)
                ->when($seenResultBikeNumbers !== [], fn (Builder $query): Builder => $query->whereNotIn('bike_number', $seenResultBikeNumbers))
                ->delete();

            $seenPayoutKeys = [];
            foreach ($payouts as $payout) {
                $key = $payout->betTypeCode.'|'.$payout->combination.'|'.$payout->sequence;
                $seenPayoutKeys[] = $key;
                RacePayout::query()->updateOrCreate(
                    [
                        'race_id' => $race->id,
                        'bet_type_code' => $payout->betTypeCode,
                        'combination' => $payout->combination,
                        'sequence' => $payout->sequence,
                    ],
                    [
                        'payout_amount' => $payout->payoutAmount,
                        'popularity' => $payout->popularity,
                        'source_url' => $sourceUrl,
                        'fetched_at' => $fetchedAt,
                    ],
                );
            }

            RacePayout::query()
                ->where('race_id', $race->id)
                ->get()
                ->each(function (RacePayout $payout) use ($seenPayoutKeys): void {
                    $key = $payout->bet_type_code.'|'.$payout->combination.'|'.$payout->sequence;
                    if (! in_array($key, $seenPayoutKeys, true)) {
                        $payout->delete();
                    }
                });

            $race->forceFill([
                'result_status' => $resultStatus,
                'result_url' => $sourceUrl,
                'result_confirmed_at' => in_array($resultStatus, ['CONFIRMED', 'CORRECTED'], true) ? $fetchedAt : $race->result_confirmed_at,
                'last_fetched_at' => $fetchedAt,
            ])->save();

            return ['results' => count($results), 'payouts' => count($payouts), 'race' => $race];
        });
    }
}
