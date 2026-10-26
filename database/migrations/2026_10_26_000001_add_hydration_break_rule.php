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
            $table->boolean('hydration_breaks_enabled')->default(true);
        });

        // Existing editions are immutable snapshots. They keep the rules under which their
        // already scheduled and historical matches were created; only new editions opt in.
        DB::table('competition_editions')->orderBy('id')->chunkById(100, function ($editions): void {
            foreach ($editions as $edition) {
                $rules = json_decode($edition->rules, true, 512, JSON_THROW_ON_ERROR);
                if (! array_key_exists('hydration_breaks_enabled', $rules)) {
                    $rules['hydration_breaks_enabled'] = false;
                    DB::table('competition_editions')->where('id', $edition->id)
                        ->update(['rules' => json_encode($rules, JSON_THROW_ON_ERROR)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->dropColumn('hydration_breaks_enabled');
        });

        // Edition snapshots deliberately retain the explicit historical rule on rollback.
    }
};
