<?php

namespace App\Domain\Football\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InteractiveMatchRequest extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['response' => 'array', 'result_revision' => 'integer'];

    public function session(): BelongsTo
    {
        return $this->belongsTo(InteractiveMatchSession::class, 'interactive_match_session_id');
    }
}
