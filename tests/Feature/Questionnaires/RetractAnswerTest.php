<?php

namespace Tests\Feature\Questionnaires;

use App\Domain\Matches\Events\MatchInvalidated;
use App\Domain\Questionnaires\Events\AnswerRetracted;
use App\Models\User;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class RetractAnswerTest extends TestCase
{
    use RefreshDatabase;

    private const Q1 = '11111111-1111-1111-1111-111111111111';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionSeeder::class);
    }

    public function test_retract_before_partner_answers_prevents_match(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->answer($alice, self::Q1, 'yes');
        $this->retract($alice, self::Q1)->assertOk();

        $this->answer($bob, self::Q1, 'yes');

        $this->actingAs($alice)->getJson('/api/matches')->assertJsonCount(0, 'matches');
        $this->actingAs($bob)->getJson('/api/matches')->assertJsonCount(0, 'matches');

        $this->assertDatabaseHas('user_answers', [
            'user_uuid' => $alice->uuid,
            'question_uuid' => self::Q1,
        ]);
        $this->assertNotNull(
            \DB::table('user_answers')
                ->where('user_uuid', $alice->uuid)
                ->where('question_uuid', self::Q1)
                ->value('retracted_at')
        );

        $this->assertDatabaseHas('stored_events', [
            'event_class' => AnswerRetracted::class,
            'aggregate_uuid' => $alice->uuid,
        ]);
    }

    public function test_retract_after_mutual_match_invalidates_match(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');

        $this->actingAs($alice)->getJson('/api/matches')->assertJsonCount(1, 'matches');

        $this->retract($alice, self::Q1)->assertOk();

        $this->actingAs($alice)->getJson('/api/matches')->assertJsonCount(0, 'matches');
        $this->actingAs($bob)->getJson('/api/matches')->assertJsonCount(0, 'matches');

        $this->assertNotNull(
            \DB::table('matches')->where('question_uuid', self::Q1)->value('invalidated_at')
        );

        $this->assertTrue(
            EloquentStoredEvent::query()->where('event_class', MatchInvalidated::class)->exists(),
            'MatchInvalidated event should be persisted'
        );
    }

    public function test_cannot_retract_an_answer_that_was_never_given(): void
    {
        $alice = $this->registerUser('alice@example.com');

        $response = $this->retract($alice, self::Q1);

        $response->assertStatus(500);
    }

    public function test_cannot_re_answer_after_retraction(): void
    {
        $alice = $this->registerUser('alice@example.com');

        $this->answer($alice, self::Q1, 'yes');
        $this->retract($alice, self::Q1)->assertOk();

        $second = $this->actingAs($alice)->postJson('/api/questionnaire/answers', [
            'question_uuid' => self::Q1,
            'answer' => 'no',
        ]);

        $second->assertStatus(500);
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

    private function retract(User $user, string $questionUuid)
    {
        return $this->actingAs($user)->deleteJson("/api/questionnaire/answers/{$questionUuid}");
    }
}
