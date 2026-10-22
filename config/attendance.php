<?php

// Published attendance rules are versioned. New tuning belongs in a new version.
return [
    'v1' => [
        'version' => '1.0.0',
        'reference_price_cents' => 3000,
        'price_elasticity' => 1.6,
        'minimum_price_factor' => 0.001,
        'maximum_price_factor' => 1.6,
        'realized_variation_percent' => 7,
        'rivalries' => [
            ['aurora', 'ferroviario-serrano'],
            ['atletico-ribeira', 'estrela-portuaria'],
            ['uniao-campo-verde', 'esportivo-lago-dourado'],
            ['real-monte-azul', 'vila-das-pedras'],
            ['nacional-ventos', 'guaranis-horizonte'],
        ],
    ],
];
