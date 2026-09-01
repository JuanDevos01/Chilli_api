<?php

namespace App\Domain\Users\Projectors;

use App\Domain\Users\Events\UserRegistered;
use App\Models\User;
use Spatie\EventSourcing\EventHandlers\Projectors\Projector;

class UserProjector extends Projector
{
    public function onUserRegistered(UserRegistered $event): void
    {
        User::create([
            'uuid' => $event->uuid,
            'name' => $event->name,
            'email' => $event->email,
            'password' => $event->hashedPassword,
        ]);
    }
}
