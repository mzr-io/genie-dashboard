<?php

namespace App\Providers;

use App\Modules\Identity\Application\ResetLimits;
use App\Modules\Identity\Application\ResetLinks;
use App\Modules\Identity\Application\ResetPassword;
use App\Modules\Identity\Http\NewPasswordController;
use App\Modules\Identity\Http\PasswordResetLinkController;
use App\Modules\Identity\Http\ResetRequestThrottle;
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
use Laravel\Fortify\Http\Controllers\NewPasswordController as FortifyNewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordResetLinkController as FortifyPasswordResetLinkController;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Where a successful sign-in lands: the Overview of the chosen area (Story 1.13).
        $this->app->singleton(LoginResponse::class, SignInResponse::class);

        // The reset request and reset steps keep Fortify's routes, broker and views but answer without
        // revealing whether an account exists (Story 1.14).
        $this->app->bind(FortifyPasswordResetLinkController::class, PasswordResetLinkController::class);
        $this->app->bind(FortifyNewPasswordController::class, NewPasswordController::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();

        // The reset-link lifetime tunable (the starter kit's 60 minutes while unset).
        ResetLimits::applyLifetime();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetPassword::class);

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

        // An expired, used or tampered link, or one for another email, is one answer: the expired state.
        Fortify::resetPasswordView(function (Request $request) {
            $email = $request->query('email');
            $usable = is_string($email) && $this->app->make(ResetLinks::class)->usable($email, (string) $request->route('token'));

            return Inertia::render('auth/ResetPassword', [
                'email' => $usable ? $email : null,
                'token' => $usable ? $request->route('token') : null,
                'expired' => ! $usable,
                'passwordRules' => Password::defaults()->toPasswordRulesString(),
            ]);
        });

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting. The `login` limiter is the one Fortify's route applies; its limits come
     * from the `pending_input` tunables, with Fortify's shipped 5 per minute while they are unset. The
     * `password-reset` limiter does the same for reset-link requests.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', fn (Request $request) => $this->app->make(SignInThrottle::class)->limit($request));
        RateLimiter::for(ResetRequestThrottle::NAME, fn (Request $request) => $this->app->make(ResetRequestThrottle::class)->limit($request));
    }
}
