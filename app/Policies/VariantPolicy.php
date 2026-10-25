<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Variant;

class VariantPolicy
{
    public function view(User $user, Variant $variant): bool
    {
        return $user->active && ($user->isManager() || (! $variant->archived_at && ! $variant->product->archived_at));
    }

    public function update(User $user, Variant $variant): bool
    {
        return $user->isManager();
    }

    public function create(User $user): bool
    {
        return $user->isManager();
    }
}
