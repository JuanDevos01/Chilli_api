<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->text('narrative')->nullable()->after('user_b_uuid');
            $table->json('matched_dimensions')->nullable()->after('narrative');
            $table->json('diverging_dimensions')->nullable()->after('matched_dimensions');
            $table->decimal('confidence', 3, 2)->nullable()->after('diverging_dimensions');
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn(['narrative', 'matched_dimensions', 'diverging_dimensions', 'confidence']);
        });
    }
};
