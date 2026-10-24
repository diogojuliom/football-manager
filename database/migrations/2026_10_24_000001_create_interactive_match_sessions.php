<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interactive_match_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('match_id')->unique();
            $table->string('engine_version', 20);
            $table->string('status', 20)->default('running');
            $table->boolean('paused')->default(true);
            $table->unsignedSmallInteger('minute')->default(0);
            $table->unsignedInteger('revision')->default(0);
            $table->json('input');
            $table->json('state');
            $table->json('meta');
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['id', 'career_id']);
            $table->index(['career_id', 'status']);
            $table->foreign(['match_id', 'career_id'])->references(['id', 'career_id'])->on('matches')->restrictOnDelete();
        });

        Schema::create('interactive_match_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('interactive_match_session_id');
            $table->foreignId('career_id');
            $table->uuid('request_id');
            $table->char('request_checksum', 64);
            $table->string('kind', 30);
            $table->unsignedInteger('result_revision');
            $table->json('response');
            $table->timestamps();
            $table->unique(['interactive_match_session_id', 'request_id'], 'interactive_request_id_unique');
            $table->foreign(['interactive_match_session_id', 'career_id'], 'interactive_request_session_foreign')
                ->references(['id', 'career_id'])->on('interactive_match_sessions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interactive_match_requests');
        Schema::dropIfExists('interactive_match_sessions');
    }
};
