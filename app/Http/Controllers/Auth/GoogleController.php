<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Exception;

class GoogleController extends Controller
{
    public function redirectToGoogle()
    {
        return Socialite::driver('google')->redirect();
    }

    public function handleGoogleCallback()
    {
        try {
            $user = Socialite::driver('google')->stateless()->user();
            $finduser = User::where('google_id', $user->id)->orWhere('email', $user->email)->first();

            if ($finduser) {
                if (!$finduser->google_id) {
                    $finduser->update(['google_id' => $user->id, 'avatar' => $user->avatar]);
                }
                Auth::login($finduser);
            } else {
                $newUser = User::create([
                    'name' => $user->name ?? $user->email,
                    'email' => $user->email,
                    'google_id' => $user->id,
                    'avatar' => $user->avatar,
                    'password' => encrypt('my-google-auth-pass'), // Mật khẩu giả
                    'role' => 'owner' // Mặc định là chủ tài khoản
                ]);
                Auth::login($newUser);
            }
            request()->session()->regenerate();
            return redirect()->intended('/admin');
        } catch (Exception $e) {
            return redirect('admin/login')->with('error', 'Có lỗi xảy ra khi đăng nhập bằng Google');
        }
    }
}
