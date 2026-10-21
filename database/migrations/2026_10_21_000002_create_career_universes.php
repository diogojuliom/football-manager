<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('careers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('coach_name', 100);
            $table->date('current_date');
            $table->string('state', 40);
            $table->unsignedInteger('current_season_number');
            $table->uuid('creation_key');
            $table->char('creation_checksum', 64);
            $table->timestamps();
            $table->unique(['user_id', 'creation_key']);
        });
        Schema::create('career_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('role', 20);
            $table->timestamps();
            $table->unique(['career_id', 'user_id']);
        });
        Schema::create('career_clubs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('club_template_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->string('short_name', 8);
            $table->string('city');
            $table->char('primary_color', 7);
            $table->char('secondary_color', 7);
            $table->bigInteger('initial_balance_cents');
            $table->bigInteger('balance_cents');
            $table->unsignedInteger('default_ticket_price_cents');
            $table->timestamps();
            $table->unique(['career_id', 'code']);
            $table->unique(['career_id', 'club_template_id']);
            $table->unique(['id', 'career_id']);
        });
        Schema::create('career_club_controllers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id')->unique();
            $table->foreignId('user_id')->nullable();
            $table->string('type', 10);
            $table->timestamps();
            $table->unique(['career_id', 'user_id']);
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
            $table->foreign(['career_id', 'user_id'])->references(['career_id', 'user_id'])->on('career_memberships')->restrictOnDelete();
        });
        Schema::create('career_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id');
            $table->foreignId('player_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedTinyInteger('age');
            $table->string('primary_position', 3);
            $table->json('secondary_positions');
            foreach (['defense', 'tackling', 'passing', 'creativity', 'finishing', 'speed', 'stamina', 'discipline', 'composure', 'set_pieces', 'goalkeeping', 'fitness', 'morale'] as $attribute) {
                $table->unsignedTinyInteger($attribute);
            }
            $table->timestamps();
            $table->unique(['career_id', 'player_template_id']);
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });
        Schema::create('career_stadiums', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id')->unique();
            $table->foreignId('stadium_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('available_capacity');
            $table->unsignedTinyInteger('infrastructure_level');
            $table->unsignedInteger('fixed_operating_cost_cents');
            $table->unsignedInteger('variable_cost_per_supporter_cents');
            $table->timestamps();
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });
        Schema::create('career_club_support', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id')->unique();
            $table->unsignedInteger('potential_supporters');
            $table->unsignedTinyInteger('loyalty');
            $table->unsignedTinyInteger('engagement');
            $table->timestamps();
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });
        Schema::create('career_seasons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('number');
            $table->date('starts_on');
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['career_id', 'number']);
            $table->unique(['id', 'career_id']);
        });
        Schema::create('competition_editions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_season_id');
            $table->foreignId('competition_template_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->json('rules');
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['career_season_id', 'competition_template_id'], 'editions_season_template_unique');
            $table->unique(['id', 'career_id']);
            $table->foreign(['career_season_id', 'career_id'])->references(['id', 'career_id'])->on('career_seasons')->restrictOnDelete();
        });
        Schema::create('competition_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_edition_id');
            $table->foreignId('career_club_id');
            $table->timestamps();
            $table->unique(['competition_edition_id', 'career_club_id']);
            $table->foreign(['competition_edition_id', 'career_id'])->references(['id', 'career_id'])->on('competition_editions')->restrictOnDelete();
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_edition_id');
            $table->foreignId('home_club_id');
            $table->foreignId('away_club_id');
            $table->unsignedSmallInteger('round');
            $table->date('scheduled_on');
            $table->string('status', 20);
            $table->timestamps();
            $table->unique(['competition_edition_id', 'home_club_id', 'away_club_id'], 'matches_ordered_pair_unique');
            $table->index(['career_id', 'scheduled_on']);
            $table->foreign(['competition_edition_id', 'career_id'])->references(['id', 'career_id'])->on('competition_editions')->restrictOnDelete();
            foreach (['home_club_id', 'away_club_id'] as $club) {
                $table->foreign([$club, 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
                $table->foreign(['competition_edition_id', $club])->references(['competition_edition_id', 'career_club_id'])->on('competition_entries')->restrictOnDelete();
            }
        });
    }

    public function down(): void
    {
        foreach (['matches', 'competition_entries', 'competition_editions', 'career_seasons', 'career_club_support', 'career_stadiums', 'career_players', 'career_club_controllers', 'career_clubs', 'career_memberships', 'careers'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
