<?php

namespace App\Domain\Matches\Events;

use Spatie\EventSourcing\StoredEvents\ShouldBeStored;

class MutualPreferencesDetected extends ShouldBeStored
{
    /**
     * @param string[] $matchedDimensions
     * @param string[] $divergingDimensions
     */
    public function __construct(
        public string $matchUuid,
        public string $coupleUuid,
        public string $questionUuid,
        public string $userAUuid,
        public string $userBUuid,
        public ?string $narrative = null,
        public array $matchedDimensions = [],
        public array $divergingDimensions = [],
        public ?float $confidence = null,
    ) {}
}
