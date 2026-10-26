<?php

declare(strict_types=1);

namespace App\Domain\Keirin\Scraping\PrestartCapture\Operational;

enum DecisionStatus: string
{
    case Proposed = 'PROPOSED_NOT_APPROVED';
    case Unresolved = 'UNRESOLVED';
    case Unverified = 'UNVERIFIED';
    case Approved = 'APPROVED';
    case Expired = 'EXPIRED';
    case Revoked = 'REVOKED';
    case Conflict = 'CONFLICT';
}
