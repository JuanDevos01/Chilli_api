<?php

namespace App\Domain\Matches\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MinimaxCompatibilityJudge implements CompatibilityJudge
{
    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private string $model,
        private int $timeoutSeconds,
    ) {}

    public function judge(
        string $questionText,
        array $dimensions,
        array $userAScores,
        array $userBScores,
    ): JudgmentResult {
        if ($this->apiKey === '') {
            Log::warning('MinimaxCompatibilityJudge: missing API key, treating as no match.');

            return JudgmentResult::noMatch('missing_api_key');
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post($this->completionsUrl(), [
                    'model' => $this->model,
                    'max_completion_tokens' => 500,
                    'temperature' => 0.4,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'user', 'content' => $this->userPrompt($questionText, $dimensions, $userAScores, $userBScores)],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('MinimaxCompatibilityJudge: non-successful HTTP status', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return JudgmentResult::noMatch('http_'.$response->status());
            }

            $content = data_get($response->json(), 'choices.0.message.content');

            if (! is_string($content) || $content === '') {
                Log::warning('MinimaxCompatibilityJudge: empty content in response', [
                    'body' => $response->json(),
                ]);

                return JudgmentResult::noMatch('empty_content');
            }

            return $this->parse($content);
        } catch (Throwable $e) {
            Log::warning('MinimaxCompatibilityJudge: request failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);

            return JudgmentResult::noMatch('exception');
        }
    }

    private function completionsUrl(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
        You are a compatibility judge for a couples app. Two partners have independently answered the same intimate question by scoring several named dimensions on a 0-100 scale. Your job is to decide whether their answers are compatible enough to reveal as a "match" and, if so, write a short narrative for the reveal moment.

        You MUST return only a single JSON object matching this exact schema, and no other text:

        {
          "match": boolean,
          "confidence": number,               // 0.0 to 1.0
          "matched_dimensions": [string, ...], // dimension names where they align
          "diverging_dimensions": [string, ...], // dimension names where they diverge
          "narrative": string                 // 1-2 sentences, second-person, warm and specific, references the actual scores/dimensions. Empty string if no match.
        }

        Guidance:
        - Consider a dimension "matched" when both partners score above 60 and are within 25 points of each other; "diverging" when the gap is larger than 30 points.
        - Only set match=true when at least half the dimensions match AND no dimension is a hard divergence (one above 70 while the other is below 30).
        - Confidence should reflect how strong and unambiguous the compatibility is.
        - Narrative should mention what they agree on and any nuance where they differ, using natural language (not raw numbers).
        - Never include the raw scores in the narrative — describe them qualitatively.
        - Respond in the same language the question is written in.
        PROMPT;
    }

    /**
     * @param array<int, array{name: string, low_label: string, high_label: string}> $dimensions
     * @param array<string, int> $userAScores
     * @param array<string, int> $userBScores
     */
    private function userPrompt(string $questionText, array $dimensions, array $userAScores, array $userBScores): string
    {
        $dimsBlock = collect($dimensions)
            ->map(fn ($d) => "- {$d['name']}: {$d['low_label']} (0) ↔ {$d['high_label']} (100)")
            ->implode("\n");

        $aBlock = collect($userAScores)->map(fn ($v, $k) => "  - {$k}: {$v}")->implode("\n");
        $bBlock = collect($userBScores)->map(fn ($v, $k) => "  - {$k}: {$v}")->implode("\n");

        return <<<PROMPT
        Question: {$questionText}

        Dimensions:
        {$dimsBlock}

        Partner A scores:
        {$aBlock}

        Partner B scores:
        {$bBlock}

        Return only the JSON object.
        PROMPT;
    }

    private function parse(string $content): JudgmentResult
    {
        $json = $this->extractJson($content);

        if ($json === null) {
            Log::warning('MinimaxCompatibilityJudge: no JSON block found', ['content' => $content]);

            return JudgmentResult::noMatch('unparseable');
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ! isset($data['match'])) {
            Log::warning('MinimaxCompatibilityJudge: JSON missing required fields', ['content' => $content]);

            return JudgmentResult::noMatch('unparseable');
        }

        return new JudgmentResult(
            match: (bool) $data['match'],
            confidence: (float) ($data['confidence'] ?? 0.0),
            matchedDimensions: array_values(array_map('strval', $data['matched_dimensions'] ?? [])),
            divergingDimensions: array_values(array_map('strval', $data['diverging_dimensions'] ?? [])),
            narrative: (string) ($data['narrative'] ?? ''),
        );
    }

    private function extractJson(string $content): ?string
    {
        $trimmed = trim($content);

        if (str_starts_with($trimmed, '{')) {
            return $trimmed;
        }

        // Extract the first {...} block if surrounded by other text (e.g. ```json fences).
        if (preg_match('/\{[\s\S]*\}/', $trimmed, $matches)) {
            return $matches[0];
        }

        return null;
    }
}
