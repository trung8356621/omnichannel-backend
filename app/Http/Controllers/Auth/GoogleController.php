<?php

namespace App\Http\Controllers\Auth;

use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
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

            if ($user->isStaff()) {
                if (SeoAccessControl::canAccessAdminAutomationPanel($user)) {
                    return redirect()->intended('/admin/automation/flows');
                }

                return redirect('/');
            }

            return redirect()->intended($this->resolveFallbackUrl($user));
        } catch (\Exception $e) {
            Log::error('Google Login Error: '.$e->getMessage());

            return redirect($this->resolveLoginPath($this->resolveFallbackUrl()));
        }
    }

    private function resolveFallbackUrl(?User $user = null): string
    {
        $user ??= auth()->user();

        if ($user instanceof User && $user->isStaff()) {
            if (SeoAccessControl::canAccessAdminAutomationPanel($user)) {
                return '/admin/automation/flows';
            }

            return '/';
        }

        $intended = session('url.intended');

        if (is_string($intended) && str_starts_with($intended, '/') && ! str_starts_with($intended, '//')) {
            return $intended;
        }

        return '/admin';
    }

    private function resolveLoginPath(string $fallbackUrl): string
    {
        if (! str_starts_with($fallbackUrl, '/seo')) {
            return '/admin/login';
        }

        $hash = app(SeoDatabaseConnectionService::class)->resolveRedirectHash(auth()->user());

        return $hash !== null
            ? '/seo/'.$hash.'/login'
            : '/seo';
    }
}
