<?php

namespace Tests\Feature\EventSourcing;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class ReplayProjectionsTest extends TestCase
{
    use RefreshDatabase;

    private const Q1 = '11111111-1111-1111-1111-111111111111';
    private const Q2 = '22222222-2222-2222-2222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionSeeder::class);
    }

    public function test_replay_rebuilds_all_projections_identically(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));

        [$alice, $bob] = $this->registerCouple();
        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');
        $this->answer($alice, self::Q2, 'yes');
        $this->answer($bob, self::Q2, 'no');

        $eventsBefore = EloquentStoredEvent::count();
        $before = $this->snapshotProjections();

        $this->assertNotEmpty($before['users']);
        $this->assertNotEmpty($before['couples']);
        $this->assertNotEmpty($before['user_answers']);
        $this->assertCount(1, $before['matches'], 'Debe existir 1 match antes del replay');

        Artisan::call('event-sourcing:replay');

        $this->assertSame(
            $eventsBefore,
            EloquentStoredEvent::count(),
            'La cinta stored_events no debe ser tocada por el replay'
        );

        $after = $this->snapshotProjections();

        $this->assertEquals($before, $after, 'Las proyecciones deben quedar idénticas tras el replay');
    }

    public function test_replay_recovers_from_dropped_projection_tables(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));

        [$alice, $bob] = $this->registerCouple();
        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');

        $before = $this->snapshotProjections();

        DB::table('users')->delete();
        DB::table('couples')->delete();
        DB::table('couple_invitations')->delete();
        DB::table('user_answers')->delete();
        DB::table('matches')->delete();

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('matches')->count());

        Artisan::call('event-sourcing:replay');

        $after = $this->snapshotProjections();
        $this->assertEquals($before, $after);
    }

    public function test_replay_yields_same_matches_endpoint_response(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));

        [$alice, $bob] = $this->registerCouple();
        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');

        $matchesBefore = $this->actingAs($alice)->getJson('/api/matches')->json('matches');

        Artisan::call('event-sourcing:replay');

        $aliceAfterReplay = User::where('email', $alice->email)->firstOrFail();
        $matchesAfter = $this->actingAs($aliceAfterReplay)->getJson('/api/matches')->json('matches');

        $this->assertEquals($matchesBefore, $matchesAfter);
    }

    private function snapshotProjections(): array
    {
        $withoutRowId = fn ($row) => collect((array) $row)->except('id')->all();

        return [
            'users' => DB::table('users')->orderBy('uuid')->get()->map($withoutRowId)->toArray(),
            'couples' => DB::table('couples')->orderBy('uuid')->get()->map($withoutRowId)->toArray(),
            'couple_invitations' => DB::table('couple_invitations')->orderBy('couple_uuid')->get()->map($withoutRowId)->toArray(),
            'user_answers' => DB::table('user_answers')
                ->orderBy('user_uuid')->orderBy('question_uuid')
                ->get()->map($withoutRowId)->toArray(),
            'matches' => DB::table('matches')->orderBy('uuid')->get()->map($withoutRowId)->toArray(),
        ];
    }

    private function registerCouple(): array
    {
        $alice = $this->registerUser('alice@example.com');
        $bob = $this->registerUser('bob@example.com');

        $create = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $code = $create->json('code');
        $this->actingAs($bob)->postJson("/api/couples/invitations/{$code}/accept")->assertOk();

        return [$alice->fresh(), $bob->fresh()];
    }

    private function registerUser(string $email): User
    {
        $this->postJson('/api/auth/register', [
            'name' => Str::before($email, '@'),
            'email' => $email,
            'password' => 'secret1234',
            'password_confirmation' => 'secret1234',
        ])->assertCreated();

        return User::where('email', $email)->firstOrFail();
    }

    private function answer(User $user, string $questionUuid, string $answer): void
    {
        $this->actingAs($user)->postJson('/api/questionnaire/answers', [
            'question_uuid' => $questionUuid,
            'answer' => $answer,
        ])->assertCreated();
    }
}
