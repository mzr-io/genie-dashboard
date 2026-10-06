<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Modules\Identity\Http\SignInPipe;
use App\Modules\Identity\Http\SignInResponse;
use App\Modules\Identity\Http\SignInThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Where a successful sign-in lands: the Overview of the chosen area (Story 1.13).
        $this->app->singleton(LoginResponse::class, SignInResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        // Role-aware sign-in replaces Fortify's login pipeline: credentials, area check, rotated session.
        Fortify::authenticateThrough(fn () => [SignInPipe::class]);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting. The `login` limiter is the one Fortify's route applies; its limits come
     * from the `pending_input` tunables, with Fortify's shipped 5 per minute while they are unset.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => $this->app->make(SignInThrottle::class)->limit($request));
    }
}
