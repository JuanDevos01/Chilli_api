<?php

namespace App\Domain\Couples\Projectors;

use App\Domain\Couples\Events\CoupleInvitationAccepted;
use App\Domain\Couples\Events\CoupleInvitationSent;
use App\Domain\Couples\Events\CoupleLinked;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class CoupleProjector extends Projector
{
    public function resetState(?string $aggregateUuid = null): void
    {
        DB::table('couples')->delete();
        DB::table('couple_invitations')->delete();
    }

    public function onCoupleInvitationSent(CoupleInvitationSent $event): void
    {
        DB::table('couple_invitations')->insert([
            'couple_uuid' => $event->coupleUuid,
            'inviter_uuid' => $event->inviterUuid,
            'code' => $event->code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function onCoupleInvitationAccepted(CoupleInvitationAccepted $event): void
    {
        DB::table('couple_invitations')
            ->where('couple_uuid', $event->coupleUuid)
            ->update([
                'accepted_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function onCoupleLinked(CoupleLinked $event): void
    {
        DB::table('couples')->insert([
            'uuid' => $event->coupleUuid,
            'user_a_uuid' => $event->userAUuid,
            'user_b_uuid' => $event->userBUuid,
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
