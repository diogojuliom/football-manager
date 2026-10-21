<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_substitutes')->default(7);
        });
        // Freeze the new rule for existing editions instead of reading mutable template rules.
        DB::table('competition_editions')->orderBy('id')->chunkById(100, function ($editions) {
            foreach ($editions as $edition) {
                $rules = json_decode($edition->rules, true, 512, JSON_THROW_ON_ERROR);
                if (! array_key_exists('max_substitutes', $rules)) {
                    $rules['max_substitutes'] = 7;
                    DB::table('competition_editions')->where('id', $edition->id)->update(['rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
                }
            }
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->date('injured_until')->nullable();
            $table->unsignedSmallInteger('suspended_matches')->default(0);
            $table->unique(['id', 'career_id', 'career_club_id'], 'career_player_membership_unique');
        });
        Schema::create('career_lineups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id')->unique();
            $table->string('formation', 10);
            $table->string('mentality', 20);
            $table->string('pressure', 20);
            $table->string('tempo', 20);
            $table->string('build_up', 20);
            $table->unsignedInteger('revision');
            $table->timestamps();
            $table->unique(['id', 'career_id', 'career_club_id'], 'lineup_membership_unique');
            $table->foreign(['career_club_id', 'career_id'])->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });
        Schema::create('career_lineup_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_lineup_id');
            $table->foreignId('career_id');
            $table->foreignId('career_club_id');
            $table->foreignId('career_player_id');
            $table->string('unit', 10);
            $table->unsignedTinyInteger('slot');
            $table->timestamps();
            $table->unique(['career_lineup_id', 'career_player_id'], 'lineup_player_unique');
            $table->unique(['career_lineup_id', 'unit', 'slot'], 'lineup_slot_unique');
            $table->foreign(['career_lineup_id', 'career_id', 'career_club_id'], 'lineup_membership_fk')
                ->references(['id', 'career_id', 'career_club_id'])->on('career_lineups')->restrictOnDelete();
            $table->foreign(['career_player_id', 'career_id', 'career_club_id'], 'lineup_player_membership_fk')
                ->references(['id', 'career_id', 'career_club_id'])->on('career_players')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_lineup_players');
        Schema::dropIfExists('career_lineups');
        Schema::table('career_players', function (Blueprint $table) {
            $table->dropUnique('career_player_membership_unique');
            $table->dropColumn(['injured_until', 'suspended_matches']);
        });
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->dropColumn('max_substitutes');
        });
        // Historical edition snapshots retain their rule value when rolling back this feature.
    }
};
