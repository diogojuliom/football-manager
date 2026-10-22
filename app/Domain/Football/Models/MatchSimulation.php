<?php

namespace App\Domain\Football\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

class MatchSimulation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['input' => 'array', 'result' => 'array', 'attendance_plan' => 'array'];

    protected function asJson($value)
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Simulações concluídas são imutáveis.'));
        static::deleting(fn () => throw new LogicException('Simulações concluídas são imutáveis.'));
    }
}
