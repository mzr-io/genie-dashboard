<?php

namespace App\Http\Middleware;

use App\Http\Navigation\ShellNavigation;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** `dashflow.tunables.timeouts.autosave_debounce` as a positive whole number of milliseconds, or null: no value is invented. */
    private function autosaveDebounce(): ?int
    {
        $value = config('dashflow.tunables.timeouts.autosave_debounce.value');

        if ((is_int($value) || (is_string($value) && preg_match('/\A[1-9][0-9]{0,8}\z/D', trim($value)) === 1)) && (int) $value > 0) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // Story 3.5: the Create block wizard's autosave debounce in whole milliseconds; null (unset or unusable) means autosave is inert.
            'autosave' => ['debounce_ms' => $this->autosaveDebounce()],
            // The two-area shell's navigation model; lazy, so it is built inside the request's Workspace transaction.
            'shell' => fn () => $request->user() === null ? null : app(ShellNavigation::class)->for($request),
        ];
    }
}
