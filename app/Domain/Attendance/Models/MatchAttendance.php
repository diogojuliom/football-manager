<?php

namespace App\Domain\Attendance\Models;

use App\Domain\Career\Models\CareerMatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MatchAttendance extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['played_on' => 'immutable_date', 'snapshot' => 'array', 'capacity' => 'integer',
        'expected_demand' => 'integer', 'forecast' => 'integer', 'realized' => 'integer',
        'occupancy_bps' => 'integer', 'importance' => 'integer', 'ticket_price_cents' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(CareerMatch::class, 'match_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Público de partida concluída é imutável.'));
        static::deleting(fn () => throw new LogicException('Público de partida concluída é imutável.'));
    }
}
