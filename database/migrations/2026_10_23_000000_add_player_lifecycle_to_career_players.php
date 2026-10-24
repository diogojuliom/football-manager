<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL may use the unique index below to enforce the career_id foreign key.
        // Keep an explicit supporting index while replacing that unique constraint.
        Schema::table('career_players', function (Blueprint $table) {
            $table->index('career_id', 'career_players_lifecycle_career_index');
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->dropUnique('career_players_career_id_player_template_id_unique');
            $table->string('origin_key', 100)->nullable();
            $table->date('retired_on')->nullable();
            $table->unsignedInteger('joined_season_number')->default(1);
        });
        Schema::table('career_seasons', function (Blueprint $table) {
            $table->json('transition_summary')->nullable();
        });
        DB::table('career_players')->orderBy('id')->chunkById(500, function ($players): void {
            foreach ($players as $player) {
                DB::table('career_players')->where('id', $player->id)->update(['origin_key' => 'template:'.$player->player_template_id]);
            }
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->unique(['career_id', 'origin_key'], 'career_player_origin_unique');
            $table->index(['career_id', 'career_club_id', 'retired_on'], 'career_active_players_index');
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->dropIndex('career_players_lifecycle_career_index');
        });
    }

    public function down(): void
    {
        $generated = DB::table('career_players')->where('origin_key', 'like', 'academy:%')->pluck('id');
        DB::table('career_lineup_players')->whereIn('career_player_id', $generated)->delete();
        DB::table('career_players')->whereIn('id', $generated)->delete();
        Schema::table('career_seasons', function (Blueprint $table) {
            $table->dropColumn('transition_summary');
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->index('career_id', 'career_players_lifecycle_career_index');
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->dropUnique('career_player_origin_unique');
            $table->dropIndex('career_active_players_index');
            $table->dropColumn(['origin_key', 'retired_on', 'joined_season_number']);
            $table->unique(['career_id', 'player_template_id']);
        });
        Schema::table('career_players', function (Blueprint $table) {
            $table->dropIndex('career_players_lifecycle_career_index');
        });
    }
};
