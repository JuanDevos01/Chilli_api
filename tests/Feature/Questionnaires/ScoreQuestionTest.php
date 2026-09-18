<?php

namespace Tests\Feature\Questionnaires;

use App\Domain\Matches\Events\MutualPreferencesDetected;
use App\Domain\Matches\Services\CompatibilityJudge;
use App\Domain\Questionnaires\Events\QuestionScored;
use App\Models\User;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\Support\FakeCompatibilityJudge;
use Tests\TestCase;

class ScoreQuestionTest extends TestCase
{
    use RefreshDatabase;

    private const Q1 = '11111111-1111-1111-1111-111111111111';

    private FakeCompatibilityJudge $judge;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionSeeder::class);
        $this->judge = new FakeCompatibilityJudge();
        $this->app->instance(CompatibilityJudge::class, $this->judge);
    }

    public function test_index_exposes_dimensions_and_null_my_scores_by_default(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/api/questionnaire');
        $response->assertOk();

        $q1 = collect($response->json('questions'))->firstWhere('uuid', self::Q1);
        $this->assertIsArray($q1['dimensions']);
        $this->assertCount(3, $q1['dimensions']);
        $this->assertSame('general', $q1['dimensions'][0]['name']);
        $this->assertArrayHasKey('low_label', $q1['dimensions'][0]);
        $this->assertArrayHasKey('high_label', $q1['dimensions'][0]);
        $this->assertContains('turn_on', array_column($q1['dimensions'], 'name'));
        $this->assertNull($q1['my_scores']);
    }

    public function test_score_persists_event_with_encrypted_payload(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 85, 'timing' => 40],
        ])->assertCreated();

        $this->assertDatabaseHas('user_question_scores', [
            'user_uuid' => $user->uuid,
            'question_uuid' => self::Q1,
        ]);

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', QuestionScored::class)
            ->firstOrFail();

        $properties = $storedEvent->event_properties;
        $this->assertArrayHasKey('scores', $properties);
        $this->assertIsString($properties['scores'], 'scores must be encrypted at rest');
        $this->assertNotSame('[', substr($properties['scores'], 0, 1));

        $decrypted = json_decode(Crypt::decryptString($properties['scores']), true);
        $this->assertSame(['general' => 85, 'timing' => 40], $decrypted);
    }

    public function test_index_returns_my_scores_after_scoring(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 75],
        ])->assertCreated();

        $q1 = collect($this->actingAs($user)->getJson('/api/questionnaire')->json('questions'))
            ->firstWhere('uuid', self::Q1);

        $this->assertSame(['general' => 90, 'timing' => 75], $q1['my_scores']);
    }

    public function test_cannot_score_same_question_twice(): void
    {
        $user = $this->createUser();
        $payload = ['question_uuid' => self::Q1, 'scores' => ['general' => 50, 'timing' => 50]];

        $this->actingAs($user)->postJson('/api/questionnaire/scores', $payload)->assertCreated();
        $this->actingAs($user)->postJson('/api/questionnaire/scores', $payload)->assertStatus(500);
    }

    public function test_cannot_score_after_retracting(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 80, 'timing' => 80],
        ])->assertCreated();

        $this->actingAs($user)->deleteJson('/api/questionnaire/answers/'.self::Q1)->assertOk();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 90],
        ])->assertStatus(500);
    }

    public function test_cannot_score_a_question_already_answered_binary(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/questionnaire/answers', [
            'question_uuid' => self::Q1,
            'answer' => 'yes',
        ])->assertCreated();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 90],
        ])->assertStatus(500);
    }

    public function test_scores_out_of_range_are_rejected(): void
    {
        $user = $this->createUser();

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 150],
        ])->assertStatus(422);

        $this->actingAs($user)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => -5],
        ])->assertStatus(422);
    }

    public function test_index_does_not_leak_partner_scores(): void
    {
        $alice = $this->createUser();
        $bob = $this->createUser();

        $this->actingAs($bob)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 100, 'timing' => 100],
        ])->assertCreated();

        $q1 = collect($this->actingAs($alice)->getJson('/api/questionnaire')->json('questions'))
            ->firstWhere('uuid', self::Q1);

        $this->assertNull($q1['my_scores']);
    }

    public function test_reactor_calls_judge_with_question_dimensions_and_both_partner_scores(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->actingAs($alice)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 80, 'timing' => 90],
        ])->assertCreated();

        $this->assertCount(0, $this->judge->calls, 'judge should not be called until both partners scored');

        $this->actingAs($bob)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 95, 'timing' => 75],
        ])->assertCreated();

        $this->assertCount(1, $this->judge->calls);
        $call = $this->judge->calls[0];
        $this->assertStringContainsString('cook a new recipe', $call['questionText']);
        $this->assertCount(3, $call['dimensions']);
        $this->assertSame(['general' => 80, 'timing' => 90], $call['userAScores']);
        $this->assertSame(['general' => 95, 'timing' => 75], $call['userBScores']);
    }

    public function test_match_fires_when_judge_returns_match_true_and_persists_narrative(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->judge->push(new \App\Domain\Matches\Services\JudgmentResult(
            match: true,
            confidence: 0.87,
            matchedDimensions: ['general', 'turn_on'],
            divergingDimensions: ['timing'],
            narrative: 'You both feel adventurous, but Alice wants it sooner than Bob.',
        ));

        $this->actingAs($alice)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 95, 'turn_on' => 85],
        ])->assertCreated();
        $this->actingAs($bob)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 85, 'timing' => 55, 'turn_on' => 80],
        ])->assertCreated();

        $this->assertSame(1, EloquentStoredEvent::where('event_class', MutualPreferencesDetected::class)->count());

        $match = \DB::table('matches')->first();
        $this->assertNotNull($match);
        $this->assertSame('You both feel adventurous, but Alice wants it sooner than Bob.', $match->narrative);
        $this->assertSame(['general', 'turn_on'], json_decode($match->matched_dimensions, true));
        $this->assertSame(['timing'], json_decode($match->diverging_dimensions, true));
        $this->assertEquals(0.87, (float) $match->confidence);
    }

    public function test_match_does_not_fire_when_judge_returns_match_false(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->judge->push(\App\Domain\Matches\Services\JudgmentResult::noMatch('too divergent'));

        $this->actingAs($alice)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 90, 'turn_on' => 90],
        ])->assertCreated();
        $this->actingAs($bob)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 20, 'timing' => 30, 'turn_on' => 15],
        ])->assertCreated();

        $this->assertSame(0, EloquentStoredEvent::where('event_class', MutualPreferencesDetected::class)->count());
    }

    public function test_matches_endpoint_exposes_narrative_and_dimensions(): void
    {
        [$alice, $bob] = $this->registerCouple();

        $this->judge->push(new \App\Domain\Matches\Services\JudgmentResult(
            match: true,
            confidence: 0.9,
            matchedDimensions: ['general'],
            divergingDimensions: [],
            narrative: 'A test narrative for the reveal card.',
        ));

        $this->actingAs($alice)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 80, 'timing' => 80, 'turn_on' => 80],
        ])->assertCreated();
        $this->actingAs($bob)->postJson('/api/questionnaire/scores', [
            'question_uuid' => self::Q1,
            'scores' => ['general' => 90, 'timing' => 90, 'turn_on' => 90],
        ])->assertCreated();

        $response = $this->actingAs($alice)->getJson('/api/matches');
        $response->assertOk();
        $response->assertJsonPath('matches.0.narrative', 'A test narrative for the reveal card.');
        $response->assertJsonPath('matches.0.matched_dimensions', ['general']);
        $response->assertJsonPath('matches.0.diverging_dimensions', []);
        $response->assertJsonPath('matches.0.confidence', 0.9);
    }

    /** @return array{0: User, 1: User} */
    private function registerCouple(): array
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');

        $create = $this->actingAs($alice)->postJson('/api/couples/invitations');
        $code = $create->json('code');
        $this->actingAs($bob)->postJson("/api/couples/invitations/{$code}/accept")->assertOk();

        return [$alice->fresh(), $bob->fresh()];
    }

    private function createUser(?string $email = null): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'User '.uniqid(),
            'email' => $email ?? 'test'.uniqid().'@example.com',
            'password' => Hash::make('secret1234'),
        ]);
    }
}
