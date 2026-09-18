<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            [
                'uuid' => '11111111-1111-1111-1111-111111111111',
                'text' => 'Would you like to cook a new recipe together this weekend?',
                'dimensions' => [
                    ['name' => 'general', 'low_label' => 'not really', 'high_label' => 'yes please'],
                    ['name' => 'timing', 'low_label' => 'some other week', 'high_label' => 'this weekend'],
                    ['name' => 'turn_on', 'low_label' => 'leaves me cold', 'high_label' => 'turns me on'],
                ],
            ],
            [
                'uuid' => '22222222-2222-2222-2222-222222222222',
                'text' => 'Would you be up for planning a surprise trip next month?',
                'dimensions' => [
                    ['name' => 'general', 'low_label' => 'rather stay home', 'high_label' => "let's go"],
                    ['name' => 'intensity', 'low_label' => 'chill weekend', 'high_label' => 'epic getaway'],
                    ['name' => 'turn_on', 'low_label' => 'leaves me cold', 'high_label' => 'turns me on'],
                ],
            ],
            [
                'uuid' => '33333333-3333-3333-3333-333333333333',
                'text' => 'Would you like to have a movie night every week?',
                'dimensions' => [
                    ['name' => 'general', 'low_label' => 'not for me', 'high_label' => 'love it'],
                    ['name' => 'frequency', 'low_label' => 'once in a while', 'high_label' => 'every week'],
                    ['name' => 'turn_on', 'low_label' => 'leaves me cold', 'high_label' => 'turns me on'],
                ],
            ],
        ];

        foreach ($questions as $q) {
            DB::table('questions')->updateOrInsert(
                ['uuid' => $q['uuid']],
                [
                    'text' => $q['text'],
                    'dimensions' => json_encode($q['dimensions']),
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }
}
