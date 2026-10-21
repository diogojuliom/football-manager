<?php

namespace App\Domain\Football\Engine;

use App\Domain\Tactics\Tactics;
use InvalidArgumentException;

final class MatchInput
{
    public readonly array $data;

    public function __construct(array $data)
    {
        if (($data['engine_version'] ?? FootballMatchEngine::VERSION) !== FootballMatchEngine::VERSION) {
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
        $normalized = ['engine_version' => FootballMatchEngine::VERSION, 'seed' => $data['seed'], 'rules' => ['max_substitutions' => $limit]];
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
                        || ! in_array($player['primary_position'] ?? null, ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'], true)
                        || ! is_array($player['secondary_positions'] ?? null) || ($player['available'] ?? true) !== true) {
                        throw new InvalidArgumentException('Atleta inválido, duplicado ou indisponível.');
                    }
                    // Keep the engine independent of Eloquent classes; position codes are domain constants.
                    foreach ($player['secondary_positions'] as $position) {
                        if (! is_string($position) || ! in_array($position, ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'], true)) {
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
            $normalized[$side] = $team;
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
