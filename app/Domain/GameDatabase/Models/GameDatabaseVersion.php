<?php

namespace App\Domain\GameDatabase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameDatabaseVersion extends Model
{
    protected $guarded = ['id'];

    public function clubs(): HasMany
    {
        return $this->hasMany(ClubTemplate::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(PlayerTemplate::class);
    }

    public function stadiums(): HasMany
    {
        return $this->hasMany(StadiumTemplate::class);
    }

    public function competitions(): HasMany
    {
        return $this->hasMany(CompetitionTemplate::class);
    }

    public function rulesets(): HasMany
    {
        return $this->hasMany(CompetitionRuleset::class);
    }
}
