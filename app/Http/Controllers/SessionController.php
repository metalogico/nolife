<?php

namespace App\Http\Controllers;

use App\PersonalAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SessionController extends Controller
{
    public function show(Request $request, PersonalAccess $access): JsonResponse
    {
        $authenticated = $access->authenticated($request);

        return response()->json([
            'authenticated' => $authenticated,
            'configured' => $access->configured(),
            'username' => $authenticated ? config('nolife.username') : null,
            'igdb_enabled' => $authenticated && filled(config('nolife.igdb_client_id')) && filled(config('nolife.igdb_client_secret')),
            'csrf_token' => csrf_token(),
        ]);
    }

    public function store(Request $request, PersonalAccess $access): JsonResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:100'],
            'password' => ['required', 'string', 'max:1000'],
        ]);
        $key = 'nolife-login:'.hash('sha256', $request->ip().'|'.mb_strtolower($credentials['username']));
        abort_if(RateLimiter::tooManyAttempts($key, 5), 429, 'Too many attempts. Try again in a minute.');
        abort_unless($access->configured(), 503, 'Personal access has not been configured.');

        $usernameMatches = hash_equals(config('nolife.username'), $credentials['username']);
        if (! $usernameMatches || ! Hash::check($credentials['password'], config('nolife.password_hash'))) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['username' => 'The username or password is incorrect.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('personal_access', $access->fingerprint());

        return $this->show($request, $access);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['csrf_token' => csrf_token()]);
    }
}
