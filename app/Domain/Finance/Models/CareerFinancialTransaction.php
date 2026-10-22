<?php

namespace App\Domain\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class CareerFinancialTransaction extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['occurred_on' => 'immutable_date', 'amount_cents' => 'integer', 'balance_after_cents' => 'integer'];

    public function match(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Career\Models\CareerMatch::class, 'match_id');
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Lançamento financeiro é imutável.'));
        static::deleting(fn () => throw new LogicException('Lançamento financeiro é imutável.'));
    }
}
