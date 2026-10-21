<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompetitionEdition extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['rules' => 'array'];

    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }
}
