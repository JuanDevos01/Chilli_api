<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('couple_uuid');
            $table->uuid('question_uuid');
            $table->uuid('user_a_uuid');
            $table->uuid('user_b_uuid');
            $table->timestamp('detected_at');
            $table->timestamps();

            $table->unique(['couple_uuid', 'question_uuid']);
            $table->index('couple_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
