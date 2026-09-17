<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            ['uuid' => '11111111-1111-1111-1111-111111111111', 'text' => 'Would you like to cook a new recipe together this weekend?'],
            ['uuid' => '22222222-2222-2222-2222-222222222222', 'text' => 'Would you be up for planning a surprise trip next month?'],
            ['uuid' => '33333333-3333-3333-3333-333333333333', 'text' => 'Would you like to have a movie night every week?'],
        ];

        foreach ($questions as $q) {
            DB::table('questions')->updateOrInsert(
                ['uuid' => $q['uuid']],
                ['text' => $q['text'], 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
