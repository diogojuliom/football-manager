<?php

namespace App\Domain\Tactics;

class Tactics
{
    public const FORMATIONS = [
        '4-4-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'LW', 'CM', 'CM', 'RW', 'ST', 'ST'],
        '4-3-3' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'CM', 'CM', 'LW', 'ST', 'RW'],
        '4-2-3-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'DM', 'LW', 'AM', 'RW', 'ST'],
        '3-5-2' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'DM', 'CM', 'RW', 'ST', 'ST'],
    ];

    public const OPTIONS = [
        'mentality' => ['defensive' => 'Defensiva', 'balanced' => 'Equilibrada', 'attacking' => 'Ofensiva'],
        'pressure' => ['low' => 'Baixa', 'normal' => 'Normal', 'high' => 'Alta'],
        'tempo' => ['slow' => 'Lento', 'normal' => 'Normal', 'fast' => 'Rápido'],
        'build_up' => ['short' => 'Passes curtos', 'balanced' => 'Equilibrada', 'direct' => 'Jogo direto'],
    ];

    public const LABELS = ['mentality' => 'Mentalidade', 'pressure' => 'Pressão', 'tempo' => 'Ritmo', 'build_up' => 'Construção de jogadas'];

    public const ATTRIBUTES = [
        'defense' => 'Defesa', 'tackling' => 'Desarme', 'passing' => 'Passe', 'creativity' => 'Criatividade',
        'finishing' => 'Finalização', 'speed' => 'Velocidade', 'stamina' => 'Resistência', 'discipline' => 'Disciplina',
        'composure' => 'Controle emocional', 'set_pieces' => 'Bolas paradas', 'goalkeeping' => 'Habilidade de goleiro',
    ];
}
