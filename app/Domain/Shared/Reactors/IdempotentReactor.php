<?php

namespace App\Domain\Shared\Reactors;

use Illuminate\Support\Facades\DB;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;

trait IdempotentReactor
{
    protected function once(EloquentStoredEvent $storedEvent, callable $handler): void
    {
        $inserted = DB::table('reactor_processed_events')->insertOrIgnore([
            'reactor' => static::class,
            'stored_event_id' => $storedEvent->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $handler();
    }
}
