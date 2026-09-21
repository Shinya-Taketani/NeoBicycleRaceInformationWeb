<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Audit\Stat35DataReadiness;

final class History
{
    public function candidate(array $history, array $target): string
    {
        Contract::date($history['race_date']);
        Contract::date($target['race_date']);
        if ($history['race_id'] === $target['race_id']) {
            return 'TARGET_SELF_EXCLUDED';
        }
        if ($history['player_id'] === null || $history['player_id'] !== $target['player_id']) {
            return 'PLAYER_UNRESOLVED';
        }
        $start = $this->time($target['scheduled_start_at']);
        $observed = $this->time($history['fetched_at']);
        $event = $this->time($history['scheduled_start_at']);
        if ($start === null || $observed === null || $event === null) {
            return 'UNKNOWN_TIMING';
        }
        if ($observed >= $start) {
            return 'LATER_OR_EQUAL_OBSERVATION_EXCLUDED';
        }
        if ($event >= $start) {
            return 'FUTURE_EVENT_EXCLUDED';
        }
        if ($history['agari_status'] !== 'VALID') {
            return 'INVALID_AGARI';
        }
        if ($history['meeting_id'] === null || $target['meeting_id'] === null || $target['meeting_start'] === null) {
            return 'UNKNOWN_MEETING';
        }
        if ($history['meeting_id'] === $target['meeting_id']) {
            return 'IN_MEETING';
        }

        return $history['race_date'] < $target['meeting_start'] ? 'PRE_MEETING' : 'NOT_PRE_MEETING';
    }

    public function latest(iterable $histories, array $target): array
    {
        $chosen = [];
        foreach ($histories as $row) {
            $kind = $this->candidate($row, $target);
            if (! in_array($kind, ['PRE_MEETING', 'IN_MEETING'], true)) {
                continue;
            }
            $key = $row['race_id'];
            $order = [$this->time($row['fetched_at']), $row['import_id']];
            if (! isset($chosen[$key]) || $order > $chosen[$key]['order']) {
                $chosen[$key] = ['order' => $order, 'kind' => $kind, 'row' => $row];
            }
        }
        ksort($chosen, SORT_NUMERIC);

        return array_values($chosen);
    }

    public function time(?string $value): ?int
    {
        return $value === null ? null : (new \DateTimeImmutable($value))->getTimestamp();
    }
}
