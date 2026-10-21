<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_database_versions', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();
            $table->string('name');
            $table->char('checksum', 64);
            $table->timestamps();
        });

        Schema::create('competition_rulesets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->string('code', 64);
            $table->string('format', 32);
            $table->unsignedSmallInteger('club_count');
            $table->unsignedTinyInteger('legs');
            $table->unsignedSmallInteger('round_count');
            $table->unsignedSmallInteger('match_count');
            $table->unsignedTinyInteger('points_for_win');
            $table->unsignedTinyInteger('points_for_draw');
            $table->unsignedTinyInteger('points_for_loss');
            $table->json('tiebreakers');
            $table->timestamps();
            $table->unique(['game_database_version_id', 'code'], 'ruleset_version_code_unique');
            $table->unique(['id', 'game_database_version_id'], 'ruleset_id_version_unique');
        });

        Schema::create('competition_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_ruleset_id');
            $table->string('code', 64);
            $table->string('name');
            $table->timestamps();
            $table->unique(['game_database_version_id', 'code'], 'competition_version_code_unique');
            $table->unique(['id', 'game_database_version_id'], 'competition_id_version_unique');
            $table->foreign(['competition_ruleset_id', 'game_database_version_id'], 'competition_ruleset_version_fk')
                ->references(['id', 'game_database_version_id'])->on('competition_rulesets')->restrictOnDelete();
        });

        Schema::create('club_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('competition_template_id');
            $table->string('code', 64);
            $table->string('name');
            $table->string('short_name', 8);
            $table->string('city');
            $table->char('primary_color', 7);
            $table->char('secondary_color', 7);
            $table->bigInteger('initial_balance_cents');
            $table->unsignedInteger('default_ticket_price_cents');
            $table->unsignedInteger('potential_supporters');
            $table->unsignedTinyInteger('initial_loyalty');
            $table->unsignedTinyInteger('initial_engagement');
            $table->timestamps();
            $table->unique(['game_database_version_id', 'code'], 'club_version_code_unique');
            $table->unique(['id', 'game_database_version_id'], 'club_id_version_unique');
            $table->foreign(['competition_template_id', 'game_database_version_id'], 'club_competition_version_fk')
                ->references(['id', 'game_database_version_id'])->on('competition_templates')->restrictOnDelete();
        });

        Schema::create('stadium_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('club_template_id')->unique();
            $table->string('name');
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('available_capacity');
            $table->unsignedTinyInteger('infrastructure_level');
            $table->unsignedInteger('fixed_operating_cost_cents');
            $table->unsignedInteger('variable_cost_per_supporter_cents');
            $table->timestamps();
            $table->foreign(['club_template_id', 'game_database_version_id'], 'stadium_club_version_fk')
                ->references(['id', 'game_database_version_id'])->on('club_templates')->restrictOnDelete();
        });

        Schema::create('player_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_database_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('club_template_id');
            $table->string('code', 64);
            $table->string('name');
            $table->unsignedTinyInteger('age');
            $table->string('primary_position', 3);
            $table->json('secondary_positions');
            foreach (['defense', 'tackling', 'passing', 'creativity', 'finishing', 'speed', 'stamina', 'discipline', 'composure', 'set_pieces', 'goalkeeping', 'initial_fitness', 'initial_morale'] as $attribute) {
                $table->unsignedTinyInteger($attribute);
            }
            $table->timestamps();
            $table->unique(['game_database_version_id', 'code'], 'player_version_code_unique');
            $table->foreign(['club_template_id', 'game_database_version_id'], 'player_club_version_fk')
                ->references(['id', 'game_database_version_id'])->on('club_templates')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        foreach (['player_templates', 'stadium_templates', 'club_templates', 'competition_templates', 'competition_rulesets', 'game_database_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
