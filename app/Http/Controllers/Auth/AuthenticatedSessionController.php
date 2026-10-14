<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AuthenticatedSessionController extends Controller
{
    /** Failed sign-ins allowed per email+IP before the pair is locked out. */
    public const MAX_ATTEMPTS = 5;

    /** Seconds the lockout lasts. */
    public const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = $this->throttleKey($request, $credentials['email']);

        // Brute-force protection: the counter is keyed on email + IP so one
        // attacker cannot lock out an entire office behind a single NAT.
        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => __('Too many sign-in attempts. Please try again in :seconds seconds.', [
                    'seconds' => $seconds,
                ]),
            ])->status(Response::HTTP_TOO_MANY_REQUESTS);
        }

        $remember = $request->boolean('remember');

        if (! Auth::attempt($credentials, $remember)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $user = Auth::user();

        if (! $user->isActive()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // Counted like a failure: probing for suspended accounts is still probing.
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);

            throw ValidationException::withMessages([
                'email' => __('Your account is not active. Please contact an administrator.'),
            ]);
        }

        // A successful sign-in clears the counter for this email+IP pair.
        RateLimiter::clear($throttleKey);

        $user->forceFill(['last_login_at' => now()])->save();

        // New session id on privilege change: prevents session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    protected function throttleKey(Request $request, string $email): string
    {
        return Str::transliterate(Str::lower($email) . '|' . $request->ip());
    }
}
