<?php

declare(strict_types=1);

namespace Workbench\App\Policies;

use Workbench\App\Models\Product;
use Workbench\App\Models\User;

class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Product $product): bool
    {
        return true;
    }

    /**
     * Locked products are read-only for everybody.
     */
    public function update(User $user, Product $product): bool
    {
        return !$product->locked;
    }
}
