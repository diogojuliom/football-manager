<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CareerStadium extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'capacity' => 'integer', 'available_capacity' => 'integer', 'infrastructure_level' => 'integer',
    ];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'career_club_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(StadiumProject::class);
    }
}
