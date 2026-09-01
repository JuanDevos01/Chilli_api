<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class QuestionSeeder extends Seeder
{
    public function run(): void
    {
        $questions = [
            ['uuid' => '11111111-1111-1111-1111-111111111111', 'text' => '¿Te gustaría cocinar juntos una nueva receta este fin de semana?'],
            ['uuid' => '22222222-2222-2222-2222-222222222222', 'text' => '¿Te interesaría planear un viaje sorpresa para el próximo mes?'],
            ['uuid' => '33333333-3333-3333-3333-333333333333', 'text' => '¿Te gustaría tener una noche de películas cada semana?'],
        ];

        foreach ($questions as $q) {
            DB::table('questions')->updateOrInsert(
                ['uuid' => $q['uuid']],
                ['text' => $q['text'], 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }
}
