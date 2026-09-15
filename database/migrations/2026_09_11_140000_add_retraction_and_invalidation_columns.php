<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_answers', function (Blueprint $table) {
            $table->timestamp('retracted_at')->nullable()->after('answered_at');
        });

        Schema::table('matches', function (Blueprint $table) {
            $table->timestamp('invalidated_at')->nullable()->after('detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('invalidated_at');
        });

        Schema::table('user_answers', function (Blueprint $table) {
            $table->dropColumn('retracted_at');
        });
    }
};
