<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->json('dimensions')->nullable()->after('text');
        });

        Schema::create('user_question_scores', function (Blueprint $table) {
            $table->id();
            $table->uuid('user_uuid');
            $table->uuid('question_uuid');
            $table->json('scores');
            $table->timestamp('scored_at');
            $table->timestamp('retracted_at')->nullable();
            $table->timestamps();

            $table->unique(['user_uuid', 'question_uuid']);
            $table->index('question_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_question_scores');
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('dimensions');
        });
    }
};
