<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            // Thêm setHttpClient để tắt verify SSL
            $client = new \GuzzleHttp\Client(['verify' => false]);

            $gUser = Socialite::driver('google')
                ->setHttpClient($client) // Ép dùng client không check SSL
                ->stateless()
                ->user();

            $user = User::updateOrCreate(['email' => $gUser->email], [
                'name' => $gUser->name,
                'google_id' => $gUser->id,
                'avatar' => $gUser->avatar,
                // Tránh ghi đè password nếu user đã tồn tại
                'password' => \App\Models\User::where('email', $gUser->email)->exists()
                    ? \App\Models\User::where('email', $gUser->email)->first()->password
                    : bcrypt(str()->random(16)),
            ]);

            Auth::login($user, true);
            request()->session()->regenerate();

            return redirect()->intended('/admin');
        } catch (\Exception $e) {
            // Log lỗi để debug nếu cần
            \Illuminate\Support\Facades\Log::error('Google Login Error: ' . $e->getMessage());
            return redirect('/admin/login');
        }
    }
}
