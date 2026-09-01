<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('couple_invitations', function (Blueprint $table) {
            $table->id();
            $table->uuid('couple_uuid')->unique();
            $table->uuid('inviter_uuid');
            $table->string('code', 12)->unique();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index('inviter_uuid');
        });

        Schema::create('couples', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('user_a_uuid');
            $table->uuid('user_b_uuid');
            $table->timestamp('linked_at');
            $table->timestamps();

            $table->index('user_a_uuid');
            $table->index('user_b_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('couples');
        Schema::dropIfExists('couple_invitations');
    }
};
