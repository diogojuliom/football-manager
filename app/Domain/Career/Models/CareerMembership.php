<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CareerMembership extends Model
{
    protected $guarded = ['id'];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }
}
