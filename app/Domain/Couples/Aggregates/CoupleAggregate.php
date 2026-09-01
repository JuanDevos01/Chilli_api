<?php

namespace App\Domain\Couples\Aggregates;

use App\Domain\Couples\Events\CoupleInvitationAccepted;
use App\Domain\Couples\Events\CoupleInvitationSent;
use App\Domain\Couples\Events\CoupleLinked;
use DomainException;
use Spatie\EventSourcing\AggregateRoots\AggregateRoot;

class CoupleAggregate extends AggregateRoot
{
    private ?string $inviterUuid = null;

    private bool $linked = false;

    public function sendInvitation(string $inviterUuid, string $code): self
    {
        if ($this->inviterUuid !== null) {
            throw new DomainException('Invitation already sent for this couple.');
        }

        $this->recordThat(new CoupleInvitationSent($this->uuid(), $inviterUuid, $code));

        return $this;
    }

    public function acceptInvitation(string $accepterUuid): self
    {
        if ($this->inviterUuid === null) {
            throw new DomainException('No invitation to accept.');
        }

        if ($this->linked) {
            throw new DomainException('Invitation already accepted.');
        }

        if ($this->inviterUuid === $accepterUuid) {
            throw new DomainException('Inviter cannot accept their own invitation.');
        }

        $this->recordThat(new CoupleInvitationAccepted($this->uuid(), $accepterUuid));
        $this->recordThat(new CoupleLinked($this->uuid(), $this->inviterUuid, $accepterUuid));

        return $this;
    }

    protected function applyCoupleInvitationSent(CoupleInvitationSent $event): void
    {
        $this->inviterUuid = $event->inviterUuid;
    }

    protected function applyCoupleLinked(CoupleLinked $event): void
    {
        $this->linked = true;
    }
}
