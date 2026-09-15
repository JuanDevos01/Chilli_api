<?php

namespace App\Domain\Matches\Reactors;

use App\Domain\Matches\Aggregates\MatchAggregate;
use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Domain\Questionnaires\Events\QuestionAnswered;
use App\Domain\Shared\Reactors\IdempotentReactor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\EventHandlers\Reactors\Reactor;

class DetectMutualMatchReactor extends Reactor
{
    use IdempotentReactor;

    public function onQuestionAnswered(QuestionAnswered $event): void
    {
        $dedupKey = "qa:{$event->userUuid}:{$event->questionUuid}";

        $this->once($dedupKey, function () use ($event) {
            if ($event->answer !== 'yes') {
                return;
            }

            $couple = $this->coupleOf($event->userUuid);
            if (! $couple) {
                return;
            }

            $partnerUuid = $couple->user_a_uuid === $event->userUuid
                ? $couple->user_b_uuid
                : $couple->user_a_uuid;

            $partnerAnswer = DB::table('user_answers')
                ->where('user_uuid', $partnerUuid)
                ->where('question_uuid', $event->questionUuid)
                ->whereNull('retracted_at')
                ->value('answer');

            if ($partnerAnswer !== 'yes') {
                return;
            }

            if ($this->activeMatchExists($couple->uuid, $event->questionUuid)) {
                return;
            }

            MatchAggregate::retrieve((string) Str::uuid())
                ->detect($couple->uuid, $event->questionUuid, $couple->user_a_uuid, $couple->user_b_uuid)
                ->persist();
        });
    }

    public function onAnswerRetracted(AnswerRetracted $event): void
    {
        $dedupKey = "ar:{$event->userUuid}:{$event->questionUuid}";

        $this->once($dedupKey, function () use ($event) {
            $couple = $this->coupleOf($event->userUuid);
            if (! $couple) {
                return;
            }

            $matchUuid = DB::table('matches')
                ->where('couple_uuid', $couple->uuid)
                ->where('question_uuid', $event->questionUuid)
                ->whereNull('invalidated_at')
                ->value('uuid');

            if (! $matchUuid) {
                return;
            }

            MatchAggregate::retrieve($matchUuid)
                ->invalidate('answer_retracted')
                ->persist();
        });
    }

    private function coupleOf(string $userUuid): ?object
    {
        return DB::table('couples')
            ->where('user_a_uuid', $userUuid)
            ->orWhere('user_b_uuid', $userUuid)
            ->first();
    }

    private function activeMatchExists(string $coupleUuid, string $questionUuid): bool
    {
        return DB::table('matches')
            ->where('couple_uuid', $coupleUuid)
            ->where('question_uuid', $questionUuid)
            ->whereNull('invalidated_at')
            ->exists();
    }
}
