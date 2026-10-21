<?php

namespace App\Domain\Football\Engine\V1;

use App\Domain\Football\Engine\SeededRandom;
use App\Domain\Tactics\Tactics;

final class Simulation
{
    public const VERSION = '1.0.0';

    private SeededRandom $random;

    private array $teams = [];

    private array $events = [];

    private int $minute = 0;

    private float $homeBonus;

    public function __construct(private array $input)
    {
        $this->random = new SeededRandom(self::VERSION.':'.$input['seed']);
        $context = $input['home_context'];
        $occupancy = $context['attendance'] === null ? 0 : $context['attendance'] / $context['capacity'];
        $this->homeBonus = $context['neutral'] ? 0 : min(0.05, 0.03 + 0.02 * $occupancy * $context['engagement'] / 100);
        foreach (['home', 'away'] as $side) {
            $team = $input[$side];
            $players = [];
            foreach ([...$team['starters'], ...$team['substitutes']] as $player) {
                $players[$player['id']] = $player + ['energy' => $player['fitness'] * 100, 'stats' => [
                    'player_id' => $player['id'], 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'shots' => 0,
                    'shots_on_target' => 0, 'xg_millis' => 0, 'fouls' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'saves' => 0,
                ]];
            }
            $this->teams[$side] = [
                'name' => $team['name'], 'tactics' => $team['tactics'], 'players' => $players,
                'active' => array_column($team['starters'], 'id'), 'bench' => array_column($team['substitutes'], 'id'),
                'stats' => ['goals' => 0, 'assists' => 0, 'possession_ticks' => 0, 'opportunities' => 0, 'shots' => 0, 'shots_on_target' => 0,
                    'xg_millis' => 0, 'fouls' => 0, 'yellow_cards' => 0, 'red_cards' => 0, 'saves' => 0, 'substitutions' => 0],
            ];
        }
    }

    public function run(): array
    {
        $this->event('kickoff');
        $status = 'finished';
        for ($this->minute = 1; $this->minute <= 90; $this->minute++) {
            foreach (['home', 'away'] as $side) {
                $this->substitute($side);
                $this->fatigue($side);
            }
            $home = $this->strength('home');
            $away = $this->strength('away');
            $side = $this->random->chance($home['control'] / ($home['control'] + $away['control'])) ? 'home' : 'away';
            $opponent = $side === 'home' ? 'away' : 'home';
            $this->teams[$side]['stats']['possession_ticks']++;
            $creator = $this->pickPlayer($side, 'passing');
            $this->event('possession', $side, $creator);
            $this->event('build_up', $side, $creator, ['style' => $this->teams[$side]['tactics']['build_up']]);
            $foul = $this->foul($opponent);
            if (count($this->teams[$opponent]['active']) < 7) {
                $status = 'abandoned';
                $this->event('abandoned', $opponent, null, ['reason' => 'fewer_than_seven_players']);
                break;
            }
            $attack = $this->strength($side)['attack'];
            $defense = $this->strength($opponent)['defense'];
            $tempo = ['slow' => 0.90, 'normal' => 1, 'fast' => 1.12][$this->teams[$side]['tactics']['tempo']];
            $probability = min(0.75, max(0.10, 0.64 * $attack / ($attack + $defense) * $tempo + ($foul ? 0.05 : 0)));
            if ($this->random->chance($probability)) {
                $this->opportunity($side, $opponent, $creator, $attack / ($attack + $defense));
            }
            if ($this->minute === 45) {
                $this->event('half_time');
            }
        }
        $played = min(90, $this->minute);
        $this->minute = $played;
        $this->event('full_time', null, null, ['status' => $status]);
        $result = ['engine_version' => self::VERSION, 'seed' => $this->input['seed'], 'status' => $status, 'minutes' => $played,
            'home_bonus' => round($this->homeBonus, 6), 'events' => $this->events];
        $ticks = $this->teams['home']['stats']['possession_ticks'] + $this->teams['away']['stats']['possession_ticks'];
        $homePossession = round(100 * $this->teams['home']['stats']['possession_ticks'] / $ticks, 2);
        foreach (['home', 'away'] as $side) {
            $team = $this->teams[$side];
            $stats = $team['stats'];
            $stats['possession'] = $side === 'home' ? $homePossession : round(100 - $homePossession, 2);
            $stats['xg'] = $stats['xg_millis'] / 1000;
            $individual = [];
            foreach ($team['players'] as $player) {
                $individual[] = $player['stats'] + ['name' => $player['name'], 'initial_fitness' => $player['fitness'],
                    'final_fitness' => (int) round($player['energy'] / 100), 'xg' => $player['stats']['xg_millis'] / 1000];
            }
            $result[$side] = ['name' => $team['name'], 'stats' => $stats, 'players' => $individual];
        }
        $context = $this->input['home_context'];
        $result['attendance'] = ['present' => $context['attendance'], 'capacity' => $context['capacity'],
            'occupancy' => $context['attendance'] === null ? null : round($context['attendance'] / $context['capacity'], 6),
            'ticket_revenue_cents' => $context['attendance'] === null ? null : $context['attendance'] * $context['ticket_price_cents']];

        return $result;
    }

    private function event(string $type, ?string $side = null, ?string $player = null, array $data = []): void
    {
        $this->events[] = ['sequence' => count($this->events) + 1, 'minute' => $this->minute, 'type' => $type,
            'side' => $side, 'player_id' => $player, 'data' => $data];
    }

    private function role(string $side, int $slot): string
    {
        return Tactics::FORMATIONS[$this->teams[$side]['tactics']['formation']][$slot];
    }

    private function effective(string $side, int $slot, string $attribute): float
    {
        $player = $this->teams[$side]['players'][$this->teams[$side]['active'][$slot]];
        $role = $this->role($side, $slot);
        $position = $player['primary_position'] === $role ? 1 : (in_array($role, $player['secondary_positions'], true) ? 0.95 : 0.78);
        $condition = 0.55 + 0.45 * $player['energy'] / 10000;
        $morale = 0.85 + 0.30 * $player['morale'] / 100;

        return max(1, $player[$attribute] * $position * $condition * $morale);
    }

    private function strength(string $side): array
    {
        $attack = $defense = $control = 1;
        foreach ($this->teams[$side]['active'] as $slot => $id) {
            if ($slot === 0) {
                continue;
            }
            $role = $this->role($side, $slot);
            $attacking = in_array($role, ['ST', 'LW', 'RW', 'AM'], true) ? 1.5 : 0.7;
            $defending = in_array($role, ['CB', 'LB', 'RB', 'DM'], true) ? 1.5 : 0.7;
            $attack += ($this->effective($side, $slot, 'finishing') + $this->effective($side, $slot, 'creativity') + $this->effective($side, $slot, 'speed') + $this->effective($side, $slot, 'set_pieces')) / 4 * $attacking;
            $defense += ($this->effective($side, $slot, 'defense') + $this->effective($side, $slot, 'tackling') + $this->effective($side, $slot, 'discipline')) / 3 * $defending;
            $control += ($this->effective($side, $slot, 'passing') + $this->effective($side, $slot, 'creativity')) / 2;
        }
        $tactics = $this->teams[$side]['tactics'];
        $attack *= ['defensive' => 0.88, 'balanced' => 1, 'attacking' => 1.14][$tactics['mentality']];
        $defense *= ['defensive' => 1.14, 'balanced' => 1, 'attacking' => 0.88][$tactics['mentality']];
        $control *= ['short' => 1.08, 'balanced' => 1, 'direct' => 0.93][$tactics['build_up']];
        $attack *= ['short' => 0.98, 'balanced' => 1, 'direct' => 1.06][$tactics['build_up']];
        $control *= ['low' => 0.98, 'normal' => 1, 'high' => 1.04][$tactics['pressure']];
        $control *= ['slow' => 1.04, 'normal' => 1, 'fast' => 0.96][$tactics['tempo']];
        $bonus = $side === 'home' ? 1 + $this->homeBonus : 1;

        return ['attack' => $attack * $bonus, 'defense' => $defense * $bonus, 'control' => $control * $bonus];
    }

    private function pickPlayer(string $side, string $attribute, bool $fouler = false): string
    {
        $weights = [];
        foreach ($this->teams[$side]['active'] as $slot => $id) {
            if ($slot === 0) {
                continue;
            }
            $rating = $fouler ? 110 - $this->teams[$side]['players'][$id]['discipline'] : $this->effective($side, $slot, $attribute);
            $roleBonus = $attribute === 'finishing' && in_array($this->role($side, $slot), ['ST', 'AM', 'LW', 'RW'], true) ? 2 : 1;
            $weights[$id] = max(1, (int) round($rating * $roleBonus));
        }

        return (string) $this->random->weighted($weights);
    }

    private function foul(string $side): bool
    {
        $pressure = $this->teams[$side]['tactics']['pressure'];
        if (! $this->random->chance(['low' => 0.20, 'normal' => 0.25, 'high' => 0.32][$pressure])) {
            return false;
        }
        $id = $this->pickPlayer($side, 'discipline', true);
        $this->increment($side, $id, 'fouls');
        $this->event('foul', $side, $id);
        $discipline = $this->teams[$side]['players'][$id]['discipline'];
        if ($this->random->chance(0.008 + (100 - $discipline) / 15000)) {
            $this->redCard($side, $id, 'direct');
        } elseif ($this->random->chance(0.12 + (100 - $discipline) / 250)) {
            $this->increment($side, $id, 'yellow_cards');
            $this->event('yellow_card', $side, $id);
            if ($this->teams[$side]['players'][$id]['stats']['yellow_cards'] === 2) {
                $this->redCard($side, $id, 'second_yellow');
            }
        }

        return true;
    }

    private function redCard(string $side, string $id, string $reason): void
    {
        $this->increment($side, $id, 'red_cards');
        $this->event('red_card', $side, $id, ['reason' => $reason]);
        $slot = array_search($id, $this->teams[$side]['active'], true);
        unset($this->teams[$side]['active'][$slot]);
    }

    private function opportunity(string $side, string $opponent, string $creator, float $advantage): void
    {
        $id = $this->pickPlayer($side, 'finishing');
        $this->teams[$side]['stats']['opportunities']++;
        $this->event('opportunity', $side, $id);
        if (! $this->random->chance(0.8)) {
            return;
        }
        $slot = array_search($id, $this->teams[$side]['active'], true);
        $finishing = ($this->effective($side, $slot, 'finishing') + $this->effective($side, $slot, 'composure')) / 2;
        $xg = (int) round(1000 * max(0.03, min(0.40, 0.04 + 0.18 * $advantage + $this->random->integer(-20, 30) / 1000)));
        $accuracy = min(0.85, 0.30 + $finishing / 250);
        $onTarget = $this->random->chance($accuracy);
        $this->increment($side, $id, 'shots');
        $this->increment($side, $id, 'xg_millis', $xg);
        $this->event('shot', $side, $id, ['on_target' => $onTarget, 'xg_millis' => $xg]);
        if (! $onTarget) {
            return;
        }
        $this->increment($side, $id, 'shots_on_target');
        $keeper = $this->teams[$opponent]['active'][0];
        $keeping = $this->effective($opponent, 0, 'goalkeeping');
        $goalProbability = min(0.95, ($xg / 1000) / $accuracy * (1.15 - $keeping / 200) * (0.75 + $finishing / 200));
        if ($this->random->chance($goalProbability)) {
            $this->increment($side, $id, 'goals');
            $this->event('goal', $side, $id, ['home_score' => $this->teams['home']['stats']['goals'], 'away_score' => $this->teams['away']['stats']['goals']]);
            if ($creator !== $id && $this->random->chance(0.8)) {
                $this->increment($side, $creator, 'assists');
                $this->event('assist', $side, $creator, ['scorer_id' => $id]);
            }
        } else {
            $this->increment($opponent, $keeper, 'saves');
            $this->event('save', $opponent, $keeper, ['shooter_id' => $id]);
        }
    }

    private function increment(string $side, string $id, string $field, int $amount = 1): void
    {
        $this->teams[$side]['stats'][$field] += $amount;
        $this->teams[$side]['players'][$id]['stats'][$field] += $amount;
    }

    private function fatigue(string $side): void
    {
        $tactics = $this->teams[$side]['tactics'];
        $fitness = [];
        foreach ($this->teams[$side]['active'] as $slot => $id) {
            $player = &$this->teams[$side]['players'][$id];
            $drain = 18 + ['low' => 0, 'normal' => 6, 'high' => 12][$tactics['pressure']]
                + ['slow' => -3, 'normal' => 0, 'fast' => 5][$tactics['tempo']] + (int) round((100 - $player['stamina']) / 10);
            if ($slot === 0) {
                $drain = (int) round($drain * 0.35);
            }
            $player['energy'] = max(0, $player['energy'] - $drain);
            $player['stats']['minutes']++;
            $fitness[$id] = (int) round($player['energy'] / 100);
            unset($player);
        }
        if ($this->minute % 15 === 0) {
            $this->event('fatigue', $side, null, ['fitness' => $fitness]);
        }
    }

    private function substitute(string $side): void
    {
        $limit = $this->input['rules']['max_substitutions'];
        $windows = $limit <= 3 ? [60, 70, 80] : [55, 60, 65, 70, 80];
        if (! in_array($this->minute, $windows, true) || $this->teams[$side]['stats']['substitutions'] >= $limit) {
            return;
        }
        $candidates = $this->teams[$side]['active'];
        unset($candidates[0]);
        uasort($candidates, fn ($a, $b) => $this->teams[$side]['players'][$a]['energy'] <=> $this->teams[$side]['players'][$b]['energy']);
        foreach ($candidates as $slot => $out) {
            $role = $this->role($side, $slot);
            $bench = array_filter($this->teams[$side]['bench'], fn ($id) => $this->teams[$side]['players'][$id]['primary_position'] !== 'GK');
            if ($bench === []) {
                return;
            }
            $in = null;
            foreach ($bench as $id) {
                $player = $this->teams[$side]['players'][$id];
                if ($player['primary_position'] === $role || in_array($role, $player['secondary_positions'], true)) {
                    $in = $id;
                    break;
                }
            }
            $in ??= reset($bench);
            $this->teams[$side]['active'][$slot] = $in;
            $this->teams[$side]['bench'] = array_values(array_diff($this->teams[$side]['bench'], [$in]));
            $this->teams[$side]['stats']['substitutions']++;
            $this->event('substitution', $side, $in, ['out_player_id' => $out, 'role' => $role]);

            return;
        }
    }
}
