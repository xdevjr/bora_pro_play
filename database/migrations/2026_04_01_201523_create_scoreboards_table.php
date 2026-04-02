<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('scoreboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('draw_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->nullable();
            $table->string('left_team_name');
            $table->string('right_team_name');
            $table->unsignedInteger('left_score')->default(0);
            $table->unsignedInteger('right_score')->default(0);
            $table->boolean('is_quick')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['room_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scoreboards');
    }
};
