<?php

namespace App\Domain\Matches\Services;

interface CompatibilityJudge
{
    /**
     * @param array<int, array{name: string, low_label: string, high_label: string}> $dimensions
     * @param array<string, int> $userAScores
     * @param array<string, int> $userBScores
     */
    public function judge(
        string $questionText,
        array $dimensions,
        array $userAScores,
        array $userBScores,
    ): JudgmentResult;
}
