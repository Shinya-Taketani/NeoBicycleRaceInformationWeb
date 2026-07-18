<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\Support;

use App\Domain\Keirin\Scraping\DTO\RaceResultStatusDecisionDto;
use App\Domain\Keirin\Scraping\Enums\RaceResultStatus;

class RaceResultStatusPolicy
{
    public function decide(array $context, array $result): RaceResultStatusDecisionDto
    {
        if ($this->boolean($context['flgRaceCancel'] ?? false)) {
            return new RaceResultStatusDecisionDto(RaceResultStatus::Cancelled, 'PC0201.flgRaceCancel=true');
        }
        if ($this->boolean($context['flgSectionCancel'] ?? false)) {
            return new RaceResultStatusDecisionDto(RaceResultStatus::Cancelled, 'PC0201.flgSectionCancel=true');
        }

        $statusText = implode(' ', [
            ...$this->statusStrings($context),
            ...$this->statusStrings($result),
        ]);
        foreach ([
            '訂正' => RaceResultStatus::Corrected,
            '審議' => RaceResultStatus::UnderReview,
            '暫定' => RaceResultStatus::Provisional,
            '確定' => RaceResultStatus::Confirmed,
            '中止' => RaceResultStatus::Cancelled,
        ] as $marker => $status) {
            if (str_contains($statusText, $marker)) {
                return new RaceResultStatusDecisionDto($status, "explicit status marker: {$marker}");
            }
        }

        $selectedRace = $this->selectedRace($context);
        $resultDisplayed = $this->boolean($result['tyakujyunDispFlg'] ?? false);
        $payoutDisplayed = $this->boolean($result['haraiGakuDispFlg'] ?? false);
        $resultCode = $result['resultCd'] ?? null;

        if ($selectedRace !== null
            && $this->falseBoolean($context['flgRaceCancel'] ?? null)
            && $this->falseBoolean($context['flgSectionCancel'] ?? null)
            && $this->boolean($selectedRace['flgRaceEnd'] ?? false)
            && (string) ($selectedRace['rcvKekka'] ?? '') === '1'
            && (string) ($selectedRace['rcvRefund'] ?? '') === '1'
            && $resultDisplayed
            && $payoutDisplayed
            && ($resultCode === 0 || $resultCode === '0')) {
            return new RaceResultStatusDecisionDto(
                RaceResultStatus::Confirmed,
                'PC0201 race ended and result/refund received; PJ0326 result/payout displayed',
            );
        }

        if ($selectedRace !== null
            && $this->falseBoolean($context['flgRaceCancel'] ?? null)
            && $this->falseBoolean($context['flgSectionCancel'] ?? null)
            && ! $this->boolean($selectedRace['flgRaceEnd'] ?? false)
            && (string) ($selectedRace['rcvKekka'] ?? '') !== '1'
            && ! $resultDisplayed) {
            return new RaceResultStatusDecisionDto(RaceResultStatus::Unavailable, 'PC0201 race not ended and no result received');
        }

        return new RaceResultStatusDecisionDto(null, 'No explicit result status evidence was found');
    }

    private function selectedRace(array $context): ?array
    {
        $raceNumber = filter_var($context['selRaceNo'] ?? null, FILTER_VALIDATE_INT);
        $races = $context['C0201race'] ?? null;
        if (! is_int($raceNumber) || ! is_array($races)) {
            return null;
        }

        foreach ($races as $race) {
            if (is_array($race) && (int) ($race['raceNo'] ?? 0) === $raceNumber) {
                return $race;
            }
        }

        return isset($races[$raceNumber - 1]) && is_array($races[$raceNumber - 1])
            ? $races[$raceNumber - 1]
            : null;
    }

    /** @return list<string> */
    private function statusStrings(array $value): array
    {
        $strings = [];
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                array_push($strings, ...$this->statusStrings($child));

                continue;
            }

            if (is_string($key)
                && is_string($child)
                && preg_match('/(?:status|state|message|condition)/i', $key) === 1) {
                $strings[] = $child;
            }
        }

        return $strings;
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }

    private function falseBoolean(mixed $value): bool
    {
        return $value === false || $value === 0 || $value === '0';
    }
}
