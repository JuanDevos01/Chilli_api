<?php

namespace App\Domain\Shared\Reactors;

use Illuminate\Support\Facades\DB;

trait IdempotentReactor
{
    protected function once(string $dedupKey, callable $handler): void
    {
        $inserted = DB::table('reactor_processed_events')->insertOrIgnore([
            'reactor' => static::class,
            'dedup_key' => $dedupKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted === 0) {
            return;
        }

        $handler();
    }
}
