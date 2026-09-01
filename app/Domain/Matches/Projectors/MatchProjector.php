<?php

namespace App\Domain\Matches\Projectors;

use App\Domain\Matches\Events\MutualPreferencesDetected;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class MatchProjector extends Projector
{
    public function onMutualPreferencesDetected(MutualPreferencesDetected $event): void
    {
        DB::table('matches')->insert([
            'uuid' => $event->matchUuid,
            'couple_uuid' => $event->coupleUuid,
            'question_uuid' => $event->questionUuid,
            'user_a_uuid' => $event->userAUuid,
            'user_b_uuid' => $event->userBUuid,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
