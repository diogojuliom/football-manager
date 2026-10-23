<?php

namespace App\Domain\GameDatabase\Models;

use App\Domain\Tactics\Tactics;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;

class PlayerTemplate extends Template
{
    public const ATTRIBUTES = ['defense', 'tackling', 'passing', 'creativity', 'finishing', 'speed', 'stamina', 'discipline', 'composure', 'set_pieces', 'goalkeeping'];

    public const POSITIONS = Tactics::POSITIONS;

    protected $casts = ['secondary_positions' => 'array'];

    protected function rules(): array
    {
        $rules = [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'age' => ['required', 'integer', 'between:16,45'],
            'primary_position' => ['required', Rule::in(self::POSITIONS)],
            'secondary_positions' => ['present', 'array'],
            'secondary_positions.*' => ['required', 'distinct', Rule::in(self::POSITIONS), Rule::notIn([$this->primary_position])],
        ];
        foreach ([...self::ATTRIBUTES, 'initial_fitness', 'initial_morale'] as $attribute) {
            $rules[$attribute] = ['required', 'integer', 'between:0,100'];
        }

        return $rules;
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(ClubTemplate::class, 'club_template_id');
    }
}
