<?php

namespace App\Domain\Career\Models;

use App\Domain\Career\CareerState;
use App\Domain\GameDatabase\Models\GameDatabaseVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Career extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['creation_key', 'creation_checksum'];

    protected $casts = ['current_date' => 'immutable_date', 'state' => CareerState::class];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(GameDatabaseVersion::class, 'game_database_version_id');
    }

    public function clubs(): HasMany
    {
        return $this->hasMany(CareerClub::class);
    }

    public function players(): HasMany
    {
        return $this->hasMany(CareerPlayer::class);
    }

    public function seasons(): HasMany
    {
        return $this->hasMany(CareerSeason::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CareerMembership::class);
    }

    public function controlledClub(): HasOne
    {
        // Persist the club identifier once, in its controller assignment.
        return $this->hasOne(CareerClubController::class)->where('type', 'human');
    }

    public function matches(): HasMany
    {
        return $this->hasMany(CareerMatch::class);
    }
}
