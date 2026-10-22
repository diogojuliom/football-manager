<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('career_stadiums', function (Blueprint $table) {
            $table->unique(['id', 'career_id', 'career_club_id'], 'stadium_career_club_unique');
        });

        Schema::create('stadium_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id');
            $table->foreignId('career_stadium_id');
            $table->foreignId('career_season_id');
            $table->string('type', 30);
            $table->string('rule_version', 20);
            $table->string('decision_source', 10);
            $table->unsignedBigInteger('cost_cents');
            $table->unsignedInteger('capacity_added')->default(0);
            $table->unsignedTinyInteger('infrastructure_added')->default(0);
            $table->date('started_on');
            $table->unsignedSmallInteger('duration_days');
            $table->date('due_on');
            $table->date('completed_on')->nullable();
            $table->string('status', 20);
            // NULL permits historical projects; 1 is unique while a project is active.
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->timestamps();
            $table->unique(['career_stadium_id', 'active_slot'], 'stadium_one_active_project');
            $table->index(['career_id', 'status', 'due_on'], 'stadium_projects_due_index');
            $table->foreign(['career_stadium_id', 'career_id', 'career_club_id'], 'stadium_project_stadium_fk')
                ->references(['id', 'career_id', 'career_club_id'])->on('career_stadiums')->restrictOnDelete();
            $table->foreign(['career_club_id', 'career_id'], 'stadium_project_club_fk')
                ->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
            $table->foreign(['career_season_id', 'career_id'], 'stadium_project_season_fk')
                ->references(['id', 'career_id'])->on('career_seasons')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stadium_projects');
        Schema::table('career_stadiums', function (Blueprint $table) {
            $table->dropUnique('stadium_career_club_unique');
        });
    }
};
