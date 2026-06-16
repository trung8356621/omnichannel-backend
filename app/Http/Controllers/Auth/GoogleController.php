<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class GoogleController extends Controller
{
    public function redirectToGoogle(): RedirectResponse
    {
        $returnUrl = request()->query('return_url');
        if (is_string($returnUrl) && str_starts_with($returnUrl, '/') && ! str_starts_with($returnUrl, '//')) {
            session(['url.intended' => $returnUrl]);
        }

        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback(): RedirectResponse
    {
        $fallbackUrl = $this->resolveFallbackUrl();

        try {
            $client = new \GuzzleHttp\Client(['verify' => false]);

            $gUser = Socialite::driver('google')
                ->setHttpClient($client)
                ->stateless()
                ->user();

            $existingUser = User::query()->where('email', $gUser->email)->first();

            $user = User::updateOrCreate(['email' => $gUser->email], [
                'name' => $gUser->name,
                'google_id' => $gUser->id,
                'avatar' => $gUser->avatar,
                'password' => $existingUser?->password ?? bcrypt(str()->random(16)),
            ]);

            Auth::login($user, true);
            request()->session()->regenerate();

            return redirect()->intended($fallbackUrl);
        } catch (\Exception $e) {
            Log::error('Google Login Error: '.$e->getMessage());

            return redirect($this->resolveLoginPath($fallbackUrl));
        }
    }

    private function resolveFallbackUrl(): string
    {
        $intended = session('url.intended');

        if (is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        return '/admin';
    }

    private function resolveLoginPath(string $fallbackUrl): string
    {
        return str_starts_with($fallbackUrl, '/seo') ? '/seo/login' : '/admin/login';
    }
}
