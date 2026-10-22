<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;

class CareerEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['scheduled_on' => 'immutable_date', 'processed_on' => 'immutable_date', 'requires_decision' => 'boolean'];
}
