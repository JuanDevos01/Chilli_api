<?php

namespace App\Domain\Questionnaires\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class AnswerRetracted extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public string $questionUuid,
    ) {}
}
