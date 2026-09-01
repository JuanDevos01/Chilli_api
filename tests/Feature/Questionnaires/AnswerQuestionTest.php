<?php

namespace Tests\Feature\Questionnaires;

use App\Domain\Questionnaires\Events\QuestionAnswered;
use App\Models\User;
use Database\Seeders\QuestionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\EventSourcing\StoredEvents\Models\EloquentStoredEvent;
use Tests\TestCase;

class AnswerQuestionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(QuestionSeeder::class);
    }

    public function test_index_returns_seeded_questions(): void
    {
        $user = $this->createUser();

        $response = $this->actingAs($user)->getJson('/api/questionnaire');

        $response->assertOk();
        $response->assertJsonStructure(['questions' => [['uuid', 'text']]]);
        $this->assertCount(3, $response->json('questions'));
    }

    public function test_answer_records_event_with_encrypted_payload(): void
    {
        $user = $this->createUser();
        $questionUuid = '11111111-1111-1111-1111-111111111111';

        $this->actingAs($user)->postJson('/api/questionnaire/answers', [
            'question_uuid' => $questionUuid,
            'answer' => 'yes',
        ])->assertCreated();

        $this->assertDatabaseHas('user_answers', [
            'user_uuid' => $user->uuid,
            'question_uuid' => $questionUuid,
            'answer' => 'yes',
        ]);

        $storedEvent = EloquentStoredEvent::query()
            ->where('event_class', QuestionAnswered::class)
            ->where('aggregate_uuid', $user->uuid)
            ->firstOrFail();

        $properties = $storedEvent->event_properties;
        $this->assertArrayHasKey('answer', $properties);
        $this->assertNotSame('yes', $properties['answer'], 'answer should be encrypted at rest');
        $this->assertSame('yes', Crypt::decryptString($properties['answer']));
    }

    public function test_cannot_answer_same_question_twice(): void
    {
        $user = $this->createUser();
        $questionUuid = '11111111-1111-1111-1111-111111111111';
        $payload = ['question_uuid' => $questionUuid, 'answer' => 'yes'];

        $this->actingAs($user)->postJson('/api/questionnaire/answers', $payload)->assertCreated();
        $second = $this->actingAs($user)->postJson('/api/questionnaire/answers', $payload);
        $second->assertStatus(500);
    }

    private function createUser(): User
    {
        return User::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test',
            'email' => 'test'.uniqid().'@example.com',
            'password' => Hash::make('secret1234'),
        ]);
    }
}
