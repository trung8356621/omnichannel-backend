<?php
namespace App\Addons\WpHeadless\Filament\Pages;

use Filament\Pages\Page;
use App\Models\Site;
use App\Models\Service;
use App\Models\SiteService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

class WpHeadlessConnect extends Page
{
    // Chúng ta không cần View vì trang này chỉ xử lý logic rồi chuyển hướng
    protected static string $view = 'wp-headless::filament.pages.wp-connect';

    // Đường dẫn sẽ là /admin/wp-headless/connect
    protected static ?string $slug = 'wp-headless/connect';

    // Ẩn trang này khỏi menu bên trái
    protected static bool $shouldRegisterNavigation = false;

    /**
     * Hàm mount() chạy ngay khi trang được nạp.
     * Vì đây là Filament Page, auth()->user() LUÔN LUÔN có dữ liệu tại đây.
     */
    public function mount(): void
    {

        // 1. Kiểm tra xác thực (Dành cho trường hợp SameSite Cookie bị chặn)
        if (!Auth::check()) {
            // Nếu người dùng bị văng ra login dù đã đăng nhập,
            // Laravel sẽ lưu URL hiện tại (kèm query string) vào 'intended'
            Notification::make()
                ->title('Yêu cầu xác thực')
                ->body('Vui lòng đăng nhập để hoàn tất kết nối WordPress.')
                ->warning()
                ->send();

            return;
        }

        $user = Auth::user();
        dd($user);

        // // 1. Lấy thông tin từ URL (WordPress gửi sang)
        // $siteUrl = request()->query('site_url');
        // $siteName = request()->query('name');
        // $appToken = request()->query('token');

        // if (!$siteUrl) {
        //     Notification::make()->title('Dữ liệu không hợp lệ')->danger()->send();
        //     $this->redirect('/admin/sites');
        //     return;
        // }

        // // 2. Xử lý logic tạo Site & Kích hoạt Service
        // $domain = parse_url($siteUrl, PHP_URL_HOST);

        // $site = Site::updateOrCreate(
        //     ['domain' => $domain],
        //     [
        //         'user_id' => $user->id,
        //         'url' => $siteUrl,
        //         'status' => 'active',
        //         'ssl' => str_starts_with($siteUrl, 'https'),
        //     ]
        // );

        // $service = Service::where('slug', 'wp-headless')->first();
        // if ($service) {
        //     SiteService::updateOrCreate(
        //         ['site_id' => $site->id, 'service_id' => $service->id],
        //         [
        //             'status' => 'active',
        //             'settings' => [
        //                 'wp_token' => $appToken,
        //                 'wp_admin_name' => $siteName,
        //                 'connected_at' => now()->toDateTimeString(),
        //             ]
        //         ]
        //     );
        // }

        // Notification::make()
        //     ->title('Kết nối thành công!')
        //     ->body("Website {$domain} đã được đồng bộ.")
        //     ->success()
        //     ->send();

        // // 3. Chuyển hướng về trang Dashboard của Addon
        // $this->redirect(WpHeadlessDashboard::getUrl(['site_id' => $site->id]));
    }
}
