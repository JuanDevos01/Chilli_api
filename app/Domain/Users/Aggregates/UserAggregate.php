<?php

namespace App\Domain\Users\Aggregates;

use App\Domain\Users\Events\UserRegistered;
use App\Domain\Users\Events\UserRegisteredViaProvider;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class UserAggregate extends AggregateRoot
{
    public function register(string $name, string $email, string $hashedPassword): self
    {
        $this->recordThat(new UserRegistered($this->uuid(), $name, $email, $hashedPassword));

        return $this;
    }

    public function registerViaProvider(string $name, string $email, string $provider, string $providerUserId): self
    {
        $this->recordThat(new UserRegisteredViaProvider($this->uuid(), $name, $email, $provider, $providerUserId));

        return $this;
    }
}
