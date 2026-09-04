<?php

namespace Tests\Feature\Auth;

use App\Domain\Users\Events\UserRegisteredViaProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class ProviderAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_apple_signin_creates_new_user_and_records_event(): void
    {
        $token = $this->fakeIdentityToken([
            'sub'   => 'apple-001',
            'email' => 'alice@icloud.com',
        ]);

        $response = $this->postJson('/api/auth/apple', [
            'identity_token' => $token,
            'name'           => 'Alice',
        ]);

        $response->assertCreated();
        $response->assertJsonStructure(['token', 'user' => ['uuid', 'name', 'email', 'provider', 'provider_user_id']]);

        $uuid = $response->json('user.uuid');

        $this->assertDatabaseHas('users', [
            'uuid'             => $uuid,
            'email'            => 'alice@icloud.com',
            'provider'         => 'apple',
            'provider_user_id' => 'apple-001',
        ]);

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', UserRegisteredViaProvider::class)
            ->where('aggregate_uuid', $uuid)
            ->first();

        $this->assertNotNull($storedEvent, 'UserRegisteredViaProvider must be stored');
    }

    public function test_google_signin_creates_new_user(): void
    {
        $token = $this->fakeIdentityToken([
            'sub'   => 'google-999',
            'email' => 'bob@gmail.com',
        ]);

        $response = $this->postJson('/api/auth/google', [
            'identity_token' => $token,
            'name'           => 'Bob',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email'            => 'bob@gmail.com',
            'provider'         => 'google',
            'provider_user_id' => 'google-999',
        ]);
    }

    public function test_same_provider_sub_returns_same_user(): void
    {
        $token = $this->fakeIdentityToken([
            'sub'   => 'apple-001',
            'email' => 'alice@icloud.com',
        ]);

        $first  = $this->postJson('/api/auth/apple', ['identity_token' => $token, 'name' => 'Alice']);
        $second = $this->postJson('/api/auth/apple', ['identity_token' => $token]);

        $first->assertCreated();
        $second->assertCreated();

        $this->assertSame($first->json('user.uuid'), $second->json('user.uuid'));

        $this->assertSame(
            1,
            EloquentStoredEvent::query()
                ->where('event_class', UserRegisteredViaProvider::class)
                ->count(),
            'a returning user must not produce a duplicate UserRegisteredViaProvider event'
        );
    }

    public function test_missing_sub_or_email_returns_422(): void
    {
        $tokenNoEmail = $this->fakeIdentityToken(['sub' => 'apple-001']);
        $response = $this->postJson('/api/auth/apple', ['identity_token' => $tokenNoEmail]);
        $response->assertStatus(422);
    }

    public function test_token_is_usable_for_authenticated_endpoints(): void
    {
        $token = $this->fakeIdentityToken([
            'sub'   => 'google-42',
            'email' => 'carol@gmail.com',
        ]);

        $signIn = $this->postJson('/api/auth/google', ['identity_token' => $token, 'name' => 'Carol']);
        $sanctumToken = $signIn->json('token');

        $me = $this->withHeader('Authorization', 'Bearer '.$sanctumToken)->getJson('/api/auth/me');
        $me->assertOk();
        $me->assertJsonFragment(['email' => 'carol@gmail.com']);
    }

    private function fakeIdentityToken(array $claims): string
    {
        $header = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->base64UrlEncode(json_encode($claims));
        $signature = $this->base64UrlEncode('poc-signature-not-verified');

        return "{$header}.{$payload}.{$signature}";
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
