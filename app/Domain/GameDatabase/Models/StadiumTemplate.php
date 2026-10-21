<?php

namespace App\Domain\GameDatabase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StadiumTemplate extends Template
{
    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:1'],
            'available_capacity' => ['required', 'integer', 'min:0', 'lte:capacity'],
            'infrastructure_level' => ['required', 'integer', 'between:1,5'],
            'fixed_operating_cost_cents' => ['required', 'integer', 'min:0'],
            'variable_cost_per_supporter_cents' => ['required', 'integer', 'min:0'],
        ];
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(ClubTemplate::class, 'club_template_id');
    }
}
