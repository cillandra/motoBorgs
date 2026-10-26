<?php

namespace App\Policies;

use App\Models\ReturnRequest;
use App\Models\User;

class ReturnRequestPolicy
{
    public function view(User $user, ReturnRequest $request): bool
    {
        return $user->active && ($user->isManager() || $request->sale->cashier_id === $user->id);
    }

    public function review(User $user, ReturnRequest $request): bool
    {
        return $user->isManager();
    }
}
