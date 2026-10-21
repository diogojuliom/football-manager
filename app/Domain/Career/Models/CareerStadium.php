<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerStadium extends Model
{
    protected $guarded = ['id'];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'career_club_id');
    }
}
