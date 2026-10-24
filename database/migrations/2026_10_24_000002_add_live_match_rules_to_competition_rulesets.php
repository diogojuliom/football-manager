<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->unsignedTinyInteger('max_substitutions')->default(3);
            $table->unsignedTinyInteger('max_substitution_windows')->default(3);
        });
    }

    public function down(): void
    {
        Schema::table('competition_rulesets', fn (Blueprint $table) => $table->dropColumn(['max_substitutions', 'max_substitution_windows']));
    }
};
