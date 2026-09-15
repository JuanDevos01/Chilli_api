<?php

namespace App\Domain\Users\Projectors;

use App\Domain\Users\Events\UserRegistered;
use App\Domain\Users\Events\UserRegisteredViaProvider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class UserProjector extends Projector
{
    public function resetState(?string $aggregateUuid = null): void
    {
        DB::table('users')->delete();
    }

    public function onUserRegistered(UserRegistered $event): void
    {
        User::create([
            'uuid' => $event->uuid,
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->hashedPassword,
        ]);
    }

    public function onUserRegisteredViaProvider(UserRegisteredViaProvider $event): void
    {
        User::create([
            'uuid'             => $event->uuid,
            'name'             => $event->name,
            'email'            => $event->email,
            'provider'         => $event->provider,
            'provider_user_id' => $event->providerUserId,
        ]);
    }
}
