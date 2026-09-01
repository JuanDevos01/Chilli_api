<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class MutualMatchGoldenPathTest extends TestCase
{
    use RefreshDatabase;

    private const Q1 = '11111111-1111-1111-1111-111111111111';
    private const Q2 = '22222222-2222-2222-2222-222222222222';
    private const Q3 = '33333333-3333-3333-3333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionSeeder::class);
    }

    public function test_two_users_register_pair_answer_and_see_mutual_match(): void
    {
        [$alice, $bob] = $this->registerCoupleViaApi();

        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');

        $aliceMatches = $this->actingAs($alice)->getJson('/api/matches');
        $aliceMatches->assertOk();
        $aliceMatches->assertJsonCount(1, 'matches');
        $aliceMatches->assertJsonPath('matches.0.question_uuid', self::Q1);

        $bobMatches = $this->actingAs($bob)->getJson('/api/matches');
        $bobMatches->assertJsonCount(1, 'matches');
        $bobMatches->assertJsonPath('matches.0.question_uuid', self::Q1);
    }

    public function test_no_match_when_only_one_answers_yes(): void
    {
        [$alice, $bob] = $this->registerCoupleViaApi();

        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'no');

        $this->actingAs($alice)->getJson('/api/matches')->assertJsonCount(0, 'matches');
        $this->actingAs($bob)->getJson('/api/matches')->assertJsonCount(0, 'matches');
    }

    public function test_only_yes_yes_pairs_produce_matches(): void
    {
        [$alice, $bob] = $this->registerCoupleViaApi();

        $this->answer($alice, self::Q1, 'yes');
        $this->answer($alice, self::Q2, 'yes');
        $this->answer($alice, self::Q3, 'no');

        $this->answer($bob, self::Q1, 'no');
        $this->answer($bob, self::Q2, 'yes');
        $this->answer($bob, self::Q3, 'no');

        $matches = $this->actingAs($alice)->getJson('/api/matches')->json('matches');
        $this->assertCount(1, $matches);
        $this->assertSame(self::Q2, $matches[0]['question_uuid']);
    }

    public function test_partner_answer_is_never_leaked_in_match_response(): void
    {
        [$alice, $bob] = $this->registerCoupleViaApi();

        $this->answer($alice, self::Q1, 'yes');
        $this->answer($bob, self::Q1, 'yes');

        $response = $this->actingAs($alice)->getJson('/api/matches');
        $json = $response->getContent();

        $this->assertStringNotContainsString('user_a_uuid', $json);
        $this->assertStringNotContainsString('user_b_uuid', $json);
        $this->assertStringNotContainsString('"yes"', $json);
        $this->assertStringNotContainsString('"no"', $json);
    }

    private function registerCoupleViaApi(): array
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
