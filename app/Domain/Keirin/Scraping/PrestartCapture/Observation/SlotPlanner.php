<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Observation;

use App\Domain\Keirin\Scraping\PrestartCapture\Operational\Contract;
use RuntimeException;

final class SlotPlanner
{
    public function plan(ObservationManifest $manifest): array
    {
        $slots = [];
        foreach ($manifest->data['targets'] as $target) {
            foreach ($target['slots'] as $index => $minutes) {
                $start = (new \DateTimeImmutable($target['scheduled_start_at']))->modify('-'.$minutes.' minutes');
                $slots[] = ['race_id' => $target['race']['race_id'], 'slot_id' => $index + 1,
                    'starts_at' => $start->format('Y-m-d\TH:i:s.uP'),
                    'expires_at' => $start->modify('+60 seconds')->format('Y-m-d\TH:i:s.uP')];
            }
        }

        return $slots;
    }

    public function requireOpen(ObservationManifest $manifest, int $raceId, int $slotId, string $at): array
    {
        $now = Contract::instant($at);
        if ($now === null || $now < Contract::instant($manifest->data['approved_from'])
            || $now >= Contract::instant($manifest->data['expires_at'])) {
            throw new RuntimeException('Authorization time invalid.');
        }
        foreach ($this->plan($manifest) as $slot) {
            if ($slot['race_id'] === $raceId && $slot['slot_id'] === $slotId
                && $now >= Contract::instant($slot['starts_at']) && $now < Contract::instant($slot['expires_at'])) {
                return $slot;
            }
        }
        throw new RuntimeException('Slot absent, not open or expired; catch-up forbidden.');
    }
}
