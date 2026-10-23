<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerSeason extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'immutable_date', 'ended_on' => 'immutable_date', 'summary' => 'array', 'transition_summary' => 'array'];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }
}
