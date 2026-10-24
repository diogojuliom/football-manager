<?php

namespace App\Domain\Football\Models;

use App\Domain\Career\Models\Career;
use App\Domain\Career\Models\CareerMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InteractiveMatchSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'paused' => 'boolean', 'minute' => 'integer', 'revision' => 'integer',
        'input' => 'array', 'state' => 'array', 'meta' => 'array', 'result' => 'array',
    ];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    public function match(): BelongsTo
    {
        return $this->belongsTo(CareerMatch::class, 'match_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(InteractiveMatchRequest::class);
    }
}
