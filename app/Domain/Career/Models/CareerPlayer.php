<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerPlayer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['secondary_positions' => 'array', 'injured_until' => 'immutable_date', 'suspended_matches' => 'integer'];

    public function isAvailableOn(\Carbon\CarbonInterface $date): bool
    {
        return $this->fitness > 0
            && $this->suspended_matches === 0
            && ($this->injured_until === null || $this->injured_until->lt($date));
    }

    public function canPlay(string $position): bool
    {
        return $this->primary_position === $position || in_array($position, $this->secondary_positions, true);
    }

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'career_club_id');
    }
}
