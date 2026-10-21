<?php

namespace App\Domain\GameDatabase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ClubTemplate extends Template
{
    protected $casts = [
        'initial_balance_cents' => 'integer',
        'default_ticket_price_cents' => 'integer',
        'potential_supporters' => 'integer',
        'initial_loyalty' => 'integer',
        'initial_engagement' => 'integer',
    ];

    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:8'],
            'city' => ['required', 'string', 'max:255'],
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'secondary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'initial_balance_cents' => ['required', 'integer', 'min:0'],
            'default_ticket_price_cents' => ['required', 'integer', 'min:0'],
            'potential_supporters' => ['required', 'integer', 'min:1'],
            'initial_loyalty' => ['required', 'integer', 'between:0,100'],
            'initial_engagement' => ['required', 'integer', 'between:0,100'],
        ];
    }

    public function competition(): BelongsTo
    {
        return $this->belongsTo(CompetitionTemplate::class, 'competition_template_id');
    }

    public function players(): HasMany
    {
        return $this->hasMany(PlayerTemplate::class);
    }

    public function stadium(): HasOne
    {
        return $this->hasOne(StadiumTemplate::class);
    }
}
