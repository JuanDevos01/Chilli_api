<?php

namespace Tests\Feature\Auth;

use App\Domain\Users\Events\UserRegistered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class RegisterUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_records_user_registered_event_and_creates_user(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Ana',
            'email' => 'ana@example.com',
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user' => ['uuid', 'name', 'email']]);

        $uuid = $response->json('user.uuid');
        $this->assertNotEmpty($uuid);

        $this->assertDatabaseHas('users', [
            'uuid' => $uuid,
            'email' => 'ana@example.com',
        ]);

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', UserRegistered::class)
            ->where('aggregate_uuid', $uuid)
            ->first();

        $this->assertNotNull($storedEvent, 'UserRegistered debe existir en stored_events');
        $this->assertSame(1, $storedEvent->aggregate_version);
    }
}
