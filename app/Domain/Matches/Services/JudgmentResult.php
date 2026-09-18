<?php

namespace App\Domain\Matches\Services;

class JudgmentResult
{
    /**
     * @param string[] $matchedDimensions
     * @param string[] $divergingDimensions
     */
    public function __construct(
        public bool $match,
        public float $confidence,
        public array $matchedDimensions,
        public array $divergingDimensions,
        public string $narrative,
    ) {}

    public static function noMatch(string $reason = ''): self
    {
        return new self(
            match: false,
            confidence: 0.0,
            matchedDimensions: [],
            divergingDimensions: [],
            narrative: $reason,
        );
    }
}
