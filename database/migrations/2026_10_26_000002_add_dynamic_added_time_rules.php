<?php

use App\Domain\Football\Engine\V5\Simulation as V5Simulation;
use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation as V6Simulation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->string('match_engine_version', 32)->default(V6Simulation::VERSION);
            $table->boolean('dynamic_added_time_enabled')->default(true);
            $table->json('added_time_rules')->nullable();
        });
        DB::table('competition_rulesets')->whereNull('added_time_rules')->update([
            'added_time_rules' => json_encode(AddedTimeRules::DEFAULTS, JSON_THROW_ON_ERROR),
        ]);

        // Editions already in progress keep V5 and its published clock. New editions freeze V6.
        DB::table('competition_editions')->orderBy('id')->chunkById(100, function ($editions): void {
            foreach ($editions as $edition) {
                $rules = json_decode($edition->rules, true, 512, JSON_THROW_ON_ERROR);
                $rules += [
                    'match_engine_version' => V5Simulation::VERSION,
                    'dynamic_added_time_enabled' => false,
                    'added_time_rules' => AddedTimeRules::DEFAULTS,
                ];
                DB::table('competition_editions')->where('id', $edition->id)->update([
                    'rules' => json_encode($rules, JSON_THROW_ON_ERROR),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('competition_rulesets', function (Blueprint $table) {
            $table->dropColumn(['match_engine_version', 'dynamic_added_time_enabled', 'added_time_rules']);
        });
    }
};
