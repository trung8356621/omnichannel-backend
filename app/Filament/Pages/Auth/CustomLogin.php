<?php
namespace App\Filament\Pages\Auth;

use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Session;

class CustomLogin extends Login
{
    /**
     * Thuộc tính lưu trữ URL trả về để sử dụng sau khi đăng nhập thành công
     */
    public ?string $return_url = null;
    public function getHeading(): string|Htmlable
    {
        return 'Đăng nhập Omnichannel';
    }

    // Ghi đè view để thêm nút Google
    protected static string $view = 'filament.pages.auth.login';

    /**
     * Phương thức chạy khi trang được khởi tạo
     */
    public function mount(): void
    {
        parent::mount();

        // 1. Tự động điền Email từ biến GET ?email=...
        $emailFromUrl = request()->query('email');
        if ($emailFromUrl) {
            $this->form->fill([
                'email' => $emailFromUrl,
                'remember' => true, // Tiện ích: tự động tích nhớ mật khẩu khi có email truyền vào
            ]);
        }

        // 2. Lưu return_url từ biến GET ?return_url=... vào Session hoặc thuộc tính
        // Chúng ta lấy từ query hoặc session (trường hợp quay lại từ Google)
        $returnUrl = request()->query('return_url');

        if ($returnUrl) {
            $this->return_url = $returnUrl;
            // Lưu vào session để không bị mất nếu user nhấn login bằng Google
            Session::put('url.intended', $returnUrl);
        }
    }

    /**
     * Ghi đè logic chuyển hướng sau khi đăng nhập thành công (Auth mặc định)
     */
    protected function getRedirectUrl(): string
    {
        // Ưu tiên chuyển hướng về return_url nếu có
        if ($this->return_url) {
            return $this->return_url;
        }

        // Nếu không có, quay về logic mặc định của Filament (vào Dashboard)
        return parent::getRedirectUrl();
    }

    public function getGoogleLoginReturnUrl(): string
    {
        if ($this->return_url) {
            return $this->return_url;
        }

        return filament()->getCurrentPanel()->getUrl();
    }
}
