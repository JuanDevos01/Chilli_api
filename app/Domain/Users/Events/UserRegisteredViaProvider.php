<?php

namespace App\Domain\Users\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class UserRegisteredViaProvider extends ShouldBeStored
{
    public function __construct(
        public string $uuid,
        public string $name,
        public string $email,
        public string $provider,
        public string $providerUserId,
    ) {}
}
