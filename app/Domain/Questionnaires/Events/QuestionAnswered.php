<?php

namespace App\Domain\Questionnaires\Events;

use App\Domain\Shared\Attributes\Encrypted;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class QuestionAnswered extends ShouldBeStored
{
    public function __construct(
        public string $userUuid,
        public string $questionUuid,
        #[Encrypted]
        public string $answer,
    ) {}
}
