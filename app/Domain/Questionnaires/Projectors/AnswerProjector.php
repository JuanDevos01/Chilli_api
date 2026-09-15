<?php

namespace App\Domain\Questionnaires\Projectors;

use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Domain\Questionnaires\Events\QuestionAnswered;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class AnswerProjector extends Projector
{
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

    public function onAnswerRetracted(AnswerRetracted $event): void
    {
        DB::table('user_answers')
            ->where('user_uuid', $event->userUuid)
            ->where('question_uuid', $event->questionUuid)
            ->update([
                'retracted_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
