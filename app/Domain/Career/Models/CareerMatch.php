<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerMatch extends Model
{
    protected $table = 'matches';

    protected $guarded = ['id'];

    protected $casts = ['scheduled_on' => 'immutable_date'];

    public function homeClub(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'home_club_id');
    }

    public function awayClub(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'away_club_id');
    }
}
