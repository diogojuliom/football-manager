<?php

namespace App\Domain\GameDatabase\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompetitionTemplate extends Template
{
    protected function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(CompetitionRuleset::class, 'competition_ruleset_id');
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(ClubTemplate::class);
    }
}
