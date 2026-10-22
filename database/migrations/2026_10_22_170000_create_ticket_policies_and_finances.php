<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_ticket_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('match_id')->unique();
            $table->foreignId('home_club_id');
            $table->unsignedInteger('price_cents');
            $table->timestamps();
            $table->foreign(['match_id', 'career_id', 'home_club_id'], 'ticket_policy_home_match_fk')
                ->references(['id', 'career_id', 'home_club_id'])->on('matches')->restrictOnDelete();
            $table->foreign(['home_club_id', 'career_id'], 'ticket_policy_home_club_fk')
                ->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
        });

        Schema::create('career_financial_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('career_id')->constrained()->restrictOnDelete();
            $table->foreignId('career_club_id');
            $table->foreignId('career_season_id');
            $table->foreignId('match_id')->nullable();
            $table->string('source_key', 100);
            $table->string('kind', 30);
            $table->date('occurred_on');
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->timestamps();
            $table->unique(['career_id', 'source_key'], 'finance_career_source_unique');
            $table->unique(['match_id', 'kind'], 'finance_match_kind_unique');
            $table->index(['career_id', 'career_club_id', 'career_season_id', 'occurred_on'], 'finance_club_season_date_index');
            $table->foreign(['career_club_id', 'career_id'], 'finance_club_fk')
                ->references(['id', 'career_id'])->on('career_clubs')->restrictOnDelete();
            $table->foreign(['career_season_id', 'career_id'], 'finance_season_fk')
                ->references(['id', 'career_id'])->on('career_seasons')->restrictOnDelete();
            $table->foreign(['match_id', 'career_id', 'career_club_id'], 'finance_home_match_fk')
                ->references(['id', 'career_id', 'home_club_id'])->on('matches')->restrictOnDelete();
            $table->foreign('match_id', 'finance_attendance_fk')->references('match_id')->on('match_attendances')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('career_financial_transactions');
        Schema::dropIfExists('match_ticket_policies');
    }
};
