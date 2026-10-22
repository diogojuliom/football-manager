<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('matches', fn (Blueprint $table) => $table->index(['career_id', 'status', 'scheduled_on', 'id'], 'matches_career_status_date_index'));
    }

    public function down(): void
    {
        Schema::table('matches', fn (Blueprint $table) => $table->dropIndex('matches_career_status_date_index'));
    }
};
