<?php

namespace App\Policies;

use App\Domain\Career\Models\Career;
use App\Models\User;

class CareerPolicy
{
    public function view(User $user, Career $career): bool
    {
        return $career->user_id === $user->id;
    }
}
