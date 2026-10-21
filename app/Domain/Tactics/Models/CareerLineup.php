<?php

namespace App\Domain\Tactics\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareerLineup extends Model
{
    protected $guarded = ['id'];

    public function selections(): HasMany
    {
        return $this->hasMany(CareerLineupPlayer::class)->orderBy('slot');
    }
}
