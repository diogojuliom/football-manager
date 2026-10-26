<?php

namespace App\Domain\Football\Engine;

use App\Domain\Tactics\Tactics;
use InvalidArgumentException;

final class MatchInput
{
    public readonly array $data;

    public function __construct(array $data)
    {
        $version = $data['engine_version'] ?? FootballMatchEngine::VERSION;
        if (! in_array($version, [V1\Simulation::VERSION, V2\Simulation::VERSION, V3\Simulation::VERSION, V4\Simulation::VERSION, V5\Simulation::VERSION], true)) {
            throw new InvalidArgumentException('Versão do motor não suportada.');
        }
        if (! is_string($data['seed'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $data['seed'])) {
            throw new InvalidArgumentException('A seed deve conter de 1 a 64 letras, números, hífens ou sublinhados.');
        }
        $rules = $data['rules'] ?? [];
        if (! is_array($rules)) {
            throw new InvalidArgumentException('Regras inválidas.');
        }
        $limit = $rules['max_substitutions'] ?? 3;
        if (! is_int($limit) || $limit < 0 || $limit > 5) {
            throw new InvalidArgumentException('Limite de substituições inválido.');
        }
        $ids = [];
        $normalized = ['engine_version' => $version, 'seed' => $data['seed'], 'rules' => ['max_substitutions' => $limit]];
        if (in_array($version, [V4\Simulation::VERSION, V5\Simulation::VERSION], true)) {
            $windows = $rules['max_substitution_windows'] ?? 3;
            if (! is_int($windows) || $windows < 0 || $windows > 5) {
                throw new InvalidArgumentException('Limite de janelas de substituição inválido.');
            }
            $normalized['rules']['max_substitution_windows'] = $windows;
            if ($version === V5\Simulation::VERSION) {
                $hydration = $rules['hydration_breaks_enabled'] ?? false;
                if (! is_bool($hydration)) {
                    throw new InvalidArgumentException('Regra de parada para hidratação inválida.');
                }
                $normalized['rules']['hydration_breaks_enabled'] = $hydration;
            }
        }
        foreach (['home', 'away'] as $side) {
            $team = $data[$side] ?? null;
            if (! is_array($team) || ! is_string($team['name'] ?? null) || trim($team['name']) === '' || strlen($team['name']) > 255) {
                throw new InvalidArgumentException('Equipe inválida.');
            }
            $tactics = $team['tactics'] ?? [];
            if (! is_array($tactics) || ! is_string($tactics['formation'] ?? null) || ! isset(Tactics::FORMATIONS[$tactics['formation']])) {
                throw new InvalidArgumentException('Formação inválida.');
            }
            foreach (Tactics::OPTIONS as $field => $choices) {
                if (! is_string($tactics[$field] ?? null) || ! isset($choices[$tactics[$field]])) {
                    throw new InvalidArgumentException('Configuração tática inválida.');
                }
            }
            if (! is_array($team['starters'] ?? null) || ! array_is_list($team['starters']) || count($team['starters']) !== 11
                || ! is_array($team['substitutes'] ?? null) || ! array_is_list($team['substitutes']) || count($team['substitutes']) > 12) {
                throw new InvalidArgumentException('Titulares ou reservas inválidos.');
            }
            foreach (['starters', 'substitutes'] as $unit) {
                foreach ($team[$unit] as $slot => $player) {
                    if (! is_array($player) || ! is_string($player['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $player['id']) || isset($ids[$player['id']])
                        || ! is_string($player['name'] ?? null) || trim($player['name']) === '' || strlen($player['name']) > 255
                        || ! in_array($player['primary_position'] ?? null, Tactics::POSITIONS, true)
                        || ! is_array($player['secondary_positions'] ?? null) || ($player['available'] ?? true) !== true) {
                        throw new InvalidArgumentException('Atleta inválido, duplicado ou indisponível.');
                    }
                    // Keep the engine independent of Eloquent classes; position codes are domain constants.
                    foreach ($player['secondary_positions'] as $position) {
                        if (! is_string($position) || ! in_array($position, Tactics::POSITIONS, true)) {
                            throw new InvalidArgumentException('Posição secundária inválida.');
                        }
                    }
                    foreach ([...array_keys(Tactics::ATTRIBUTES), 'fitness', 'morale'] as $attribute) {
                        if (! is_int($player[$attribute] ?? null) || $player[$attribute] < 0 || $player[$attribute] > 100) {
                            throw new InvalidArgumentException('Atributos devem ser inteiros entre 0 e 100.');
                        }
                    }
                    if ($player['fitness'] === 0) {
                        throw new InvalidArgumentException('Atleta sem condição física.');
                    }
                    $isKeeper = $player['primary_position'] === 'GK' || in_array('GK', $player['secondary_positions'], true);
                    if ($unit === 'starters' && (($slot === 0) !== $isKeeper)) {
                        throw new InvalidArgumentException('A escalação deve conter um goleiro na primeira vaga e dez jogadores de linha.');
                    }
                    $ids[$player['id']] = true;
                    $team[$unit][$slot] = array_intersect_key($player, array_flip(['id', 'name', 'primary_position', 'secondary_positions', ...array_keys(Tactics::ATTRIBUTES), 'fitness', 'morale', 'available']));
                }
            }
            if (in_array($version, [V2\Simulation::VERSION, V3\Simulation::VERSION, V4\Simulation::VERSION, V5\Simulation::VERSION], true)) {
                $controller = $team['controller'] ?? 'human';
                if (! in_array($controller, ['human', 'ai'], true)
                    || ($controller === 'ai' && ($team['ai_policy_version'] ?? \App\Domain\AI\V1\MatchDecisions::VERSION) !== \App\Domain\AI\V1\MatchDecisions::VERSION)) {
                    throw new InvalidArgumentException('Controlador ou versão da IA inválidos.');
                }
                $team['controller'] = $controller;
                if ($controller === 'ai') {
                    $team['ai_policy_version'] = \App\Domain\AI\V1\MatchDecisions::VERSION;
                }
            }
            $normalized[$side] = $team;
        }
        if (in_array($version, [V4\Simulation::VERSION, V5\Simulation::VERSION], true)) {
            $decisions = $data['decisions'] ?? [];
            if (! is_array($decisions) || ! array_is_list($decisions) || count($decisions) > 100) {
                throw new InvalidArgumentException('Decisões da partida inválidas.');
            }
            $normalized['decisions'] = $decisions;
        }
        $context = $data['home_context'] ?? [];
        if (! is_array($context)) {
            throw new InvalidArgumentException('Contexto de mando inválido.');
        }
        $context += ['neutral' => false, 'attendance' => null, 'capacity' => null, 'engagement' => 0, 'ticket_price_cents' => null];
        if (! is_bool($context['neutral']) || ! is_int($context['engagement']) || $context['engagement'] < 0 || $context['engagement'] > 100) {
            throw new InvalidArgumentException('Contexto de mando inválido.');
        }
        if ($context['attendance'] !== null || $context['capacity'] !== null || $context['ticket_price_cents'] !== null) {
            if (! is_int($context['attendance']) || ! is_int($context['capacity']) || ! is_int($context['ticket_price_cents'])
                || $context['capacity'] < 1 || $context['attendance'] < 0 || $context['attendance'] > $context['capacity']
                || $context['capacity'] > 10000000 || $context['ticket_price_cents'] < 0 || $context['ticket_price_cents'] > 10000000) {
                throw new InvalidArgumentException('Público, capacidade e preço devem ser fornecidos juntos e dentro dos limites.');
            }
        }
        $normalized['home_context'] = array_intersect_key($context, array_flip(['neutral', 'attendance', 'capacity', 'engagement', 'ticket_price_cents']));
        $this->data = $normalized;
    }
}
