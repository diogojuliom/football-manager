<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CareerClub extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['initial_balance_cents' => 'integer', 'balance_cents' => 'integer'];

    public function lineup(): HasOne
    {
        return $this->hasOne(\App\Domain\Tactics\Models\CareerLineup::class);
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(CareerPlayer::class)->whereNull('retired_on');
    }

    public function allPlayers(): HasMany
    {
        return $this->hasMany(CareerPlayer::class);
    }

    public function stadium(): HasOne
    {
        return $this->hasOne(CareerStadium::class);
    }

    public function support(): HasOne
    {
        return $this->hasOne(CareerClubSupport::class);
    }
}
