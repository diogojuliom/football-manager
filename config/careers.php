<?php

return [
    // Game time is deliberately independent of the server clock.
    'initial_date' => '2026-01-01',
    'first_match_after_days' => 13,
    'days_between_rounds' => 7,
    // A new season begins only after the user confirms this internal-calendar jump.
    'offseason_days' => 70,
    'daily_fitness_recovery' => 8,
    'minimum_squad_size' => 20,
    'minimum_goalkeepers' => 2,
    'retirement_age' => 40,
];
