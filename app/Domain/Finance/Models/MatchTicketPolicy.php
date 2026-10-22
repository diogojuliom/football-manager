<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class MatchTicketPolicy extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['price_cents' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Career\Models\CareerMatch::class, 'match_id');
    }

    protected static function booted(): void
    {
        static::updating(fn (self $policy) => $policy->assertOpen());
        static::deleting(fn (self $policy) => $policy->assertOpen());
    }

    private function assertOpen(): void
    {
        if (! $this->match()->where('status', 'scheduled')->exists()) {
            throw new LogicException('Preço de partida concluída é imutável.');
        }
    }
}
