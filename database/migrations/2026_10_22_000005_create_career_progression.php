<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('careers', function (Blueprint $table) {
            $table->unsignedInteger('progression_revision')->default(0);
        });
        Schema::table('matches', function (Blueprint $table) {
            $table->unique(['id', 'career_id']);
        });
        Schema::table('career_seasons', function (Blueprint $table) {
            $table->date('ended_on')->nullable();
            $table->json('summary')->nullable();
        });
        Schema::create('career_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_checksum', 64);
            $table->string('kind', 40);
            $table->string('status', 20);
            $table->date('from_date');
            $table->string('from_state', 40);
            $table->date('target_date');
            $table->json('input');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['career_id', 'request_id']);
            $table->unique(['id', 'career_id']);
            $table->index(['career_id', 'status']);
        });
        Schema::create('career_processed_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_operation_id');
            $table->date('processed_on');
            $table->timestamps();
            $table->unique(['career_id', 'processed_on']);
            $table->foreign(['career_operation_id', 'career_id'])->references(['id', 'career_id'])->on('career_operations')->restrictOnDelete();
        });
        Schema::create('career_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_season_id');
            $table->string('event_key', 100);
            $table->string('kind', 40);
            $table->string('title');
            $table->date('scheduled_on');
            $table->boolean('requires_decision')->default(false);
            $table->string('status', 20)->default('pending');
            $table->date('processed_on')->nullable();
            $table->timestamps();
            $table->unique(['career_id', 'event_key']);
            $table->index(['career_id', 'status', 'scheduled_on']);
            $table->foreign(['career_season_id', 'career_id'])->references(['id', 'career_id'])->on('career_seasons')->restrictOnDelete();
        });
        Schema::create('official_match_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('match_id')->unique();
            $table->foreignId('career_operation_id');
            $table->date('played_on');
            $table->string('engine_version', 20);
            $table->string('seed', 64);
            $table->unsignedSmallInteger('home_goals');
            $table->unsignedSmallInteger('away_goals');
            $table->json('input');
            $table->json('result');
            $table->char('input_checksum', 64);
            $table->char('result_checksum', 64);
            $table->timestamps();
            $table->foreign(['match_id', 'career_id'])->references(['id', 'career_id'])->on('matches')->restrictOnDelete();
            $table->foreign(['career_operation_id', 'career_id'])->references(['id', 'career_id'])->on('career_operations')->restrictOnDelete();
        });

        // Existing careers also receive a dated season-end event, without advancing time.
        foreach (DB::table('competition_editions')->get() as $edition) {
            $last = DB::table('matches')->where('competition_edition_id', $edition->id)->max('scheduled_on');
            if ($last !== null) {
                DB::table('career_events')->insertOrIgnore([
                    'career_id' => $edition->career_id, 'career_season_id' => $edition->career_season_id,
                    'event_key' => 'season-end-'.$edition->career_season_id, 'kind' => 'season_end',
                    'title' => 'Encerramento da temporada', 'scheduled_on' => CarbonImmutable::parse($last)->addDay()->toDateString(),
                    'requires_decision' => false, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['official_match_results', 'career_events', 'career_processed_days', 'career_operations'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('career_seasons', fn (Blueprint $table) => $table->dropColumn(['ended_on', 'summary']));
        Schema::table('matches', fn (Blueprint $table) => $table->dropUnique(['id', 'career_id']));
        Schema::table('careers', fn (Blueprint $table) => $table->dropColumn('progression_revision'));
    }
};
