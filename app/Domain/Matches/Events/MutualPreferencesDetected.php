<?php

namespace App\Domain\Matches\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MutualPreferencesDetected extends ShouldBeStored
{
    public function __construct(
        public string $matchUuid,
        public string $coupleUuid,
        public string $questionUuid,
        public string $userAUuid,
        public string $userBUuid,
    ) {}
}
