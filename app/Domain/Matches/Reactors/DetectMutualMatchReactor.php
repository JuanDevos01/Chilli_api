<?php

namespace App\Domain\Matches\Reactors;

use App\Domain\Matches\Aggregates\MatchAggregate;
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
                ->value('answer');

            if ($partnerAnswer !== 'yes') {
                return;
            }

            if ($this->matchAlreadyExists($couple->uuid, $event->questionUuid)) {
                return;
            }

            MatchAggregate::retrieve((string) Str::uuid())
                ->detect($couple->uuid, $event->questionUuid, $couple->user_a_uuid, $couple->user_b_uuid)
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

    private function matchAlreadyExists(string $coupleUuid, string $questionUuid): bool
    {
        return DB::table('matches')
            ->where('couple_uuid', $coupleUuid)
            ->where('question_uuid', $questionUuid)
            ->exists();
    }
}
