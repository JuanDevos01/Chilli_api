<?php

namespace App\Domain\Users\Aggregates;

use App\Domain\Users\Events\UserRegistered;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class UserAggregate extends AggregateRoot
{
    public function register(string $name, string $email, string $hashedPassword): self
    {
        $this->recordThat(new UserRegistered($this->uuid(), $name, $email, $hashedPassword));

        return $this;
    }
}
