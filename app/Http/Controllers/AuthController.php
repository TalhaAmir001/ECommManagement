<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * Show the sign-in form. Authenticated users are sent straight to the
     * dashboard instead.
     */
    public function showLoginForm(): View
    {
        return view('auth.login');
    }

    /**
     * Attempt to sign in with the operator credentials from the
     * environment (config/admin.php -> ADMIN_*). On success the identity
     * is mirrored into the users table and the standard session guard is
     * used from there on.
     */
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email'],
            'password' => ['required', 'string'],
        ]);

        $operatorEmail = strtolower((string) config('admin.email'));
        $operatorPassword = (string) config('admin.password');
        $providedEmail = strtolower($credentials['email']);

        $validCredentials = $operatorEmail !== ''
            && $providedEmail === $operatorEmail
            && hash_equals($operatorPassword, $credentials['password'])
            && $operatorPassword !== '';

        if (! $validCredentials) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors([
                    'email' => 'These credentials do not match our records.',
                ]);
        }

        // Mirror the operator into the users table (create or update on
        // every successful login) so the rest of the app can use the
        // framework's session auth and auth()->user() as usual.
        $user = User::updateOrCreate(
            ['email' => $operatorEmail],
            [
                'name' => (string) config('admin.name'),
                'email_verified_at' => now(),
                'password' => Hash::make($operatorPassword),
            ]
        );

        Auth::login($user, $request->boolean('remember'));

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Sign the current user out and return to the login page.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
