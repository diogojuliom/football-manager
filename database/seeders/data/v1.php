<?php

// Published data releases are append-only. Change balance or content in a new version file.
// Amounts are integer centavos (BRL); these are initial tuning values, not a balanced economy.
$clubs = [
    ['aurora', 'Aurora Futebol Clube', 'AUR', 'Aurora do Vale', '#D97706', '#FFFFFF', 'Estádio do Amanhecer', 18000, 3, 65],
    ['ferroviario-serrano', 'Ferroviário Serrano', 'FES', 'Serra Alta', '#991B1B', '#111827', 'Estádio dos Trilhos', 22000, 3, 69],
    ['atletico-ribeira', 'Atlético da Ribeira', 'ATR', 'Ribeira Nova', '#1D4ED8', '#FFFFFF', 'Arena das Águas', 16000, 2, 63],
    ['uniao-campo-verde', 'União Campo Verde', 'UCV', 'Campo Verde', '#15803D', '#FFFFFF', 'Estádio das Palmeiras', 20000, 3, 67],
    ['estrela-portuaria', 'Estrela Portuária', 'EPT', 'Porto Estrela', '#0E7490', '#FDE047', 'Arena do Farol', 24000, 4, 72],
    ['real-monte-azul', 'Real Monte Azul', 'RMA', 'Monte Azul', '#4338CA', '#FFFFFF', 'Estádio do Mirante', 14000, 2, 61],
    ['esportivo-lago-dourado', 'Esportivo Lago Dourado', 'ELD', 'Lago Dourado', '#CA8A04', '#111827', 'Estádio das Garças', 18000, 3, 66],
    ['vila-das-pedras', 'Vila das Pedras Esporte Clube', 'VPE', 'Vila das Pedras', '#6D28D9', '#FFFFFF', 'Arena da Pedreira', 12000, 2, 60],
    ['nacional-ventos', 'Nacional dos Ventos', 'NVE', 'Ventos do Sul', '#BE123C', '#FFFFFF', 'Estádio das Brisas', 26000, 4, 73],
    ['guaranis-horizonte', 'Guaranis do Horizonte', 'GHO', 'Novo Horizonte', '#166534', '#F97316', 'Arena do Horizonte', 20000, 3, 68],
];
$firstNames = ['Caio', 'Davi', 'Ícaro', 'Bento', 'Heitor', 'Nilo', 'Ravi', 'Luan', 'Otávio', 'Gael', 'Enzo', 'Samuel', 'Theo', 'Murilo', 'Danilo', 'Vicente', 'Cauã', 'Miguel', 'Hugo', 'Arthur'];
$lastNames = ['Valverde', 'Montelar', 'Ribeiral', 'Campovaz', 'Portelar', 'Azulmar', 'Doural', 'Pedravil', 'Venturim', 'Horizal'];
$positions = ['GK', 'GK', 'CB', 'CB', 'CB', 'CB', 'LB', 'LB', 'RB', 'RB', 'DM', 'DM', 'CM', 'CM', 'CM', 'AM', 'LW', 'RW', 'ST', 'ST'];
$secondary = ['GK' => [], 'CB' => ['DM'], 'LB' => ['LW'], 'RB' => ['RW'], 'DM' => ['CM'], 'CM' => ['DM', 'AM'], 'AM' => ['CM'], 'LW' => ['AM'], 'RW' => ['AM'], 'ST' => ['LW']];
$specialties = [
    'defense' => ['CB', 'LB', 'RB', 'DM'],
    'tackling' => ['CB', 'LB', 'RB', 'DM'],
    'passing' => ['DM', 'CM', 'AM'],
    'creativity' => ['CM', 'AM', 'LW', 'RW'],
    'finishing' => ['ST', 'AM', 'LW', 'RW'],
    'speed' => ['LB', 'RB', 'LW', 'RW', 'ST'],
    'stamina' => ['LB', 'RB', 'DM', 'CM'],
    'discipline' => ['CB', 'DM'],
    'composure' => ['GK', 'CB', 'CM'],
    'set_pieces' => ['AM', 'CM', 'ST'],
    'goalkeeping' => ['GK'],
];

$data = [
    'version' => '1.0.0',
    'name' => 'Universo fictício inicial — PRD 2.1',
    'ruleset' => [
        'code' => 'liga-10-turno-returno',
        'format' => 'double_round_robin',
        'club_count' => 10,
        'legs' => 2,
        'round_count' => 18,
        'match_count' => 90,
        'points_for_win' => 3,
        'points_for_draw' => 1,
        'points_for_loss' => 0,
        'tiebreakers' => ['wins', 'goal_difference', 'goals_for', 'club_code'],
    ],
    'competition' => ['code' => 'liga-horizonte', 'name' => 'Liga do Horizonte'],
    'clubs' => [],
];

foreach ($clubs as $index => [$code, $name, $shortName, $city, $primaryColor, $secondaryColor, $stadiumName, $capacity, $level, $strength]) {
    $players = [];
    foreach ($positions as $slot => $position) {
        $player = [
            'code' => $code.'-'.str_pad((string) ($slot + 1), 2, '0', STR_PAD_LEFT),
            'name' => $firstNames[$slot].' '.$lastNames[$index],
            'age' => 18 + (($index * 3 + $slot * 7) % 18),
            'primary_position' => $position,
            'secondary_positions' => $secondary[$position],
            'initial_fitness' => 100,
            'initial_morale' => 70,
        ];
        // Integer arithmetic only: identical release data on every seed, independent of time/RNG.
        foreach ($specialties as $attribute => $preferredPositions) {
            $variation = (($index * 11 + $slot * 7 + strlen($attribute)) % 17) - 8;
            $rating = $strength - 12 + $variation + (in_array($position, $preferredPositions, true) ? 18 : 0);
            if ($attribute === 'goalkeeping' && $position !== 'GK') {
                $rating = 5 + ($slot % 11);
            }
            $player[$attribute] = max(0, min(100, $rating));
        }
        $players[] = $player;
    }

    $data['clubs'][] = [
        'club' => [
            'code' => $code,
            'name' => $name,
            'short_name' => $shortName,
            'city' => $city,
            'primary_color' => $primaryColor,
            'secondary_color' => $secondaryColor,
            'initial_balance_cents' => 30000000 + ($index * 2500000),
            'default_ticket_price_cents' => 3000,
            'potential_supporters' => $capacity * 2,
            'initial_loyalty' => 65 + ($index % 4) * 5,
            'initial_engagement' => 60,
        ],
        'stadium' => [
            'name' => $stadiumName,
            'capacity' => $capacity,
            'available_capacity' => $capacity,
            'infrastructure_level' => $level,
            'fixed_operating_cost_cents' => 1500000 + ($level * 250000),
            'variable_cost_per_supporter_cents' => 500,
        ],
        'players' => $players,
    ];
}

return $data;
