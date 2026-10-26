<?php

namespace App\Services\Pos;

use App\Models\Sale;

final readonly class CheckoutResult
{
    public function __construct(public Sale $sale, public bool $replayed) {}
}
