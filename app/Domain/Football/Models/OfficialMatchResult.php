<?php

namespace App\Domain\Football\Models;

use App\Domain\Career\Models\CareerMatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficialMatchResult extends MatchSimulation
{
    protected $table = 'official_match_results';

    protected $casts = ['input' => 'array', 'result' => 'array', 'played_on' => 'immutable_date', 'home_goals' => 'integer', 'away_goals' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(CareerMatch::class, 'match_id');
    }
}
