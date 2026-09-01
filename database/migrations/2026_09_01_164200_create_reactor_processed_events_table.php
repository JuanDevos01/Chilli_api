<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reactor_processed_events', function (Blueprint $table) {
            $table->id();
            $table->string('reactor');
            $table->string('dedup_key');
            $table->timestamps();

            $table->unique(['reactor', 'dedup_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reactor_processed_events');
    }
};
