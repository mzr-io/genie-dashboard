<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * The Horizon dashboard is denied to everyone, in every environment, until
     * a later story grants it to an operator role.
     */
    protected function authorization(): void
    {
        $this->gate();

        Horizon::auth(fn ($request): bool => Gate::check('viewHorizon', [$request->user()]));
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn ($user = null): bool => false);
    }
}
