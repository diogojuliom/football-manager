<?php

namespace App\Domain\Career\Models;

use Illuminate\Database\Eloquent\Model;

class CareerOperation extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['input' => 'array', 'result' => 'array', 'from_date' => 'immutable_date', 'target_date' => 'immutable_date'];
}
