<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerSeason extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['starts_on' => 'immutable_date'];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }
}
