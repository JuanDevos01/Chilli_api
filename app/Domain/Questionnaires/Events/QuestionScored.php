<?php

namespace App\Domain\Questionnaires\Events;

use App\Domain\Shared\Attributes\Encrypted;
use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class QuestionScored extends ShouldBeStored
{
    /**
     * @param array<string, int> $scores map of dimension name => score in [0, 100]
     */
    public function __construct(
        public string $userUuid,
        public string $questionUuid,
        #[Encrypted]
        public array $scores,
    ) {}
}
