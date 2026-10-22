<?php

namespace App\Domain\Football\Models;

use App\Domain\Career\Models\CareerMatch;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OfficialMatchResult extends MatchSimulation
{
    protected $table = 'official_match_results';

    protected $casts = ['input' => 'array', 'result' => 'array', 'played_on' => 'immutable_date', 'home_goals' => 'integer', 'away_goals' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(CareerMatch::class, 'match_id');
    }

    public function attendance(): HasOne
    {
        return $this->hasOne(\App\Domain\Attendance\Models\MatchAttendance::class, 'match_id', 'match_id');
    }
}
