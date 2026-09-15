<?php

namespace App\Domain\Matches\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MatchInvalidated extends ShouldBeStored
{
    public function __construct(
        public string $matchUuid,
        public string $reason,
    ) {}
}
