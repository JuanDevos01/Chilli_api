<?php

namespace App\Domain\Couples\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CoupleInvitationAccepted extends ShouldBeStored
{
    public function __construct(
        public string $coupleUuid,
        public string $accepterUuid,
    ) {}
}
