<?php

namespace App\Domain\Questionnaires\Projectors;

use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Domain\Questionnaires\Events\QuestionAnswered;
use App\Domain\Questionnaires\Events\QuestionScored;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AnswerProjector extends Projector
{
    public function resetState(?string $aggregateUuid = null): void
    {
        DB::table('user_answers')->delete();
        DB::table('user_question_scores')->delete();
    }

    public function onQuestionAnswered(QuestionAnswered $event): void
    {
        DB::table('user_answers')->insert([
            'user_uuid' => $event->userUuid,
            'question_uuid' => $event->questionUuid,
            'answer' => $event->answer,
            'answered_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function onQuestionScored(QuestionScored $event): void
    {
        DB::table('user_question_scores')->insert([
            'user_uuid' => $event->userUuid,
            'question_uuid' => $event->questionUuid,
            'scores' => json_encode($event->scores),
            'scored_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function onAnswerRetracted(AnswerRetracted $event): void
    {
        DB::table('user_answers')
            ->where('user_uuid', $event->userUuid)
            ->where('question_uuid', $event->questionUuid)
            ->update([
                'retracted_at' => now(),
                'updated_at' => now(),
            ]);

        DB::table('user_question_scores')
            ->where('user_uuid', $event->userUuid)
            ->where('question_uuid', $event->questionUuid)
            ->update([
                'retracted_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
