<?php

namespace App\Domain\Matches\Projectors;

use App\Domain\Matches\Events\MatchInvalidated;
use App\Domain\Matches\Events\MutualPreferencesDetected;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class MatchProjector extends Projector
{
    public function resetState(?string $aggregateUuid = null): void
    {
        DB::table('matches')->delete();
    }

    public function onMutualPreferencesDetected(MutualPreferencesDetected $event): void
    {
        DB::table('matches')->insert([
            'uuid' => $event->matchUuid,
            'couple_uuid' => $event->coupleUuid,
            'question_uuid' => $event->questionUuid,
            'user_a_uuid' => $event->userAUuid,
            'user_b_uuid' => $event->userBUuid,
            'narrative' => $event->narrative,
            'matched_dimensions' => $event->matchedDimensions !== [] ? json_encode($event->matchedDimensions) : null,
            'diverging_dimensions' => $event->divergingDimensions !== [] ? json_encode($event->divergingDimensions) : null,
            'confidence' => $event->confidence,
            'detected_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function onMatchInvalidated(MatchInvalidated $event): void
    {
        DB::table('matches')
            ->where('uuid', $event->matchUuid)
            ->update([
                'invalidated_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
