<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('text', 500);
            $table->timestamps();
        });

        Schema::create('user_answers', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_uuid');
            $table->uuid('question_uuid');
            $table->string('answer', 8);
            $table->timestamp('answered_at');
            $table->timestamps();

            $table->unique(['user_uuid', 'question_uuid']);
            $table->index('question_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_answers');
        Schema::dropIfExists('questions');
    }
};
