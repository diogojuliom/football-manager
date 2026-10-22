<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StadiumProject extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'cost_cents' => 'integer', 'capacity_added' => 'integer', 'infrastructure_added' => 'integer',
        'duration_days' => 'integer', 'started_on' => 'immutable_date', 'due_on' => 'immutable_date',
        'completed_on' => 'immutable_date',
    ];

    public function stadium(): BelongsTo
    {
        return $this->belongsTo(CareerStadium::class, 'career_stadium_id');
    }

    public function club(): BelongsTo
    {
        return $this->belongsTo(CareerClub::class, 'career_club_id');
    }
}
