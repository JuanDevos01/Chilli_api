<?php

namespace Tests\Support;

use App\Domain\Matches\Services\CompatibilityJudge;
use App\Domain\Matches\Services\JudgmentResult;

/**
 * Deterministic in-memory CompatibilityJudge used by tests.
 * Keeps a queue of pre-programmed verdicts; falls back to a threshold rule when the queue is empty.
 */
class FakeCompatibilityJudge implements CompatibilityJudge
{
    /** @var JudgmentResult[] */
    private array $queue = [];

    /** @var array<int, array{questionText: string, dimensions: array, userAScores: array, userBScores: array}> */
    public array $calls = [];

    public function push(JudgmentResult $result): void
    {
        $this->queue[] = $result;
    }

    public function judge(
        string $questionText,
        array $dimensions,
        array $userAScores,
        array $userBScores,
    ): JudgmentResult {
        $this->calls[] = [
            'questionText' => $questionText,
            'dimensions' => $dimensions,
            'userAScores' => $userAScores,
            'userBScores' => $userBScores,
        ];

        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        return $this->thresholdFallback($userAScores, $userBScores);
    }

    /**
     * @param array<string, int> $userAScores
     * @param array<string, int> $userBScores
     */
    private function thresholdFallback(array $userAScores, array $userBScores): JudgmentResult
    {
        $threshold = 70;
        $matched = [];
        $diverging = [];

        foreach ($userAScores as $dim => $valueA) {
            $valueB = (int) ($userBScores[$dim] ?? 0);
            if ((int) $valueA >= $threshold && $valueB >= $threshold) {
                $matched[] = $dim;
            } else {
                $diverging[] = $dim;
            }
        }

        $match = $matched !== [] && $diverging === [];

        return new JudgmentResult(
            match: $match,
            confidence: $match ? 0.75 : 0.0,
            matchedDimensions: $matched,
            divergingDimensions: $diverging,
            narrative: $match ? 'Fake judge: aligned on all dimensions.' : '',
        );
    }
}
