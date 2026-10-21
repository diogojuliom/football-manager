<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_simulations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('home_club_id');
            $table->foreignId('away_club_id');
            $table->uuid('request_id');
            $table->char('request_checksum', 64);
            $table->string('seed', 64);
            $table->string('engine_version', 32);
            $table->json('input');
            $table->json('result');
            $table->char('input_checksum', 64);
            $table->char('result_checksum', 64);
            $table->timestamps();
            $table->unique(['career_id', 'request_id']);
            foreach (['home_club_id', 'away_club_id'] as $club) {
                $table->foreign([$club, 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_simulations');
    }
};
