<?php

namespace App\Domain\Couples\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class CoupleInvitationSent extends ShouldBeStored
{
    public function __construct(
        public string $coupleUuid,
        public string $inviterUuid,
        public string $code,
    ) {}
}
