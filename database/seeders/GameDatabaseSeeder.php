<?php

namespace Database\Seeders;

use App\Domain\Football\Engine\V6\AddedTimeRules;
use App\Domain\Football\Engine\V6\Simulation;
use App\Domain\GameDatabase\Models\ClubTemplate;
use App\Domain\GameDatabase\Models\CompetitionRuleset;
use App\Domain\GameDatabase\Models\CompetitionTemplate;
use App\Domain\GameDatabase\Models\GameDatabaseVersion;
use App\Domain\GameDatabase\Models\PlayerTemplate;
use App\Domain\GameDatabase\Models\StadiumTemplate;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class GameDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $data = require database_path('seeders/data/v1.php');
        $checksum = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        DB::transaction(function () use ($data, $checksum): void {
            $version = GameDatabaseVersion::firstOrCreate(
                ['version' => $data['version']],
                ['name' => $data['name'], 'checksum' => $checksum],
            );

            // Serialize seeds of the same release before writing its children.
            $version = GameDatabaseVersion::whereKey($version->id)->lockForUpdate()->firstOrFail();

            if (! hash_equals($version->checksum, $checksum)) {
                throw new LogicException('Dados publicados foram alterados. Crie uma nova versão dos dados-base.');
            }

            $versionKey = ['game_database_version_id' => $version->id];
            $ruleset = CompetitionRuleset::firstOrCreate(
                $versionKey + ['code' => $data['ruleset']['code']],
                $data['ruleset'] + [
                    'match_engine_version' => Simulation::VERSION,
                    'dynamic_added_time_enabled' => true,
                    'added_time_rules' => AddedTimeRules::DEFAULTS,
                ],
            );
            $competition = CompetitionTemplate::firstOrCreate(
                $versionKey + ['code' => $data['competition']['code']],
                $data['competition'] + ['competition_ruleset_id' => $ruleset->id],
            );

            foreach ($data['clubs'] as $entry) {
                $club = ClubTemplate::firstOrCreate(
                    $versionKey + ['code' => $entry['club']['code']],
                    $entry['club'] + ['competition_template_id' => $competition->id],
                );
                StadiumTemplate::firstOrCreate(
                    ['club_template_id' => $club->id],
                    $versionKey + $entry['stadium'],
                );
                foreach ($entry['players'] as $player) {
                    PlayerTemplate::firstOrCreate(
                        $versionKey + ['code' => $player['code']],
                        $player + ['club_template_id' => $club->id],
                    );
                }
            }
        });
    }
}
