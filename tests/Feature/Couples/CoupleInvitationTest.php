<?php

namespace Tests\Feature\Couples;

use App\Domain\Couples\Events\CoupleInvitationSent;
use App\Domain\Couples\Events\CoupleLinked;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class CoupleInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_users_can_pair_via_invitation_code(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $create = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $create->assertCreated();
        $create->assertJsonStructure(['couple_uuid', 'code']);
        $code = $create->json('code');

        $accept = $this->actingAs($bob)->postJson("/api/couples/invitations/{$code}/accept");
        $accept->assertOk();
        $coupleUuid = $accept->json('couple_uuid');

        $this->assertDatabaseHas('couples', [
            'uuid' => $coupleUuid,
            'user_a_uuid' => $alice->uuid,
            'user_b_uuid' => $bob->uuid,
        ]);

        $this->assertSame(1, EloquentStoredEvent::where('event_class', CoupleInvitationSent::class)->count());
        $this->assertSame(1, EloquentStoredEvent::where('event_class', CoupleLinked::class)->count());
    }

    public function test_inviter_cannot_accept_own_invitation(): void
    {
        $alice = $this->createUser('alice@example.com');

        $create = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $code = $create->json('code');

        $accept = $this->actingAs($alice)->postJson("/api/couples/invitations/{$code}/accept");
        $accept->assertStatus(500);
    }

    public function test_user_already_in_couple_cannot_create_invitation(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');

        $create = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $this->actingAs($bob)->postJson("/api/couples/invitations/{$create->json('code')}/accept");

        $second = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $second->assertStatus(422);
    }

    private function createUser(string $email): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => Hash::make('secret1234'),
        ]);
    }
}
