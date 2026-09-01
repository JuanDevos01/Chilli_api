<?php

namespace App\Domain\Matches\Aggregates;

use App\Domain\Matches\Events\MutualPreferencesDetected;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class MatchAggregate extends AggregateRoot
{
    private bool $detected = false;

    public function detect(string $coupleUuid, string $questionUuid, string $userAUuid, string $userBUuid): self
    {
        if ($this->detected) {
            throw new DomainException('Match already detected.');
        }

        $this->recordThat(new MutualPreferencesDetected(
            $this->uuid(),
            $coupleUuid,
            $questionUuid,
            $userAUuid,
            $userBUuid,
        ));

        return $this;
    }

    protected function applyMutualPreferencesDetected(MutualPreferencesDetected $event): void
    {
        $this->detected = true;
    }
}
