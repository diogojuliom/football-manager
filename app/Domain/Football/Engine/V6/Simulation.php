<?php

namespace App\Domain\Football\Engine\V6;

use App\Domain\AI\V1\MatchDecisions;
use App\Domain\Football\Engine\SeededRandom;
use App\Domain\Tactics\Tactics;
use InvalidArgumentException;

/** Incremental engine. Every mutable value is carried by the serializable state. */
final class Simulation
{
    public const VERSION = '6.0.0';

    private SeededRandom $random;

    private array $state;

    private float $homeBonus;

    public function __construct(private array $input, ?array $state = null)
    {
        $context = $input['home_context'];
        $occupancy = $context['attendance'] === null ? 0 : $context['attendance'] / $context['capacity'];
        $this->homeBonus = $context['neutral'] ? 0 : min(0.05, 0.03 + 0.02 * $occupancy * $context['engagement'] / 100);

        if ($state === null) {
            $this->random = new SeededRandom(self::VERSION.':'.$input['seed']);
            $this->state = $this->initialState();
        } else {
            $this->assertState($state);
            $this->state = $state;
            // MySQL stores JSON objects in its own key order. Restore the published
            // snapshot order so resumed matches produce the same player list/hash
            // as uninterrupted simulations.
            foreach (['home', 'away'] as $side) {
                $players = [];
                foreach ([...$input[$side]['starters'], ...$input[$side]['substitutes']] as $player) {
                    $players[$player['id']] = $this->state['teams'][$side]['players'][$player['id']];
                }
                $this->state['teams'][$side]['players'] = $players;
            }
            $this->random = new SeededRandom(self::VERSION.':'.$input['seed'], (int) $state['rng_counter']);
        }
    }

    public function state(): array
    {
        $this->state['rng_counter'] = $this->random->counter();

        return $this->state;
    }

    public function run(): array
    {
        $decisions = $this->input['decisions'] ?? [];
        $cursor = 0;
        while (! in_array($this->state['status'], ['finished', 'abandoned'], true)) {
            while (isset($decisions[$cursor]) && $this->decisionTick($decisions[$cursor]) === $this->state['tick']) {
                $decision = $decisions[$cursor++];
                $this->applyDecision((string) ($decision['side'] ?? ''), (string) ($decision['type'] ?? ''), (array) ($decision['payload'] ?? []));
            }
            if (isset($decisions[$cursor]) && $this->decisionTick($decisions[$cursor]) < $this->state['tick']) {
                throw new InvalidArgumentException('Decisões fora de ordem.');
            }
            if ($this->state['status'] === 'half_time') {
                $this->resumeHalfTime();
            } elseif ($this->state['status'] === 'hydration_break') {
                $this->resumeHydrationBreak();
            }
            $this->advance(1);
        }
        if ($cursor !== count($decisions)) {
            throw new InvalidArgumentException('Há decisões posteriores ao encerramento.');
        }

        return $this->result();
    }

    public function advance(int $minutes = 1, bool $ignoreAutomaticPauses = false): array
    {
        if ($minutes < 1 || $minutes > 120) {
            throw new InvalidArgumentException('Bloco de avanço inválido.');
        }
        if (in_array($this->state['status'], ['finished', 'abandoned'], true)) {
            return $this->state();
        }
        if (in_array($this->state['status'], ['half_time', 'hydration_break'], true)) {
            if (! $ignoreAutomaticPauses) {
                return $this->state();
            }
            $this->resumeAutomaticPause();
        }

        $target = $this->state['tick'] + $minutes;
        while ($this->state['tick'] < $target) {
            $this->state['tick']++;
            $this->state['period_elapsed']++;
            $this->updateClock();
            $this->playMinute();
            if ($this->state['status'] === 'abandoned') {
                break;
            }
            if ($this->startHydrationBreak()) {
                if (! $ignoreAutomaticPauses) {
                    break;
                }
                $this->resumeHydrationBreak();
            }
            if ($this->state['period_elapsed'] === 45) {
                $this->announceAddedTime();
            }
            $period = $this->periodKey();
            $periodEnd = 45 + $this->state['lost_time'][$period]['effective_minutes'];
            if ($this->state['period_elapsed'] >= $periodEnd && $this->state['period'] === 1) {
                $this->event('half_time');
                $this->state['status'] = 'half_time';
                if (! $ignoreAutomaticPauses) {
                    break;
                }
                $this->resumeHalfTime();
            } elseif ($this->state['period_elapsed'] >= $periodEnd && $this->state['period'] === 2) {
                $this->finish();
                break;
            }
        }
        if ($this->state['status'] === 'abandoned') {
            $this->finish();
        }

        return $this->state();
    }

    public function resumeHalfTime(): array
    {
        if ($this->state['status'] !== 'half_time') {
            throw new InvalidArgumentException('A partida não está no intervalo.');
        }
        $this->state['period'] = 2;
        $this->state['period_elapsed'] = 0;
        $this->state['minute'] = 45;
        $this->state['added_minute'] = 0;
        $this->state['status'] = 'playing';
        $this->decision('system', 'resume', ['reason' => 'half_time'], 'system');

        return $this->state();
    }

    public function resumeHydrationBreak(): array
    {
        if ($this->state['status'] !== 'hydration_break') {
            throw new InvalidArgumentException('A partida não está em uma parada de hidratação.');
        }
        $key = $this->activeHydrationBreak();
        if ($key === null) {
            throw new InvalidArgumentException('A parada de hidratação ativa é inválida.');
        }
        $this->event('hydration_break_end', null, null, ['duration' => 3, 'period' => $this->state['period']]);
        $this->state['hydration_breaks'][$key]['status'] = 'completed';
        $this->state['status'] = 'playing';
        $this->decision('system', 'resume', ['reason' => 'hydration_break', 'break' => $key], 'system');

        return $this->state();
    }

    public function applyDecision(string $side, string $type, array $payload): array
    {
        if (! in_array($side, ['home', 'away'], true) || $this->state['teams'][$side]['controller'] !== 'human') {
            throw new InvalidArgumentException('Somente o treinador humano pode enviar esta decisão.');
        }
        if (! in_array($this->state['status'], ['playing', 'half_time', 'hydration_break'], true)) {
            throw new InvalidArgumentException('A partida não aceita decisões neste estado.');
        }

        match ($type) {
            'tactics' => $this->changeTactics($side, $payload),
            'positions' => $this->changePositions($side, $payload),
            'substitution' => $this->humanSubstitution($side, $payload),
            default => throw new InvalidArgumentException('Tipo de decisão inválido.'),
        };
        $this->decision($side, $type, $payload);

        return $this->state();
    }

    public function result(): array
    {
        if (! in_array($this->state['status'], ['finished', 'abandoned'], true)) {
            throw new InvalidArgumentException('A partida ainda não terminou.');
        }
        $result = [
            'engine_version' => self::VERSION,
            'seed' => $this->input['seed'],
            'status' => $this->state['status'],
            'minutes' => $this->state['tick'],
            'added_time' => $this->state['added_time'],
            'first_half_added_time' => $this->state['first_half_added_time'],
            'second_half_added_time' => $this->state['second_half_added_time'],
            'added_time_rules_version' => $this->state['added_time_rules_version'],
            'lost_time' => $this->state['lost_time'],
            'home_bonus' => round($this->homeBonus, 6),
            'events' => $this->state['events'],
            'decisions' => $this->state['decisions'],
        ];
        $ticks = max(1, $this->state['teams']['home']['stats']['possession_ticks'] + $this->state['teams']['away']['stats']['possession_ticks']);
        $homePossession = round(100 * $this->state['teams']['home']['stats']['possession_ticks'] / $ticks, 2);
        foreach (['home', 'away'] as $side) {
            $team = $this->state['teams'][$side];
            $stats = $team['stats'];
            $stats['possession'] = $side === 'home' ? $homePossession : round(100 - $homePossession, 2);
            $stats['xg'] = $stats['xg_millis'] / 1000;
            $individual = [];
            foreach ($team['players'] as $player) {
                $individual[] = $player['stats'] + [
                    'name' => $player['name'], 'initial_fitness' => $player['fitness'],
                    'final_fitness' => (int) round($player['energy'] / 100),
                    'xg' => $player['stats']['xg_millis'] / 1000,
                    'rating' => $this->rating($player), 'injured' => $player['injured'],
                    'injury_days' => $player['injury_days'],
                ];
            }
            $result[$side] = [
                'name' => $team['name'], 'stats' => $stats, 'players' => $individual,
                'controller' => $team['controller'], 'final_tactics' => $team['tactics'],
            ];
        }
        $context = $this->input['home_context'];
        $result['attendance'] = [
            'present' => $context['attendance'], 'capacity' => $context['capacity'],
            'occupancy' => $context['attendance'] === null ? null : round($context['attendance'] / $context['capacity'], 6),
            'ticket_revenue_cents' => $context['attendance'] === null ? null : $context['attendance'] * $context['ticket_price_cents'],
        ];

        // The official result is persisted as JSON. Normalize whole floating-point values to
        // their JSON representation before hashing so storage and replay remain byte-stable.
        return json_decode(json_encode($result, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
    }

    private function initialState(): array
    {
        $teams = [];
        foreach (['home', 'away'] as $side) {
            $team = $this->input[$side];
            $players = [];
            foreach ([...$team['starters'], ...$team['substitutes']] as $player) {
                $players[$player['id']] = $player + ['energy' => $player['fitness'] * 100, 'injured' => false, 'injury_days' => 0, 'stats' => [
                    'player_id' => $player['id'], 'minutes' => 0, 'goals' => 0, 'assists' => 0, 'shots' => 0,
                    'shots_on_target' => 0, 'xg_millis' => 0, 'fouls' => 0, 'yellow_cards' => 0,
                    'red_cards' => 0, 'saves' => 0,
                ]];
            }
            $tactics = $team['tactics'] + ['style' => 'balanced', 'attack_direction' => 'balanced', 'marking' => 'light'];
            $teams[$side] = [
                'name' => $team['name'], 'tactics' => $tactics, 'players' => $players,
                'controller' => $team['controller'], 'last_change' => -100, 'last_counts' => [11, 11],
                'active' => array_column($team['starters'], 'id'), 'bench' => array_column($team['substitutes'], 'id'),
                'substitution_windows' => 0, 'last_substitution_minute' => null, 'last_substitution_tick' => null,
                'stats' => ['goals' => 0, 'assists' => 0, 'possession_ticks' => 0, 'opportunities' => 0,
                    'shots' => 0, 'shots_on_target' => 0, 'xg_millis' => 0, 'fouls' => 0,
                    'yellow_cards' => 0, 'red_cards' => 0, 'saves' => 0, 'substitutions' => 0],
            ];
        }
        $hydrationEnabled = $this->input['rules']['hydration_breaks_enabled'];
        $state = [
            'schema_version' => 3, 'engine_version' => self::VERSION,
            'tick' => 0, 'period' => 1, 'period_elapsed' => 0, 'minute' => 0, 'added_minute' => 0,
            'added_time_rules_version' => $this->input['rules']['added_time_rules']['version'],
            'first_half_added_time' => 0, 'second_half_added_time' => 0, 'added_time' => 0,
            'first_half_total' => 45, 'total_minutes' => 90, 'status' => 'playing',
            'lost_time' => [
                'first_half' => ['seconds' => 0, 'contributions' => [], 'announced_minutes' => null, 'effective_minutes' => 0, 'extensions' => []],
                'second_half' => ['seconds' => 0, 'contributions' => [], 'announced_minutes' => null, 'effective_minutes' => 0, 'extensions' => []],
            ],
            'hydration_breaks' => [
                'first_half' => ['target_minute' => 30, 'status' => $hydrationEnabled ? 'pending' : 'disabled'],
                'second_half' => ['target_minute' => 75, 'status' => $hydrationEnabled ? 'pending' : 'disabled'],
            ],
            'rng_counter' => $this->random->counter(), 'teams' => $teams, 'events' => [], 'decisions' => [],
        ];
        $this->state = $state;
        $this->event('kickoff');

        return $this->state;
    }

    private function playMinute(): void
    {
        foreach (['home', 'away'] as $side) {
            $this->aiReact($side);
            $this->aiSubstitute($side);
            $this->fatigue($side);
        }
        $home = $this->strength('home');
        $away = $this->strength('away');
        $side = $this->random->chance($home['control'] / ($home['control'] + $away['control'])) ? 'home' : 'away';
        $opponent = $side === 'home' ? 'away' : 'home';
        $this->state['teams'][$side]['stats']['possession_ticks']++;
        $creator = $this->pickPlayer($side, 'passing');
        $this->event('possession', $side, $creator);
        $this->event('build_up', $side, $creator, ['style' => $this->state['teams'][$side]['tactics']['build_up']]);
        $foul = $this->foul($opponent);
        if (count($this->state['teams'][$opponent]['active']) < 7) {
            $this->state['status'] = 'abandoned';
            $this->event('abandoned', $opponent, null, ['reason' => 'fewer_than_seven_players']);

            return;
        }
        $attack = $this->strength($side)['attack'];
        $defense = $this->strength($opponent)['defense'];
        $tempo = ['slow' => 0.90, 'normal' => 1, 'fast' => 1.12][$this->state['teams'][$side]['tactics']['tempo']];
        $probability = min(0.78, max(0.08, 0.64 * $attack / ($attack + $defense) * $tempo + ($foul ? 0.05 : 0)));
        if ($this->random->chance($probability)) {
            $this->opportunity($side, $opponent, $creator, $attack / ($attack + $defense));
        }
        $this->injury($side);
    }

    private function updateClock(): void
    {
        $elapsed = $this->state['period_elapsed'];
        $base = $this->state['period'] === 1 ? 0 : 45;
        $this->state['minute'] = min($base + 45, $base + $elapsed);
        $this->state['added_minute'] = max(0, $elapsed - 45);
    }

    private function startHydrationBreak(): bool
    {
        if ($this->state['added_minute'] !== 0) {
            return false;
        }
        $key = $this->state['period'] === 1 ? 'first_half' : 'second_half';
        $break = $this->state['hydration_breaks'][$key];
        if ($break['status'] !== 'pending' || $this->state['minute'] !== $break['target_minute']) {
            return false;
        }
        $this->state['hydration_breaks'][$key]['status'] = 'active';
        $this->event('hydration_break_start', null, null, ['duration' => 3, 'period' => $this->state['period']]);
        $this->state['status'] = 'hydration_break';
        foreach (['home', 'away'] as $side) {
            $this->aiReact($side, true);
            $this->aiSubstitute($side);
        }

        return true;
    }

    private function activeHydrationBreak(): ?string
    {
        foreach ($this->state['hydration_breaks'] as $key => $break) {
            if ($break['status'] === 'active') {
                return $key;
            }
        }

        return null;
    }

    private function resumeAutomaticPause(): void
    {
        if ($this->state['status'] === 'half_time') {
            $this->resumeHalfTime();

            return;
        }
        $this->resumeHydrationBreak();
    }

    private function decisionTick(array $decision): int
    {
        if (is_int($decision['tick'] ?? null)) {
            return $decision['tick'];
        }
        $minute = (int) ($decision['minute'] ?? -1);
        $added = (int) ($decision['added_minute'] ?? 0);

        return $minute <= 45 ? $minute + $added : $this->state['first_half_total'] + $minute - 45 + $added;
    }

    private function finish(): void
    {
        if (! in_array($this->state['status'], ['finished', 'abandoned'], true)) {
            $this->state['status'] = 'finished';
        }
        $hasFullTime = false;
        foreach ($this->state['events'] as $event) {
            $hasFullTime = $hasFullTime || $event['type'] === 'full_time';
        }
        if (! $hasFullTime) {
            $this->event('full_time', null, null, ['status' => $this->state['status']]);
        }
    }

    private function changeTactics(string $side, array $payload): void
    {
        $formation = $payload['formation'] ?? $this->state['teams'][$side]['tactics']['formation'];
        if (! is_string($formation) || ! isset(Tactics::FORMATIONS[$formation])) {
            throw new InvalidArgumentException('Formação inválida.');
        }
        $tactics = $this->state['teams'][$side]['tactics'];
        foreach (Tactics::LIVE_OPTIONS as $field => $options) {
            $value = $payload[$field] ?? $tactics[$field];
            if (! is_string($value) || ! isset($options[$value])) {
                throw new InvalidArgumentException('Instrução tática inválida.');
            }
            $tactics[$field] = $value;
        }
        $tactics['formation'] = $formation;
        $this->state['teams'][$side]['tactics'] = $tactics;
        $this->event('tactical_change', $side, null, ['tactics' => $this->state['teams'][$side]['tactics'], 'reason' => 'coach']);
    }

    private function changePositions(string $side, array $payload): void
    {
        $active = $payload['active'] ?? null;
        if (! is_array($active) || ! array_is_list($active) || count($active) !== count($this->state['teams'][$side]['active'])
            || array_diff($active, $this->state['teams'][$side]['active']) !== [] || count(array_unique($active)) !== count($active)) {
            throw new InvalidArgumentException('Distribuição dos titulares inválida.');
        }
        if (isset($active[0]) && ! $this->keeper($side, $active[0])) {
            throw new InvalidArgumentException('A primeira vaga exige goleiro.');
        }
        $this->state['teams'][$side]['active'] = $active;
        $this->event('positions_change', $side, null, ['active' => $active]);
    }

    private function humanSubstitution(string $side, array $payload): void
    {
        $out = (string) ($payload['out'] ?? '');
        $in = (string) ($payload['in'] ?? '');
        $slot = array_search($out, $this->state['teams'][$side]['active'], true);
        if ($slot === false || ! in_array($in, $this->state['teams'][$side]['bench'], true)) {
            throw new InvalidArgumentException('Substituição inválida.');
        }
        if (($slot === 0) !== $this->keeper($side, $in)) {
            throw new InvalidArgumentException('A substituição não respeita a vaga de goleiro.');
        }
        $team = &$this->state['teams'][$side];
        if ($team['stats']['substitutions'] >= $this->input['rules']['max_substitutions']) {
            throw new InvalidArgumentException('Limite de substituições atingido.');
        }
        $atHalfTime = $this->state['status'] === 'half_time';
        $newWindow = ! $atHalfTime && $team['last_substitution_tick'] !== $this->state['tick'];
        if ($newWindow && $team['substitution_windows'] >= ($this->input['rules']['max_substitution_windows'] ?? 3)) {
            throw new InvalidArgumentException('Limite de janelas de substituição atingido.');
        }
        $team['active'][$slot] = $in;
        $team['bench'] = array_values(array_diff($team['bench'], [$in]));
        $team['stats']['substitutions']++;
        if ($newWindow) {
            $team['substitution_windows']++;
        }
        $team['last_substitution_minute'] = $this->state['minute'];
        $team['last_substitution_tick'] = $this->state['tick'];
        $team['last_change'] = $this->state['minute'];
        $this->event('substitution', $side, $in, ['out_player_id' => $out, 'role' => $this->role($side, $slot), 'reason' => 'coach']);
    }

    private function aiReact(string $side, bool $force = false): void
    {
        $team = &$this->state['teams'][$side];
        if ($team['controller'] !== 'ai') {
            return;
        }
        $other = $side === 'home' ? 'away' : 'home';
        $counts = [count($team['active']), count($this->state['teams'][$other]['active'])];
        if (! $force && ($this->state['added_minute'] !== 0
            || (! in_array($this->state['minute'], [1, 45, 60, 70, 80, 90], true) && $counts === $team['last_counts']))) {
            return;
        }
        $team['last_counts'] = $counts;
        $difference = $team['stats']['goals'] - $this->state['teams'][$other]['stats']['goals'];
        $previous = $team['tactics'];
        $fitness = array_sum(array_map(fn (string $id): float => $team['players'][$id]['energy'] / 100, $team['active']))
            / max(1, count($team['active']));
        $reaction = MatchDecisions::react($team['tactics'], $this->state['minute'], $difference,
            count($team['active']), count($this->state['teams'][$other]['active']), $fitness);
        $team['tactics'] = $reaction['tactics'];
        if ($this->state['minute'] >= 70 && $difference < 0) {
            $team['tactics']['formation'] = '4-2-4';
            $team['tactics']['style'] = 'total_attack';
            $team['tactics']['attack_direction'] = 'balanced';
            $team['tactics']['marking'] = 'heavy';
        } elseif ($this->state['minute'] >= 70 && $difference > 0) {
            $team['tactics']['formation'] = '5-4-1';
            $team['tactics']['style'] = 'counter';
            $team['tactics']['marking'] = 'very_heavy';
        }
        if ($previous !== $team['tactics']) {
            $this->decision($side, 'tactics', $team['tactics'], 'ai');
            $this->event('tactical_change', $side, null, ['previous' => $previous, 'tactics' => $team['tactics'], 'reason' => 'ai_context']);
        }
    }

    private function aiSubstitute(string $side): void
    {
        $team = &$this->state['teams'][$side];
        if ($team['controller'] !== 'ai' || $team['stats']['substitutions'] >= $this->input['rules']['max_substitutions']
            || $team['substitution_windows'] >= ($this->input['rules']['max_substitution_windows'] ?? 3)) {
            return;
        }
        $other = $side === 'home' ? 'away' : 'home';
        $decisionPlayers = $team['players'];
        $injured = false;
        foreach ($team['active'] as $id) {
            if ($decisionPlayers[$id]['injured']) {
                $decisionPlayers[$id]['energy'] = 0;
                $injured = true;
            }
        }
        $decision = MatchDecisions::substitute($this->state['minute'], $injured ? -100 : $team['last_change'],
            $team['stats']['goals'] - $this->state['teams'][$other]['stats']['goals'], $decisionPlayers, $team['active'], $team['bench'],
            Tactics::FORMATIONS[$team['tactics']['formation']]);
        if ($decision === null) {
            return;
        }
        $slot = $decision['slot'];
        $out = $team['active'][$slot];
        $in = $decision['in'];
        $team['active'][$slot] = $in;
        $team['bench'] = array_values(array_diff($team['bench'], [$in]));
        $team['stats']['substitutions']++;
        $team['substitution_windows']++;
        $team['last_substitution_minute'] = $this->state['minute'];
        $team['last_substitution_tick'] = $this->state['tick'];
        $team['last_change'] = $this->state['minute'];
        $reason = $team['players'][$out]['injured'] ? 'injury' : $decision['reason'];
        $payload = ['out' => $out, 'in' => $in, 'reason' => $reason];
        $this->decision($side, 'substitution', $payload, 'ai');
        $this->event('substitution', $side, $in, ['out_player_id' => $out, 'role' => $this->role($side, $slot), 'reason' => $reason]);
    }

    private function strength(string $side): array
    {
        $attack = $defense = $control = 1;
        foreach ($this->state['teams'][$side]['active'] as $slot => $id) {
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
        $tactics = $this->state['teams'][$side]['tactics'];
        $attack *= ['defensive' => 0.88, 'balanced' => 1, 'attacking' => 1.14][$tactics['mentality']];
        $defense *= ['defensive' => 1.14, 'balanced' => 1, 'attacking' => 0.88][$tactics['mentality']];
        $control *= ['short' => 1.08, 'balanced' => 1, 'direct' => 0.93][$tactics['build_up']];
        $attack *= ['short' => 0.98, 'balanced' => 1, 'direct' => 1.06][$tactics['build_up']];
        $profile = Tactics::PROFILES[$tactics['formation']];
        $attack *= $profile['attack'] * ['balanced' => 1, 'total_attack' => 1.18, 'counter' => 0.94][$tactics['style']];
        $defense *= $profile['defense'] * ['balanced' => 1, 'total_attack' => 0.86, 'counter' => 1.13][$tactics['style']];
        $control *= $profile['control'] * ['balanced' => 1, 'total_attack' => 1.03, 'counter' => 0.92][$tactics['style']];
        $attack *= ['balanced' => 1, 'center' => 1.03, 'wings' => 1.05][$tactics['attack_direction']];
        $control *= ['balanced' => 1, 'center' => 1.07, 'wings' => 0.96][$tactics['attack_direction']];
        $defense *= ['light' => 0.98, 'heavy' => 1.06, 'very_heavy' => 1.10][$tactics['marking']];
        $bonus = $side === 'home' ? 1 + $this->homeBonus : 1;

        return ['attack' => $attack * $bonus, 'defense' => $defense * $bonus, 'control' => $control * $bonus];
    }

    private function effective(string $side, int $slot, string $attribute): float
    {
        $player = $this->state['teams'][$side]['players'][$this->state['teams'][$side]['active'][$slot]];
        $role = $this->role($side, $slot);
        $position = $player['primary_position'] === $role ? 1 : (in_array($role, $player['secondary_positions'], true) ? 0.95 : 0.78);
        $condition = 0.55 + 0.45 * $player['energy'] / 10000;
        $morale = 0.85 + 0.30 * $player['morale'] / 100;
        $injury = $player['injured'] ? 0.45 : 1;

        return max(1, $player[$attribute] * $position * $condition * $morale * $injury);
    }

    private function role(string $side, int $slot): string
    {
        return Tactics::FORMATIONS[$this->state['teams'][$side]['tactics']['formation']][$slot] ?? 'CM';
    }

    private function keeper(string $side, string $id): bool
    {
        $player = $this->state['teams'][$side]['players'][$id] ?? null;

        return $player && ($player['primary_position'] === 'GK' || in_array('GK', $player['secondary_positions'], true));
    }

    private function pickPlayer(string $side, string $attribute, bool $fouler = false): string
    {
        $weights = [];
        foreach ($this->state['teams'][$side]['active'] as $slot => $id) {
            if ($slot === 0) {
                continue;
            }
            $rating = $fouler ? 110 - $this->state['teams'][$side]['players'][$id]['discipline'] : $this->effective($side, $slot, $attribute);
            $roleBonus = $attribute === 'finishing' && in_array($this->role($side, $slot), ['ST', 'AM', 'LW', 'RW'], true) ? 2 : 1;
            $weights[$id] = max(1, (int) round($rating * $roleBonus));
        }

        return (string) $this->random->weighted($weights);
    }

    private function foul(string $side): bool
    {
        $tactics = $this->state['teams'][$side]['tactics'];
        $probability = ['low' => 0.20, 'normal' => 0.25, 'high' => 0.32][$tactics['pressure']]
            * ['light' => 0.78, 'heavy' => 1.12, 'very_heavy' => 1.42][$tactics['marking']];
        if (! $this->random->chance($probability)) {
            return false;
        }
        $id = $this->pickPlayer($side, 'discipline', true);
        $this->increment($side, $id, 'fouls');
        $this->event('foul', $side, $id);
        $discipline = $this->state['teams'][$side]['players'][$id]['discipline'];
        $severity = ['light' => 0.8, 'heavy' => 1.1, 'very_heavy' => 1.4][$tactics['marking']];
        if ($this->random->chance((0.008 + (100 - $discipline) / 15000) * $severity)) {
            $this->redCard($side, $id, 'direct');
        } elseif ($this->random->chance((0.12 + (100 - $discipline) / 250) * $severity)) {
            $this->increment($side, $id, 'yellow_cards');
            $this->event('yellow_card', $side, $id);
            if ($this->state['teams'][$side]['players'][$id]['stats']['yellow_cards'] === 2) {
                $this->redCard($side, $id, 'second_yellow');
            }
        }

        return true;
    }

    private function redCard(string $side, string $id, string $reason): void
    {
        $this->increment($side, $id, 'red_cards');
        $this->event('red_card', $side, $id, ['reason' => $reason]);
        $slot = array_search($id, $this->state['teams'][$side]['active'], true);
        unset($this->state['teams'][$side]['active'][$slot]);
        $this->state['teams'][$side]['active'] = array_values($this->state['teams'][$side]['active']);
    }

    private function opportunity(string $side, string $opponent, string $creator, float $advantage): void
    {
        $id = $this->pickPlayer($side, 'finishing');
        $this->state['teams'][$side]['stats']['opportunities']++;
        $this->event('opportunity', $side, $id);
        if (! $this->random->chance(0.8)) {
            return;
        }
        $slot = array_search($id, $this->state['teams'][$side]['active'], true);
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
        $keeper = $this->state['teams'][$opponent]['active'][0];
        $keeping = $this->effective($opponent, 0, 'goalkeeping');
        $goalProbability = min(0.95, ($xg / 1000) / $accuracy * (1.15 - $keeping / 200) * (0.75 + $finishing / 200));
        if ($this->random->chance($goalProbability)) {
            $this->increment($side, $id, 'goals');
            $this->event('goal', $side, $id, ['home_score' => $this->state['teams']['home']['stats']['goals'], 'away_score' => $this->state['teams']['away']['stats']['goals']]);
            if ($creator !== $id && $this->random->chance(0.8)) {
                $this->increment($side, $creator, 'assists');
                $this->event('assist', $side, $creator, ['scorer_id' => $id]);
            }
        } else {
            $this->increment($opponent, $keeper, 'saves');
            $this->event('save', $opponent, $keeper, ['shooter_id' => $id]);
        }
    }

    private function fatigue(string $side): void
    {
        $tactics = $this->state['teams'][$side]['tactics'];
        $fitness = [];
        foreach ($this->state['teams'][$side]['active'] as $slot => $id) {
            $player = &$this->state['teams'][$side]['players'][$id];
            $drain = 18 + ['low' => 0, 'normal' => 6, 'high' => 12][$tactics['pressure']]
                + ['slow' => -3, 'normal' => 0, 'fast' => 5][$tactics['tempo']]
                + ['light' => -2, 'heavy' => 4, 'very_heavy' => 8][$tactics['marking']]
                + ['balanced' => 0, 'total_attack' => 6, 'counter' => -2][$tactics['style']]
                + (int) round((100 - $player['stamina']) / 10);
            if ($slot === 0) {
                $drain = (int) round($drain * 0.35);
            }
            $player['energy'] = max(0, $player['energy'] - $drain);
            $player['stats']['minutes']++;
            $fitness[$id] = (int) round($player['energy'] / 100);
            unset($player);
        }
        if ($this->state['minute'] % 10 === 0) {
            $this->event('fatigue', $side, null, ['fitness' => $fitness]);
        }
    }

    private function injury(string $side): void
    {
        if (! $this->random->chance(0.0015)) {
            return;
        }
        $candidates = array_filter($this->state['teams'][$side]['active'], fn ($id) => ! $this->state['teams'][$side]['players'][$id]['injured']);
        if ($candidates === []) {
            return;
        }
        $id = $candidates[array_keys($candidates)[$this->random->integer(0, count($candidates) - 1)]];
        $days = $this->random->integer(3, 21);
        $this->state['teams'][$side]['players'][$id]['injured'] = true;
        $this->state['teams'][$side]['players'][$id]['injury_days'] = $days;
        $this->event('injury', $side, $id, ['days' => $days]);
    }

    private function increment(string $side, string $id, string $field, int $amount = 1): void
    {
        $this->state['teams'][$side]['stats'][$field] += $amount;
        $this->state['teams'][$side]['players'][$id]['stats'][$field] += $amount;
    }

    private function rating(array $player): int|float
    {
        $stats = $player['stats'];
        $rating = round(max(1, min(10, 6 + $stats['goals'] * 1.2 + $stats['assists'] * 0.7 + $stats['saves'] * 0.08
            + $stats['shots_on_target'] * 0.08 - $stats['yellow_cards'] * 0.25 - $stats['red_cards'] * 1.5)), 1);

        // JSON storage decodes whole numbers as integers. Normalize them before hashing so a
        // persisted result and a fresh deterministic replay have the exact same representation.
        return floor($rating) === $rating ? (int) $rating : $rating;
    }

    private function event(string $type, ?string $side = null, ?string $player = null, array $data = []): void
    {
        $event = [
            'sequence' => count($this->state['events']) + 1, 'tick' => $this->state['tick'],
            'period' => $this->state['period'], 'minute' => $this->state['minute'], 'added_minute' => $this->state['added_minute'],
            'type' => $type, 'side' => $side, 'player_id' => $player, 'data' => $data,
        ];
        $this->state['events'][] = $event;
        $this->recordLostTime($event);
    }

    private function periodKey(): string
    {
        return $this->state['period'] === 1 ? 'first_half' : 'second_half';
    }

    private function announceAddedTime(): void
    {
        $period = $this->periodKey();
        if ($this->state['lost_time'][$period]['announced_minutes'] !== null) {
            return;
        }
        $minutes = $this->calculatedAddedMinutes($period);
        $this->state['lost_time'][$period]['announced_minutes'] = $minutes;
        $this->state['lost_time'][$period]['effective_minutes'] = $minutes;
        $this->synchronizeAddedTime();
        if ($minutes > 0) {
            $this->event('added_time_announced', null, null, [
                'minutes' => $minutes,
                'lost_time_seconds' => $this->state['lost_time'][$period]['seconds'],
            ]);
        }
    }

    private function recordLostTime(array $event): void
    {
        if (! ($this->input['rules']['dynamic_added_time_enabled'] ?? false)) {
            return;
        }

        $type = $event['type'];
        $rules = $this->input['rules']['added_time_rules'];
        $range = null;
        $group = null;
        if ($type === 'hydration_break_start') {
            $range = [(int) $rules['hydration_seconds'], (int) $rules['hydration_seconds']];
            $group = 'hydration:'.$this->periodKey();
        } elseif ($type === 'goal') {
            $range = $rules['goal_celebration_seconds'];
            $group = 'goal:'.$event['sequence'];
        } elseif ($type === 'substitution') {
            if (in_array($this->state['status'], ['hydration_break', 'half_time'], true)) {
                return;
            }
            $range = $rules['substitution_seconds'];
            $group = 'substitution:'.$this->state['period'].':'.$this->state['tick'];
        } elseif ($type === 'injury') {
            $range = $rules['injury_seconds'];
            $group = 'injury:'.$event['sequence'];
        } elseif (in_array($type, ['yellow_card', 'red_card'], true)) {
            $range = $rules['card_seconds'];
            $group = 'discipline:'.$this->state['period'].':'.$this->state['tick'];
        }
        if ($range === null || $group === null) {
            return;
        }

        $period = $this->periodKey();
        foreach ($this->state['lost_time'][$period]['contributions'] as $index => $contribution) {
            if ($contribution['id'] !== $group) {
                continue;
            }
            $this->state['lost_time'][$period]['contributions'][$index]['event_sequences'][] = $event['sequence'];

            return;
        }

        $seconds = $this->deterministicDuration((int) $range[0], (int) $range[1], $group);
        if ($seconds === 0) {
            return;
        }
        $this->state['lost_time'][$period]['seconds'] += $seconds;
        $this->state['lost_time'][$period]['contributions'][] = [
            'id' => $group,
            'type' => $type,
            'seconds' => $seconds,
            'tick' => $event['tick'],
            'event_sequences' => [$event['sequence']],
        ];
        $last = array_key_last($this->state['events']);
        $this->state['events'][$last]['data']['lost_time_seconds'] = $seconds;
        $this->extendAddedTimeIfNeeded();
    }

    private function deterministicDuration(int $minimum, int $maximum, string $key): int
    {
        if ($minimum === $maximum) {
            return $minimum;
        }
        $span = $maximum - $minimum + 1;
        $value = hexdec(substr(hash('sha256', self::VERSION.'|'.$this->input['seed'].'|'.$key), 0, 8));

        return $minimum + ($value % $span);
    }

    private function extendAddedTimeIfNeeded(): void
    {
        $period = $this->periodKey();
        $lostTime = &$this->state['lost_time'][$period];
        if ($lostTime['announced_minutes'] === null) {
            return;
        }
        $minutes = $this->calculatedAddedMinutes($period);
        if ($minutes <= $lostTime['effective_minutes']) {
            return;
        }
        $previous = $lostTime['effective_minutes'];
        $lostTime['effective_minutes'] = $minutes;
        $lostTime['extensions'][] = [
            'tick' => $this->state['tick'],
            'from_minutes' => $previous,
            'to_minutes' => $minutes,
            'lost_time_seconds' => $lostTime['seconds'],
        ];
        unset($lostTime);
        $this->synchronizeAddedTime();
        $this->event('added_time_extended', null, null, [
            'previous_minutes' => $previous,
            'minutes' => $minutes,
            'lost_time_seconds' => $this->state['lost_time'][$period]['seconds'],
        ]);
    }

    private function synchronizeAddedTime(): void
    {
        $first = $this->state['lost_time']['first_half']['effective_minutes'];
        $second = $this->state['lost_time']['second_half']['effective_minutes'];
        $this->state['first_half_added_time'] = $first;
        $this->state['second_half_added_time'] = $second;
        $this->state['added_time'] = $first + $second;
        $this->state['first_half_total'] = 45 + $first;
        $this->state['total_minutes'] = 90 + $first + $second;
    }

    private function calculatedAddedMinutes(string $period): int
    {
        return min(
            (int) $this->input['rules']['added_time_rules']['maximum_added_minutes'],
            (int) ceil($this->state['lost_time'][$period]['seconds'] / 60),
        );
    }

    private function decision(string $side, string $type, array $payload, string $actor = 'human'): void
    {
        $this->state['decisions'][] = [
            'sequence' => count($this->state['decisions']) + 1, 'tick' => $this->state['tick'],
            'period' => $this->state['period'], 'minute' => $this->state['minute'], 'added_minute' => $this->state['added_minute'],
            'side' => $side, 'actor' => $actor, 'type' => $type, 'payload' => $payload,
        ];
    }

    private function assertState(array $state): void
    {
        if (($state['schema_version'] ?? null) !== 3 || ($state['engine_version'] ?? null) !== self::VERSION
            || ! is_int($state['tick'] ?? null) || ! is_int($state['period_elapsed'] ?? null)
            || ! is_int($state['minute'] ?? null) || ! is_int($state['added_minute'] ?? null)
            || ! in_array($state['period'] ?? null, [1, 2], true) || ! is_int($state['rng_counter'] ?? null)
            || ! is_array($state['lost_time'] ?? null) || ! is_array($state['hydration_breaks'] ?? null) || ! is_array($state['teams'] ?? null)
            || ! is_array($state['events'] ?? null) || ! is_array($state['decisions'] ?? null)) {
            throw new InvalidArgumentException('Estado incremental inválido.');
        }
    }
}
