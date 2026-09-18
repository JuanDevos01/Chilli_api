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

    /**
     * @param string[] $matchedDimensions
     * @param string[] $divergingDimensions
     */
    public function detect(
        string $coupleUuid,
        string $questionUuid,
        string $userAUuid,
        string $userBUuid,
        ?string $narrative = null,
        array $matchedDimensions = [],
        array $divergingDimensions = [],
        ?float $confidence = null,
    ): self {
        if ($this->detected) {
            throw new DomainException('Match already detected.');
        }

        if ($confidence !== null && ($confidence < 0.0 || $confidence > 1.0)) {
            throw new DomainException('Confidence must be between 0 and 1.');
        }

        $this->recordThat(new MutualPreferencesDetected(
            $this->uuid(),
            $coupleUuid,
            $questionUuid,
            $userAUuid,
            $userBUuid,
            $narrative,
            $matchedDimensions,
            $divergingDimensions,
            $confidence,
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
