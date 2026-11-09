<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function manage(User $actor): bool
    {
        return $actor->active && $actor->role === 'superadmin';
    }
}
