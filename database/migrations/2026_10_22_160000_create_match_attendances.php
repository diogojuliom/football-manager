<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->unique(['id', 'career_id', 'home_club_id'], 'matches_attendance_home_unique');
        });
        Schema::create('match_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('match_id')->unique();
            $table->foreignId('home_club_id');
            $table->string('stadium_name');
            $table->date('played_on');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('expected_demand');
            $table->unsignedInteger('forecast');
            $table->unsignedInteger('realized');
            $table->unsignedSmallInteger('occupancy_bps');
            $table->unsignedTinyInteger('importance');
            $table->unsignedInteger('ticket_price_cents');
            $table->string('algorithm_version', 20);
            $table->json('snapshot');
            $table->timestamps();
            $table->foreign(['match_id', 'career_id', 'home_club_id'], 'attendance_match_home_foreign')
                ->references(['id', 'career_id', 'home_club_id'])->on('matches')->restrictOnDelete();
            $table->foreign('match_id')->references('match_id')->on('official_match_results')->restrictOnDelete();
            $table->foreign(['home_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
            $table->index(['career_id', 'home_club_id', 'played_on'], 'attendances_club_date_index');
        });
        Schema::table('match_simulations', function (Blueprint $table) {
            $table->json('attendance_plan')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('match_simulations', fn (Blueprint $table) => $table->dropColumn('attendance_plan'));
        Schema::dropIfExists('match_attendances');
        Schema::table('matches', fn (Blueprint $table) => $table->dropUnique('matches_attendance_home_unique'));
    }
};
