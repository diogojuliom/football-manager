<?php

namespace App\Domain\Tactics\Models;

use App\Domain\Career\Models\CareerPlayer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerLineupPlayer extends Model
{
    protected $guarded = ['id'];

    public function player(): BelongsTo
    {
        return $this->belongsTo(CareerPlayer::class, 'career_player_id');
    }
}
