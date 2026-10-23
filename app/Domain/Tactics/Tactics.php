<?php

namespace App\Domain\Tactics;

class Tactics
{
    public const MODEL_VERSION = '1.0.0';

    public const POSITIONS = ['GK', 'CB', 'LB', 'RB', 'DM', 'CM', 'AM', 'LW', 'RW', 'ST'];

    /** Labels shown to players. Internal identifiers remain in English. */
    public const POSITION_LABELS = [
        'GK' => 'GOL', 'CB' => 'ZAG', 'LB' => 'LE', 'RB' => 'LD', 'DM' => 'VOL',
        'CM' => 'MC', 'AM' => 'MEI', 'LW' => 'PE', 'RW' => 'PD', 'ST' => 'ATA',
    ];

    public const POSITION_NAMES = [
        'GK' => 'Goleiro', 'CB' => 'Zagueiro', 'LB' => 'Lateral esquerdo', 'RB' => 'Lateral direito',
        'DM' => 'Volante', 'CM' => 'Meio-campista central', 'AM' => 'Meia ofensivo',
        'LW' => 'Ponta esquerda', 'RW' => 'Ponta direita', 'ST' => 'Atacante',
    ];

    public const FORMATIONS = [
        '4-4-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'LW', 'CM', 'CM', 'RW', 'ST', 'ST'],
        '4-3-3' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'CM', 'CM', 'LW', 'ST', 'RW'],
        '4-2-3-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'DM', 'LW', 'AM', 'RW', 'ST'],
        '3-5-2' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'DM', 'CM', 'RW', 'ST', 'ST'],
        '4-1-4-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'LW', 'CM', 'CM', 'RW', 'ST'],
        '4-5-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'LW', 'CM', 'CM', 'CM', 'RW', 'ST'],
        '4-4-1-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'LW', 'CM', 'CM', 'RW', 'AM', 'ST'],
        '4-2-2-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'DM', 'AM', 'AM', 'ST', 'ST'],
        '4-1-2-1-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'CM', 'CM', 'AM', 'ST', 'ST'],
        '4-3-1-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'CM', 'CM', 'CM', 'AM', 'ST', 'ST'],
        '4-3-2-1' => ['GK', 'LB', 'CB', 'CB', 'RB', 'CM', 'CM', 'CM', 'AM', 'AM', 'ST'],
        '4-2-4' => ['GK', 'LB', 'CB', 'CB', 'RB', 'CM', 'CM', 'LW', 'ST', 'ST', 'RW'],
        '4-1-3-2' => ['GK', 'LB', 'CB', 'CB', 'RB', 'DM', 'LW', 'CM', 'RW', 'ST', 'ST'],
        '3-4-3' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'CM', 'RW', 'LW', 'ST', 'RW'],
        '3-4-2-1' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'CM', 'RW', 'AM', 'AM', 'ST'],
        '3-4-1-2' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'CM', 'RW', 'AM', 'ST', 'ST'],
        '3-5-1-1' => ['GK', 'CB', 'CB', 'CB', 'LW', 'CM', 'DM', 'CM', 'RW', 'AM', 'ST'],
        '5-3-2' => ['GK', 'LB', 'CB', 'CB', 'CB', 'RB', 'CM', 'DM', 'CM', 'ST', 'ST'],
        '5-4-1' => ['GK', 'LB', 'CB', 'CB', 'CB', 'RB', 'LW', 'CM', 'CM', 'RW', 'ST'],
        '5-2-3' => ['GK', 'LB', 'CB', 'CB', 'CB', 'RB', 'CM', 'CM', 'LW', 'ST', 'RW'],
        '3-6-1' => ['GK', 'CB', 'CB', 'CB', 'LW', 'DM', 'CM', 'CM', 'AM', 'RW', 'ST'],
    ];

    /** Coordinates are percentages from the top-left of the attacking pitch. */
    public const SHAPES = [
        '4-4-2' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [14, 48], [38, 53], [62, 53], [86, 48], [38, 18], [62, 18]],
        '4-3-3' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [50, 62], [32, 48], [68, 48], [16, 24], [50, 15], [84, 24]],
        '4-2-3-1' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [35, 61], [65, 61], [18, 38], [50, 34], [82, 38], [50, 14]],
        '3-5-2' => [[50, 92], [22, 77], [50, 80], [78, 77], [10, 48], [32, 51], [50, 62], [68, 51], [90, 48], [35, 16], [65, 16]],
        '4-1-4-1' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [50, 63], [13, 43], [37, 48], [63, 48], [87, 43], [50, 14]],
        '4-5-1' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [10, 47], [30, 52], [50, 48], [70, 52], [90, 47], [50, 14]],
        '4-4-1-1' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [14, 49], [38, 53], [62, 53], [86, 49], [50, 31], [50, 13]],
        '4-2-2-2' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [35, 61], [65, 61], [30, 37], [70, 37], [35, 15], [65, 15]],
        '4-1-2-1-2' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [50, 63], [34, 49], [66, 49], [50, 33], [35, 14], [65, 14]],
        '4-3-1-2' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [25, 52], [50, 55], [75, 52], [50, 34], [35, 14], [65, 14]],
        '4-3-2-1' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [25, 54], [50, 57], [75, 54], [35, 34], [65, 34], [50, 13]],
        '4-2-4' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [35, 54], [65, 54], [12, 25], [38, 14], [62, 14], [88, 25]],
        '4-1-3-2' => [[50, 92], [14, 74], [38, 78], [62, 78], [86, 74], [50, 63], [20, 44], [50, 48], [80, 44], [35, 14], [65, 14]],
        '3-4-3' => [[50, 92], [22, 77], [50, 80], [78, 77], [14, 51], [38, 55], [62, 55], [86, 51], [17, 23], [50, 14], [83, 23]],
        '3-4-2-1' => [[50, 92], [22, 77], [50, 80], [78, 77], [14, 51], [38, 55], [62, 55], [86, 51], [35, 33], [65, 33], [50, 13]],
        '3-4-1-2' => [[50, 92], [22, 77], [50, 80], [78, 77], [14, 51], [38, 55], [62, 55], [86, 51], [50, 34], [35, 14], [65, 14]],
        '3-5-1-1' => [[50, 92], [22, 77], [50, 80], [78, 77], [10, 48], [32, 51], [50, 62], [68, 51], [90, 48], [50, 32], [50, 13]],
        '5-3-2' => [[50, 92], [9, 70], [29, 77], [50, 80], [71, 77], [91, 70], [28, 50], [50, 61], [72, 50], [35, 15], [65, 15]],
        '5-4-1' => [[50, 92], [9, 70], [29, 77], [50, 80], [71, 77], [91, 70], [14, 47], [38, 52], [62, 52], [86, 47], [50, 14]],
        '5-2-3' => [[50, 92], [9, 70], [29, 77], [50, 80], [71, 77], [91, 70], [35, 52], [65, 52], [16, 24], [50, 14], [84, 24]],
        '3-6-1' => [[50, 92], [22, 77], [50, 80], [78, 77], [9, 48], [28, 61], [43, 49], [57, 49], [72, 35], [91, 48], [50, 13]],
    ];

    /** Relative tactical tendencies consumed by engine V3. */
    public const PROFILES = [
        '4-4-2' => ['attack' => 1.00, 'defense' => 1.00, 'control' => 1.00],
        '4-3-3' => ['attack' => 1.05, 'defense' => 0.96, 'control' => 1.02],
        '4-2-3-1' => ['attack' => 1.02, 'defense' => 1.02, 'control' => 1.04],
        '3-5-2' => ['attack' => 1.02, 'defense' => 0.98, 'control' => 1.06],
        '4-1-4-1' => ['attack' => 0.96, 'defense' => 1.06, 'control' => 1.04],
        '4-5-1' => ['attack' => 0.93, 'defense' => 1.04, 'control' => 1.08],
        '4-4-1-1' => ['attack' => 1.00, 'defense' => 1.01, 'control' => 1.02],
        '4-2-2-2' => ['attack' => 1.06, 'defense' => 1.01, 'control' => 0.96],
        '4-1-2-1-2' => ['attack' => 1.04, 'defense' => 1.02, 'control' => 1.03],
        '4-3-1-2' => ['attack' => 1.05, 'defense' => 0.98, 'control' => 1.04],
        '4-3-2-1' => ['attack' => 1.02, 'defense' => 1.00, 'control' => 1.06],
        '4-2-4' => ['attack' => 1.12, 'defense' => 0.90, 'control' => 0.93],
        '4-1-3-2' => ['attack' => 1.05, 'defense' => 1.00, 'control' => 1.01],
        '3-4-3' => ['attack' => 1.10, 'defense' => 0.91, 'control' => 1.00],
        '3-4-2-1' => ['attack' => 1.07, 'defense' => 0.94, 'control' => 1.03],
        '3-4-1-2' => ['attack' => 1.08, 'defense' => 0.93, 'control' => 1.01],
        '3-5-1-1' => ['attack' => 0.98, 'defense' => 0.99, 'control' => 1.09],
        '5-3-2' => ['attack' => 0.96, 'defense' => 1.10, 'control' => 0.99],
        '5-4-1' => ['attack' => 0.91, 'defense' => 1.13, 'control' => 0.98],
        '5-2-3' => ['attack' => 1.04, 'defense' => 1.06, 'control' => 0.94],
        '3-6-1' => ['attack' => 0.95, 'defense' => 0.98, 'control' => 1.12],
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

    public static function slots(string $formation): array
    {
        return array_map(
            fn (string $role, array $coordinates): array => [
                'role' => $role,
                'label' => self::positionLabel($role),
                'name' => self::positionName($role),
                'x' => $coordinates[0],
                'y' => $coordinates[1],
            ],
            self::FORMATIONS[$formation],
            self::SHAPES[$formation],
        );
    }

    public static function positionLabel(string $position): string
    {
        return self::POSITION_LABELS[$position] ?? $position;
    }

    public static function positionName(string $position): string
    {
        return self::POSITION_NAMES[$position] ?? $position;
    }

    public static function formationModel(string $formation): array
    {
        return [
            'id' => $formation,
            'slots' => self::slots($formation),
            'profile' => self::PROFILES[$formation],
        ];
    }

    /**
     * Serializable contract shared with the tactical board. Coordinates are
     * normalized now so free positioning can be added without changing shape data.
     */
    public static function frontendModel(): array
    {
        $positions = [];

        foreach (self::POSITIONS as $position) {
            $positions[$position] = [
                'label' => self::positionLabel($position),
                'name' => self::positionName($position),
            ];
        }

        $formations = [];

        foreach (array_keys(self::FORMATIONS) as $formation) {
            $formations[$formation] = self::formationModel($formation);
        }

        return [
            'version' => self::MODEL_VERSION,
            'coordinateSystem' => ['unit' => 'percent', 'min' => 0, 'max' => 100, 'origin' => 'top-left'],
            'interaction' => ['mode' => 'fixed', 'draggable' => false, 'swappable' => false],
            'positions' => $positions,
            'formations' => $formations,
        ];
    }
}
