<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class StaffSessionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $request->merge(['phone' => User::normalizePhone($request->input('phone'))]);
        $credentials = $request->validate([
            'phone' => ['required', 'string'],
            'password' => ['required', 'string', 'max:72'],
        ]);
        $key = 'staff-login:'.hash('sha256', $credentials['phone'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['phone' => 'Terlalu banyak percobaan. Coba lagi dalam satu menit.'])->status(429);
        }

        if (! Auth::attempt($credentials + ['active' => true])) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages(['phone' => 'Nomor telepon atau kata sandi tidak dapat digunakan untuk masuk.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        /** @var User $user */
        $user = $request->user();
        $request->session()->put('staff_access_version', $user->access_version);

        return redirect()->intended(route('staff'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
