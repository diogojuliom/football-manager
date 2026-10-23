<?php

namespace App\Application\Careers;

use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerClub;
use App\Domain\Career\Models\CareerPlayer;
use App\Domain\GameDatabase\Models\PlayerTemplate;
use App\Domain\Tactics\Models\CareerLineup;
use Illuminate\Support\Facades\DB;

class PlayerLifecycle
{
    private const POSITION_CYCLE = ['CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'];

    public function process(Career $career, int $seasonNumber): array
    {
        $retired = 0;
        $generated = 0;
        foreach ($career->clubs()->orderBy('id')->get() as $club) {
            $retiredIds = [];
            foreach ($club->players()->orderBy('id')->lockForUpdate()->get() as $player) {
                $newAge = $player->age + 1;
                if ($newAge >= (int) config('careers.retirement_age')) {
                    $player->update(['age' => $newAge, 'retired_on' => $career->current_date->toDateString(),
                        'fitness' => 100, 'injured_until' => null, 'suspended_matches' => 0]);
                    $retiredIds[] = $player->id;
                    $retired++;

                    continue;
                }
                $player->update($this->evolution($player, $newAge) + [
                    'age' => $newAge, 'fitness' => 100, 'morale' => max(50, (int) $player->morale),
                    'injured_until' => null, 'suspended_matches' => 0,
                ]);
            }
            if ($retiredIds) {
                $lineupId = CareerLineup::where('career_id', $career->id)->where('career_club_id', $club->id)
                    ->whereHas('selections', fn ($query) => $query->whereIn('career_player_id', $retiredIds))->value('id');
                if ($lineupId) {
                    DB::table('career_lineup_players')->where('career_lineup_id', $lineupId)->delete();
                    CareerLineup::whereKey($lineupId)->delete();
                }
            }
            $generated += $this->replenish($career, $club, $seasonNumber);
        }

        return ['policy_version' => '1.0.0', 'retired_players' => $retired, 'generated_players' => $generated];
    }

    private function evolution(CareerPlayer $player, int $newAge): array
    {
        $changes = [];
        foreach (PlayerTemplate::ATTRIBUTES as $attribute) {
            $delta = 0;
            if ($newAge <= 23 && (($player->id + strlen($attribute)) % 3 !== 0)) {
                $delta = 1;
            } elseif ($newAge >= 34 && in_array($attribute, ['speed', 'stamina'], true)) {
                $delta = -2;
            } elseif ($newAge >= 30 && in_array($attribute, ['speed', 'stamina'], true)) {
                $delta = -1;
            } elseif ($newAge >= 30 && $attribute === 'composure') {
                $delta = 1;
            }
            $changes[$attribute] = max(0, min(100, (int) $player->{$attribute} + $delta));
        }

        return $changes;
    }

    private function replenish(Career $career, CareerClub $club, int $seasonNumber): int
    {
        $created = 0;
        $minimum = (int) config('careers.minimum_squad_size');
        $goalkeepers = (int) config('careers.minimum_goalkeepers');
        $templateId = $club->allPlayers()->whereNotNull('player_template_id')->value('player_template_id');
        while ($club->players()->count() < $minimum || $club->players()->where('primary_position', 'GK')->count() < $goalkeepers) {
            $slot = $created + 1;
            $position = $club->players()->where('primary_position', 'GK')->count() < $goalkeepers
                ? 'GK' : self::POSITION_CYCLE[($club->id + $seasonNumber + $slot) % count(self::POSITION_CYCLE)];
            $origin = "academy:{$seasonNumber}:{$club->id}:{$slot}";
            $attributes = [];
            foreach (PlayerTemplate::ATTRIBUTES as $index => $attribute) {
                $value = 52 + (($club->id * 17 + $seasonNumber * 13 + $slot * 7 + $index * 5) % 17);
                if (($position === 'GK') === ($attribute === 'goalkeeping')) {
                    $value += 18;
                } elseif ($attribute === 'goalkeeping') {
                    $value = 5 + (($club->id + $slot + $index) % 10);
                }
                $attributes[$attribute] = min(100, $value);
            }
            $club->allPlayers()->create($attributes + [
                'career_id' => $career->id, 'player_template_id' => $templateId, 'origin_key' => $origin,
                'name' => 'Jovem '.$club->short_name.' '.str_pad((string) $seasonNumber, 2, '0', STR_PAD_LEFT).str_pad((string) $slot, 2, '0', STR_PAD_LEFT),
                'age' => 18, 'primary_position' => $position, 'secondary_positions' => [],
                'fitness' => 100, 'morale' => 70, 'joined_season_number' => $seasonNumber,
            ]);
            $created++;
        }

        return $created;
    }
}
