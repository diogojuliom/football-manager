<?php

namespace App\Domain\Career\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CareerMatch extends Model
{
    protected $table = 'matches';

    protected $guarded = ['id'];

    protected $casts = ['scheduled_on' => 'immutable_date'];

    public function scopeOnDate(Builder $query, CarbonImmutable $date): Builder
    {
        // Keep the index usable and accept both DATE and legacy SQLite midnight timestamps.
        return $query->where('scheduled_on', '>=', $date->toDateString())
            ->where('scheduled_on', '<', $date->addDay()->toDateString());
    }

    public function result(): HasOne
    {
        return $this->hasOne(\App\Domain\Football\Models\OfficialMatchResult::class, 'match_id');
    }

    public function homeClub(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'home_club_id');
    }

    public function awayClub(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'away_club_id');
    }
}
