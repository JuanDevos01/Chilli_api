<?php

namespace App\Domain\Matches\Aggregates;

use App\Domain\Matches\Events\MatchInvalidated;
use App\Domain\Matches\Events\MutualPreferencesDetected;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class MatchAggregate extends AggregateRoot
{
    private bool $detected = false;
    private bool $invalidated = false;

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

    public function invalidate(string $reason): self
    {
        if (! $this->detected) {
            throw new DomainException('Cannot invalidate a match that was never detected.');
        }

        if ($this->invalidated) {
            throw new DomainException('Match already invalidated.');
        }

        $this->recordThat(new MatchInvalidated($this->uuid(), $reason));

        return $this;
    }

    protected function applyMutualPreferencesDetected(MutualPreferencesDetected $event): void
    {
        $this->detected = true;
    }

    protected function applyMatchInvalidated(MatchInvalidated $event): void
    {
        $this->invalidated = true;
    }
}
