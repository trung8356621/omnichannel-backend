<?php
namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;

class CustomLogin extends Login
{
    public function getHeading(): string|Htmlable
    {
        return 'Đăng nhập Omnichannel';
    }

    // Ghi đè view để thêm nút Google
    protected static string $view = 'filament.pages.auth.login';
}
