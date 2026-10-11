<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartEvidence;

use InvalidArgumentException;

/** Phase 1 only: caller supplies artificial bytes and artificial acquisition evidence; no fetch occurs. */
final readonly class SyntheticCapture
{
    public string $captureId;

    /** @param list<RaceTarget> $targets */
    public function __construct(
        public string $source,
        public string $raw,
        public array $targets,
        public string $requestUri,
        public ?string $effectiveUri,
        public string $method,
        public array $parameters,
        public ?int $httpStatus,
        public ?bool $redirectDetected,
        public ?string $fetchedAt,
        public ?string $scheduleTimezone,
        public ?string $clockEvidence,
        ?string $captureId = null,
    ) {
        $this->captureId = $captureId ?? bin2hex(random_bytes(16));
        if (! in_array($source, ['JSJ017', 'PJ0315'], true) || $targets === [] || ! array_is_list($targets)
            || strlen($raw) > EvidenceFiles::MAX_BYTES || preg_match('/^[a-f0-9]{32}$/D', $this->captureId) !== 1
            || ($source === 'PJ0315' && count($targets) !== 1)) {
            throw new InvalidArgumentException('Invalid synthetic capture.');
        }
        $identities = [];
        foreach ($targets as $target) {
            if (! $target instanceof RaceTarget) {
                throw new InvalidArgumentException('Typed synthetic targets required.');
            }
            $identity = $target->raceDate.':'.$target->trackCode.':'.$target->raceNumber;
            if (isset($identities[$identity])) {
                throw new InvalidArgumentException('Duplicate synthetic target.');
            }
            $identities[$identity] = true;
        }
    }
}
