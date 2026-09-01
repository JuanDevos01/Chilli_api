<?php

namespace App\Domain\Couples\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CoupleLinked extends ShouldBeStored
{
    public function __construct(
        public string $coupleUuid,
        public string $userAUuid,
        public string $userBUuid,
    ) {}
}
